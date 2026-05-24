<?php
namespace App\Middleware;

use PDO;

class RateLimiter {
    private PDO $db;

    public function __construct() {
        $this->db = \Database::getInstance();
    }

    /**
     * So'rovlar sonini tekshirish.
     * @param string $key    — noyob kalit (masalan: "login:127.0.0.1")
     * @param int    $max    — ruxsat etilgan maksimal so'rovlar soni
     * @param int    $window — vaqt oynasi (soniyalar)
     */
    public function check(string $key, int $max, int $window): void {
        $this->cleanup();

        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM rate_limits WHERE key = ? AND created_at > NOW() - INTERVAL \'' . $window . ' seconds\''
        );
        $stmt->execute([$key]);
        $count = (int) $stmt->fetchColumn();

        if ($count >= $max) {
            $retryAfter = $window - (time() % $window);
            header('Retry-After: ' . $retryAfter);
            error('Juda ko\'p so\'rov. ' . $retryAfter . ' soniyadan keyin qayta urinib ko\'ring.', 429);
        }

        $this->db->prepare(
            'INSERT INTO rate_limits (key, created_at) VALUES (?, NOW())'
        )->execute([$key]);
    }

    private function cleanup(): void {
        // Har 100 ta so'rovda bir marta eski yozuvlarni tozalash
        if (rand(1, 100) === 1) {
            $this->db->exec("DELETE FROM rate_limits WHERE created_at < NOW() - INTERVAL '1 hour'");
        }
    }

    public static function ip(): string {
        return $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['HTTP_X_REAL_IP']
            ?? $_SERVER['REMOTE_ADDR']
            ?? 'unknown';
    }
}
