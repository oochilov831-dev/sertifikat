<?php
namespace App\Middleware;

use App\Helpers\JWT;

class AuthMiddleware {
    public static function handle(): array {
        $token = self::extractToken();
        if (!$token) error('Avtorizatsiya talab etiladi', 401);

        $payload = JWT::decode($token);
        $db      = \Database::getInstance();
        
        if ($payload) {
            if (!empty($payload['sid'])) {
                $sessStmt = $db->prepare('SELECT is_revoked FROM user_sessions WHERE sid = ?');
                $sessStmt->execute([$payload['sid']]);
                $session = $sessStmt->fetch();
                if (!$session || $session['is_revoked']) {
                    error('Sessiya bekor qilingan yoki topilmadi', 401);
                }
                
                // Update last_activity of the session
                $db->prepare('UPDATE user_sessions SET last_activity = NOW() WHERE sid = ?')->execute([$payload['sid']]);
            }
            $stmt = $db->prepare('SELECT id, uuid, name, email, phone, role, is_active, is_verified FROM users WHERE id = ?');
            $stmt->execute([$payload['sub']]);
            $user = $stmt->fetch();
        } else {
            // Agar JWT decode bo'lmasa, API Key sifatida tekshiramiz
            $stmt = $db->prepare('SELECT id, uuid, name, email, phone, role, is_active FROM users WHERE api_key = ?');
            $stmt->execute([$token]);
            $user = $stmt->fetch();
        }

        if (!$user || !$user['is_active']) error('Foydalanuvchi topilmadi yoki bloklangan', 401);

        return $user;
    }

    public static function admin(): array {
        $user = self::handle();
        if (!in_array($user['role'], ['admin', 'super_admin'], true)) error('Ruxsat yo\'q', 403);
        return $user;
    }

    public static function superAdmin(): array {
        $user = self::handle();
        if (!in_array($user['role'], ['admin', 'super_admin'], true)) error('Super admin ruxsati talab etiladi', 403);
        return $user;
    }

    private static function extractToken(): ?string {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.+)/', $header, $m)) return $m[1];

        return $_COOKIE['token'] ?? null;
    }
}
