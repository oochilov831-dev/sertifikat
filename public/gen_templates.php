<?php
/**
 * 5 ta yangi premium shablon yaratish (PNG + DB qator)
 * Ishga tushirish: php public/gen_templates.php
 */
require_once __DIR__ . '/../src/Helpers/env.php';
loadEnv(__DIR__ . '/../.env');
require_once __DIR__ . '/../config/database.php';

$W = 1280;
$H = 960;
$outDir = __DIR__ . '/uploads/templates';
if (!is_dir($outDir)) mkdir($outDir, 0755, true);
$font = __DIR__ . '/fonts/arial.ttf';
if (!file_exists($font) && file_exists('C:/Windows/Fonts/arial.ttf')) {
    $font = 'C:/Windows/Fonts/arial.ttf';
}
$hasFont = file_exists($font);

// ───────────────────────────────────────────────────
// Yordamchi funksiyalar
// ───────────────────────────────────────────────────
function gradientBg($img, int $w, int $h, array $c1, array $c2): void {
    for ($y = 0; $y < $h; $y++) {
        $r = (int)($c1[0] + ($c2[0] - $c1[0]) * $y / $h);
        $g = (int)($c1[1] + ($c2[1] - $c1[1]) * $y / $h);
        $b = (int)($c1[2] + ($c2[2] - $c1[2]) * $y / $h);
        $col = imagecolorallocate($img, max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b)));
        imageline($img, 0, $y, $w, $y, $col);
    }
}

function drawFilledCircle($img, int $x, int $y, int $r, $color): void {
    imagefilledellipse($img, $x, $y, $r * 2, $r * 2, $color);
}

function drawStar($img, int $cx, int $cy, int $r, $color): void {
    $pts = [];
    for ($i = 0; $i < 10; $i++) {
        $rr = $i % 2 === 0 ? $r : $r / 2.5;
        $a = -M_PI / 2 + $i * M_PI / 5;
        $pts[] = $cx + (int)($rr * cos($a));
        $pts[] = $cy + (int)($rr * sin($a));
    }
    imagefilledpolygon($img, $pts, $color);
}

function saveTemplate($img, string $name): string {
    global $outDir;
    $file = "{$outDir}/cert_{$name}.png";
    imagepng($img, $file);
    return "uploads/templates/cert_{$name}.png";
}

// ───────────────────────────────────────────────────
// 1. ACADEMIC GREEN (yashil akademik)
// ───────────────────────────────────────────────────
function tplAcademicGreen(): string {
    global $W, $H, $font, $hasFont;
    $img = imagecreatetruecolor($W, $H);

    // Krem fon
    $cream = imagecolorallocate($img, 248, 244, 230);
    imagefill($img, 0, 0, $cream);

    $green     = imagecolorallocate($img, 14, 95, 58);
    $greenLite = imagecolorallocate($img, 28, 130, 80);
    $gold      = imagecolorallocate($img, 200, 168, 75);
    $white     = imagecolorallocate($img, 255, 255, 255);
    $dark      = imagecolorallocate($img, 40, 50, 40);
    $gray      = imagecolorallocate($img, 110, 120, 110);

    // Yuqori chiziq
    imagefilledrectangle($img, 0, 0, $W, 12, $green);
    imagefilledrectangle($img, 0, 12, $W, 16, $gold);

    // Pastki chiziq
    imagefilledrectangle($img, 0, $H - 16, $W, $H - 12, $gold);
    imagefilledrectangle($img, 0, $H - 12, $W, $H, $green);

    // Chap va o'ngdagi vertikal yashil chiziqlar
    imagefilledrectangle($img, 30, 30, 38, $H - 30, $green);
    imagefilledrectangle($img, $W - 38, 30, $W - 30, $H - 30, $green);

    // Yuqorida tugatuv qalpoqchasi (graduation cap) — soddalashtirilgan
    $capX = $W / 2;
    $capY = 150;
    imagefilledrectangle($img, $capX - 80, $capY, $capX + 80, $capY + 15, $green);
    imagefilledpolygon($img, [
        $capX - 100, $capY,
        $capX + 100, $capY,
        $capX,       $capY - 40,
    ], $green);
    drawFilledCircle($img, $capX, $capY - 38, 8, $gold);
    imageline($img, $capX, $capY - 30, $capX + 50, $capY + 25, $gold);

    if ($hasFont) {
        // Sarlavha
        imagettftext($img, 18, 0, 460, 240, $gray, $font, 'ACADEMIC EXCELLENCE');
        imagettftext($img, 64, 0, 350, 320, $green, $font, 'SERTIFIKAT');

        // Ajratuvchi
        imagefilledrectangle($img, 540, 350, 740, 354, $gold);

        // Placeholder matnlar
        imagettftext($img, 16, 0, 470, 410, $gray, $font, 'Quyidagi shaxsga taqdim etiladi');
        imagettftext($img, 42, 0, 320, 490, $dark, $font, 'Ism Familiya');
        imagefilledrectangle($img, 290, 510, 990, 512, $greenLite);

        imagettftext($img, 14, 0, 490, 560, $gray, $font, 'Muvaffaqiyatli tugatganligi uchun');
        imagettftext($img, 26, 0, 380, 620, $green, $font, 'Kurs nomi');

        // Imzo chiziqlari
        imagefilledrectangle($img, 250, 800, 450, 802, $gray);
        imagefilledrectangle($img, 830, 800, 1030, 802, $gray);
        imagettftext($img, 12, 0, 290, 825, $gray, $font, 'Imzo');
        imagettftext($img, 12, 0, 870, 825, $gray, $font, 'Tashkilot');
    }

    return saveTemplate($img, 'academic_green');
}

