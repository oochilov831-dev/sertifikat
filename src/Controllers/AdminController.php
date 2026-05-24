<?php
namespace App\Controllers;

use PDO;
use App\Models\UserModel;
use App\Models\SubscriptionModel;
use App\Middleware\AuthMiddleware;
use App\Services\AuditLogger;
use App\Services\EmailService;
use App\Services\PlanService;

class AdminController {
    private PDO $db;

    public function __construct() {
        $this->db = \Database::getInstance();
    }

    // GET /api/admin/stats
    public function stats(): never {
        AuthMiddleware::admin();

        $queries = [
            'total_users'        => 'SELECT COUNT(*) FROM users WHERE role = \'user\'',
            'active_subs'        => 'SELECT COUNT(*) FROM subscriptions WHERE status = \'active\'',
            'total_certs'        => 'SELECT COUNT(*) FROM certificates',
            'total_revenue'      => 'SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = \'success\'',
            'certs_today'        => 'SELECT COUNT(*) FROM certificates WHERE created_at::date = CURRENT_DATE',
            'revenue_this_month' => "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'success'
                                     AND date_trunc('month', paid_at) = date_trunc('month', NOW())",
        ];

        $stats = [];
        foreach ($queries as $key => $sql) {
            $stmt        = $this->db->prepare($sql);
            $stmt->execute();
            $stats[$key] = $stmt->fetchColumn();
        }

        // To'lovlar statistikasi
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM payments WHERE status = 'success'");
        $stmt->execute(); $stats['total_payments_success'] = $stmt->fetchColumn();

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM payments WHERE status = 'pending'");
        $stmt->execute(); $stats['total_payments_pending'] = $stmt->fetchColumn();

        // Oylik daromad grafigi (oxirgi 12 oy)
        $stmt = $this->db->prepare(
            "SELECT TO_CHAR(DATE_TRUNC('month', paid_at), 'YYYY-MM') as month,
                    SUM(amount) as revenue, COUNT(*) as count
             FROM payments WHERE status = 'success' AND paid_at >= NOW() - INTERVAL '12 months'
             GROUP BY 1 ORDER BY 1"
        );
        $stmt->execute();
        $stats['monthly_chart'] = $stmt->fetchAll();

        // Kunlik sertifikatlar (oxirgi 30 kun)
        $stmt = $this->db->prepare(
            "SELECT DATE(created_at) as day, COUNT(*) as count
             FROM certificates WHERE created_at >= NOW() - INTERVAL '30 days'
             GROUP BY 1 ORDER BY 1"
        );
        $stmt->execute();
        $stats['daily_certs'] = $stmt->fetchAll();

        // Tarif bo'yicha taqsimot
        $stmt = $this->db->prepare(
            "SELECT plan, COUNT(*) as count FROM subscriptions WHERE status = 'active' GROUP BY plan"
        );
        $stmt->execute();
        $stats['plan_distribution'] = $stmt->fetchAll();

        // Top 5 faol foydalanuvchi (sertifikat soni bo'yicha)
        $stmt = $this->db->prepare(
            "SELECT u.name, u.email, COUNT(c.id) as cert_count, SUM(c.view_count) as total_views
             FROM users u LEFT JOIN certificates c ON c.user_id = u.id
             WHERE u.role = 'user'
             GROUP BY u.id, u.name, u.email
             ORDER BY cert_count DESC LIMIT 5"
        );
        $stmt->execute();
        $stats['top_users'] = $stmt->fetchAll();

        // Konversiya: bepuldan pulligaga
        $stmt = $this->db->prepare(
            "SELECT
                COUNT(DISTINCT CASE WHEN plan != 'free' THEN user_id END)::float /
                NULLIF(COUNT(DISTINCT user_id), 0) * 100 as conversion_rate
             FROM subscriptions"
        );
        $stmt->execute();
        $stats['conversion_rate'] = round((float)($stmt->fetchColumn() ?? 0), 1);

        // Bugungi yangi foydalanuvchilar
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURRENT_DATE AND role='user'");
        $stmt->execute(); $stats['new_users_today'] = $stmt->fetchColumn();

        // Jami skan (QR tekshiruvlar)
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM cert_scans");
        $stmt->execute(); $stats['total_scans'] = $stmt->fetchColumn();

        success($stats);
    }

