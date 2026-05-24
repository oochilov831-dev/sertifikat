<?php
namespace App\Services;

/**
 * QR Code generator — faqat PHP GD, tashqi kutubxonasiz.
 * ISO/IEC 18004 standartiga mos: finder patterns, timing, alignment, format info,
 * Reed-Solomon, mask selection. Versiya 1–10, xato darajasi M.
 */
class QrGenerator {

    // ----------------------------------------------------------------
    // GF(256) jadvallari
    // ----------------------------------------------------------------
    private static array $EXP = [];
    private static array $LOG = [];

    private static function gfInit(): void {
        if (!empty(self::$EXP)) return;
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$EXP[$i] = $x;
            self::$LOG[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) $x ^= 0x11D;
        }
        self::$EXP[255] = self::$EXP[0];
    }

    private static function gfMul(int $a, int $b): int {
        if ($a === 0 || $b === 0) return 0;
        self::gfInit();
        return self::$EXP[(self::$LOG[$a] + self::$LOG[$b]) % 255];
    }

    private static function gfPow(int $x, int $n): int {
        self::gfInit();
        return self::$EXP[(self::$LOG[$x] * $n) % 255];
    }

    // ----------------------------------------------------------------
    // Ommaviy metod
    // ----------------------------------------------------------------
    public static function savePng(string $text, string $file, int $scale = 8, int $quiet = 4): bool {
        $matrix = self::buildQr($text);
        if ($matrix === null) return false;

        $n     = count($matrix);
        $total = ($n + $quiet * 2) * $scale;
        $img   = imagecreatetruecolor($total, $total);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);

        imagefill($img, 0, 0, $white);

        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                if ($matrix[$r][$c] === 1) {
                    $x1 = ($c + $quiet) * $scale;
                    $y1 = ($r + $quiet) * $scale;
                    imagefilledrectangle($img, $x1, $y1,
                        $x1 + $scale - 1, $y1 + $scale - 1, $black);
                }
            }
        }

        $ok = imagepng($img, $file) !== false;

        return $ok;
    }

    // ----------------------------------------------------------------
    // Asosiy QR qurish
    // ----------------------------------------------------------------
    private static function buildQr(string $text): ?array {
        $bytes   = array_values(unpack('C*', $text));
        $version = self::chooseVersion(count($bytes));
        if ($version === null) return null;

        $size = 17 + 4 * $version;

        // Bitstream va kodewordlar
        $bits      = self::makeBitstream($bytes, $version);
        $codewords = self::bitsToCodewords($bits, $version);

        // m: modul qiymatlari (-1 = bo'sh)
        // f: funksional naqsh belgisi (true = o'zgarmas)
        $m = array_fill(0, $size, array_fill(0, $size, -1));
        $f = array_fill(0, $size, array_fill(0, $size, false));

        // Funksional naqshlar
        self::putFinder($m, $f, 0, 0);
        self::putFinder($m, $f, 0, $size - 7);
        self::putFinder($m, $f, $size - 7, 0);
        self::putSeparators($m, $f, $size);
        self::putTiming($m, $f, $size);
        self::putDarkModule($m, $f, $version);
        self::putAlignment($m, $f, $version);
        self::reserveFormat($m, $f, $size);

        // Ma'lumot bitlarini joylashtirish
        self::placeData($m, $f, $codewords, $size);

        // Eng yaxshi mask tanlash va format yozish
        return self::chooseMask($m, $f, $size, $version);
    }

    // ----------------------------------------------------------------
    // Versiya tanlash (byte mode, M darajasi).
    // Bu generator 1 blokli QR versiyalarini ishlatadi; sertifikat havolalari
    // qisqa bo'lgani uchun v1-v3 yetarli va skanerlarda barqarorroq chiqadi.
    // ----------------------------------------------------------------
    private static function chooseVersion(int $len): ?int {
        $cap = [1=>14, 2=>26, 3=>42];
        foreach ($cap as $v => $c) {
            if ($len <= $c) return $v;
        }
        return null;
    }

    // ----------------------------------------------------------------
    // Bitstream (byte mode)
    // ----------------------------------------------------------------
    private static function makeBitstream(array $bytes, int $version): array {
        $bits = [];
        self::pushBits($bits, 0b0100, 4);       // mode: byte
        self::pushBits($bits, count($bytes), 8); // length (v1-9 uchun 8 bit)
        foreach ($bytes as $b) self::pushBits($bits, $b, 8);

        $cap = self::dataCapacity($version) * 8;

        // Terminator
        for ($i = 0; $i < 4 && count($bits) < $cap; $i++) $bits[] = 0;
        // 8 bitga to'ldir
        while (count($bits) % 8) $bits[] = 0;
        // Padding baytlar
        $pads = [0xEC, 0x11];
        $pi   = 0;
        while (count($bits) < $cap) self::pushBits($bits, $pads[$pi++ % 2], 8);

        return $bits;
    }

    private static function pushBits(array &$bits, int $val, int $n): void {
        for ($i = $n - 1; $i >= 0; $i--) $bits[] = ($val >> $i) & 1;
    }

    private static function dataCapacity(int $v): int {
        return [1=>16, 2=>28, 3=>44][$v];
    }

    private static function ecCapacity(int $v): int {
        return [1=>10, 2=>16, 3=>26][$v];
    }

    // ----------------------------------------------------------------
    // RS kodlash + kodewordlar
    // ----------------------------------------------------------------
    private static function bitsToCodewords(array $bits, int $version): array {
        $dataCw = self::dataCapacity($version);
        $ecCw   = self::ecCapacity($version);

        $data = [];
        for ($i = 0; $i < $dataCw; $i++) {
            $b = 0;
            for ($j = 0; $j < 8; $j++) $b = ($b << 1) | ($bits[$i*8+$j] ?? 0);
            $data[] = $b;
        }

        $ec = self::rsEncode($data, $ecCw);
        return array_merge($data, $ec);
    }

    private static function rsEncode(array $data, int $n): array {
        $gen = self::rsGenerator($n);
        $msg = array_merge($data, array_fill(0, $n, 0));
        for ($i = 0; $i < count($data); $i++) {
            $c = $msg[$i];
            if ($c === 0) continue;
            for ($j = 0; $j <= $n; $j++) {
                $msg[$i+$j] ^= self::gfMul($gen[$j], $c);
            }
        }
        return array_slice($msg, count($data));
    }

    private static function rsGenerator(int $n): array {
        $g = [1];
        for ($i = 0; $i < $n; $i++) {
            $p2i = self::gfPow(2, $i);
            $newg = array_fill(0, count($g) + 1, 0);
            foreach ($g as $pi => $pv) {
                $newg[$pi]   ^= $pv;
                $newg[$pi+1] ^= self::gfMul($pv, $p2i);
            }
            $g = $newg;
        }
        return $g;
    }

    // ----------------------------------------------------------------
    // Finder pattern — 7×7
    // ----------------------------------------------------------------
    private static function putFinder(array &$m, array &$f, int $row, int $col): void {
        for ($r = 0; $r < 7; $r++) {
            for ($c = 0; $c < 7; $c++) {
                $outer = ($r === 0 || $r === 6 || $c === 0 || $c === 6);
                $inner = ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4);
                $v = ($outer || $inner) ? 1 : 0;
                $m[$row+$r][$col+$c] = $v;
                $f[$row+$r][$col+$c] = true;
            }
        }
    }

    // ----------------------------------------------------------------
    // Separatorlar
    // ----------------------------------------------------------------
    private static function putSeparators(array &$m, array &$f, int $size): void {
        // Yuqori-chap
        for ($i = 0; $i < 8; $i++) {
            self::setFixed($m, $f, 7, $i, 0);
            self::setFixed($m, $f, $i, 7, 0);
        }
        // Yuqori-o'ng
        for ($i = 0; $i < 8; $i++) {
            self::setFixed($m, $f, 7, $size-8+$i, 0);
            self::setFixed($m, $f, $i, $size-8, 0);
        }
        // Pastki-chap
        for ($i = 0; $i < 8; $i++) {
            self::setFixed($m, $f, $size-8, $i, 0);
            self::setFixed($m, $f, $size-8+$i, 7, 0);
        }
    }

    // ----------------------------------------------------------------
    // Timing patterns
    // ----------------------------------------------------------------
    private static function putTiming(array &$m, array &$f, int $size): void {
        for ($i = 8; $i < $size-8; $i++) {
            $v = ($i % 2 === 0) ? 1 : 0;
            self::setFixed($m, $f, 6, $i, $v);
            self::setFixed($m, $f, $i, 6, $v);
        }
    }

    // ----------------------------------------------------------------
    // Dark module
    // ----------------------------------------------------------------
    private static function putDarkModule(array &$m, array &$f, int $version): void {
        $r = 4*$version + 9;
        $m[$r][8] = 1;
        $f[$r][8] = true;
    }

    // ----------------------------------------------------------------
    // Alignment patterns (versiya 2+)
    // ----------------------------------------------------------------
    private static function putAlignment(array &$m, array &$f, int $version): void {
        $coords = self::alignCoords($version);
        foreach ($coords as $r) {
            foreach ($coords as $c) {
                if ($f[$r][$c]) continue; // finder bilan ustma-ust
                for ($dr = -2; $dr <= 2; $dr++) {
                    for ($dc = -2; $dc <= 2; $dc++) {
                        $v = (abs($dr)===2 || abs($dc)===2 || ($dr===0 && $dc===0)) ? 1 : 0;
                        $m[$r+$dr][$c+$dc] = $v;
                        $f[$r+$dr][$c+$dc] = true;
                    }
                }
            }
        }
    }

    private static function alignCoords(int $v): array {
        return match($v) {
            1  => [],
            2  => [6, 18],
            3  => [6, 22],
            4  => [6, 26],
            5  => [6, 30],
            6  => [6, 34],
            7  => [6, 22, 38],
            8  => [6, 24, 42],
            9  => [6, 26, 46],
            10 => [6, 28, 50],
            default => [],
        };
    }

    // ----------------------------------------------------------------
    // Format area ni rezerv qilish (masking oldidan)
    // ----------------------------------------------------------------
    private static function reserveFormat(array &$m, array &$f, int $size): void {
        // Yuqori-chap: row 8, col 0-8 va row 0-8, col 8
        for ($i = 0; $i <= 8; $i++) {
            self::setFixed($m, $f, 8, $i, 0);
            self::setFixed($m, $f, $i, 8, 0);
        }
        // Yuqori-o'ng: row 8, col $size-1 dan $size-8 gacha
        for ($i = 0; $i < 8; $i++) {
            self::setFixed($m, $f, 8, $size-1-$i, 0);
        }
        // Pastki-chap: col 8, row $size-7 dan $size-1 gacha
        for ($i = 0; $i < 7; $i++) {
            self::setFixed($m, $f, $size-1-$i, 8, 0);
        }
    }

    // ----------------------------------------------------------------
    // Ma'lumot bitlarini joylashtirish (zigzag)
    // ----------------------------------------------------------------
    private static function placeData(array &$m, array &$f, array $codewords, int $size): void {
        $bits = [];
        foreach ($codewords as $cw) {
            for ($i = 7; $i >= 0; $i--) $bits[] = ($cw >> $i) & 1;
        }

        $idx = 0;
        $up  = true;

        for ($col = $size-1; $col >= 1; $col -= 2) {
            if ($col === 6) $col = 5; // timing ustunini o'tkazib yuborish

            for ($i = 0; $i < $size; $i++) {
                $row = $up ? ($size-1-$i) : $i;
                for ($dc = 0; $dc <= 1; $dc++) {
                    $c = $col - $dc;
                    if ($c < 0 || $f[$row][$c]) continue;
                    $m[$row][$c] = ($idx < count($bits)) ? $bits[$idx++] : 0;
                }
            }
            $up = !$up;
        }
    }

    // ----------------------------------------------------------------
    // Mask tanlash
    // ----------------------------------------------------------------
    private static function chooseMask(array $m, array $f, int $size, int $version): array {
        $best      = null;
        $bestScore = PHP_INT_MAX;

        for ($mask = 0; $mask < 8; $mask++) {
            $tmp = self::applyMask($m, $f, $size, $mask);
            self::writeFormat($tmp, $size, $mask);
            $score = self::penalty($tmp, $size);
            if ($score < $bestScore) {
                $bestScore = $score;
                $best      = $tmp;
            }
        }

        return $best;
    }

    private static function applyMask(array $m, array $f, int $size, int $mask): array {
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if ($f[$r][$c]) continue; // funksional naqsh — o'zgartirma
                if ($m[$r][$c] === -1) continue; // bo'sh hujayra
                if (self::maskCond($mask, $r, $c)) $m[$r][$c] ^= 1;
            }
        }
        return $m;
    }

    private static function maskCond(int $mask, int $r, int $c): bool {
        return match ($mask) {
            0 => ($r + $c) % 2 === 0,
            1 => $r % 2 === 0,
            2 => $c % 3 === 0,
            3 => ($r + $c) % 3 === 0,
            4 => (intdiv($r, 2) + intdiv($c, 3)) % 2 === 0,
            5 => ($r * $c) % 2 + ($r * $c) % 3 === 0,
            6 => (($r * $c) % 2 + ($r * $c) % 3) % 2 === 0,
            7 => (($r + $c) % 2 + ($r * $c) % 3) % 2 === 0,
        };
    }

    // ----------------------------------------------------------------
    // Format so'zini yozish (EC=M, BCH, XOR 0x5412)
    // ----------------------------------------------------------------
    private static function writeFormat(array &$m, int $size, int $mask): void {
        // EC darajasi M = 0b01
        $data = (0b01 << 3) | ($mask & 0x07);
        $rem  = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) & 1 ? 0x537 : 0);
        }
        $fmt  = (($data << 10) | ($rem & 0x3FF)) ^ 0x5412;

        // 15 bit (14 dan 0 gacha)
        $bits = [];
        for ($i = 14; $i >= 0; $i--) $bits[] = ($fmt >> $i) & 1;

        // Yuqori-chap joylar: row=8 bo'ylab 0..8 (6 ni o'tkazib), col=8 bo'ylab 7..0 (6 ni o'tkazib)
        // Standart pozitsiyalar:
        $pos1 = [
            [8,0],[8,1],[8,2],[8,3],[8,4],[8,5],[8,7],[8,8],
            [7,8],[5,8],[4,8],[3,8],[2,8],[1,8],[0,8]
        ];
        $pos2 = [
            [$size-1,8],[$size-2,8],[$size-3,8],[$size-4,8],
            [$size-5,8],[$size-6,8],[$size-7,8],
            [8,$size-8],[8,$size-7],[8,$size-6],[8,$size-5],
            [8,$size-4],[8,$size-3],[8,$size-2],[8,$size-1]
        ];

        foreach ($pos1 as $i => [$r, $c]) $m[$r][$c] = $bits[$i];
        foreach ($pos2 as $i => [$r, $c]) $m[$r][$c] = $bits[$i];

        // Dark module doim 1
        $m[$size-8][8] = 1;
    }

    // ----------------------------------------------------------------
    // Penalty score
    // ----------------------------------------------------------------
    private static function penalty(array $m, int $size): int {
        $score = 0;

        // P1: 5+ ketma-ket bir xil modullar
        for ($r = 0; $r < $size; $r++) {
            $run = 1;
            for ($c = 1; $c < $size; $c++) {
                if ($m[$r][$c] === $m[$r][$c-1]) { $run++; if ($run === 5) $score += 3; elseif ($run > 5) $score++; }
                else $run = 1;
            }
        }
        for ($c = 0; $c < $size; $c++) {
            $run = 1;
            for ($r = 1; $r < $size; $r++) {
                if ($m[$r][$c] === $m[$r-1][$c]) { $run++; if ($run === 5) $score += 3; elseif ($run > 5) $score++; }
                else $run = 1;
            }
        }

        // P2: 2×2 bir xil rang bloklari
        for ($r = 0; $r < $size-1; $r++) {
            for ($c = 0; $c < $size-1; $c++) {
                $v = $m[$r][$c];
                if ($v === $m[$r][$c+1] && $v === $m[$r+1][$c] && $v === $m[$r+1][$c+1])
                    $score += 3;
            }
        }

        // P3: finder-ga o'xshash naqshlar (gorizontal va vertikal)
        $p1 = [1,0,1,1,1,0,1,0,0,0,0];
        $p2 = [0,0,0,0,1,0,1,1,1,0,1];
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c <= $size-11; $c++) {
                $h1=$h2=true;
                for ($k = 0; $k < 11; $k++) {
                    if ($m[$r][$c+$k] !== $p1[$k]) $h1=false;
                    if ($m[$r][$c+$k] !== $p2[$k]) $h2=false;
                }
                if ($h1||$h2) $score+=40;
            }
        }
        for ($c = 0; $c < $size; $c++) {
            for ($r = 0; $r <= $size-11; $r++) {
                $v1=$v2=true;
                for ($k = 0; $k < 11; $k++) {
                    if ($m[$r+$k][$c] !== $p1[$k]) $v1=false;
                    if ($m[$r+$k][$c] !== $p2[$k]) $v2=false;
                }
                if ($v1||$v2) $score+=40;
            }
        }

        // P4: qora/oq nisbati
        $total = $size*$size;
        $dark  = 0;
        foreach ($m as $row) foreach ($row as $v) if ($v===1) $dark++;
        $pct   = intdiv($dark*100, $total);
        $p5    = intdiv($pct, 5)*5;
        $score += min(abs($p5-50), abs($p5+5-50))*2;

        return $score;
    }

    // ----------------------------------------------------------------
    // Yordamchi
    // ----------------------------------------------------------------
    private static function setFixed(array &$m, array &$f, int $r, int $c, int $v): void {
        if (!$f[$r][$c]) {
            $m[$r][$c] = $v;
            $f[$r][$c] = true;
        }
    }
}