// ───────────────────────────────────────────────────
// 2. CORPORATE NAVY (korporativ to'q ko'k + kumush)
// ───────────────────────────────────────────────────
function tplCorporateNavy(): string {
    global $W, $H, $font, $hasFont;
    $img = imagecreatetruecolor($W, $H);

    $white  = imagecolorallocate($img, 255, 255, 255);
    $navy   = imagecolorallocate($img, 25, 45, 80);
    $navy2  = imagecolorallocate($img, 35, 65, 110);
    $silver = imagecolorallocate($img, 180, 190, 210);
    $silverLight = imagecolorallocate($img, 220, 225, 235);
    $dark   = imagecolorallocate($img, 20, 30, 50);
    $gray   = imagecolorallocate($img, 100, 110, 130);

    imagefill($img, 0, 0, $white);

    // Yuqori va pastki katta navy bloklar
    imagefilledrectangle($img, 0, 0, $W, 140, $navy);
    imagefilledrectangle($img, 0, 0, $W, 4, $silver);
    imagefilledrectangle($img, 0, 144, $W, 148, $silver);

    imagefilledrectangle($img, 0, $H - 140, $W, $H, $navy);
    imagefilledrectangle($img, 0, $H - 148, $W, $H - 144, $silver);
    imagefilledrectangle($img, 0, $H - 4, $W, $H, $silver);

    // Diagonal aksent
    for ($i = 0; $i < 80; $i++) {
        imageline($img, $W - 200 + $i, 0, $W, 200 - $i, $navy2);
    }

    if ($hasFont) {
        imagettftext($img, 16, 0, 460, 50, $silverLight, $font, 'CORPORATE EXCELLENCE');
        imagettftext($img, 52, 0, 380, 110, $white, $font, 'CERTIFICATE');

        imagettftext($img, 14, 0, 510, 290, $gray, $font, 'TAQDIM ETILADI');
        imagettftext($img, 48, 0, 320, 380, $dark, $font, 'Ism Familiya');
        imagefilledrectangle($img, 290, 400, 990, 402, $navy);

        imagettftext($img, 14, 0, 470, 460, $gray, $font, 'Quyidagi xizmatlari uchun');
        imagettftext($img, 26, 0, 380, 520, $navy, $font, 'Kurs / Mukofot nomi');

        imagettftext($img, 11, 0, 130, $H - 200, $silverLight, $font, 'SANA');
        imagettftext($img, 16, 0, 130, $H - 175, $white,       $font, '01.01.2024');

        imagettftext($img, 11, 0, $W - 270, $H - 200, $silverLight, $font, 'IMZO');
        imagefilledrectangle($img, $W - 270, $H - 180, $W - 70, $H - 178, $silver);
    }

    return saveTemplate($img, 'corporate_navy');
}

// ───────────────────────────────────────────────────
// 3. EVENT FESTIVE (tadbir, rangli pastki to'lqin)
// ───────────────────────────────────────────────────
function tplEventFestive(): string {
    global $W, $H, $font, $hasFont;
    $img = imagecreatetruecolor($W, $H);

    $white = imagecolorallocate($img, 255, 255, 255);
    $pink  = imagecolorallocate($img, 236, 72, 153);
    $purple= imagecolorallocate($img, 168, 85, 247);
    $orange= imagecolorallocate($img, 251, 146, 60);
    $yellow= imagecolorallocate($img, 250, 204, 21);
    $dark  = imagecolorallocate($img, 30, 41, 59);
    $gray  = imagecolorallocate($img, 100, 116, 139);

    imagefill($img, 0, 0, $white);

    // Yuqorida rangli to'lqin
    for ($x = 0; $x < $W; $x++) {
        $y1 = (int)(60 + 30 * sin($x / 80));
        imageline($img, $x, 0, $x, $y1, $pink);
        imageline($img, $x, $y1, $x, $y1 + 15, $purple);
    }

    // Pastda rangli to'lqin
    for ($x = 0; $x < $W; $x++) {
        $y1 = $H - (int)(60 + 30 * sin($x / 80 + 1));
        imageline($img, $x, $y1, $x, $H, $orange);
        imageline($img, $x, $y1 - 15, $x, $y1, $yellow);
    }

    // Confetti
    for ($i = 0; $i < 50; $i++) {
        $cx = rand(40, $W - 40);
        $cy = rand(140, $H - 140);
        $cl = [$pink, $purple, $orange, $yellow][rand(0, 3)];
        imagefilledellipse($img, $cx, $cy, 6, 6, $cl);
    }

    if ($hasFont) {
        imagettftext($img, 22, 0, 460, 240, $purple, $font, 'TADBIR ISHTIROKCHISI');
        imagettftext($img, 64, 0, 290, 340, $dark, $font, 'SERTIFIKAT');

        // Yulduzlar
        for ($s = 0; $s < 5; $s++) {
            drawStar($img, 530 + $s * 60, 390, 12, $yellow);
        }

        imagettftext($img, 16, 0, 470, 480, $gray, $font, 'Tabriklaymiz!');
        imagettftext($img, 46, 0, 320, 560, $purple, $font, 'Ism Familiya');
        imagettftext($img, 18, 0, 380, 620, $orange, $font, 'Tadbir / Festival nomi');

        imagettftext($img, 12, 0, 130, $H - 180, $gray, $font, 'SANA');
        imagettftext($img, 12, 0, $W - 250, $H - 180, $gray, $font, 'TASHKILOTCHI');
    }

    return saveTemplate($img, 'event_festive');
}

// ───────────────────────────────────────────────────
// 4. SPORTS RED-GOLD (sport mukofoti)
// ───────────────────────────────────────────────────
function tplSportsRed(): string {
    global $W, $H, $font, $hasFont;
    $img = imagecreatetruecolor($W, $H);

    $cream = imagecolorallocate($img, 252, 246, 232);
    imagefill($img, 0, 0, $cream);

    $red    = imagecolorallocate($img, 185, 28, 28);
    $redDark= imagecolorallocate($img, 130, 20, 20);
    $gold   = imagecolorallocate($img, 217, 168, 50);
    $goldL  = imagecolorallocate($img, 250, 200, 80);
    $dark   = imagecolorallocate($img, 40, 40, 40);
    $gray   = imagecolorallocate($img, 110, 110, 110);
    $white  = imagecolorallocate($img, 255, 255, 255);

    // Diagonal qizil panel (chap yuqori)
    imagefilledpolygon($img, [0, 0, 450, 0, 280, $H, 0, $H], $red);

    // Diagonal oltin chiziq
    imagefilledpolygon($img, [
        430, 0, 470, 0,
        300, $H, 260, $H,
    ], $gold);

    // Pastki o'ng burchakda medal effekti
    drawFilledCircle($img, $W - 200, $H - 200, 100, $gold);
    drawFilledCircle($img, $W - 200, $H - 200, 85,  $cream);
    drawFilledCircle($img, $W - 200, $H - 200, 70,  $gold);
    drawStar($img, $W - 200, $H - 200, 38, $redDark);
    drawStar($img, $W - 200, $H - 200, 34, $goldL);

    if ($hasFont) {
        // Chap qizil panelda matn
        imagettftext($img, 24, 90, 100, 600, $white, $font, 'CHAMPION');
        imagettftext($img, 14, 90, 140, 600, $cream, $font, 'AWARD 2024');

        // O'ng tomonda
        imagettftext($img, 18, 0, 530, 200, $red,   $font, 'GALABA SERTIFIKATI');
        imagettftext($img, 50, 0, 530, 280, $dark,  $font, 'CHAMPION');

        imagefilledrectangle($img, 530, 310, 730, 314, $gold);

        imagettftext($img, 16, 0, 530, 380, $gray, $font, 'Quyidagi musobaqada g\'olib:');
        imagettftext($img, 42, 0, 530, 460, $dark, $font, 'Ism Familiya');
        imagefilledrectangle($img, 530, 480, 1050, 482, $red);

        imagettftext($img, 22, 0, 530, 540, $red, $font, 'Musobaqa / Sport turi');

        imagettftext($img, 12, 0, 530, $H - 100, $gray, $font, 'BERILGAN SANA');
        imagettftext($img, 16, 0, 530, $H - 75,  $dark, $font, '01.01.2024');
    }

    return saveTemplate($img, 'sports_red');
}