    // GET /api/admin/users
    public function users(): never {
        AuthMiddleware::admin();

        $page   = max(1, (int) ($_GET['page'] ?? 1));
        $limit  = 20;
        $offset = ($page - 1) * $limit;
        $search = $_GET['search'] ?? '';

        $model  = new UserModel();
        $result = $model->getAll($limit, $offset, $search);

        // Obuna ma'lumotlarini qo'shish
        foreach ($result['items'] as &$user) {
            $stmt = $this->db->prepare(
                'SELECT plan, status, cert_limit, cert_used, expires_at
                 FROM subscriptions WHERE user_id = ? AND status = \'active\' LIMIT 1'
            );
            $stmt->execute([$user['id']]);
            $user['subscription'] = $stmt->fetch() ?: null;
        }

        success(paginate($result['items'], $result['total'], $page, $limit));
    }

    // PUT /api/admin/users/:id/block
    public function blockUser(int $id): never {
        $admin = AuthMiddleware::admin();

        $stmt = $this->db->prepare(
            'UPDATE users SET is_active = NOT is_active WHERE id = ? RETURNING is_active, name'
        );
        $stmt->execute([$id]);
        $user = $stmt->fetch();

        if (!$user) error('Foydalanuvchi topilmadi', 404);

        $msg = $user['is_active'] ? 'Faollashtirildi' : 'Bloklandi';
        $this->logActivity($admin['id'], 'user.toggle_active', 'user', $id, ['is_active' => $user['is_active']]);
        success(['is_active' => $user['is_active']], "{$user['name']} {$msg}");
    }

    // PUT /api/admin/users/:id/subscription
    public function manageSubscription(int $id): never {
        $admin = AuthMiddleware::admin();

        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $plan = $data['plan'] ?? 'free';
        $months = max(1, (int)($data['months'] ?? 1));

        $currentStmt = $this->db->prepare(
            'SELECT cert_used, cert_limit FROM subscriptions WHERE user_id = ? AND status = \'active\' ORDER BY created_at DESC LIMIT 1'
        );
        $currentStmt->execute([$id]);
        $current = $currentStmt->fetch() ?: ['cert_used' => 0, 'cert_limit' => null];

        $customLimit = array_key_exists('cert_limit', $data) && $data['cert_limit'] !== ''
            ? (int)$data['cert_limit']
            : null;
        if ($customLimit !== null && $customLimit < -1) {
            error("Limit -1 yoki undan katta bo'lishi kerak", 422);
        }

        $subModel = new SubscriptionModel();
        try {
            $subModel->activate($id, $plan, $months, $customLimit, (int)$current['cert_used']);
        } catch (\InvalidArgumentException $e) {
            error($e->getMessage(), 422);
        }
        $this->logActivity($admin['id'], 'user.subscription_update', 'user', $id, [
            'plan' => $plan,
            'months' => $months,
            'cert_limit' => $customLimit,
        ]);

        success(null, 'Obuna yangilandi');
    }

    // GET /api/admin/plans
    public function plans(): never {
        AuthMiddleware::admin();
        success((new PlanService())->all());
    }

    // PUT /api/admin/plans/:plan
    public function updatePlan(string $plan): never {
        $admin = AuthMiddleware::superAdmin();
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        try {
            $updated = (new PlanService())->update($plan, $data, (int)$admin['id']);
        } catch (\InvalidArgumentException $e) {
            error($e->getMessage(), 422);
        }

        AuditLogger::log('admin.plan.update', $admin['id'], target: 'plan', meta: ['plan' => $plan, 'data' => $updated]);
        $this->logActivity($admin['id'], 'plan.update', 'plan', null, ['plan' => $plan]);

        success($updated, 'Tarif sozlamalari yangilandi');
    }

    // GET /api/admin/templates
    public function templates(): never {
        $admin = AuthMiddleware::admin();

        $stmt = $this->db->prepare(
            'SELECT * FROM templates ORDER BY created_at DESC'
        );
        $stmt->execute();
        success($stmt->fetchAll());
    }

