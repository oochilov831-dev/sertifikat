<?php
namespace App\Helpers;

class JWT {
    private static function base64UrlEncode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', 3 - (3 + strlen($data)) % 4));
    }

    public static function encode(array $payload): string {
        $secret = env('JWT_SECRET');
        if (empty($secret) || $secret === 'default_secret') {
            throw new \RuntimeException('JWT_SECRET is not configured or insecure.');
        }
        $expire = (int) env('JWT_EXPIRE', 86400);

        $payload['iat'] = time();
        $payload['exp'] = time() + $expire;

        $header    = self::base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $payload   = self::base64UrlEncode(json_encode($payload));
        $signature = self::base64UrlEncode(hash_hmac('sha256', "{$header}.{$payload}", $secret, true));

        return "{$header}.{$payload}.{$signature}";
    }

    public static function decode(string $token): ?array {
        $secret = env('JWT_SECRET');
        if (empty($secret) || $secret === 'default_secret') {
            throw new \RuntimeException('JWT_SECRET is not configured or insecure.');
        }
        $parts  = explode('.', $token);

        if (count($parts) !== 3) return null;

        [$header, $payload, $signature] = $parts;

        $expectedSig = self::base64UrlEncode(hash_hmac('sha256', "{$header}.{$payload}", $secret, true));
        if (!hash_equals($expectedSig, $signature)) return null;

        $data = json_decode(self::base64UrlDecode($payload), true);
        if (!$data || $data['exp'] < time()) return null;

        return $data;
    }
}