// ───────────────────────────────────────────────────
// 5. MINIMAL MODERN (zamonaviy, oq + qora + sariq aksent)
// ───────────────────────────────────────────────────
function tplMinimalModern(): string {
    global $W, $H, $font, $hasFont;
    $img = imagecreatetruecolor($W, $H);

    $white = imagecolorallocate($img, 255, 255, 255);
    $black = imagecolorallocate($img, 17, 24, 39);
    $accent= imagecolorallocate($img, 250, 204, 21);  // sariq
    $gray  = imagecolorallocate($img, 100, 116, 139);
    $light = imagecolorallocate($img, 230, 235, 240);

    imagefill($img, 0, 0, $white);

    // Pastda qora pol
    imagefilledrectangle($img, 0, $H - 6, $W, $H, $black);

    // Yuqori chap burchakda sariq kvadrat
    imagefilledrectangle($img, 50, 50, 120, 120, $accent);

    // Yuqori o'ng burchakda chiziqlar
    for ($i = 0; $i < 5; $i++) {
        imagefilledrectangle($img, $W - 220 + $i * 40, 50, $W - 200 + $i * 40, 110, $light);
    }

    // Markazdan o'ngga sariq vertikal chiziq
    imagefilledrectangle($img, $W / 2 + 200, 250, $W / 2 + 204, 700, $accent);

    if ($hasFont) {
        // Yuqori chap — kichik matnlar
        imagettftext($img, 11, 0, 50, 170, $gray,  $font, '— SERTIFIKAT —');
        imagettftext($img, 11, 0, 50, 200, $gray,  $font, 'No. 000-2024');

        // Markaz — asosiy sarlavha (chap tomonda)
        imagettftext($img, 32, 0, 50, 320, $black, $font, 'CERTIFICATE');
        imagettftext($img, 16, 0, 50, 360, $gray,  $font, 'OF ACHIEVEMENT');

        imagettftext($img, 12, 0, 50, 460, $gray, $font, 'Bu sertifikat tasdiqlaydi:');
        imagettftext($img, 44, 0, 50, 540, $black, $font, 'Ism Familiya');
        imagefilledrectangle($img, 50, 560, 250, 562, $accent);

        imagettftext($img, 14, 0, 50, 620, $gray, $font, 'Muvaffaqiyatli yakunladi:');
        imagettftext($img, 22, 0, 50, 670, $black, $font, 'Kurs nomi');

        // Pastda metadata
        imagettftext($img, 10, 0, 50, $H - 130, $gray, $font, 'BERILGAN');
        imagettftext($img, 14, 0, 50, $H - 110, $black, $font, '01.01.2024');

        imagettftext($img, 10, 0, 240, $H - 130, $gray, $font, 'ID');
        imagettftext($img, 14, 0, 240, $H - 110, $black, $font, 'CERT-XXXXX');

        imagettftext($img, 10, 0, 450, $H - 130, $gray, $font, 'TASHKILOT');
        imagettftext($img, 14, 0, 450, $H - 110, $black, $font, 'Organization');
    }

    return saveTemplate($img, 'minimal_modern');
}

