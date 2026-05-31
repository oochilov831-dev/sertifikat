<?php
namespace App\Controllers;

use PDO;
use App\Models\UserModel;
use App\Middleware\RateLimiter;
use App\Middleware\AuthMiddleware;
use App\Helpers\JWT;
use App\Services\AuditLogger;
use App\Services\EmailService;
use App\Helpers\TOTP;

class AuthController {
    private UserModel $users;
    private PDO $db;

    public function __construct() {
        $this->users = new UserModel();
        $this->db    = \Database::getInstance();
    }

    private function registerSession(int $userId): string {
        $sid = bin2hex(random_bytes(32));
        $ip = \App\Middleware\RateLimiter::ip();
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        $deviceType = 'Desktop';
        if (preg_match('/(tablet|ipad|playbook|silk)|(android(?!.*mobi))/i', $ua)) {
            $deviceType = 'Tablet';
        } elseif (preg_match('/(mobi|ipod|iphone|opera mini|fennec|minimo|symbian|psp|nintendo)/i', $ua)) {
            $deviceType = 'Mobile';
        }
        
        $os = 'Unknown OS';
        if (preg_match('/windows|win32/i', $ua)) {
            $os = 'Windows';
        } elseif (preg_match('/macintosh|mac os x/i', $ua)) {
            $os = 'macOS';
        } elseif (preg_match('/linux/i', $ua)) {
            $os = 'Linux';
        } elseif (preg_match('/iphone|ipad|ipod/i', $ua)) {
            $os = 'iOS';
        } elseif (preg_match('/android/i', $ua)) {
            $os = 'Android';
        }
        
        $browser = 'Unknown Browser';
        if (preg_match('/chrome/i', $ua)) {
            $browser = 'Chrome';
        } elseif (preg_match('/firefox/i', $ua)) {
            $browser = 'Firefox';
        } elseif (preg_match('/safari/i', $ua)) {
            $browser = 'Safari';
        } elseif (preg_match('/edge/i', $ua)) {
            $browser = 'Edge';
        } elseif (preg_match('/opera/i', $ua)) {
            $browser = 'Opera';
        }
        
        $deviceInfo = "{$deviceType} ({$os} - {$browser})";
        
        $stmt = $this->db->prepare(
            'INSERT INTO user_sessions (user_id, sid, ip_address, user_agent, device_type) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $sid, $ip, $ua, $deviceInfo]);
        
        return $sid;
    }

    private function extractTokenFromRequest(): ?string {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.+)/', $header, $m)) return $m[1];
        return $_COOKIE['token'] ?? null;
    }

    // POST /api/auth/register
    public function register(): never {
        (new RateLimiter)->check('register:' . RateLimiter::ip(), 10, 3600);
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        $name     = trim($data['name'] ?? '');
        $email    = trim($data['email'] ?? '');
        $phone    = trim($data['phone'] ?? '');
        $password = $data['password'] ?? '';

        $errors = [];
        if (strlen($name) < 2)        $errors['name']     = 'Ism kamida 2 harf bo\'lishi kerak';
        if (strlen($password) < 6)    $errors['password'] = 'Parol kamida 6 belgidan iborat bo\'lishi kerak';
        if (!$email && !$phone)        $errors['contact']  = 'Email yoki telefon raqam kiritilishi shart';
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Email formati noto\'g\'ri';

        if (!empty($errors)) error('Validatsiya xatosi', 422, $errors);

        if ($email && $this->users->findByEmail($email)) error('Bu email allaqachon ro\'yxatdan o\'tgan', 409);
        if ($phone && $this->users->findByPhone($phone)) error('Bu telefon raqam allaqachon ro\'yxatdan o\'tgan', 409);

        $userId = $this->users->create([
            'name'     => $name,
            'email'    => $email ?: null,
            'phone'    => $phone ?: null,
            'password' => $password,
        ]);

        $user  = $this->users->findById($userId);
        $sid   = $this->registerSession($user['id']);
        $token = JWT::encode(['sub' => $user['id'], 'role' => $user['role'], 'sid' => $sid]);

        AuditLogger::log('register', $userId);

        // Email verifikatsiya kodi yuborish (agar email bo'lsa)
        if ($email) {
            $this->createVerificationCode($userId, $email);
        }

        success([
            'token' => $token,
            'user'  => $this->safeUser($user),
            'requires_verification' => (bool)$email,
        ], 'Muvaffaqiyatli ro\'yxatdan o\'tdingiz. Emailingizga tasdiqlash kodi yuborildi.', 201);
    }

