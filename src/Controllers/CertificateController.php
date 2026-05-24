<?php
namespace App\Controllers;

use PDO;
use App\Services\CertificateService;
use App\Models\SubscriptionModel;
use App\Middleware\AuthMiddleware;
use App\Services\AuditLogger;
use App\Helpers\DocType;

class CertificateController {
    private PDO $db;
    private CertificateService $service;
    private SubscriptionModel $subscriptions;

    public function __construct() {
        $this->db            = \Database::getInstance();
        $this->service       = new CertificateService();
        $this->subscriptions = new SubscriptionModel();
    }

    // POST /api/certificates
    public function create(): never {
        $user = AuthMiddleware::handle();

        if (!$this->subscriptions->canCreate($user['id'])) {
            error('Sertifikat limiti tugadi. Obunangizni yangilang.', 403);
        }

        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        if (empty($data['recipient_name'])) error('Oluvchi ismi kiritilishi shart', 422);

        $cert = $this->service->generate($user['id'], $data);

        AuditLogger::log('cert.create', $user['id'], target: 'certificate', targetId: $cert['id'] ?? null, meta: ['cert_id' => $cert['cert_id'] ?? null]);

        success($cert, 'Sertifikat muvaffaqiyatli yaratildi', 201);
    }

    // GET /api/certificates
    public function index(): never {
        $user   = AuthMiddleware::handle();
        $page   = max(1, (int) ($_GET['page'] ?? 1));
        $limit  = 12;
        $offset = ($page - 1) * $limit;
        $search = $_GET['search'] ?? '';

        $where  = 'WHERE c.user_id = ?';
        $params = [$user['id']];

        if ($search) {
            $where    .= ' AND (c.recipient_name ILIKE ? OR c.course_name ILIKE ? OR c.cert_id ILIKE ?)';
            $like      = "%{$search}%";
            $params    = [...$params, $like, $like, $like];
        }

        if (!empty($_GET['doc_type'])) {
            $docType   = DocType::normalize($_GET['doc_type']);
            $where    .= ' AND c.doc_type = ?';
            $params[]  = $docType;
        }

        $countStmt = $this->db->prepare(
            "SELECT COUNT(*) FROM certificates c {$where}"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->prepare(
            "SELECT c.id, c.uuid, c.cert_id, c.recipient_name, c.course_name,
                    c.issued_date, c.is_valid, c.file_pdf, c.file_png, c.qr_code,
                    c.watermark, c.view_count, c.created_at, c.doc_type, c.orientation,
                    t.name as template_name
             FROM certificates c
             LEFT JOIN templates t ON t.id = c.template_id
             {$where} ORDER BY c.created_at DESC LIMIT ? OFFSET ?"
        );
        $stmt->execute([...$params, $limit, $offset]);

        success(paginate($stmt->fetchAll(), $total, $page, $limit));
    }

    // GET /api/certificates/:id
    public function show(int $id): never {
        $user = AuthMiddleware::handle();

        $stmt = $this->db->prepare(
            'SELECT c.*, t.name as template_name
             FROM certificates c
             LEFT JOIN templates t ON t.id = c.template_id
             WHERE c.id = ? AND c.user_id = ?'
        );
        $stmt->execute([$id, $user['id']]);
        $cert = $stmt->fetch();

        if (!$cert) error('Sertifikat topilmadi', 404);

        success($cert);
    }

    // DELETE /api/certificates/:id
    public function delete(int $id): never {
        $user = AuthMiddleware::handle();

        $stmt = $this->db->prepare(
            'SELECT id, file_pdf, file_png, qr_code FROM certificates WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$id, $user['id']]);
        $cert = $stmt->fetch();

        if (!$cert) error('Sertifikat topilmadi', 404);

        // Fayllarni o'chirish
        $publicDir = __DIR__ . '/../../public/';
        foreach (['file_pdf', 'file_png', 'qr_code'] as $field) {
            if ($cert[$field] && file_exists($publicDir . $cert[$field])) {
                unlink($publicDir . $cert[$field]);
            }
        }

        $this->db->prepare('DELETE FROM certificates WHERE id = ?')->execute([$id]);

        success(null, 'Sertifikat o\'chirildi');
    }

    // POST /api/certificates/bulk
    public function bulk(): never {
        $user = AuthMiddleware::handle();
        $sub  = $this->subscriptions->getActive($user['id']);

        if (!$sub || $sub['plan'] === 'free') {
            error('Ommaviy generatsiya faqat Standart va Pro tarifda mavjud', 403);
        }

        if (!isset($_FILES['file'])) error('CSV fayl yuklanishi shart', 422);

        $file  = $_FILES['file'];
        if ($file['error'] !== UPLOAD_ERR_OK) error('Fayl yuklashda xato', 500);

        // MIME-type va extension tekshiruvi
        $mime = null;
        if (function_exists('mime_content_type')) {
            $mime = mime_content_type($file['tmp_name']);
        }
        $allowedMimes = ['text/csv', 'text/plain', 'application/vnd.ms-excel', 'text/x-comma-separated-values'];
        if ($mime && !in_array($mime, $allowedMimes, true)) {
            error('Faqat CSV format qabul qilinadi', 422);
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt'])) error('Faqat CSV format qabul qilinadi', 422);

        $rows     = [];
        $handle   = fopen($file['tmp_name'], 'r');
        if (!$handle) error('CSV faylni o\'qib bo\'lmadi', 422);
        $headersRaw = fgetcsv($handle);
        if (!$headersRaw) {
            fclose($handle);
            error('CSV header topilmadi', 422);
        }
        $headers  = array_map('trim', $headersRaw);
        if (!in_array('recipient_name', $headers, true)) {
            fclose($handle);
            error('CSV faylda recipient_name ustuni bo\'lishi shart', 422);
        }

        $rowCount = 0;
        while (($row = fgetcsv($handle)) !== false) {
            $rowCount++;
            if ($rowCount > 1000) {
                fclose($handle);
                error('Ommaviy yuklashda qatorlar soni 1000 tadan oshmasligi kerak', 422);
            }

            if (count($row) !== count($headers)) {
                $rows[] = ['recipient_name' => '', '_error' => 'Ustunlar soni header bilan mos emas'];
                continue;
            }

            // CSV Injection'ni oldini olish va ma'lumotlarni tozalash
            $cleanedRow = array_map(function($val) {
                $val = trim($val);
                if ($val !== '' && in_array($val[0], ['=', '+', '-', '@'], true)) {
                    $val = "'" . $val;
                }
                return htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
            }, $row);

            $item = array_combine($headers, $cleanedRow);
            if (!empty($item['_error'])) unset($item['_error']);
            $rows[] = $item;
        }
        fclose($handle);

        if (empty($rows)) error('CSV faylda ma\'lumot topilmadi', 422);

        // Limit tekshirish
        if ($sub['cert_limit'] !== -1) {
            $remaining = $sub['cert_limit'] - $sub['cert_used'];
            if (count($rows) > $remaining) {
                error("Limitingiz yetarli emas. Qolgan: {$remaining}, So'ralgan: " . count($rows), 403);
            }
        }

        $baseData = [
            'template_id' => isset($_POST['template_id']) && $_POST['template_id'] !== '' ? (int)$_POST['template_id'] : null,
            'course_name' => trim($_POST['course_name'] ?? ''),
            'issued_date' => trim($_POST['issued_date'] ?? ''),
            'expiry_date' => trim($_POST['expiry_date'] ?? ''),
            'doc_type'    => DocType::normalize($_POST['doc_type'] ?? 'certificate'),
        ];
        $baseData = array_filter($baseData, fn($v) => $v !== null && $v !== '');

        // Faylni saqlash
        $dir = __DIR__ . '/../../public/uploads/bulk';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $filename = 'bulk_u' . $user['id'] . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.csv';
        $destPath = "{$dir}/{$filename}";

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            error('Faylni saqlashda xato yuz berdi', 500);
        }

        // Job yaratish
        $stmt = $this->db->prepare(
            'INSERT INTO bulk_jobs (user_id, template_id, filename, total, processed, failed, status, error_log)
             VALUES (?, ?, ?, ?, 0, 0, \'pending\', ?) RETURNING id'
        );
        $stmt->execute([
            $user['id'],
            $baseData['template_id'] ?? null,
            $filename,
            count($rows),
            json_encode($baseData)
        ]);
        $jobId = (int)$stmt->fetchColumn();

        AuditLogger::log('cert.bulk', $user['id'], target: 'bulk_job', targetId: $jobId, meta: ['total' => count($rows)]);

        success([
            'job_id' => $jobId,
            'total'  => count($rows),
            'status' => 'pending',
        ], "Fayl muvaffaqiyatli yuklandi, ommaviy yaratish jarayoni boshlandi.");
    }

    // GET /api/certificates/bulk-jobs/:id
    public function bulkJob(int $id): never {
        $user = AuthMiddleware::handle();

        $stmt = $this->db->prepare(
            'SELECT id, total, processed, failed, status, error_log, created_at, updated_at
             FROM bulk_jobs WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$id, $user['id']]);
        $job = $stmt->fetch();

        if (!$job) error('Bulk ish topilmadi', 404);

        $payload = [];
        if (!empty($job['error_log'])) {
            $decoded = json_decode((string)$job['error_log'], true);
            if (is_array($decoded)) {
                $payload = $decoded;
            } else {
                $payload = ['errors' => preg_split('/\r?\n/', (string)$job['error_log'], -1, PREG_SPLIT_NO_EMPTY)];
            }
        }

        success([
            'id'           => (int)$job['id'],
            'total'        => (int)$job['total'],
            'processed'    => (int)$job['processed'],
            'failed'       => (int)$job['failed'],
            'status'       => $job['status'],
            'created_at'   => $job['created_at'],
            'updated_at'   => $job['updated_at'],
            'progress_pct' => (int)($job['total'] > 0 ? floor((($job['processed'] + $job['failed']) / $job['total']) * 100) : 0),
            'results'      => $payload['certificates'] ?? [],
            'errors'       => $payload['errors'] ?? [],
        ]);
    }

    // POST /api/constructor/layout
    public function saveConstructorLayout(): never {
        $user = AuthMiddleware::handle();
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        if (!isset($data['layout']) || !is_array($data['layout'])) {
            error("Layout ma'lumoti noto'g'ri", 422);
        }

        $name = trim((string)($data['name'] ?? 'Asosiy layout'));
        if ($name === '') {
            $name = 'Asosiy layout';
        }

        $stmt = $this->db->prepare(
            "INSERT INTO constructor_layouts (user_id, name, layout, updated_at)
             VALUES (?, ?, ?::jsonb, NOW())
             ON CONFLICT (user_id)
             DO UPDATE SET name = EXCLUDED.name, layout = EXCLUDED.layout, updated_at = NOW()
             RETURNING id, name, layout, created_at, updated_at"
        );
        $stmt->execute([
            $user['id'],
            $name,
            json_encode($data['layout'], JSON_UNESCAPED_UNICODE),
        ]);
        $row = $stmt->fetch();
        $row['layout'] = is_string($row['layout']) ? json_decode($row['layout'], true) : $row['layout'];

        AuditLogger::log('constructor.layout.save', $user['id'], target: 'constructor_layout', targetId: (int)$row['id']);

        success($row, 'Konstruktor ishi profilga saqlandi');
    }

    // GET /api/constructor/layout
    public function loadConstructorLayout(): never {
        $user = AuthMiddleware::handle();

        $stmt = $this->db->prepare(
            'SELECT id, name, layout, created_at, updated_at
             FROM constructor_layouts
             WHERE user_id = ?
             ORDER BY updated_at DESC
             LIMIT 1'
        );
        $stmt->execute([$user['id']]);
        $row = $stmt->fetch();

        if (!$row) error('Profilingizda saqlangan konstruktor ishi topilmadi', 404);

        $row['layout'] = is_string($row['layout']) ? json_decode($row['layout'], true) : $row['layout'];

        success($row);
    }

    // GET /verify/:certId (public)
    public function verify(string $certId): never {
        $meta = ($_GET['no_log'] ?? '') === '1'
            ? ['no_log' => true]
            : [
                'ip'         => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'referer'    => $_SERVER['HTTP_REFERER'] ?? null,
            ];
        $cert = $this->service->verify($certId, $meta);

        if (!$cert) error('Sertifikat topilmadi', 404);

        success($cert, 'Sertifikat haqiqiy');
    }

    // PUT /api/certificates/:id/revoke
    public function revoke(int $id): never {
        $user   = AuthMiddleware::handle();
        $data   = json_decode(file_get_contents('php://input'), true) ?? [];
        $reason = trim($data['reason'] ?? '');

        $ok = $this->service->revoke($id, $user['id'], $reason);
        if (!$ok) error('Sertifikat topilmadi', 404);

        AuditLogger::log('cert.revoke', $user['id'], target: 'certificate', targetId: $id, meta: ['reason' => $reason]);
        success(null, 'Sertifikat bekor qilindi');
    }

    // PUT /api/certificates/:id/restore
    public function restore(int $id): never {
        $user = AuthMiddleware::handle();
        $ok   = $this->service->restore($id, $user['id']);
        if (!$ok) error('Sertifikat topilmadi', 404);

        AuditLogger::log('cert.restore', $user['id'], target: 'certificate', targetId: $id);
        success(null, 'Sertifikat tiklandi');
    }

    // POST /api/certificates/bulk-download
    // body: { ids: [1, 2, 3], format: 'pdf' | 'png' }
    public function bulkDownload(): never {
        $user   = AuthMiddleware::handle();
        $data   = json_decode(file_get_contents('php://input'), true) ?? [];
        $ids    = array_filter(array_map('intval', $data['ids'] ?? []));
        $format = in_array($data['format'] ?? 'pdf', ['pdf', 'png'], true) ? $data['format'] : 'pdf';

        if (empty($ids))            error("Sertifikatlar tanlanmagan", 422);
        if (count($ids) > 200)      error("Bir vaqtda 200 tadan ko'p yuklab bo'lmaydi", 422);
        if (!class_exists('ZipArchive')) error("Server ZipArchive ni qo'llab-quvvatlamaydi", 500);

        // Faqat foydalanuvchining sertifikatlarini olamiz
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT cert_id, recipient_name, file_pdf, file_png
             FROM certificates
             WHERE user_id = ? AND id IN ({$placeholders})"
        );
        $stmt->execute([$user['id'], ...$ids]);
        $rows = $stmt->fetchAll();

        if (empty($rows)) error('Sertifikat topilmadi', 404);

        $publicDir = __DIR__ . '/../../public/';
        $tmpZip    = tempnam(sys_get_temp_dir(), 'cert_zip_') . '.zip';

        $zip = new \ZipArchive();
        if ($zip->open($tmpZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            error('ZIP yaratishda xato', 500);
        }

        $added = 0;
        foreach ($rows as $r) {
            $rel = $format === 'pdf' ? ($r['file_pdf'] ?? '') : ($r['file_png'] ?? '');
            if (!$rel) continue;
            $path = $publicDir . ltrim($rel, '/');
            if (!is_file($path)) continue;

            $ext   = pathinfo($path, PATHINFO_EXTENSION);
            $safe  = preg_replace('/[^a-zA-Z0-9_\- ]/u', '_', $r['recipient_name'] ?? 'cert');
            $zip->addFile($path, "{$r['cert_id']}_{$safe}.{$ext}");
            $added++;
        }
        $zip->close();

        if ($added === 0) {
            @unlink($tmpZip);
            error('Yuklab olinadigan fayl topilmadi', 404);
        }

        $filename = "sertifikatlar_" . date('Y-m-d_His') . ".zip";

        header_remove('Content-Type');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($tmpZip));
        header('Cache-Control: no-cache');

        readfile($tmpZip);
        @unlink($tmpZip);
        exit;
    }

    // GET /api/certificates/:id/scans
    public function scans(int $id): never {
        $user  = AuthMiddleware::handle();
        $stats = $this->service->getScanStats($id, $user['id']);
        if (empty($stats)) error('Sertifikat topilmadi', 404);
        success($stats);
    }

    // POST /api/templates/submit — foydalanuvchi shablon yuklaydi
    public function submitTemplate(): never {
        $user = AuthMiddleware::handle();

        if (!isset($_FILES['file'])) error('Shablon rasmi yuklanishi shart', 422);

        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $cat  = trim($_POST['category'] ?? 'certificate');
        $orientation = ($_POST['orientation'] ?? 'landscape') === 'portrait' ? 'portrait' : 'landscape';

        if (strlen($name) < 3) error('Shablon nomi kamida 3 belgi', 422);

        $file = $_FILES['file'];
        if ($file['size'] > 10 * 1024 * 1024) error('Fayl 10MB dan oshmasligi kerak', 422);

        // MIME-type va real rasm decode tekshiruvi
        $mime = null;
        if (function_exists('mime_content_type')) {
            $mime = mime_content_type($file['tmp_name']);
        } elseif (function_exists('getimagesize')) {
            $imgInfo = getimagesize($file['tmp_name']);
            if ($imgInfo) $mime = $imgInfo['mime'];
        }

        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!$mime || !isset($allowed[$mime])) {
            error('Faqat JPG, PNG yoki WEBP rasmlarni yuklash mumkin', 422);
        }

        // Real image decode validation
        $src = match($mime) {
            'image/jpeg' => @imagecreatefromjpeg($file['tmp_name']),
            'image/png'  => @imagecreatefrompng($file['tmp_name']),
            'image/webp' => @imagecreatefromwebp($file['tmp_name']),
            default      => null,
        };

        if (!$src) {
            error('Yaroqsiz yoki buzilgan rasm fayli', 422);
        }

        $dir = __DIR__ . '/../../public/uploads/templates';
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        // Safe filename with extension
        $ext = $allowed[$mime];
        $filename = 'user_' . $user['id'] . '_' . uniqid('tpl_') . '.' . $ext;
        $path     = "{$dir}/{$filename}";

        // Save fresh re-encoded image without EXIF/PHP payloads
        $saved = false;
        if ($mime === 'image/jpeg') {
            $saved = @imagejpeg($src, $path, 90);
        } elseif ($mime === 'image/png') {
            @imagesavealpha($src, true);
            $saved = @imagepng($src, $path, 6);
        } elseif ($mime === 'image/webp') {
            $saved = @imagewebp($src, $path, 85);
        }
        @imagedestroy($src);

        if (!$saved) {
            error('Rasm faylini saqlashda xato yuz berdi', 500);
        }

        $rel = "uploads/templates/{$filename}";
        $stmt = $this->db->prepare(
            "INSERT INTO templates (name, description, preview_url, file_url, category, is_premium, width, height, fields, status, submitted_by, submitted_at, orientation)
             VALUES (?, ?, ?, ?, ?, false, 1280, 960, '[]', 'pending', ?, NOW(), ?) RETURNING id"
        );
        $stmt->execute([$name, $desc, $rel, $rel, $cat, $user['id'], $orientation]);
        $id = $stmt->fetchColumn();

        AuditLogger::log('template.submit', $user['id'], target: 'template', targetId: (int)$id, meta: ['name' => $name]);

        success(['id' => $id, 'status' => 'pending'], 'Shablon yuborildi, admin tomonidan ko\'rib chiqilishini kuting', 201);
    }

    // GET /api/templates/my — foydalanuvchining yuborgan shablonlari
    public function myTemplates(): never {
        $user = AuthMiddleware::handle();
        $stmt = $this->db->prepare(
            'SELECT id, name, description, preview_url, status, reject_reason, submitted_at, reviewed_at, category
             FROM templates WHERE submitted_by = ? ORDER BY submitted_at DESC'
        );
        $stmt->execute([$user['id']]);
        success(['items' => $stmt->fetchAll()]);
    }

    // GET /api/templates
    public function templates(): never {
        $stmt = $this->db->prepare(
            "SELECT id, name, description, preview_url, file_url, category, is_premium, width, height, fields, doc_type, orientation
             FROM templates WHERE is_active = true AND status = 'approved' ORDER BY id ASC"
        );
        $stmt->execute();
        success($stmt->fetchAll());
    }
}