// ───────────────────────────────────────────────────
// 6. LUXURY EMERALD GOLD (Premium yashil + oltin)
// ───────────────────────────────────────────────────
function tplLuxuryEmerald(): string {
    global $W, $H, $font, $hasFont;
    $img = imagecreatetruecolor($W, $H);

    // To'q zumrad yashil gradient fon
    gradientBg($img, $W, $H, [8, 48, 30], [2, 28, 15]);

    $gold      = imagecolorallocate($img, 212, 175, 55);
    $goldLite  = imagecolorallocate($img, 244, 215, 94);
    $goldDark  = imagecolorallocate($img, 166, 124, 40);
    $white     = imagecolorallocate($img, 255, 255, 255);
    $cream     = imagecolorallocate($img, 245, 235, 215);
    $gray      = imagecolorallocate($img, 150, 170, 160);

    // Oltin hoshiya chiziqlari (Double gold border)
    imagerectangle($img, 20, 20, $W - 20, $H - 20, $goldDark);
    for ($i = 0; $i < 4; $i++) {
        imagerectangle($img, 30 + $i, 30 + $i, $W - 30 - $i, $H - 30 - $i, $gold);
    }
    imagerectangle($img, 42, 42, $W - 42, $H - 42, $goldDark);

    // Burchaklardagi naqshlar (corner ornaments)
    $corners = [
        [30, 30, 1, 1],
        [$W - 30, 30, -1, 1],
        [30, $H - 30, 1, -1],
        [$W - 30, $H - 30, -1, -1]
    ];
    foreach ($corners as $c) {
        $cx = $c[0]; $cy = $c[1]; $sx = $c[2]; $sy = $c[3];
        imagefilledpolygon($img, [
            $cx, $cy,
            $cx + $sx * 40, $cy,
            $cx, $cy + $sy * 40
        ], $goldDark);
        imagefilledpolygon($img, [
            $cx + $sx * 5, $cy + $sy * 5,
            $cx + $sx * 35, $cy + $sy * 5,
            $cx + $sx * 5, $cy + $sy * 35
        ], $goldLite);

        drawFilledCircle($img, $cx + $sx * 50, $cy + $sy * 50, 4, $gold);
        drawFilledCircle($img, $cx + $sx * 65, $cy + $sy * 65, 3, $goldLite);
        imageline($img, $cx + $sx * 42, $cy + $sy * 15, $cx + $sx * 15, $cy + $sy * 42, $gold);
    }

    // Hashamatli Oltin Gerb/Medal ramzi (Emblem)
    $mx = $W / 2;
    $my = 160;
    for ($a = 0; $a < 360; $a += 15) {
        $rad = deg2rad($a);
        $rx = $mx + (int)(45 * cos($rad));
        $ry = $my + (int)(45 * sin($rad));
        imageline($img, $mx, $my, $rx, $ry, $goldDark);
    }
    drawFilledCircle($img, $mx, $my, 40, $gold);
    drawFilledCircle($img, $mx, $my, 35, $goldDark);
    drawFilledCircle($img, $mx, $my, 30, $goldLite);
    drawStar($img, $mx, $my, 18, $goldDark);
    drawStar($img, $mx, $my, 14, $gold);

    if ($hasFont) {
        imagettftext($img, 18, 0, 480, 260, $goldLite, $font, 'PREMIUM CERTIFICATE');
        imagettftext($img, 56, 0, 310, 340, $cream, $font, 'LORE DE LAUREL');
        imagefilledrectangle($img, 450, 370, 830, 373, $gold);
        imagettftext($img, 15, 0, 480, 430, $gray, $font, 'Ushbu yuksak hujjat topshiriladi:');
        imagettftext($img, 46, 0, 300, 510, $white, $font, 'Ism Familiya');
        imagefilledrectangle($img, 280, 530, 1000, 532, $goldDark);
        imagettftext($img, 14, 0, 490, 580, $gray, $font, 'Muvaffaqiyatli bajarganligi uchun');
        imagettftext($img, 28, 0, 360, 640, $goldLite, $font, 'Premium Dastur / Kurs');

        imagefilledrectangle($img, 220, 800, 450, 802, $goldDark);
        imagefilledrectangle($img, 830, 800, 1060, 802, $goldDark);
        imagettftext($img, 12, 0, 290, 825, $gray, $font, 'Direktor Imzosi');
        imagettftext($img, 12, 0, 890, 825, $gray, $font, 'Taqdim etuvchi');
    }

    return saveTemplate($img, 'luxury_emerald');
}

// ───────────────────────────────────────────────────
// 7. ROYAL PURPLE VELVET (Premium binafsha + neon oltin)
// ───────────────────────────────────────────────────
function tplRoyalPurple(): string {
    global $W, $H, $font, $hasFont;
    $img = imagecreatetruecolor($W, $H);

    gradientBg($img, $W, $H, [36, 11, 54], [11, 4, 24]);

    $magenta  = imagecolorallocate($img, 219, 39, 119);
    $violet   = imagecolorallocate($img, 124, 58, 237);
    $gold     = imagecolorallocate($img, 245, 158, 11);
    $goldL    = imagecolorallocate($img, 253, 224, 71);
    $white    = imagecolorallocate($img, 255, 255, 255);
    $pink     = imagecolorallocate($img, 253, 164, 186);
    $gray     = imagecolorallocate($img, 160, 150, 175);

    for ($i = 0; $i < 60; $i++) {
        $col = imagecolorallocate($img, max(0, 124 - $i*2), max(0, 58 - $i), 237);
        imageellipse($img, 0, 0, 300 + $i * 8, 400 + $i * 6, $col);
        imageellipse($img, $W, $H, 300 + $i * 8, 400 + $i * 6, $col);
    }

    for ($s = 0; $s < 40; $s++) {
        $sx = rand(60, $W - 60);
        $sy = rand(60, $H - 60);
        $sz = rand(2, 6);
        $sc = [$gold, $goldL, $white][rand(0, 2)];
        drawFilledCircle($img, $sx, $sy, $sz, $sc);
    }
    
    imagerectangle($img, 40, 40, $W - 40, $H - 40, $violet);
    imagerectangle($img, 42, 42, $W - 42, $H - 42, $magenta);

    if ($hasFont) {
        imagettftext($img, 16, 0, 500, 220, $pink, $font, 'ROYAL RECOGNITION');
        imagettftext($img, 60, 0, 320, 300, $goldL, $font, 'TAQDIRNOMA');
        imagefilledrectangle($img, 510, 320, 770, 323, $gold);
        imagettftext($img, 15, 0, 480, 390, $gray, $font, 'Yuksak muvaffaqiyatlar uchun taqdirlanadi');
        imagettftext($img, 48, 0, 300, 470, $white, $font, 'Ism Familiya');
        imagefilledrectangle($img, 280, 490, 1000, 492, $magenta);
        imagettftext($img, 14, 0, 500, 540, $gray, $font, 'Quyidagi munosib xizmatlari uchun:');
        imagettftext($img, 26, 0, 350, 600, $pink, $font, 'Loyixa / Fan / Kurs yo\'nalishi');

        imagettftext($img, 11, 0, 120, $H - 140, $gray, $font, 'BERILGAN SANA');
        imagettftext($img, 14, 0, 120, $H - 110, $white, $font, '23.05.2026');
        imagettftext($img, 11, 0, $W - 270, $H - 140, $gray, $font, 'KENGASH RAISI');
        imagefilledrectangle($img, $W - 270, $H - 115, $W - 100, $H - 113, $violet);
    }

    return saveTemplate($img, 'royal_purple');
}

