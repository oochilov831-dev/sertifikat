<?php
namespace App\Helpers;

/**
 * TOTP (RFC 6238) — Google Authenticator/Authy bilan mos.
 * Sof PHP, tashqi kutubxonasiz.
 */
final class TOTP {

    /** 32-belgi base32 secret yaratish */
    public static function generateSecret(int $length = 32): string {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= $alphabet[random_int(0, 31)];
        }
        return $secret;
    }

    /** TOTP kod hisoblash (6 raqamli) */
    public static function code(string $secret, ?int $timestamp = null, int $period = 30, int $digits = 6): string {
        $timestamp = $timestamp ?? time();
        $counter = pack('N*', 0) . pack('N*', (int)floor($timestamp / $period));

        $key = self::base32Decode($secret);
        $hash = hash_hmac('sha1', $counter, $key, true);

        $offset = ord($hash[19]) & 0xf;
        $value  = ((ord($hash[$offset])     & 0x7f) << 24)
                | ((ord($hash[$offset + 1]) & 0xff) << 16)
                | ((ord($hash[$offset + 2]) & 0xff) << 8)
                | ( ord($hash[$offset + 3]) & 0xff);

        $code = $value % 10 ** $digits;
        return str_pad((string)$code, $digits, '0', STR_PAD_LEFT);
    }

    /** Kodni tekshirish (±1 vaqt oynasi tolerantligi bilan) */
    public static function verify(string $secret, string $code, int $period = 30, int $window = 1): bool {
        $code = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{6}$/', $code)) return false;

        $now = time();
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::code($secret, $now + $i * $period, $period), $code)) {
                return true;
            }
        }
        return false;
    }

    /** otpauth:// URI — QR kod uchun */
    public static function uri(string $secret, string $accountName, string $issuer = 'Sertifikat Tizimi'): string {
        $label = rawurlencode($issuer) . ':' . rawurlencode($accountName);
        $params = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'digits' => 6,
            'period' => 30,
        ]);
        return "otpauth://totp/{$label}?{$params}";
    }

    /** Recovery codes — 10 ta, har biri 8 belgi */
    public static function generateRecoveryCodes(int $count = 10): array {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtolower(substr(bin2hex(random_bytes(5)), 0, 4)) . '-' .
                       strtolower(substr(bin2hex(random_bytes(5)), 0, 4));
        }
        return $codes;
    }

    private static function base32Decode(string $input): string {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $input    = rtrim(strtoupper($input), '=');
        $output   = '';
        $bits = 0; $value = 0;

        for ($i = 0; $i < strlen($input); $i++) {
            $pos = strpos($alphabet, $input[$i]);
            if ($pos === false) continue;
            $value = ($value << 5) | $pos;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $output .= chr(($value >> $bits) & 0xff);
            }
        }
        return $output;
    }
}
