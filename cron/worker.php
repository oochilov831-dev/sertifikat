<?php
// Background worker: php cron/worker.php
// Windows Task Scheduler: run every minute, or run continuously in a loop.

chdir(__DIR__ . '/..');

require_once 'vendor/autoload.php';
require_once 'src/Helpers/env.php';
loadEnv(__DIR__ . '/../.env');

require_once 'config/database.php';
require_once 'src/Helpers/response.php';

use App\Services\CertificateService;
use App\Models\SubscriptionModel;

$log = fn(string $msg) => print('[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL);

$db = Database::getInstance();

// 1. Pending bo'lgan bitta ishni olamiz va blocklaymiz (multi-process safe)
$stmt = $db->prepare("
    UPDATE bulk_jobs 
    SET status = 'processing', updated_at = NOW()
    WHERE id = (
        SELECT id FROM bulk_jobs
        WHERE status = 'pending'
        ORDER BY id ASC
        LIMIT 1
        FOR UPDATE SKIP LOCKED
    )
    RETURNING *
");
$stmt->execute();
$job = $stmt->fetch();

if (!$job) {
    // Agar kutilayotgan ish yo'q bo'lsa, chiqib ketamiz
    exit;
}

$jobId = $job['id'];
$log("Job #{$jobId} olindi. Jami sertifikatlar: {$job['total']}");

$csvFile = __DIR__ . '/../public/uploads/bulk/' . $job['filename'];
if (!file_exists($csvFile)) {
    $db->prepare("UPDATE bulk_jobs SET status = 'failed', error_log = 'CSV fayl topilmadi', updated_at = NOW() WHERE id = ?")
       ->execute([$jobId]);
    $log("Xato: CSV fayl topilmadi: {$csvFile}");
    exit;
}

$baseData = json_decode($job['error_log'] ?? '{}', true) ?: [];
$userId = (int)$job['user_id'];

// CSV faylni ochish va o'qish
$handle = fopen($csvFile, 'r');
if (!$handle) {
    $db->prepare("UPDATE bulk_jobs SET status = 'failed', error_log = 'CSV faylni o\'qib bo\'lmadi', updated_at = NOW() WHERE id = ?")
       ->execute([$jobId]);
    $log("Xato: CSV faylni o'qib bo'lmadi");
    @unlink($csvFile);
    exit;
}

$headersRaw = fgetcsv($handle);
$headers = array_map('trim', $headersRaw ?: []);

if (!in_array('recipient_name', $headers, true)) {
    $db->prepare("UPDATE bulk_jobs SET status = 'failed', error_log = 'CSV faylda recipient_name ustuni mavjud emas', updated_at = NOW() WHERE id = ?")
       ->execute([$jobId]);
    $log("Xato: recipient_name ustuni topilmadi");
    fclose($handle);
    @unlink($csvFile);
    exit;
}

$service = new CertificateService();
$subModel = new SubscriptionModel();

$processed = 0;
$failed = 0;
$errors = [];
$certificates = [];

while (($row = fgetcsv($handle)) !== false) {
    if (count($row) !== count($headers)) {
        $failed++;
        $errors[] = "Qator ustunlari header bilan mos kelmadi.";
        continue;
    }

    $item = array_combine($headers, array_map('trim', $row));
    $data = array_merge($baseData, $item);

    // Limit tekshirish
    if (!$subModel->canCreate($userId)) {
        $remainingRows = max(1, (int)$job['total'] - $processed - $failed);
        $failed += $remainingRows;
        $errors[] = "Foydalanuvchi limiti tugadi. Qolgan qatorlar yaratilmadi.";
        break;
    }

    try {
        if (empty($data['recipient_name'])) {
            throw new \InvalidArgumentException("Oluvchi ismi kiritilmagan.");
        }

        // Sertifikatni yaratish
        $cert = $service->generate($userId, $data);
        $certificates[] = [
            'id' => (int)($cert['id'] ?? 0),
            'cert_id' => $cert['cert_id'] ?? null,
            'recipient_name' => $data['recipient_name'] ?? '',
            'file_pdf' => $cert['file_pdf'] ?? null,
            'file_png' => $cert['file_png'] ?? null,
        ];
        $processed++;
    } catch (\Throwable $e) {
        $failed++;
        $errors[] = "Recipient " . ($data['recipient_name'] ?? 'Noma\'lum') . " xato: " . $e->getMessage();
    }

    // Har bir sertifikat yaratilganda progressni bazada yangilab boramiz
    $db->prepare("UPDATE bulk_jobs SET processed = ?, failed = ?, updated_at = NOW() WHERE id = ?")
       ->execute([$processed, $failed, $jobId]);
}

fclose($handle);
@unlink($csvFile); // CSV faylni o'chirib yuboramiz

// Job statusini yakunlash
$finalStatus = ($processed > 0) ? 'completed' : 'failed';
$errorLogText = json_encode([
    'errors' => array_slice($errors, 0, 100),
    'certificates' => $certificates,
], JSON_UNESCAPED_UNICODE);

$db->prepare("
    UPDATE bulk_jobs 
    SET status = ?, 
        error_log = ?, 
        updated_at = NOW() 
    WHERE id = ?
")->execute([$finalStatus, $errorLogText, $jobId]);

$log("Job #{$jobId} yakunlandi. Muvaffaqiyatli: {$processed}, Xato: {$failed}, Status: {$finalStatus}");