// ───────────────────────────────────────────────────
// 8. FUTURISTIC CYBERPUNK (Premium to'q texnologik)
// ───────────────────────────────────────────────────
function tplCyberpunk(): string {
    global $W, $H, $font, $hasFont;
    $img = imagecreatetruecolor($W, $H);

    gradientBg($img, $W, $H, [10, 12, 16], [17, 24, 39]);

    $cyan     = imagecolorallocate($img, 6, 182, 212);
    $neonBlue = imagecolorallocate($img, 59, 130, 246);
    $purple   = imagecolorallocate($img, 168, 85, 247);
    $darkGray = imagecolorallocate($img, 31, 41, 55);
    $lightGray= imagecolorallocate($img, 75, 85, 99);
    $white    = imagecolorallocate($img, 255, 255, 255);
    $cyanLite = imagecolorallocate($img, 207, 250, 254);

    for ($x = 0; $x < $W; $x += 40) {
        imageline($img, $x, 0, $x, $H, $darkGray);
    }
    for ($y = 0; $y < $H; $y += 40) {
        imageline($img, 0, $y, $W, $y, $darkGray);
    }

    imagefilledrectangle($img, 30, 30, 100, 33, $cyan);
    imagefilledrectangle($img, 30, 30, 33, 100, $cyan);
    imageline($img, 100, 31, 130, 61, $cyan);
    imagefilledrectangle($img, 130, 60, 180, 62, $neonBlue);

    imagefilledrectangle($img, $W - 100, $H - 33, $W - 30, $H - 30, $purple);
    imagefilledrectangle($img, $W - 33, $H - 100, $W - 30, $H - 30, $purple);
    imageline($img, $W - 100, $H - 31, $W - 130, $H - 61, $purple);
    imagefilledrectangle($img, $W - 180, $H - 62, $W - 130, $H - 60, $neonBlue);

    for ($i = 0; $i < 6; $i++) {
        drawFilledCircle($img, $W / 2 - 100 + $i * 40, 70, 3, $cyan);
    }

    imagerectangle($img, 45, 45, $W - 45, $H - 45, $darkGray);
    imagerectangle($img, 47, 47, $W - 47, $H - 47, $neonBlue);

    if ($hasFont) {
        imagettftext($img, 14, 0, 500, 160, $cyan, $font, '[ SYSTEM VERIFIED ]');
        imagettftext($img, 52, 0, 320, 240, $white, $font, 'CYBER PROTOCOL');
        imagefilledrectangle($img, 400, 260, 880, 262, $purple);
        imagettftext($img, 12, 0, 480, 330, $lightGray, $font, 'NODAL RECIPIENT NODE ADDRESS:');
        imagettftext($img, 42, 0, 310, 410, $cyanLite, $font, 'Ism Familiya');
        
        imagerectangle($img, 280, 350, 1000, 430, $cyan);
        imagerectangle($img, 278, 348, 1002, 432, $purple);

        imagettftext($img, 13, 0, 490, 490, $lightGray, $font, 'COMPLETED SECURITY COMPLIANCE:');
        imagettftext($img, 26, 0, 360, 550, $purple, $font, 'Cybersecurity & Tech Course');

        imagettftext($img, 9, 0, 80, $H - 120, $cyan, $font, 'HASH STATUS: SECURE');
        imagettftext($img, 9, 0, 80, $H - 100, $lightGray, $font, 'ALGORITHM: SHA-256');
        imagettftext($img, 9, 0, $W - 250, $H - 120, $purple, $font, 'COMPLIANCE: ISO-27001');
        imagettftext($img, 9, 0, $W - 250, $H - 100, $lightGray, $font, 'VERIFIER: AUTONOMOUS');
    }

    return saveTemplate($img, 'cyberpunk');
}

// ───────────────────────────────────────────────────
// Cubic Bezier curve points generator for drawing smooth SVG paths via GD
// ───────────────────────────────────────────────────
function getCubicBezierPoints(int $x0, int $y0, int $x1, int $y1, int $x2, int $y2, int $x3, int $y3, int $numPoints = 60): array {
    $points = [];
    for ($i = 0; $i <= $numPoints; $i++) {
        $t = $i / $numPoints;
        $mt = 1 - $t;
        $x = $mt * $mt * $mt * $x0 + 3 * $mt * $mt * $t * $x1 + 3 * $mt * $t * $t * $x2 + $t * $t * $t * $x3;
        $y = $mt * $mt * $mt * $y0 + 3 * $mt * $mt * $t * $y1 + 3 * $mt * $t * $t * $y2 + $t * $t * $t * $y3;
        $points[] = (int)round($x);
        $points[] = (int)round($y);
    }
    return $points;
}

