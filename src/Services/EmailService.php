<?php
namespace App\Services;

class EmailService {

    private static function smtp(): array {
        return [
            'host' => env('MAIL_HOST', 'localhost'),
            'port' => (int) env('MAIL_PORT', '25'),
            'user' => env('MAIL_USER', ''),
            'pass' => env('MAIL_PASS', ''),
            'from' => env('MAIL_FROM', 'noreply@sertifikat.uz'),
            'name' => env('MAIL_FROM_NAME', 'Sertifikat Tizimi'),
        ];
    }

    public static function send(string $to, string $subject, string $htmlBody): bool {
        if (env('OFFLINE_MODE', 'true') === 'true') {
            return false;
        }

        $cfg = self::smtp();
        if (!$cfg['user'] || !$cfg['pass']) return false;

        $boundary = md5(uniqid());
        $headers  = implode("\r\n", [
            'From: ' . self::mime($cfg['name']) . ' <' . $cfg['from'] . '>',
            'To: ' . $to,
            'Subject: ' . self::mime($subject),
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'X-Mailer: SertifikatTizimi/1.0',
        ]);

        $plain = strip_tags($htmlBody);
        $body  = "--{$boundary}\r\n"
               . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$plain}\r\n"
               . "--{$boundary}\r\n"
               . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$htmlBody}\r\n"
               . "--{$boundary}--";

        // PHP mail() + SMTP sozlamalari ini_set orqali
        ini_set('SMTP',     $cfg['host']);
        ini_set('smtp_port', (string) $cfg['port']);

        $sent = @mail($to, $subject, $body, $headers);

        if (!$sent) {
            error_log("[EmailService] mail() failed to: {$to}, subject: {$subject}");
        }
        return $sent;
    }

    // Obuna tugash ogohlantirishi
    public static function sendSubscriptionExpiry(string $to, string $name, string $plan, string $expiresAt): bool {
        $planName = match($plan) { 'standard' => 'Standart', 'pro' => 'Pro', default => $plan };
        $date     = date('d.m.Y', strtotime($expiresAt));
        $appUrl   = env('APP_URL', 'http://localhost:8000');

        $html = <<<HTML
<!DOCTYPE html><html lang="uz"><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;background:#f1f5f9;margin:0;padding:20px;">
<div style="max-width:520px;margin:0 auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.1);">
  <div style="background:linear-gradient(135deg,#f59e0b,#d97706);padding:28px 32px;color:#fff;text-align:center;">
    <div style="font-size:48px;margin-bottom:8px;">⏰</div>
    <h1 style="margin:0;font-size:22px;">Obuna muddati tugayapti</h1>
  </div>
  <div style="padding:28px 32px;">
    <p style="color:#374151;font-size:16px;">Hurmatli <strong>{$name}</strong>,</p>
    <p style="color:#6b7280;font-size:14px;line-height:1.6;">
      <strong>{$planName}</strong> tarifingiz muddati <strong style="color:#ef4444;">{$date}</strong> da tugaydi.
      Obunani yangilamasangiz, sertifikat yaratish imkoniyati cheklanadi.
    </p>
    <div style="background:#fef9c3;border-radius:12px;padding:16px;margin:20px 0;border-left:4px solid #f59e0b;">
      <p style="margin:0;color:#92400e;font-size:14px;">
        ⚠️ Obuna tugagach: sertifikat yaratish to'xtatiladi, mavjud sertifikatlar saqlanib qoladi.
      </p>
    </div>
    <a href="{$appUrl}/plans.html" style="display:block;text-align:center;background:#6366f1;color:#fff;text-decoration:none;padding:14px 28px;border-radius:12px;font-size:15px;font-weight:700;margin-top:20px;">
      💎 Obunani yangilash
    </a>
  </div>
  <div style="background:#f8fafc;padding:16px 32px;text-align:center;color:#9ca3af;font-size:12px;">
    Sertifikat Tizimi · <a href="{$appUrl}" style="color:#6366f1;">sertifikat.uz</a>
  </div>
</div>
</body></html>
HTML;

        return self::send($to, "⏰ Obunangiz {$date} da tugaydi — yangilang", $html);
    }

    // Email tasdiqlash kodi
    public static function sendVerificationCode(string $to, string $code): bool {
        $appUrl = env('APP_URL', 'http://localhost:8000');
        $html = <<<HTML
<!DOCTYPE html><html lang="uz"><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;background:#f1f5f9;margin:0;padding:20px;">
<div style="max-width:480px;margin:0 auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.08);">
  <div style="background:linear-gradient(135deg,#6366f1,#8b5cf6);padding:32px;color:#fff;text-align:center;">
    <div style="font-size:40px;margin-bottom:8px;">📧</div>
    <h1 style="margin:0;font-size:22px;">Emailingizni tasdiqlang</h1>
  </div>
  <div style="padding:32px;text-align:center;">
    <p style="color:#64748b;font-size:14px;margin-bottom:24px;">Quyidagi 6 raqamli kodni saytda kiriting:</p>
    <div style="background:#f1f5f9;border-radius:12px;padding:20px;margin:0 auto 24px;max-width:240px;">
      <div style="font-family:monospace;font-size:32px;font-weight:900;letter-spacing:8px;color:#6366f1;">{$code}</div>
    </div>
    <p style="color:#94a3b8;font-size:12px;">Kod 15 daqiqa amal qiladi. Agar siz ro'yxatdan o'tmagan bo'lsangiz, bu xatni e'tiborsiz qoldiring.</p>
    <a href="{$appUrl}/verify-email.html" style="display:inline-block;margin-top:20px;background:#6366f1;color:#fff;text-decoration:none;padding:12px 28px;border-radius:10px;font-weight:700;font-size:14px;">Saytga o'tish</a>
  </div>
  <div style="background:#f8fafc;padding:14px;text-align:center;color:#94a3b8;font-size:11px;">Sertifikat Tizimi</div>
</div>
</body></html>
HTML;
        return self::send($to, '🔐 Tasdiqlash kodi: ' . $code, $html);
    }

    // Yangi sertifikat bildirishi
    public static function sendCertificateCreated(string $to, string $recipientName, string $courseName, string $certId, string $verifyUrl): bool {
        $html = <<<HTML
<!DOCTYPE html><html lang="uz"><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;background:#f1f5f9;margin:0;padding:20px;">
<div style="max-width:520px;margin:0 auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.1);">
  <div style="background:linear-gradient(135deg,#10b981,#059669);padding:28px 32px;color:#fff;text-align:center;">
    <div style="font-size:48px;margin-bottom:8px;">🎓</div>
    <h1 style="margin:0;font-size:22px;">Tabriklaymiz!</h1>
  </div>
  <div style="padding:28px 32px;">
    <p style="color:#374151;font-size:16px;">Hurmatli <strong>{$recipientName}</strong>,</p>
    <p style="color:#6b7280;font-size:14px;line-height:1.6;">
      <strong>{$courseName}</strong> kursi bo'yicha sertifikatingiz tayyor!
    </p>
    <div style="background:#f0fdf4;border-radius:12px;padding:16px;margin:20px 0;text-align:center;">
      <div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px;">Sertifikat ID</div>
      <div style="font-family:monospace;font-size:18px;font-weight:700;color:#059669;">{$certId}</div>
    </div>
    <a href="{$verifyUrl}" style="display:block;text-align:center;background:#6366f1;color:#fff;text-decoration:none;padding:14px 28px;border-radius:12px;font-size:15px;font-weight:700;margin-top:20px;">
      🔍 Sertifikatni ko'rish va tekshirish
    </a>
  </div>
  <div style="background:#f8fafc;padding:16px 32px;text-align:center;color:#9ca3af;font-size:12px;">
    Sertifikat Tizimi · <a href="{$verifyUrl}" style="color:#6366f1;">Tekshirish havolasi</a>
  </div>
</div>
</body></html>
HTML;

        return self::send($to, "🎓 Sertifikatingiz tayyor: {$courseName}", $html);
    }

    // Parol tiklash xati
    public static function sendPasswordReset(string $to, string $token): bool {
        $appUrl = env('APP_URL', 'http://localhost:8000');
        $resetUrl = "{$appUrl}/reset-password.html?token={$token}";
        $html = <<<HTML
<!DOCTYPE html><html lang="uz"><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;background:#f1f5f9;margin:0;padding:20px;">
<div style="max-width:480px;margin:0 auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.08);">
  <div style="background:linear-gradient(135deg,#e11d48,#be123c);padding:32px;color:#fff;text-align:center;">
    <div style="font-size:40px;margin-bottom:8px;">🔑</div>
    <h1 style="margin:0;font-size:22px;">Parolni tiklash</h1>
  </div>
  <div style="padding:32px;text-align:center;">
    <p style="color:#64748b;font-size:14px;margin-bottom:24px;">Hisobingiz parolini tiklash uchun quyidagi tugmani bosing:</p>
    <a href="{$resetUrl}" style="display:inline-block;background:#e11d48;color:#fff;text-decoration:none;padding:14px 28px;border-radius:10px;font-weight:700;font-size:15px;margin-bottom:24px;">🔑 Parolni tiklash</a>
    <p style="color:#94a3b8;font-size:12px;">Ushbu havola 1 soat davomida amal qiladi. Agar siz parolni tiklashni so'ramagan bo'lsangiz, bu xatni e'tiborsiz qoldiring.</p>
  </div>
  <div style="background:#f8fafc;padding:14px;text-align:center;color:#94a3b8;font-size:11px;">Sertifikat Tizimi</div>
</div>
</body></html>
HTML;
        return self::send($to, '🔑 Parolni tiklash havolasi', $html);
    }


    private static function mime(string $s): string {
        return '=?UTF-8?B?' . base64_encode($s) . '?=';
    }
}
