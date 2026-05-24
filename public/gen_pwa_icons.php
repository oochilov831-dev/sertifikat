<?php
/**
 * PWA app iconlarini generatsiya qilish
 * Ishga tushirish: php public/gen_pwa_icons.php
 */

function generateIcon(int $size, string $outFile): void {
    $img = imagecreatetruecolor($size, $size);

    // Gradient fon (binafsha → indigo)
    for ($y = 0; $y < $size; $y++) {
        $ratio = $y / $size;
        $r = (int)(99  + $ratio * 60);   // 99 → 159
        $g = (int)(102 + $ratio * (-10)); // 102 → 92
        $b = (int)(241 + $ratio * 6);    // 241 → 247
        $col = imagecolorallocate($img, min(255, $r), min(255, $g), min(255, $b));
        imageline($img, 0, $y, $size, $y, $col);
    }

    // Yumaloq burchaklar uchun alpha mask qo'llab bo'lmaydi GD da to'liq,
    // shuning uchun maskaning o'rniga to'g'ridan-to'g'ri tashqarisini o'chiramiz
    imagesavealpha($img, true);

    // Markazda "🎓" ramzi o'rniga sertifikat ikonkasi chiziladi
    $white = imagecolorallocate($img, 255, 255, 255);
    $gold  = imagecolorallocate($img, 251, 191, 36);

    // Sertifikat shakli — markazda
    $cx = $size / 2;
    $cy = $size / 2;
    $rectW = $size * 0.55;
    $rectH = $size * 0.42;
    $rectX = (int)($cx - $rectW / 2);
    $rectY = (int)($cy - $rectH / 2 - $size * 0.04);

    // Sertifikat fon (oq)
    imagefilledrectangle($img, $rectX, $rectY, $rectX + (int)$rectW, $rectY + (int)$rectH, $white);

    // Sertifikat chiziqlari (gradient ko'k)
    $primary = imagecolorallocate($img, 99, 102, 241);
    $lineY1 = $rectY + (int)($rectH * 0.3);
    $lineY2 = $rectY + (int)($rectH * 0.5);
    $lineY3 = $rectY + (int)($rectH * 0.7);
    $lineW = (int)($rectW * 0.7);
    $lineX = $rectX + (int)(($rectW - $lineW) / 2);
    imagefilledrectangle($img, $lineX, $lineY1, $lineX + $lineW, $lineY1 + (int)($size * 0.015), $primary);
    imagefilledrectangle($img, $lineX, $lineY2, $lineX + (int)($lineW * 0.6), $lineY2 + (int)($size * 0.01), $primary);
    imagefilledrectangle($img, $lineX, $lineY3, $lineX + (int)($lineW * 0.8), $lineY3 + (int)($size * 0.01), $primary);

    // Oltin medal (pastda)
    $medalR = (int)($size * 0.1);
    $medalCx = (int)$cx;
    $medalCy = $rectY + (int)$rectH + (int)($size * 0.04);
    imagefilledellipse($img, $medalCx, $medalCy, $medalR * 2, $medalR * 2, $gold);
    $goldDark = imagecolorallocate($img, 180, 130, 30);
    imageellipse($img, $medalCx, $medalCy, $medalR * 2, $medalR * 2, $goldDark);

    $dir = __DIR__ . '/icons';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    imagepng($img, $outFile);
}

$outDir = __DIR__ . '/icons';
if (!is_dir($outDir)) mkdir($outDir, 0755, true);

generateIcon(192, $outDir . '/icon-192.png');
generateIcon(512, $outDir . '/icon-512.png');
generateIcon(180, $outDir . '/apple-touch-icon.png');

echo "PWA iconlar yaratildi:\n";
echo "- icon-192.png (" . filesize($outDir . '/icon-192.png') . " bayt)\n";
echo "- icon-512.png (" . filesize($outDir . '/icon-512.png') . " bayt)\n";
echo "- apple-touch-icon.png (" . filesize($outDir . '/apple-touch-icon.png') . " bayt)\n";