    private function createVerificationCode(int $userId, string $email): string {
        // Eski kodlarni o'chirib qo'yamiz
        $this->db->prepare('DELETE FROM email_verifications WHERE user_id = ? AND used = false')
                 ->execute([$userId]);

        $code = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        $this->db->prepare(
            'INSERT INTO email_verifications (user_id, code, expires_at) VALUES (?, ?, NOW() + INTERVAL \'15 minutes\')'
        )->execute([$userId, $code]);

        // Email yuborish — OFFLINE_MODE da error_log'ga yoziladi
        EmailService::sendVerificationCode($email, $code);

        if (env('OFFLINE_MODE', 'true') === 'true') {
            error_log("[EmailVerify] User #{$userId} ({$email}) code: {$code}");
        }

        return $code;
    }

    // POST /api/auth/verify-email  { code }
    public function verifyEmail(): never {
        $user = AuthMiddleware::handle();
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $code = trim((string)($data['code'] ?? ''));

        if (strlen($code) !== 6) error('Kod 6 raqamdan iborat bo\'lishi kerak', 422);

        (new RateLimiter)->check('verify-email:' . $user['id'], 10, 600);

        $stmt = $this->db->prepare(
            'SELECT * FROM email_verifications
             WHERE user_id = ? AND code = ? AND used = false AND expires_at > NOW()
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$user['id'], $code]);
        $row = $stmt->fetch();

        if (!$row) error('Kod noto\'g\'ri yoki muddati tugagan', 400);

        $this->db->prepare('UPDATE email_verifications SET used = true WHERE id = ?')->execute([$row['id']]);
        $this->users->verify($user['id']);

        AuditLogger::log('email.verify', $user['id']);

