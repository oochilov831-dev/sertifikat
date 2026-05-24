<?php
namespace App\Models;

use PDO;
use App\Services\EmailService;
use App\Services\PlanService;

class SubscriptionModel {
    private PDO $db;

    public function __construct() {
        $this->db = \Database::getInstance();
    }

    public function getActive(int $userId): ?array {
        $stmt = $this->db->prepare(
            'SELECT * FROM subscriptions WHERE user_id = ? AND status = \'active\'
             ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: null;
    }

    public function canCreate(int $userId): bool {
        $sub = $this->getActive($userId);
        if (!$sub) return false;

        // Pro plan - cheksiz
        if ((int)$sub['cert_limit'] === -1) return true;

        return (int)$sub['cert_used'] < (int)$sub['cert_limit'];
    }

    public function incrementUsed(int $userId): void {
        $this->tryIncrementUsed($userId);
    }

    public function tryIncrementUsed(int $userId): bool {
        $sub = $this->getActive($userId);
        if (!$sub) return false;

        // Unlimited plan
        if ((int)$sub['cert_limit'] === -1) {
            $this->db->prepare(
                'UPDATE subscriptions SET cert_used = cert_used + 1 WHERE id = ?'
            )->execute([$sub['id']]);
            return true;
        }

        // Standard limited plans - atomic increment checking
        $stmt = $this->db->prepare(
            'UPDATE subscriptions SET cert_used = cert_used + 1 WHERE id = ? AND cert_used < cert_limit'
        );
        $stmt->execute([$sub['id']]);
        return $stmt->rowCount() > 0;
    }

    public function decrementUsed(int $userId): void {
        $this->db->prepare(
            'UPDATE subscriptions SET cert_used = GREATEST(0, cert_used - 1) WHERE user_id = ? AND status = \'active\''
        )->execute([$userId]);
    }

    public function activate(int $userId, string $plan, int $months = 1, ?int $customLimit = null, int $certUsed = 0): int {
        $plans = (new PlanService())->all();

        if (!isset($plans[$plan])) throw new \InvalidArgumentException("Noto'g'ri tarif: {$plan}");

        $limit = $customLimit ?? $plans[$plan]['limit'];
        if ($limit < -1) throw new \InvalidArgumentException("Limit -1 yoki undan katta bo'lishi kerak");
        $certUsed = max(0, $certUsed);

        // Oldingi obunani bekor qilish
        $this->db->prepare(
            'UPDATE subscriptions SET status = \'cancelled\' WHERE user_id = ? AND status = \'active\''
        )->execute([$userId]);

        $stmt = $this->db->prepare(
            'INSERT INTO subscriptions (user_id, plan, status, cert_limit, cert_used, started_at, expires_at)
             VALUES (?, ?, \'active\', ?, ?, NOW(), NOW() + INTERVAL \'1 month\' * ?)
             RETURNING id'
        );
        $stmt->execute([$userId, $plan, $limit, $certUsed, $months]);
        return (int) $stmt->fetchColumn();
    }

    public function expireOld(): int {
        $stmt = $this->db->prepare(
            'UPDATE subscriptions SET status = \'expired\'
             WHERE status = \'active\' AND expires_at IS NOT NULL AND expires_at < NOW()
             RETURNING id'
        );
        $stmt->execute();
        return $stmt->rowCount();
    }

    // Muddati tugayotgan obunalarni topib, email yuborish (3 kun qolganda)
    public function notifyExpiring(): int {
        $stmt = $this->db->prepare(
            "SELECT s.*, u.name, u.email
             FROM subscriptions s
             JOIN users u ON u.id = s.user_id
             WHERE s.status = 'active'
               AND s.plan != 'free'
               AND s.expires_at IS NOT NULL
               AND s.expires_at BETWEEN NOW() + INTERVAL '2 days' AND NOW() + INTERVAL '4 days'
               AND s.notified_expiry = false"
        );
        $stmt->execute();
        $rows  = $stmt->fetchAll();
        $count = 0;

        foreach ($rows as $row) {
            if (empty($row['email'])) continue;
            $sent = EmailService::sendSubscriptionExpiry(
                $row['email'],
                $row['name'],
                $row['plan'],
                $row['expires_at']
            );
            if ($sent) {
                $this->db->prepare(
                    'UPDATE subscriptions SET notified_expiry = true WHERE id = ?'
                )->execute([$row['id']]);
                $count++;
            }
        }
        return $count;
    }
}