    // POST /api/admin/templates
    public function createTemplate(): never {
        $admin = AuthMiddleware::admin();

        if (!isset($_FILES['file'])) error('Shablon rasmi yuklanishi shart', 422);

        $name   = trim($_POST['name'] ?? '');
        if (!$name) error('Shablon nomi kiritilishi shart', 422);

        $file   = $_FILES['file'];
        $ext    = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) error('Faqat rasm formatlari', 422);
        if ($file['size'] > 10 * 1024 * 1024) error('Fayl hajmi 10MB dan oshmasligi kerak', 422);

        $dir      = __DIR__ . '/../../public/uploads/templates';
        $filename = uniqid('tpl_') . '.' . $ext;
        $path     = "{$dir}/{$filename}";

        if (!is_dir($dir)) mkdir($dir, 0755, true);
        if (!move_uploaded_file($file['tmp_name'], $path)) error('Fayl saqlashda xato', 500);

        $stmt = $this->db->prepare(
            'INSERT INTO templates (name, description, file_url, preview_url, category, is_premium, width, height, fields)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             RETURNING id'
        );
        $stmt->execute([
            $name,
            $_POST['description'] ?? null,
            str_replace('public/', '', $path),
            str_replace('public/', '', $path),
            $_POST['category'] ?? 'general',
            ($_POST['is_premium'] ?? '0') === '1' ? 1 : 0,
            (int) ($_POST['width'] ?? 1280),
            (int) ($_POST['height'] ?? 960),
            $_POST['fields'] ?? '[]',
        ]);