// ───────────────────────────────────────────────────
// Premium Landscape Waves (Qarshi davlat texnika universiteti mehmonlari uchun)
// ───────────────────────────────────────────────────
function tplLandscapeWaves(): string {
    global $W, $H, $font, $hasFont;
    $img = imagecreatetruecolor($W, $H);

    // 1. Off-white background
    $white = imagecolorallocate($img, 255, 255, 255);
    imagefill($img, 0, 0, $white);

    // Color definitions
    $teal      = imagecolorallocate($img, 27, 77, 79);      // #1b4d4f
    $tealDark  = imagecolorallocate($img, 13, 52, 54);      // #0d3436
    $gold      = imagecolorallocate($img, 191, 149, 63);    // #bf953f
    $goldSeal  = imagecolorallocate($img, 255, 223, 115);   // #FFDF73
    $grayText  = imagecolorallocate($img, 119, 119, 119);   // #777

    // 2. Double/thin gold frame (inset by 30px)
    imagesetthickness($img, 2);
    imagerectangle($img, 30, 30, $W - 30, $H - 30, $gold);
    imagesetthickness($img, 1);

    // 3. Top Right Swooshes (curves)
    // Wave 1: Teal
    $w1 = getCubicBezierPoints(741, 0, 912, 181, 1083, 60, 1280, 363, 60);
    $w1[] = 1280; $w1[] = 0;
    imagefilledpolygon($img, $w1, $teal);

    // Pattern (stipple dots)
    for ($x = 800; $x < 1280; $x += 16) {
        for ($y = 0; $y < 360; $y += 16) {
            if ($x > 850 && $y < ($x - 850) * 0.8) {
                imagefilledellipse($img, $x, $y, 3, 3, $gold);
            }
        }
    }

    // Wave 3: Gold gradient wave
    $w3 = getCubicBezierPoints(798, 0, 969, 218, 1117, 97, 1280, 423, 60);
    $w3[] = 1280; $w3[] = 0;
    for ($g = 0; $g < 8; $g++) {
        $col = imagecolorallocate($img, 164 + $g * 8, 123 + $g * 12, 34 + $g * 20);
        $w3_g = getCubicBezierPoints(798 + $g * 5, 0, 969 + $g * 4, 218 - $g * 2, 1117 + $g * 2, 97 - $g * 2, 1280, 423 - $g * 6, 60);
        $w3_g[] = 1280; $w3_g[] = 0;
        imagefilledpolygon($img, $w3_g, $col);
    }

    // Wave 4: TealDark
    $w4 = getCubicBezierPoints(855, 0, 1026, 242, 1140, 121, 1280, 363, 60);
    $w4[] = 1280; $w4[] = 0;
    imagefilledpolygon($img, $w4, $tealDark);

    // 4. Bottom Left Swooshes
    // Wave 1: Teal
    $b1 = getCubicBezierPoints(0, 544, 228, 725, 456, 786, 741, 960, 60);
    $b1[] = 0; $b1[] = 960;
    imagefilledpolygon($img, $b1, $teal);

    // Stipple dots
    for ($x = 0; $x < 650; $x += 16) {
        for ($y = 500; $y < 960; $y += 16) {
            if ($x < ($y - 500) * 1.2) {
                imagefilledellipse($img, $x, $y, 3, 3, $gold);
            }
        }
    }

    // Wave 3: Gold gradient wave
    $b3 = getCubicBezierPoints(0, 629, 228, 786, 513, 822, 798, 960, 60);
    $b3[] = 0; $b3[] = 960;
    for ($g = 0; $g < 8; $g++) {
        $col = imagecolorallocate($img, 164 + $g * 8, 123 + $g * 12, 34 + $g * 20);
        $b3_g = getCubicBezierPoints(0, 629 + $g * 6, 228 + $g * 4, 786 - $g * 2, 513 - $g * 2, 822 - $g * 2, 798 - $g * 8, 960, 60);
        $b3_g[] = 0; $b3_g[] = 960;
        imagefilledpolygon($img, $b3_g, $col);
    }

    // Wave 4: TealDark
    $b4 = getCubicBezierPoints(0, 701, 228, 822, 456, 846, 684, 960, 60);
    $b4[] = 0; $b4[] = 960;
    imagefilledpolygon($img, $b4, $tealDark);

    // Wave 5: Gold highlight
    $b5 = getCubicBezierPoints(0, 822, 171, 907, 399, 919, 570, 960, 60);
    $b5[] = 0; $b5[] = 960;
    for ($g = 0; $g < 5; $g++) {
        $col = imagecolorallocate($img, 164 + $g * 15, 123 + $g * 20, 34 + $g * 35);
        $b5_g = getCubicBezierPoints(0, 822 + $g * 8, 171 + $g * 4, 907 - $g * 2, 399 - $g * 2, 919 - $g * 2, 570 - $g * 15, 960, 60);
        $b5_g[] = 0; $b5_g[] = 960;
        imagefilledpolygon($img, $b5_g, $col);
    }

    // Wave 6: TealDark bottom highlight
    $b6 = getCubicBezierPoints(0, 858, 137, 943, 342, 943, 456, 960, 60);
    $b6[] = 0; $b6[] = 960;
    imagefilledpolygon($img, $b6, $tealDark);

    // 5. Gold Starburst Seal in Top-Right
    $scx = 1100;
    $scy = 130;
    $sr = 90;
    $pts = [];
    for ($i = 0; $i < 32; $i++) {
        $rr = $i % 2 === 0 ? $sr : $sr - 12;
        $a = $i * M_PI / 16;
        $pts[] = $scx + (int)($rr * cos($a));
        $pts[] = $scy + (int)($rr * sin($a));
    }
    imagefilledpolygon($img, $pts, $goldSeal);

    $innerR = $sr - 18;
    imagefilledellipse($img, $scx, $scy, $innerR * 2, $innerR * 2, $teal);
    imageellipse($img, $scx, $scy, ($innerR - 3) * 2, ($innerR - 3) * 2, $goldSeal);

    if ($hasFont) {
        imagettftext($img, 16, 0, $scx - 38, $scy - 12, $goldSeal, $font, 'Premium');
        imagettftext($img, 18, 0, $scx - 30, $scy + 14, $goldSeal, $font, 'QDTU');
        imagettftext($img, 9, 0, $scx - 26, $scy + 32, $goldSeal, $font, '2026 YIL');
        imagettftext($img, 10, 0, $scx - 22, $scy + 48, $goldSeal, $font, '★★★');
    }

    // 6. QDTU logo crest and university header in top-left
    $lcx = 90;
    $lcy = 80;
    $shield = [
        $lcx, $lcy - 30,
        $lcx + 25, $lcy - 30,
        $lcx + 25, $lcy,
        $lcx, $lcy + 30,
        $lcx - 25, $lcy,
        $lcx - 25, $lcy - 30
    ];
    imagefilledpolygon($img, $shield, $teal);
    imagepolygon($img, $shield, $gold);
    imagefilledellipse($img, $lcx, $lcy - 5, 12, 12, $gold);
    imagefilledrectangle($img, $lcx - 10, $lcy + 4, $lcx + 10, $lcy + 8, $gold);

    if ($hasFont) {
        imagettftext($img, 10, 0, 130, 72, $grayText, $font, "QARSHI DAVLAT");
        imagettftext($img, 10, 0, 130, 92, $grayText, $font, "TEXNIKA UNIVERSITETI");
    }

    // 7. Recipient Line
    imagefilledrectangle($img, 365, 430, 915, 432, $gold);

    // Signature separating dot
    imagefilledellipse($img, 560, 740, 8, 8, $gold);

    return saveTemplate($img, 'landscape_waves');
}

