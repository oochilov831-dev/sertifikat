<?php
require_once __DIR__ . '/../src/Services/QrGenerator.php';

$url  = '/verify.html?id=CERT-TEST01';
$file = __DIR__ . '/test_qr.png';

$ok = QrGenerator::savePng($url, $file, 8, 4);

if ($ok && file_exists($file)) {
    $size = getimagesize($file);
    echo "OK — {$size[0]}x{$size[1]} px, " . filesize($file) . " bayt\n";
    echo "Fayl: $file\n";
} else {
    echo "XATO — QR yaratilmadi\n";
}