        $templateId = (int)$stmt->fetchColumn();
        $this->logActivity($admin['id'], 'template.create', 'template', $templateId, ['name' => $name]);
        success(['id' => $templateId], 'Shablon qo\'shildi', 201);
    }

    // DELETE /api/admin/templates/:id
    public function deleteTemplate(int $id): never {
        $admin = AuthMiddleware::admin();

        $stmt = $this->db->prepare('SELECT file_url FROM templates WHERE id = ?');
        $stmt->execute([$id]);
        $tpl = $stmt->fetch();

        if (!$tpl) error('Shablon topilmadi', 404);

        $fullPath = __DIR__ . '/../../public/' . $tpl['file_url'];
        if ($tpl['file_url'] && file_exists($fullPath)) {
            unlink($fullPath);
        }

        $this->db->prepare('DELETE FROM templates WHERE id = ?')->execute([$id]);
        $this->logActivity($admin['id'], 'template.delete', 'template', $id);
        success(null, 'Shablon o\'chirildi');
    }

    // GET /api/admin/payments
    public function payments(): never {
        $admin = AuthMiddleware::admin();

        $page   = max(1, (int) ($_GET['page'] ?? 1));
        $limit  = min(200, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        $search = trim($_GET['search'] ?? '');
        $status = trim($_GET['status'] ?? '');
        $provider = trim($_GET['provider'] ?? '');

        $where = 'WHERE 1=1';
        $params = [];
        if ($search !== '') {
            $where .= ' AND (u.name ILIKE ? OR u.email ILIKE ? OR p.transaction_id ILIKE ? OR p.id::text = ?)';
            $like = "%{$search}%";
            $params = [...$params, $like, $like, $like, $search];
        }
        if ($status !== '') {
            $where .= ' AND p.status = ?';
            $params[] = $status;
        }
        if ($provider !== '') {
            $where .= ' AND p.provider = ?';
            $params[] = $provider;
        }

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM payments p JOIN users u ON u.id = p.user_id {$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->prepare(
            'SELECT p.*, u.name as user_name, u.email as user_email
             FROM payments p JOIN users u ON u.id = p.user_id
             ' . $where . ' ORDER BY p.created_at DESC LIMIT ? OFFSET ?'
        );
        $stmt->execute([...$params, $limit, $offset]);

        success(paginate($stmt->fetchAll(), $total, $page, $limit));
    }

    public function approvePayment(int $id): never {
        $admin = AuthMiddleware::admin();

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM payments WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $payment = $stmt->fetch();
            if (!$payment) {
                $this->db->rollBack();
                error('To\'lov topilmadi', 404);
            }
            if ($payment['status'] !== 'pending') {
                $this->db->rollBack();
                error('Faqat kutilayotgan to\'lov tasdiqlanadi', 400);
            }

            $meta = json_decode($payment['meta'] ?? '{}', true) ?: [];
            $months = (int)($meta['months'] ?? 1);
            $subId = (new SubscriptionModel())->activate((int)$payment['user_id'], $payment['plan'], $months);

            $tx = 'offline-' . $id . '-' . date('YmdHis');
            $this->db->prepare(
                'UPDATE payments SET status = \'success\', transaction_id = ?, subscription_id = ?, paid_at = NOW(), updated_at = NOW()
                 WHERE id = ?'
            )->execute([$tx, $subId, $id]);
            $this->logActivity($admin['id'], 'payment.approve', 'payment', $id, ['subscription_id' => $subId, 'transaction_id' => $tx]);

            $this->db->commit();
            success(null, 'To\'lov tasdiqlandi va obuna faollashtirildi');
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function rejectPayment(int $id): never {
        $admin = AuthMiddleware::admin();
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $reason = trim($data['reason'] ?? '');

        $stmt = $this->db->prepare(
            'UPDATE payments
             SET status = \'failed\',
                 meta = COALESCE(meta, \'{}\'::jsonb) || ?::jsonb,
                 updated_at = NOW()
             WHERE id = ? AND status = \'pending\'
             RETURNING id'
        );
        $stmt->execute([json_encode(['reject_reason' => $reason ?: 'Admin tomonidan rad etildi']), $id]);
        if (!$stmt->fetch()) error('Kutilayotgan to\'lov topilmadi', 404);
        $this->logActivity($admin['id'], 'payment.reject', 'payment', $id, ['reason' => $reason]);

        success(null, 'To\'lov arizasi rad etildi');
    }

    // GET /api/admin/audit-logs?page=1&action=&user_id=&search=
    public function auditLogs(): never {
        AuthMiddleware::admin();

        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = 30;
        $offset = ($page - 1) * $limit;

        $where  = [];
        $params = [];

        if (!empty($_GET['action'])) {
            $where[]  = 'a.action = ?';
            $params[] = $_GET['action'];
        }
        if (!empty($_GET['user_id'])) {
            $where[]  = 'a.user_id = ?';
            $params[] = (int)$_GET['user_id'];
        }
        if (!empty($_GET['search'])) {
            $where[]  = '(u.name ILIKE ? OR u.email ILIKE ? OR a.target ILIKE ?)';
            $like     = "%{$_GET['search']}%";
            $params[] = $like; $params[] = $like; $params[] = $like;
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id {$whereSql}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $this->db->prepare(
            "SELECT a.id, a.user_id, a.action, a.target, a.target_id, a.meta,
                    a.ip, a.user_agent, a.created_at,
                    u.name as user_name, u.email as user_email
             FROM audit_logs a
             LEFT JOIN users u ON u.id = a.user_id
             {$whereSql}
             ORDER BY a.created_at DESC LIMIT ? OFFSET ?"
        );
        $stmt->execute([...$params, $limit, $offset]);

        $items = $stmt->fetchAll();
        foreach ($items as &$it) {
            $it['action_label'] = AuditLogger::actionLabel($it['action']);
        }

        success([
            'items'       => $items,
            'total'       => $total,
            'page'        => $page,
            'total_pages' => (int)ceil($total / $limit),
            'actions'     => array_keys(AuditLogger::ACTIONS),
        ]);
    }

    // GET /api/admin/health
    public function serverHealth(): never {
        AuthMiddleware::admin();

        // Database ping
        $dbStart = microtime(true);
        $this->db->query('SELECT 1')->fetch();
        $dbLatency = round((microtime(true) - $dbStart) * 1000, 2);

        // Disk hajmi (uploads)
        $uploadsDir = __DIR__ . '/../../public/uploads';
        $uploadsSize = is_dir($uploadsDir) ? $this->folderSize($uploadsDir) : 0;

        // Disk bo'sh joy
        $freeSpace  = @disk_free_space(__DIR__);
        $totalSpace = @disk_total_space(__DIR__);

        // PHP memory
        $memUsage = memory_get_usage(true);
        $memPeak  = memory_get_peak_usage(true);

        // Oxirgi 24 soatdagi audit hodisalari
        $stmt = $this->db->prepare(
            "SELECT action, COUNT(*) as cnt
             FROM audit_logs
             WHERE created_at > NOW() - INTERVAL '24 hours'
             GROUP BY action ORDER BY cnt DESC LIMIT 10"
        );
        $stmt->execute();
        $recentActions = $stmt->fetchAll();

        // Statistika sonlari
        $counts = [];
        $tables = ['users', 'certificates', 'payments', 'subscriptions', 'cert_scans', 'audit_logs', 'rate_limits'];
        foreach ($tables as $t) {
            $counts[$t] = (int)$this->db->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
        }

        // PHP info
        $phpInfo = [
            'version'         => PHP_VERSION,
            'memory_limit'    => ini_get('memory_limit'),
            'max_upload'      => ini_get('upload_max_filesize'),
            'max_post'        => ini_get('post_max_size'),
            'extensions'      => [
                'pdo_pgsql' => extension_loaded('pdo_pgsql'),
                'gd'        => extension_loaded('gd'),
                'zip'       => extension_loaded('zip'),
                'curl'      => extension_loaded('curl'),
                'mbstring'  => extension_loaded('mbstring'),
            ],
        ];

        // Oxirgi xato (agar log fayl bo'lsa)
        $errorLog = ini_get('error_log');
        $recentErrors = [];
        if ($errorLog && is_readable($errorLog) && filesize($errorLog) < 5 * 1024 * 1024) {
            $lines = array_slice(file($errorLog, FILE_IGNORE_NEW_LINES) ?: [], -10);
            $recentErrors = array_values($lines);
        }

        success([
            'status'      => 'OK',
            'time'        => date('c'),
            'db'          => ['latency_ms' => $dbLatency, 'connection' => 'pgsql'],
            'disk'        => [
                'free_gb'      => $freeSpace ? round($freeSpace / 1073741824, 2) : 0,
                'total_gb'     => $totalSpace ? round($totalSpace / 1073741824, 2) : 0,
                'uploads_mb'   => round($uploadsSize / 1048576, 2),
            ],
            'memory'      => [
                'current_mb' => round($memUsage / 1048576, 2),
                'peak_mb'    => round($memPeak / 1048576, 2),
            ],
            'php'         => $phpInfo,
            'counts'      => $counts,
            'recent_24h'  => $recentActions,
            'recent_errors' => $recentErrors,
        ]);
    }

    // ── Template moderation ──

    // GET /api/admin/templates/pending
    public function pendingTemplates(): never {
        AuthMiddleware::admin();
        $stmt = $this->db->prepare(
            "SELECT t.*, u.name as submitter_name, u.email as submitter_email
             FROM templates t LEFT JOIN users u ON u.id = t.submitted_by
             WHERE t.status = 'pending' AND t.is_active = true
             ORDER BY t.submitted_at DESC NULLS LAST"
        );
        $stmt->execute();
        success(['items' => $stmt->fetchAll()]);
    }

    // POST /api/admin/templates/:id/approve
    public function approveTemplate(int $id): never {
        $admin = AuthMiddleware::admin();
        $stmt = $this->db->prepare(
            "UPDATE templates SET status = 'approved', reviewed_at = NOW() WHERE id = ? RETURNING id, submitted_by, name"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) error('Topilmadi', 404);

        AuditLogger::log('admin.template.approve', $admin['id'], target: 'template', targetId: $id, meta: ['name' => $row['name']]);

        // Foydalanuvchiga email
        if ($row['submitted_by']) {
            $u = $this->db->prepare('SELECT email, name FROM users WHERE id = ?');
            $u->execute([$row['submitted_by']]);
            $user = $u->fetch();
            if ($user && $user['email']) {
                EmailService::send($user['email'], "Shabloningiz tasdiqlandi: {$row['name']}",
                    "<p>Salom {$user['name']},</p><p>Sizning <strong>{$row['name']}</strong> shabloningiz admin tomonidan tasdiqlandi va katalogga qo'shildi!</p>");
            }
        }

        success(null, 'Shablon tasdiqlandi');
    }

    // POST /api/admin/templates/:id/reject  { reason }
    public function rejectTemplate(int $id): never {
        $admin = AuthMiddleware::admin();
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $reason = trim((string)($data['reason'] ?? ''));
        if ($reason === '') error('Rad etish sababi kiritilishi shart', 422);

        $stmt = $this->db->prepare(
            "UPDATE templates SET status = 'rejected', reject_reason = ?, reviewed_at = NOW()
             WHERE id = ? RETURNING id, submitted_by, name"
        );
        $stmt->execute([$reason, $id]);
        $row = $stmt->fetch();
        if (!$row) error('Topilmadi', 404);

        AuditLogger::log('admin.template.reject', $admin['id'], target: 'template', targetId: $id, meta: ['reason' => $reason]);

        if ($row['submitted_by']) {
            $u = $this->db->prepare('SELECT email, name FROM users WHERE id = ?');
            $u->execute([$row['submitted_by']]);
            $user = $u->fetch();
            if ($user && $user['email']) {
                EmailService::send($user['email'], "Shabloningiz rad etildi: {$row['name']}",
                    "<p>Salom {$user['name']},</p><p>Afsuski, sizning <strong>{$row['name']}</strong> shabloningiz rad etildi.</p><p><strong>Sabab:</strong> {$reason}</p>");
            }
        }

        success(null, 'Shablon rad etildi');
    }

    // ── Broadcast (ommaviy email) ──

    // GET /api/admin/broadcasts
    public function listBroadcasts(): never {
        AuthMiddleware::admin();
        $rows = $this->db->query(
            'SELECT * FROM broadcasts ORDER BY created_at DESC LIMIT 50'
        )->fetchAll();
        success(['items' => $rows]);
    }

    // POST /api/admin/broadcasts
    public function createBroadcast(): never {
        $admin = AuthMiddleware::admin();
        $data  = json_decode(file_get_contents('php://input'), true) ?? [];

        $subject = trim((string)($data['subject'] ?? ''));
        $body    = trim((string)($data['body_html'] ?? ''));
        $filterPlan     = $data['filter_plan']     ?? null;
        $filterVerified = isset($data['filter_verified']) ? (bool)$data['filter_verified'] : null;

        if (strlen($subject) < 2) error('Subject kiritilishi shart', 422);
        if (strlen($body) < 10)   error('Xat matni juda qisqa', 422);

        $stmt = $this->db->prepare(
            'INSERT INTO broadcasts (subject, body_html, filter_plan, filter_verified, status, created_by)
             VALUES (?, ?, ?, ?, \'draft\', ?) RETURNING id'
        );
        $stmt->execute([$subject, $body, $filterPlan, $filterVerified, $admin['id']]);
        $id = $stmt->fetchColumn();

        success(['id' => $id], 'Broadcast yaratildi (draft)', 201);
    }

    // POST /api/admin/broadcasts/:id/send
    public function sendBroadcast(int $id): never {
        $admin = AuthMiddleware::admin();

        $stmt = $this->db->prepare('SELECT * FROM broadcasts WHERE id = ?');
        $stmt->execute([$id]);
        $bc = $stmt->fetch();
        if (!$bc) error('Broadcast topilmadi', 404);
        if ($bc['status'] === 'sent') error('Allaqachon yuborilgan', 400);

        // Filtr asosida foydalanuvchilarni olish
        $where  = ['u.is_active = true', 'u.email IS NOT NULL'];
        $params = [];

        if (!empty($bc['filter_plan'])) {
            $where[]  = 's.plan = ?';
            $params[] = $bc['filter_plan'];
        }
        if ($bc['filter_verified'] !== null) {
            $where[]  = 'u.is_verified = ?';
            $params[] = $bc['filter_verified'] ? 't' : 'f';
        }

        $sql = 'SELECT DISTINCT u.email, u.name FROM users u
                LEFT JOIN subscriptions s ON s.user_id = u.id AND s.status = \'active\'
                WHERE ' . implode(' AND ', $where);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $users = $stmt->fetchAll();

        $sent = 0; $failed = 0;
        foreach ($users as $u) {
            $personalized = str_replace(['{{name}}', '{{email}}'],
                                        [$u['name'], $u['email']],
                                        $bc['body_html']);
            $ok = EmailService::send($u['email'], $bc['subject'], $personalized);
            if ($ok) $sent++; else $failed++;
        }

        $this->db->prepare(
            'UPDATE broadcasts SET status = \'sent\', sent_count = ?, failed_count = ?, sent_at = NOW() WHERE id = ?'
        )->execute([$sent, $failed, $id]);

        AuditLogger::log('admin.broadcast.send', $admin['id'], target: 'broadcast', targetId: $id, meta: ['sent' => $sent, 'failed' => $failed]);

        success(['sent' => $sent, 'failed' => $failed, 'total' => count($users)], "Yuborildi: {$sent} / " . count($users));
    }

    // DELETE /api/admin/broadcasts/:id
    public function deleteBroadcast(int $id): never {
        AuthMiddleware::admin();
        $stmt = $this->db->prepare('DELETE FROM broadcasts WHERE id = ? RETURNING id');
        $stmt->execute([$id]);
        if (!$stmt->fetch()) error('Topilmadi', 404);
        success(null, 'O\'chirildi');
    }

    // ── Promokodlar ──

    // GET /api/admin/promo-codes
    public function listPromoCodes(): never {
        AuthMiddleware::admin();
        $stmt = $this->db->query(
            'SELECT * FROM discount_codes ORDER BY created_at DESC LIMIT 100'
        );
        success(['items' => $stmt->fetchAll()]);
    }

    // POST /api/admin/promo-codes
    public function createPromoCode(): never {
        $admin = AuthMiddleware::admin();
        $data  = json_decode(file_get_contents('php://input'), true) ?? [];

        $code = strtoupper(trim((string)($data['code'] ?? '')));
        $type = in_array($data['type'] ?? 'percent', ['percent', 'fixed'], true) ? $data['type'] : 'percent';
        $discount = (int)($data['discount'] ?? 0);

        if (!preg_match('/^[A-Z0-9_-]{3,50}$/', $code)) error('Kod 3-50 belgi (lotin harfi/raqam) bo\'lishi kerak', 422);
        if ($discount < 1) error('Chegirma 1 dan kichik bo\'lmasligi kerak', 422);
        if ($type === 'percent' && $discount > 100) error('Foiz 100 dan oshmasligi kerak', 422);

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO discount_codes (code, discount, type, max_uses, plan_filter, valid_from, valid_to, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, true) RETURNING id'
            );
            $stmt->execute([
                $code,
                $discount,
                $type,
                !empty($data['max_uses']) ? (int)$data['max_uses'] : null,
                !empty($data['plan_filter']) ? $data['plan_filter'] : null,
                !empty($data['valid_from']) ? $data['valid_from'] : null,
                !empty($data['valid_to'])   ? $data['valid_to']   : null,
            ]);
            $id = $stmt->fetchColumn();
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'unique')) error('Bu kod allaqachon mavjud', 409);
            throw $e;
        }

        AuditLogger::log('admin.promo.create', $admin['id'], target: 'promo_code', targetId: (int)$id, meta: ['code' => $code, 'discount' => $discount]);
        success(['id' => $id], 'Promokod yaratildi', 201);
    }

    // DELETE /api/admin/promo-codes/:id
    public function deletePromoCode(int $id): never {
        $admin = AuthMiddleware::admin();
        $stmt  = $this->db->prepare('UPDATE discount_codes SET is_active = false WHERE id = ? RETURNING id');
        $stmt->execute([$id]);
        if (!$stmt->fetch()) error('Promokod topilmadi', 404);
        $this->logActivity($admin['id'], 'promo.deactivate', 'promo_code', $id);
        success(null, 'Promokod o\'chirildi');
    }

    private function folderSize(string $dir): int {
        $size = 0;
        try {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile()) $size += $f->getSize();
            }
        } catch (\Throwable) { /* skip */ }
        return $size;
    }

    private function logActivity(int $adminId, string $action, ?string $entityType = null, ?int $entityId = null, array $meta = []): void {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO admin_activity_logs (admin_id, action, entity_type, entity_id, meta)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$adminId, $action, $entityType, $entityId, json_encode($meta)]);
        } catch (\Throwable $e) {
            error_log('[AdminActivity] ' . $e->getMessage());
        }
    }
}