// ───────────────────────────────────────────────────
// Hammasini yaratish va DB ga qo'shish
// ───────────────────────────────────────────────────
$templates = [
    [
        'name'         => 'Academic Excellence',
        'description'  => 'Yashil-oltin akademik dizayn, graduation cap va klassik tipografiya.',
        'category'     => 'akademik',
        'is_premium'   => false,
        'generator'    => 'tplAcademicGreen',
        'fields'       => premiumFields([0, 510, 1000, 80, 38, '#0e5f3a', '{{recipient_name}}'], [0, 610, 1000, 50, 22, '#0e5f3a', '{{course_name}}']),
    ],
    [
        'name'         => 'Corporate Navy',
        'description'  => 'Korporativ to\'q ko\'k va kumush, professional ko\'rinish.',
        'category'     => 'korporativ',
        'is_premium'   => true,
        'generator'    => 'tplCorporateNavy',
        'fields'       => premiumFields([0, 370, 1000, 80, 38, '#192d50', '{{recipient_name}}'], [0, 510, 1000, 50, 22, '#192d50', '{{course_name}}']),
    ],
    [
        'name'         => 'Event Festive',
        'description'  => 'Tadbirlar uchun rangli, bayramona dizayn — to\'lqin va konfetti.',
        'category'     => 'tadbir',
        'is_premium'   => false,
        'generator'    => 'tplEventFestive',
        'fields'       => premiumFields([0, 550, 1000, 70, 36, '#a855f7', '{{recipient_name}}'], [0, 615, 1000, 40, 18, '#fb923c', '{{course_name}}']),
    ],
    [
        'name'         => 'Sports Champion',
        'description'  => 'Sport g\'oliblari uchun qizil-oltin medal dizayni.',
        'category'     => 'sport',
        'is_premium'   => true,
        'generator'    => 'tplSportsRed',
        'fields'       => premiumFieldsRight(450, 460, 38, '#1f2937', 540, 22, '#b91c1c'),
    ],
    [
        'name'         => 'Minimal Modern',
        'description'  => 'Minimalistik zamonaviy dizayn — oq fon, qora matn, sariq aksent.',
        'category'     => 'zamonaviy',
        'is_premium'   => false,
        'generator'    => 'tplMinimalModern',
        'fields'       => premiumFieldsLeft(50, 540, 40, '#111827', 670, 22, '#111827'),
    ],
    [
        'name'         => 'Luxury Emerald Gold',
        'description'  => 'Zumrad yashil fon va boy oltin burchak naqshlari bilan bezatilgan shohona dizayn.',
        'category'     => 'korporativ',
        'is_premium'   => true,
        'generator'    => 'tplLuxuryEmerald',
        'fields'       => premiumFields([0, 500, 1000, 80, 38, '#ffffff', '{{recipient_name}}'], [0, 630, 1000, 50, 22, '#f4d75e', '{{course_name}}']),
    ],
    [
        'name'         => 'Royal Purple Velvet',
        'description'  => 'Royal binafsha gradient va sehrli oltin yulduzlar uyg\'unligidagi premium dizayn.',
        'category'     => 'zamonaviy',
        'is_premium'   => true,
        'generator'    => 'tplRoyalPurple',
        'fields'       => premiumFields([0, 460, 1000, 80, 38, '#ffffff', '{{recipient_name}}'], [0, 590, 1000, 50, 22, '#fda4b4', '{{course_name}}']),
    ],
    [
        'name'         => 'Futuristic Cyberpunk',
        'description'  => 'Glow neon cyan va binafsha kiber-panjara, yuqori texnologik va Dasturchilar uchun dizayn.',
        'category'     => 'zamonaviy',
        'is_premium'   => true,
        'generator'    => 'tplCyberpunk',
        'fields'       => premiumFields([0, 400, 1000, 80, 38, '#cffafe', '{{recipient_name}}'], [0, 540, 1000, 50, 22, '#a855f7', '{{course_name}}']),
    ],
    [
        'name'         => 'Premium Tashakkurnoma — Landscape Waves',
        'description'  => 'Qarshi davlat texnika universiteti mehmonlari uchun so\'ralgan abstrakt landshaft dizayn.',
        'category'     => 'akademik',
        'is_premium'   => true,
        'generator'    => 'tplLandscapeWaves',
        'fields'       => json_encode([
            ['id'=>1,'type'=>'text','variable'=>'','text'=>'Tashakkurnoma','x'=>240,'y'=>180,'w'=>800,'h'=>90,'fontSize'=>64,'color'=>'#111111','fontFamily'=>'Playfair Display','fontWeight'=>'bold','align'=>'center'],
            ['id'=>2,'type'=>'text','variable'=>'','text'=>'OLIY DARAJADAGI MINNATDORCHILIK','x'=>240,'y'=>275,'w'=>800,'h'=>30,'fontSize'=>13,'color'=>'#b38728','fontWeight'=>'bold','align'=>'center'],
            ['id'=>3,'type'=>'text','variable'=>'{{recipient_name}}','text'=>'Ism Familiya','x'=>140,'y'=>360,'w'=>1000,'h'=>70,'fontSize'=>44,'color'=>'#111111','fontFamily'=>'Playfair Display','fontWeight'=>'bold','align'=>'center'],
            ['id'=>4,'type'=>'text','variable'=>'{{course_name}}','text'=>'OʻZBEKISTON RESPUBLIKASI FAN ARBOBI, TARIX FANLARI DOKTORI, PROFESSOR','x'=>140,'y'=>455,'w'=>1000,'h'=>40,'fontSize'=>12,'color'=>'#1b4d4f','fontWeight'=>'bold','align'=>'center'],
            ['id'=>5,'type'=>'text','variable'=>'','text'=>'Tashkil etilgan “Akademik va yoshlar uchrashuvi” doirasidagi ishtirokingiz, yurtimiz boy tarixi, milliy davlatchilik asoslari hamda ilm-fan taraqqiyoti yuzasidan o\'rtoqlashgan qimmatli fikr-mulohazalaringiz talaba-yoshlarning ilmiy izlanishlarga boʻlgan qiziqishini oshirishda beqiyos ahamiyat kasb etdi.','x'=>240,'y'=>510,'w'=>800,'h'=>120,'fontSize'=>11,'color'=>'#666666','fontWeight'=>'normal','align'=>'center'],
            ['id'=>6,'type'=>'text','variable'=>'{{issued_date}}','text'=>'14-MAY, 2026','x'=>230,'y'=>700,'w'=>240,'h'=>30,'fontSize'=>14,'color'=>'#1b4d4f','fontWeight'=>'bold','align'=>'center'],
            ['id'=>7,'type'=>'text','variable'=>'','text'=>'TADBIR SANASI','x'=>230,'y'=>745,'w'=>240,'h'=>20,'fontSize'=>10,'color'=>'#333333','fontWeight'=>'bold','align'=>'center'],
            ['id'=>8,'type'=>'text','variable'=>'','text'=>'Sh. Nematov','x'=>650,'y'=>700,'w'=>240,'h'=>30,'fontSize'=>22,'color'=>'#1b4d4f','fontFamily'=>'Playfair Display','fontWeight'=>'normal','align'=>'center'],
            ['id'=>9,'type'=>'text','variable'=>'','text'=>'UNIVERSITET REKTORI','x'=>650,'y'=>745,'w'=>240,'h'=>20,'fontSize'=>10,'color'=>'#333333','fontWeight'=>'bold','align'=>'center']
        ])
    ]
];

