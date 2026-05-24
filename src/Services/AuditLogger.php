<?php
namespace App\Services;

/**
 * Audit log — muhim foydalanuvchi va admin amallarini yozadi.
 *
 * Foydalanish:
 *   AuditLogger::log('login', $userId);
 *   AuditLogger::log('cert.create', $userId, target: 'certificate', targetId: $certId);
 *   AuditLogger::log('admin.user.block', $adminId, target: 'user', targetId: $blockedUserId, meta: ['reason' => $r]);
 */
final class AuditLogger {

    public const ACTIONS = [
        // Auth
        'login'                => 'Tizimga kirish',
        'login.failed'         => 'Muvaffaqiyatsiz kirish',
        'logout'               => 'Tizimdan chiqish',
        'register'             => 'Ro\'yxatdan o\'tish',
        'password.change'      => 'Parol o\'zgartirish',
        'password.reset'       => 'Parolni tiklash',
        'email.verify'         => 'Email tasdiqlash',
        '2fa.enable'           => '2FA yoqish',
        '2fa.disable'          => '2FA o\'chirish',
        'api_key.create'       => 'API kalit yaratish',
        'api_key.delete'       => 'API kalit o\'chirish',
        'account.delete'       => 'Hisobni o\'chirish',
        'profile.update'       => 'Profil yangilash',

        // Certificates
        'cert.create'          => 'Sertifikat yaratish',
        'cert.bulk'            => 'Ommaviy yaratish',
        'cert.delete'          => 'Sertifikat o\'chirish',
        'cert.revoke'          => 'Sertifikat bekor qilish',
        'cert.restore'         => 'Sertifikatni tiklash',
        'constructor.layout.save' => 'Konstruktor ishini saqlash',

        // Payments
        'payment.initiate'     => 'To\'lov boshlash',
        'payment.success'      => 'To\'lov muvaffaqiyatli',
        'payment.failed'       => 'To\'lov muvaffaqiyatsiz',

        // Admin actions
        'admin.user.block'     => 'Admin: foydalanuvchini bloklash',
        'admin.user.unblock'   => 'Admin: foydalanuvchini ochish',
        'admin.subscription'   => 'Admin: obunani o\'zgartirish',
        'admin.plan.update'    => 'Admin: tarifni yangilash',
        'admin.template.create'=> 'Admin: shablon yaratish',
        'admin.template.delete'=> 'Admin: shablon o\'chirish',
        'admin.template.approve' => 'Admin: shablonni tasdiqlash',
        'admin.template.reject'  => 'Admin: shablonni rad etish',
        'admin.payment.approve'  => 'Admin: to\'lovni tasdiqlash',
        'admin.payment.reject'   => 'Admin: to\'lovni rad etish',
        'admin.broadcast.send'   => 'Admin: ommaviy email',
        'admin.promo.create'     => 'Admin: promokod yaratish',
    ];

    public static function log(
        string $action,
        ?int $userId = null,
        ?string $target = null,
        ?int $targetId = null,
        ?array $meta = null,
    ): void {
        try {
            $db = \Database::getInstance();
            $stmt = $db->prepare(
                'INSERT INTO audit_logs (user_id, action, target, target_id, meta, ip, user_agent)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $userId,
                $action,
                $target,
                $targetId,
                $meta ? json_encode($meta) : null,
                self::ip(),
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            ]);
        } catch (\Throwable $e) {
            // Audit log xatolari asosiy oqimni to'xtatmasligi kerak
            error_log('[AuditLogger] ' . $e->getMessage());
        }
    }

    public static function ip(): string {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public static function actionLabel(string $action): string {
        return self::ACTIONS[$action] ?? $action;
    }
}
