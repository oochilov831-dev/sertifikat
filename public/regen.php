<?php
// Mavjud sertifikatlarni yangi shablon bilan qayta generatsiya qilish
chdir(__DIR__ . '/..');
require_once 'src/Helpers/env.php';
loadEnv(__DIR__ . '/../.env');
require_once 'config/database.php';
require_once 'src/Models/SubscriptionModel.php';
require_once 'src/Services/QrGenerator.php';
require_once 'src/Services/CertificateService.php';

$db = Database::getInstance();

// Barcha sertifikatlarni olish
$stmt = $db->query(
    'SELECT c.*, u.name as issuer_name, u.company as issuer_company
     FROM certificates c JOIN users u ON u.id = c.user_id'
);
$certs = $stmt->fetchAll();

$svc = new CertificateService();
$ref = new ReflectionClass($svc);

$buildHtml  = $ref->getMethod('buildHtml');
$publicPath = $ref->getMethod('publicPath');
$buildHtml->setAccessible(true);
$publicPath->setAccessible(true);

$count = 0;
foreach ($certs as $cert) {
    $data = [
        'recipient_name' => $cert['recipient_name'],
        'course_name'    => $cert['course_name'],
        'issued_date'    => $cert['issued_date'],
        'cert_id'        => $cert['cert_id'],
        'issuer_name'    => $cert['issuer_name'],
        'issuer_company' => $cert['issuer_company'],
    ];
    $qrPath   = $cert['qr_code'];
    $watermark = (bool)$cert['watermark'];

    $html     = $buildHtml->invoke($svc, $data, $qrPath, $watermark);
    $dir      = $publicPath->invoke($svc, 'uploads/certificates/pdf');
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $htmlFile = "{$dir}/cert_{$cert['id']}.html";
    file_put_contents($htmlFile, $html);
    echo "Yangilandi: cert_{$cert['id']}.html ({$cert['cert_id']})\n";
    $count++;
}

echo "\nJami {$count} ta sertifikat yangilandi.\n";
