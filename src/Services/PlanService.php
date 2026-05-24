<?php
namespace App\Services;

use PDO;

class PlanService {
    private PDO $db;

    public function __construct() {
        $this->db = \Database::getInstance();
    }

    public function all(): array {
        $this->ensureTable();

        $stmt = $this->db->prepare(
            'SELECT plan_key, name, price, cert_limit, watermark, custom_template, is_active, sort_order
             FROM plan_settings
             ORDER BY sort_order ASC, plan_key ASC'
        );
        $stmt->execute();

        $plans = [];
        foreach ($stmt->fetchAll() as $row) {
            $plans[$row['plan_key']] = $this->normalizeRow($row);
        }

        return $plans;
    }

    public function find(string $planKey): ?array {
        $plans = $this->all();
        return $plans[$planKey] ?? null;
    }

    public function update(string $planKey, array $data, int $adminId): array {
        $current = $this->find($planKey);
        if (!$current) {
            throw new \InvalidArgumentException("Noto'g'ri tarif: {$planKey}");
        }

        $name = trim((string)($data['name'] ?? $current['name']));
        $price = (int)($data['price'] ?? $current['price']);
        $limit = (int)($data['limit'] ?? $current['limit']);
        $watermark = array_key_exists('watermark', $data) ? (bool)$data['watermark'] : (bool)$current['watermark'];
        $customTemplate = array_key_exists('custom_template', $data) ? (bool)$data['custom_template'] : (bool)$current['custom_template'];
        $isActive = array_key_exists('is_active', $data) ? (bool)$data['is_active'] : (bool)$current['is_active'];
        $sortOrder = array_key_exists('sort_order', $data) ? (int)$data['sort_order'] : (int)($current['sort_order'] ?? 0);

        if ($name === '') throw new \InvalidArgumentException('Tarif nomi kiritilishi shart');
        if ($price < 0) throw new \InvalidArgumentException("Narx manfiy bo'lishi mumkin emas");
        if ($limit < -1) throw new \InvalidArgumentException("Limit -1 yoki undan katta bo'lishi kerak");
        if ($planKey === 'free') {
            $price = 0;
            $isActive = true;
        }

        $stmt = $this->db->prepare(
            'UPDATE plan_settings
             SET name = ?, price = ?, cert_limit = ?, watermark = ?, custom_template = ?,
                 is_active = ?, sort_order = ?, updated_by = ?, updated_at = NOW()
             WHERE plan_key = ?
             RETURNING plan_key, name, price, cert_limit, watermark, custom_template, is_active, sort_order'
        );
        $stmt->execute([
            $name,
            $price,
            $limit,
            $watermark ? 1 : 0,
            $customTemplate ? 1 : 0,
            $isActive ? 1 : 0,
            $sortOrder,
            $adminId,
            $planKey
        ]);

        return $this->normalizeRow($stmt->fetch());
    }

    private function ensureTable(): void {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS plan_settings (
                plan_key VARCHAR(20) PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                price DECIMAL(12,2) NOT NULL DEFAULT 0,
                cert_limit INTEGER NOT NULL DEFAULT 0,
                watermark BOOLEAN NOT NULL DEFAULT false,
                custom_template BOOLEAN NOT NULL DEFAULT false,
                is_active BOOLEAN NOT NULL DEFAULT true,
                sort_order INTEGER NOT NULL DEFAULT 0,
                updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW()
            )"
        );

        $app = require __DIR__ . '/../../config/app.php';
        $sort = 1;
        $stmt = $this->db->prepare(
            'INSERT INTO plan_settings (plan_key, name, price, cert_limit, watermark, custom_template, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (plan_key) DO NOTHING'
        );

        foreach (($app['plans'] ?? []) as $key => $plan) {
            $stmt->execute([
                $key,
                $plan['name'],
                (int)$plan['price'],
                (int)$plan['limit'],
                $plan['watermark'] ? 1 : 0,
                $plan['custom_template'] ? 1 : 0,
                $sort++,
            ]);
        }
    }

    private function normalizeRow(array $row): array {
        return [
            'name'            => $row['name'],
            'price'           => (int)$row['price'],
            'limit'           => (int)$row['cert_limit'],
            'watermark'       => $this->boolValue($row['watermark']),
            'custom_template' => $this->boolValue($row['custom_template']),
            'is_active'       => $this->boolValue($row['is_active']),
            'sort_order'      => (int)($row['sort_order'] ?? 0),
        ];
    }

    private function boolValue(mixed $value): bool {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }
}
