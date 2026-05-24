<?php
namespace App\Models;

use PDO;
use App\Services\PlanService;

class UserModel {
    private PDO $db;

    public function __construct() {
        $this->db = \Database::getInstance();
    }

    public function findByEmail(string $email): ?array {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        return $stmt->fetch() ?: null;
    }

    public function findByPhone(string $phone): ?array {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE phone = ?');
        $stmt->execute([$phone]);
        return $stmt->fetch() ?: null;
    }

    public function findById(int $id): ?array {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function create(array $data): int {
        $stmt = $this->db->prepare(
            'INSERT INTO users (name, email, phone, password, role)
             VALUES (:name, :email, :phone, :password, :role)
             RETURNING id'
        );
        $stmt->execute([
            ':name'     => $data['name'],
            ':email'    => $data['email'] ?? null,
            ':phone'    => $data['phone'] ?? null,
            ':password' => password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]),
            ':role'     => $data['role'] ?? 'user',
        ]);

        $userId = $stmt->fetchColumn();

        // Bepul obuna yaratish
        $freeLimit = (new PlanService())->find('free')['limit'] ?? 5;
        $this->db->prepare(
            'INSERT INTO subscriptions (user_id, plan, status, cert_limit, expires_at)
             VALUES (?, \'free\', \'active\', ?, NOW() + INTERVAL \'100 year\')'
        )->execute([$userId, $freeLimit]);

        return $userId;
    }

    public function update(int $id, array $data): bool {
        $fields = [];
        $params = [];

        foreach (['name', 'email', 'phone', 'company', 'avatar'] as $field) {
            if (isset($data[$field])) {
                $fields[] = "{$field} = ?";
                $params[]  = $data[$field];
            }
        }

        if (empty($fields)) return false;

        $fields[]  = 'updated_at = NOW()';
        $params[]  = $id;

        $sql  = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function changePassword(int $id, string $password): bool {
        $stmt = $this->db->prepare(
            'UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?'
        );
        return $stmt->execute([password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), $id]);
    }

    public function verify(int $id): bool {
        $stmt = $this->db->prepare('UPDATE users SET is_verified = true WHERE id = ?');
        return $stmt->execute([$id]);
    }

    public function getAll(int $limit = 20, int $offset = 0, string $search = ''): array {
        $where  = '';
        $params = [];

        if ($search) {
            $where    = 'WHERE name ILIKE ? OR email ILIKE ? OR phone ILIKE ?';
            $like     = "%{$search}%";
            $params   = [$like, $like, $like];
        }

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM users {$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->prepare(
            "SELECT id, uuid, name, email, phone, role, is_active, is_verified, company, created_at
             FROM users {$where} ORDER BY created_at DESC LIMIT ? OFFSET ?"
        );
        $stmt->execute([...$params, $limit, $offset]);

        return ['total' => $total, 'items' => $stmt->fetchAll()];
    }
}