        success(null, 'Email muvaffaqiyatli tasdiqlandi');
    }

    // POST /api/auth/resend-verification
    public function resendVerification(): never {
        $user = AuthMiddleware::handle();
        (new RateLimiter)->check('resend-verify:' . $user['id'], 3, 600);

        $full = $this->users->findById($user['id']);
        if (!$full) error('Foydalanuvchi topilmadi', 404);
        if ($full['is_verified']) error('Email allaqachon tasdiqlangan', 400);
        if (!$full['email']) error('Emailingiz mavjud emas', 400);

        $this->createVerificationCode($user['id'], $full['email']);
        success(null, 'Yangi tasdiqlash kodi yuborildi');
    }

    // POST /api/auth/login
    public function login(): never {
        $ip   = RateLimiter::ip();
        $rl   = new RateLimiter;
        $rl->check('login:' . $ip, 10, 300); // 5 daqiqada 10 ta
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        $login    = trim($data['login'] ?? '');
        $password = $data['password'] ?? '';

        if (!$login || !$password) error('Login va parol kiritilishi shart', 422);

        $user = filter_var($login, FILTER_VALIDATE_EMAIL)
            ? $this->users->findByEmail($login)
            : $this->users->findByPhone($login);

        if (!$user || !password_verify($password, $user['password'])) {
            AuditLogger::log('login.failed', $user['id'] ?? null, meta: ['login' => $login]);
            error('Login yoki parol noto\'g\'ri', 401);
        }

        if (!$user['is_active']) error('Akkauntingiz bloklangan', 403);

        // 2FA — agar yoqilgan bo'lsa, kod talab qilamiz
        if (!empty($user['totp_enabled'])) {
            $totpCode = trim((string)($data['totp_code'] ?? ''));
            $recoveryCode = trim((string)($data['recovery_code'] ?? ''));

            if ($totpCode === '' && $recoveryCode === '') {
                success(['requires_2fa' => true], '2FA kodini kiriting', 200);
            }

            $verified = false;
            if ($totpCode !== '' && TOTP::verify($user['totp_secret'], $totpCode)) {
                $verified = true;
            } elseif ($recoveryCode !== '') {
                $codes = $user['recovery_codes'] ? json_decode($user['recovery_codes'], true) : [];
                if (in_array($recoveryCode, $codes ?? [], true)) {
                    // Ishlatilgan kodni o'chiramiz
                    $codes = array_values(array_diff($codes, [$recoveryCode]));
                    $this->db->prepare('UPDATE users SET recovery_codes = ? WHERE id = ?')
                             ->execute([json_encode($codes), $user['id']]);
                    $verified = true;
                }
            }

            if (!$verified) {
                AuditLogger::log('login.failed', $user['id'], meta: ['reason' => '2fa']);
                error('2FA kod noto\'g\'ri', 401);
            }
        }

        AuditLogger::log('login', $user['id']);

        $sid   = $this->registerSession($user['id']);
        $token = JWT::encode(['sub' => $user['id'], 'role' => $user['role'], 'sid' => $sid]);

        success([
            'token' => $token,
            'user'  => $this->safeUser($user),
        ], 'Muvaffaqiyatli kirdingiz');
    }

    // POST /api/auth/forgot-password
    public function forgotPassword(): never {
        (new RateLimiter)->check('forgot:' . RateLimiter::ip(), 5, 3600);
        $data  = json_decode(file_get_contents('php://input'), true) ?? [];
        $login = trim($data['login'] ?? '');

        if (!$login) error('Email yoki telefon kiritilishi shart', 422);

        $user = filter_var($login, FILTER_VALIDATE_EMAIL)
            ? $this->users->findByEmail($login)
            : $this->users->findByPhone($login);

        // Account Enumeration xavfini oldini olish uchun foydalanuvchi topilmasa ham muvaffaqiyatli javob qaytaramiz
        if (!$user) {
            success(['message' => 'Agar akkaunt mavjud bo\'lsa, parol tiklash havolasi yuborildi'], 'Tekshiring');
        }

        $token = bin2hex(random_bytes(32));

        $stmt = $this->db->prepare(
            'INSERT INTO password_resets (email, phone, token, expires_at)
             VALUES (?, ?, ?, NOW() + INTERVAL \'1 hour\')'
        );
        $stmt->execute([$user['email'] ?: null, $user['phone'] ?: null, $token]);

        // Haqiqiy Email yuborish
        if (!empty($user['email'])) {
            EmailService::sendPasswordReset($user['email'], $token);
        }

        if (env('OFFLINE_MODE', 'true') === 'true') {
            error_log("[PasswordReset] User #{$user['id']} Reset Token: {$token}");
        }

        success(['message' => 'Agar akkaunt mavjud bo\'lsa, parol tiklash havolasi yuborildi'], 'Tekshiring');
    }

    // POST /api/auth/reset-password
    public function resetPassword(): never {
        $data     = json_decode(file_get_contents('php://input'), true) ?? [];
        $token    = trim($data['token'] ?? '');
        $password = $data['password'] ?? '';

        if (!$token || strlen($password) < 6) error('Token va yangi parol (6+ belgi) kiritilishi shart', 422);

        $stmt = $this->db->prepare(
            'SELECT * FROM password_resets WHERE token = ? AND used = false AND expires_at > NOW()'
        );
        $stmt->execute([$token]);
        $reset = $stmt->fetch();

        if (!$reset) error('Token yaroqsiz yoki muddati tugagan', 400);

        $user = $reset['email']
            ? $this->users->findByEmail($reset['email'])
            : $this->users->findByPhone($reset['phone']);

        if (!$user) error('Foydalanuvchi topilmadi', 404);

        $this->users->changePassword($user['id'], $password);

        $this->db->prepare('UPDATE password_resets SET used = true WHERE token = ?')->execute([$token]);

        success(null, 'Parol muvaffaqiyatli o\'zgartirildi');
    }

    // GET /api/auth/me
    public function me(): never {
        $user = AuthMiddleware::handle();
        $full = $this->users->findById($user['id']);

        // Obuna ma'lumotlari
        $stmt = $this->db->prepare(
            'SELECT * FROM subscriptions WHERE user_id = ? AND status = \'active\' ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute([$user['id']]);
        $subscription = $stmt->fetch();

        success([
            'user'         => $this->safeUser($full),
            'subscription' => $subscription,
        ]);
    }

    // PUT /api/auth/profile
    public function updateProfile(): never {
        $user = AuthMiddleware::handle();
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        $this->users->update($user['id'], $data);
        $updated = $this->users->findById($user['id']);

        success($this->safeUser($updated), 'Profil yangilandi');
    }

    // PUT /api/auth/change-password
    public function changePassword(): never {
        $user     = AuthMiddleware::handle();
        $data     = json_decode(file_get_contents('php://input'), true) ?? [];
        $oldPass  = $data['old_password'] ?? '';
        $newPass  = $data['new_password'] ?? '';

        if (!$oldPass || strlen($newPass) < 6) error('Eski va yangi parol kiritilishi shart', 422);

        $full = $this->users->findById($user['id']);
        if (!password_verify($oldPass, $full['password'])) error('Eski parol noto\'g\'ri', 400);

        $this->users->changePassword($user['id'], $newPass);
        AuditLogger::log('password.change', $user['id']);
        success(null, 'Parol muvaffaqiyatli o\'zgartirildi');
    }

    // POST /api/auth/api-key
    public function createApiKey(): never {
        $user = AuthMiddleware::handle();
        $apiKey = 'sk_' . bin2hex(random_bytes(24));

        $this->db->prepare('UPDATE users SET api_key = ?, updated_at = NOW() WHERE id = ?')
                 ->execute([$apiKey, $user['id']]);

        AuditLogger::log('api_key.create', $user['id']);

        success([
            'api_key' => $apiKey,
            'message' => 'API kalit faqat hozir ko\'rsatiladi. Uni xavfsiz joyda saqlang.',
        ], 'API kalit yaratildi');
    }

    // DELETE /api/auth/api-key
    public function deleteApiKey(): never {
        $user = AuthMiddleware::handle();

        $this->db->prepare('UPDATE users SET api_key = NULL, updated_at = NOW() WHERE id = ?')
                 ->execute([$user['id']]);

        AuditLogger::log('api_key.delete', $user['id']);

        success(null, 'API kalit o\'chirildi');
    }

    // POST /api/auth/2fa/setup — 1-qadam: secret yaratish va QR URI qaytarish
    public function setup2FA(): never {
        $user = AuthMiddleware::handle();
        $full = $this->users->findById($user['id']);
        if (!empty($full['totp_enabled'])) error('2FA allaqachon yoqilgan', 400);

        $secret = TOTP::generateSecret();
        // Hozircha enabled=false bilan saqlaymiz — confirm orqali yoqamiz
        $this->db->prepare('UPDATE users SET totp_secret = ? WHERE id = ?')
                 ->execute([$secret, $user['id']]);

        $account = $full['email'] ?: $full['phone'] ?: ('user_' . $user['id']);
        $uri = TOTP::uri($secret, $account);

        success([
            'secret'    => $secret,
            'uri'       => $uri,
            'qr_url'    => null,
        ], '2FA secret yaratildi — authenticator ilovasiga manual kiriting');
    }

    // POST /api/auth/2fa/confirm — 2-qadam: kod kiritib tasdiqlash
    public function confirm2FA(): never {
        $user = AuthMiddleware::handle();
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $code = trim((string)($data['code'] ?? ''));

        if (!preg_match('/^\d{6}$/', $code)) error('6 raqamli kod kiriting', 422);

        $full = $this->users->findById($user['id']);
        if (empty($full['totp_secret'])) error('Avval /2fa/setup chaqiring', 400);
        if (!empty($full['totp_enabled'])) error('2FA allaqachon yoqilgan', 400);

        if (!TOTP::verify($full['totp_secret'], $code)) error('Kod noto\'g\'ri', 400);

        $recoveryCodes = TOTP::generateRecoveryCodes(10);

        $this->db->prepare(
            'UPDATE users SET totp_enabled = true, recovery_codes = ? WHERE id = ?'
        )->execute([json_encode($recoveryCodes), $user['id']]);

        AuditLogger::log('2fa.enable', $user['id']);

        success([
            'recovery_codes' => $recoveryCodes,
            'message' => 'Recovery kodlarini xavfsiz joyda saqlang — qaytib ko\'rsatilmaydi',
        ], '2FA yoqildi');
    }

    // POST /api/auth/2fa/disable — { password }
    public function disable2FA(): never {
        $user = AuthMiddleware::handle();
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $password = $data['password'] ?? '';

        $full = $this->users->findById($user['id']);
        if (empty($full['totp_enabled'])) error('2FA o\'chirilgan', 400);
        if (!password_verify($password, $full['password'])) error('Parol noto\'g\'ri', 401);

        $this->db->prepare(
            'UPDATE users SET totp_enabled = false, totp_secret = NULL, recovery_codes = NULL WHERE id = ?'
        )->execute([$user['id']]);

        AuditLogger::log('2fa.disable', $user['id']);
        success(null, '2FA o\'chirildi');
    }

    // GET /api/auth/2fa/status
    public function status2FA(): never {
        $user = AuthMiddleware::handle();
        $full = $this->users->findById($user['id']);
        $codes = $full['recovery_codes'] ? json_decode($full['recovery_codes'], true) : [];
        success([
            'enabled'         => (bool)($full['totp_enabled'] ?? false),
            'recovery_remaining' => count($codes ?? []),
        ]);
    }

    // POST /api/auth/avatar  (multipart/form-data: file)
    public function uploadAvatar(): never {
        $user = AuthMiddleware::handle();
        $url  = $this->saveUploadedImage('avatar', 'avatars', $user['id']);

        $this->db->prepare('UPDATE users SET avatar = ?, updated_at = NOW() WHERE id = ?')
                 ->execute([$url, $user['id']]);

        success(['avatar' => $url], 'Avatar yangilandi');
    }

    // POST /api/auth/logo
    public function uploadLogo(): never {
        $user = AuthMiddleware::handle();
        $url  = $this->saveUploadedImage('logo', 'logos', $user['id']);

        $this->db->prepare('UPDATE users SET logo_url = ?, updated_at = NOW() WHERE id = ?')
                 ->execute([$url, $user['id']]);

        success(['logo_url' => $url], 'Logo yangilandi');
    }

    // DELETE /api/auth/avatar
    public function deleteAvatar(): never {
        $user = AuthMiddleware::handle();
        $cur  = $this->users->findById($user['id']);
        if (!empty($cur['avatar'])) {
            $f = __DIR__ . '/../../public/' . ltrim($cur['avatar'], '/');
            if (is_file($f)) @unlink($f);
        }
        $this->db->prepare('UPDATE users SET avatar = NULL WHERE id = ?')->execute([$user['id']]);
        success(null, 'Avatar o\'chirildi');
    }

    // DELETE /api/auth/logo
    public function deleteLogo(): never {
        $user = AuthMiddleware::handle();
        $cur  = $this->users->findById($user['id']);
        if (!empty($cur['logo_url'])) {
            $f = __DIR__ . '/../../public/' . ltrim($cur['logo_url'], '/');
            if (is_file($f)) @unlink($f);
        }
        $this->db->prepare('UPDATE users SET logo_url = NULL WHERE id = ?')->execute([$user['id']]);
        success(null, 'Logo o\'chirildi');
    }

    // POST /api/auth/signature
    public function uploadSignature(): never {
        $user = AuthMiddleware::handle();
        $url  = $this->saveUploadedImage('signature', 'signatures', $user['id']);

        $this->db->prepare('UPDATE users SET signature_url = ?, updated_at = NOW() WHERE id = ?')
                 ->execute([$url, $user['id']]);

        success(['signature_url' => $url], 'Imzo yangilandi');
    }

    // DELETE /api/auth/signature
    public function deleteSignature(): never {
        $user = AuthMiddleware::handle();
        $cur  = $this->users->findById($user['id']);
        if (!empty($cur['signature_url'])) {
            $f = __DIR__ . '/../../public/' . ltrim($cur['signature_url'], '/');
            if (is_file($f)) @unlink($f);
        }
        $this->db->prepare('UPDATE users SET signature_url = NULL WHERE id = ?')->execute([$user['id']]);
        success(null, 'Imzo o\'chirildi');
    }

    // POST /api/auth/seal
    public function uploadSeal(): never {
        $user = AuthMiddleware::handle();
        $url  = $this->saveUploadedImage('seal', 'seals', $user['id']);

        $this->db->prepare('UPDATE users SET seal_url = ?, updated_at = NOW() WHERE id = ?')
                 ->execute([$url, $user['id']]);

        success(['seal_url' => $url], 'Muhr yangilandi');
    }

    // DELETE /api/auth/seal
    public function deleteSeal(): never {
        $user = AuthMiddleware::handle();
        $cur  = $this->users->findById($user['id']);
        if (!empty($cur['seal_url'])) {
            $f = __DIR__ . '/../../public/' . ltrim($cur['seal_url'], '/');
            if (is_file($f)) @unlink($f);
        }
        $this->db->prepare('UPDATE users SET seal_url = NULL WHERE id = ?')->execute([$user['id']]);
        success(null, 'Muhr o\'chirildi');
    }

    // DELETE /api/auth/account
    public function deleteAccount(): never {
        $user     = AuthMiddleware::handle();
        $data     = json_decode(file_get_contents('php://input'), true) ?? [];
        $password = $data['password'] ?? '';

        if (!$password) error('Tasdiqlash uchun parol kiritilishi shart', 422);

        $full = $this->users->findById($user['id']);
        if (!$full || !password_verify($password, $full['password'])) {
            error('Parol noto\'g\'ri', 401);
        }

        $this->db->beginTransaction();
        try {
            // Sertifikat fayllarini o'chirish
            $stmt = $this->db->prepare('SELECT file_pdf, file_png, qr_code FROM certificates WHERE user_id = ?');
            $stmt->execute([$user['id']]);
            $publicDir = __DIR__ . '/../../public/';
            foreach ($stmt->fetchAll() as $c) {
                foreach (['file_pdf', 'file_png', 'qr_code'] as $f) {
                    if (!empty($c[$f])) {
                        $p = $publicDir . ltrim($c[$f], '/');
                        if (is_file($p)) @unlink($p);
                    }
                }
            }

            // Avatar/logo/signature/seal fayllari
            foreach (['avatar', 'logo_url', 'signature_url', 'seal_url'] as $f) {
                if (!empty($full[$f])) {
                    $p = $publicDir . ltrim($full[$f], '/');
                    if (is_file($p)) @unlink($p);
                }
            }

            // Cascading delete (FK ON DELETE CASCADE bo'lmasa qo'lda)
            $this->db->prepare('DELETE FROM cert_scans WHERE cert_id IN (SELECT id FROM certificates WHERE user_id = ?)')->execute([$user['id']]);
            $this->db->prepare('DELETE FROM certificates WHERE user_id = ?')->execute([$user['id']]);
            $this->db->prepare('DELETE FROM subscriptions WHERE user_id = ?')->execute([$user['id']]);
            $this->db->prepare('DELETE FROM payments WHERE user_id = ?')->execute([$user['id']]);
            $this->db->prepare('DELETE FROM users WHERE id = ?')->execute([$user['id']]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        AuditLogger::log('account.delete', $user['id']);
        success(null, 'Hisobingiz va barcha ma\'lumotlaringiz butunlay o\'chirildi');
    }

    // GET /api/auth/export — foydalanuvchi ma'lumotlarini JSON ko'rinishida olish
    public function exportData(): never {
        $user = AuthMiddleware::handle();
        $full = $this->users->findById($user['id']);

        $cs = $this->db->prepare('SELECT cert_id, recipient_name, course_name, issued_date, created_at FROM certificates WHERE user_id = ?');
        $cs->execute([$user['id']]);

        $ps = $this->db->prepare('SELECT amount, plan, status, paid_at, created_at FROM payments WHERE user_id = ?');
        $ps->execute([$user['id']]);

        success([
            'user'         => $this->safeUser($full),
            'certificates' => $cs->fetchAll(),
            'payments'     => $ps->fetchAll(),
            'exported_at'  => date('c'),
        ]);
    }

    private function saveUploadedImage(string $field, string $folder, int $userId): string {
        if (empty($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
            error('Fayl yuklashda xato', 422);
        }
        $file = $_FILES[$field];
        if ($file['size'] > 2 * 1024 * 1024) error('Fayl 2MB dan kichik bo\'lishi kerak', 422);
        $mime = null;
        if (function_exists('mime_content_type')) {
            $mime = mime_content_type($file['tmp_name']);
        } elseif (function_exists('getimagesize')) {
            $imgInfo = getimagesize($file['tmp_name']);
            if ($imgInfo && isset($imgInfo['mime'])) {
                $mime = $imgInfo['mime'];
            }
        }
        if (!$mime) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $extMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
            if (isset($extMap[$ext])) {
                $mime = $extMap[$ext];
            }
        }

        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($allowed[$mime])) error('Faqat JPG, PNG yoki WEBP fayl yuklash mumkin', 422);

        $ext = $allowed[$mime];
        $dir = __DIR__ . "/../../public/uploads/{$folder}";
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $filename = "{$folder}_u{$userId}_" . substr(bin2hex(random_bytes(6)), 0, 12) . ".{$ext}";
        $dest     = "{$dir}/{$filename}";

        // Eski faylni o'chirish
        $oldKey = $field === 'avatar' ? 'avatar' : ($field === 'logo' ? 'logo_url' : ($field === 'signature' ? 'signature_url' : 'seal_url'));
        $cur    = $this->users->findById($userId);
        if (!empty($cur[$oldKey])) {
            $oldPath = __DIR__ . '/../../public/' . ltrim($cur[$oldKey], '/');
            if (is_file($oldPath)) @unlink($oldPath);
        }

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            error('Faylni saqlashda xato', 500);
        }

        // Avatar uchun kvadrat resize (256x256)
        if ($field === 'avatar') {
            $this->resizeSquare($dest, 256);
        } else {
            // Logo uchun max 400x400 saqlab proportsiyalarni
            $this->resizeMax($dest, 400);
        }

        return "uploads/{$folder}/{$filename}";
    }

    private function resizeSquare(string $path, int $size): void {
        $info = @getimagesize($path);
        if (!$info) return;

        $src = match($info['mime']) {
            'image/jpeg' => imagecreatefromjpeg($path),
            'image/png'  => imagecreatefrompng($path),
            'image/webp' => imagecreatefromwebp($path),
            default      => null,
        };
        if (!$src) return;

        $w = imagesx($src);
        $h = imagesy($src);
        $min = min($w, $h);
        $sx = (int)(($w - $min) / 2);
        $sy = (int)(($h - $min) / 2);

        $dst = imagecreatetruecolor($size, $size);
        imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $size, $size, $min, $min);
        imagepng($dst, $path, 6);
    }

    private function resizeMax(string $path, int $max): void {
        $info = @getimagesize($path);
        if (!$info) return;
        $w = $info[0]; $h = $info[1];
        if ($w <= $max && $h <= $max) return;

        $ratio = $w > $h ? $max / $w : $max / $h;
        $nw = (int)($w * $ratio);
        $nh = (int)($h * $ratio);

        $src = match($info['mime']) {
            'image/jpeg' => imagecreatefromjpeg($path),
            'image/png'  => imagecreatefrompng($path),
            'image/webp' => imagecreatefromwebp($path),
            default      => null,
        };
        if (!$src) return;

        $dst = imagecreatetruecolor($nw, $nh);
        imagesavealpha($dst, true);
        $trans = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $nw, $nh, $trans);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagepng($dst, $path, 6);
    }

    private function safeUser(array $user): array {
        $hasApiKey = !empty($user['api_key'] ?? null);
        unset($user['password'], $user['totp_secret'], $user['recovery_codes'], $user['api_key']);
        $user['has_api_key'] = $hasApiKey;
        return $user;
    }

    public function getSessions(): void {
        $user = AuthMiddleware::handle();
        
        $token = $this->extractTokenFromRequest();
        $currentSid = null;
        if ($token) {
            $payload = JWT::decode($token);
            $currentSid = $payload['sid'] ?? null;
        }

        $stmt = $this->db->prepare(
            'SELECT id, ip_address, device_type, created_at, last_activity, sid
             FROM user_sessions
             WHERE user_id = ? AND is_revoked = false
             ORDER BY last_activity DESC'
        );
        $stmt->execute([$user['id']]);
        $sessions = $stmt->fetchAll();

        // Mark current session
        foreach ($sessions as &$sess) {
            $sess['is_current'] = ($sess['sid'] === $currentSid);
            unset($sess['sid']);
        }

        success($sessions);
    }

    public function revokeSession(): void {
        $user = AuthMiddleware::handle();
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $sessionId = (int)($data['session_id'] ?? 0);

        if (!$sessionId) {
            error('Sessiya ID kiritilishi shart', 422);
        }

        $stmt = $this->db->prepare(
            'UPDATE user_sessions SET is_revoked = true WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$sessionId, $user['id']]);

        success(null, 'Sessiya bekor qilindi');
    }

    public function googleRedirect(): void {
        $clientId = env('GOOGLE_CLIENT_ID');
        $redirectUri = env('GOOGLE_REDIRECT_URI');
        
        if (!$clientId || !$redirectUri) {
            http_response_code(500);
            echo json_encode(['message' => 'Google OAuth sozlanmagan']);
            exit;
        }

        $url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'access_type' => 'online',
            'prompt' => 'select_account'
        ]);

        header("Location: {$url}");
        exit;
    }

    public function googleCallback(): void {
        try {
            $code = $_GET['code'] ?? '';
            if (!$code) {
                header("Location: /login.html?error=Google_auth_failed");
                exit;
            }

            $clientId = env('GOOGLE_CLIENT_ID');
            $clientSecret = env('GOOGLE_CLIENT_SECRET');
            $redirectUri = env('GOOGLE_REDIRECT_URI');

            $ch = curl_init('https://oauth2.googleapis.com/token');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                'code' => $code,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code'
            ]));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $res = curl_exec($ch);
            curl_close($ch);

            $tokenData = json_decode($res, true);
            $accessToken = $tokenData['access_token'] ?? '';

            if (!$accessToken) {
                throw new \Exception("Google token olish muvaffaqiyatsiz yakunlandi. Response: " . $res);
            }

            $ch = curl_init('https://www.googleapis.com/oauth2/v3/userinfo');
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer {$accessToken}"
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $profileRes = curl_exec($ch);
            curl_close($ch);

            $profile = json_decode($profileRes, true);
            $email = trim($profile['email'] ?? '');
            $name = trim($profile['name'] ?? 'Google User');

            if (!$email) {
                throw new \Exception("Google email topilmadi. Response: " . $profileRes);
            }

            $user = $this->users->findByEmail($email);
            if (!$user) {
                $userId = $this->users->create([
                    'name' => $name,
                    'email' => $email,
                    'phone' => null,
                    'password' => bin2hex(random_bytes(16))
                ]);
                $this->users->verify($userId);
                $user = $this->users->findById($userId);
            }

            if (!$user['is_active']) {
                header("Location: /login.html?error=User_blocked");
                exit;
            }

            $sid = $this->registerSession($user['id']);
            $token = JWT::encode(['sub' => $user['id'], 'role' => $user['role'], 'sid' => $sid]);

            $userSafe = [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'phone' => $user['phone'],
                'role' => $user['role'],
                'is_verified' => 1
            ];

            $redirectUrl = '/login.html?google_token=' . urlencode($token) . '&google_user=' . urlencode(json_encode($userSafe));
            header("Location: {$redirectUrl}");
            exit;
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Google Auth Error: ' . $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            exit;
        }
    }
}
