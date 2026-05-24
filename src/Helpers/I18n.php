<?php
namespace App\Helpers;

class I18n {
    private static ?array $translations = null;
    private static string $lang = 'uz';

    public static function init(): void {
        if (self::$translations !== null) return;

        // Tilni aniqlash: query param -> cookie -> default (uz)
        $lang = $_GET['lang'] ?? $_COOKIE['lang'] ?? 'uz';
        $lang = in_array($lang, ['uz', 'ru', 'en'], true) ? $lang : 'uz';
        
        self::$lang = $lang;

        // Cookieni 1 yilga saqlash
        if (!isset($_COOKIE['lang']) || $_COOKIE['lang'] !== $lang) {
            setcookie('lang', $lang, time() + 365 * 86400, '/', '', false, false);
        }

        $file = __DIR__ . "/../Lang/{$lang}.php";
        if (file_exists($file)) {
            self::$translations = require $file;
        } else {
            self::$translations = [];
        }
    }

    public static function getLang(): string {
        self::init();
        return self::$lang;
    }

    public static function t(string $key, array $replace = []): string {
        self::init();
        
        $value = self::$translations[$key] ?? $key;

        foreach ($replace as $k => $v) {
            $value = str_replace("{{{$k}}}", (string)$v, $value);
        }

        return $value;
    }
}

// Global translator helper shortcut
if (!function_exists('__')) {
    function __(string $key, array $replace = []): string {
        return \App\Helpers\I18n::t($key, $replace);
    }
}