function premiumFields(array $name, array $course): string {
    return json_encode([
        ['id'=>1,'type'=>'text','variable'=>$name[6],'text'=>'Ism Familiya',
         'x'=>$name[0]+140,'y'=>$name[1],'w'=>$name[2],'h'=>$name[3],'fontSize'=>$name[4],'color'=>$name[5],'fontWeight'=>'bold','align'=>'center'],
        ['id'=>2,'type'=>'text','variable'=>$course[6],'text'=>'Kurs nomi',
         'x'=>$course[0]+140,'y'=>$course[1],'w'=>$course[2],'h'=>$course[3],'fontSize'=>$course[4],'color'=>$course[5],'fontWeight'=>'bold','align'=>'center'],
        ['id'=>3,'type'=>'text','variable'=>'{{issued_date}}','text'=>'Sana',
         'x'=>140,'y'=>820,'w'=>260,'h'=>32,'fontSize'=>16,'color'=>'#374151','fontWeight'=>'normal','align'=>'left'],
        ['id'=>4,'type'=>'text','variable'=>'{{cert_id}}','text'=>'ID',
         'x'=>500,'y'=>820,'w'=>300,'h'=>32,'fontSize'=>14,'color'=>'#6b7280','fontWeight'=>'normal','align'=>'center'],
        ['id'=>5,'type'=>'qr','variable'=>'{{cert_id}}','text'=>'QR',
         'x'=>1110,'y'=>800,'w'=>120,'h'=>120,'fontSize'=>12,'color'=>'#111827','fontWeight'=>'normal','align'=>'center'],
    ]);
}

function premiumFieldsRight(int $nameY, int $courseY, int $nameSize, string $nameColor, int $cy, int $csize, string $ccolor): string {
    return json_encode([
        ['id'=>1,'type'=>'text','variable'=>'{{recipient_name}}','text'=>'Ism Familiya',
         'x'=>530,'y'=>$nameY,'w'=>520,'h'=>60,'fontSize'=>$nameSize,'color'=>$nameColor,'fontWeight'=>'bold','align'=>'left'],
        ['id'=>2,'type'=>'text','variable'=>'{{course_name}}','text'=>'Kurs nomi',
         'x'=>530,'y'=>$cy,'w'=>520,'h'=>40,'fontSize'=>$csize,'color'=>$ccolor,'fontWeight'=>'bold','align'=>'left'],
        ['id'=>3,'type'=>'text','variable'=>'{{issued_date}}','text'=>'Sana',
         'x'=>530,'y'=>880,'w'=>260,'h'=>30,'fontSize'=>16,'color'=>'#374151','fontWeight'=>'normal','align'=>'left'],
        ['id'=>4,'type'=>'text','variable'=>'{{cert_id}}','text'=>'ID',
         'x'=>820,'y'=>880,'w'=>200,'h'=>30,'fontSize'=>13,'color'=>'#6b7280','fontWeight'=>'normal','align'=>'left'],
        ['id'=>5,'type'=>'qr','variable'=>'{{cert_id}}','text'=>'QR',
         'x'=>1110,'y'=>120,'w'=>120,'h'=>120,'fontSize'=>12,'color'=>'#111827','fontWeight'=>'normal','align'=>'center'],
    ]);
}

function premiumFieldsLeft(int $x, int $nameY, int $nameSize, string $nameColor, int $cy, int $csize, string $ccolor): string {
    $h = 960;
    return json_encode([
        ['id'=>1,'type'=>'text','variable'=>'{{recipient_name}}','text'=>'Ism Familiya',
         'x'=>$x,'y'=>$nameY,'w'=>800,'h'=>60,'fontSize'=>$nameSize,'color'=>$nameColor,'fontWeight'=>'bold','align'=>'left'],
        ['id'=>2,'type'=>'text','variable'=>'{{course_name}}','text'=>'Kurs nomi',
         'x'=>$x,'y'=>$cy,'w'=>800,'h'=>40,'fontSize'=>$csize,'color'=>$ccolor,'fontWeight'=>'bold','align'=>'left'],
        ['id'=>3,'type'=>'text','variable'=>'{{issued_date}}','text'=>'Sana',
         'x'=>50,'y'=>830,'w'=>180,'h'=>30,'fontSize'=>14,'color'=>'#111827','fontWeight'=>'normal','align'=>'left'],
        ['id'=>4,'type'=>'text','variable'=>'{{cert_id}}','text'=>'ID',
         'x'=>240,'y'=>830,'w'=>200,'h'=>30,'fontSize'=>14,'color'=>'#111827','fontWeight'=>'normal','align'=>'left'],
        ['id'=>5,'type'=>'qr','variable'=>'{{cert_id}}','text'=>'QR',
         'x'=>1110,'y'=>$h - 220,'w'=>120,'h'=>120,'fontSize'=>12,'color'=>'#111827','fontWeight'=>'normal','align'=>'center'],
    ]);
}

// ───────────────────────────────────────────────────
$db = Database::getInstance();
$added = 0;
$skipped = 0;

foreach ($templates as $t) {
    $check = $db->prepare("SELECT id FROM templates WHERE name = ?");
    $check->execute([$t['name']]);
    if ($check->fetch()) {
        echo "Mavjud, o'tkazib yuborildi: {$t['name']}\n";
        $skipped++;
        continue;
    }

    $path = ($t['generator'])();
    $size = filesize(__DIR__ . '/../public/' . $path);

    $stmt = $db->prepare(
        "INSERT INTO templates (name, description, preview_url, file_url, category, is_premium, width, height, fields)
         VALUES (?, ?, ?, ?, ?, ?, 1280, 960, ?)
         RETURNING id"
    );
    $stmt->execute([
        $t['name'],
        $t['description'],
        $path,
        $path,
        $t['category'],
        $t['is_premium'] ? 't' : 'f',
        $t['fields'],
    ]);
    $id = $stmt->fetchColumn();
    echo "✓ Yaratildi: {$t['name']} (ID={$id}, {$size} bayt)\n";
    $added++;
}

echo "\nJami: {$added} ta yangi shablon, {$skipped} ta o'tkazib yuborildi.\n";
