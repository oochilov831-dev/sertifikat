<?php
namespace App\Helpers;

/**
 * Hujjat turlarini boshqarish — sertifikat, diplom, tashakkurnoma, faxriy yorliq, maqtov yorliq.
 */
final class DocType {
    public const TYPES = [
        'certificate' => [
            'name'      => 'Sertifikat',
            'prefix'    => 'S',
            'icon'      => '🎓',
            'title'     => 'Sertifikat',
            'subtitle'  => 'Rasmiy',
            'badge_bg'  => '#eef2ff',
            'badge_fg'  => '#4338ca',
            'present_text' => 'Muvaffaqiyatli tugatganligi uchun',
        ],
        'diploma' => [
            'name'      => 'Diplom',
            'prefix'    => 'D',
            'icon'      => '📜',
            'title'     => 'Diplom',
            'subtitle'  => 'Davlat namunasidagi',
            'badge_bg'  => '#fef3c7',
            'badge_fg'  => '#92400e',
            'present_text' => 'Quyidagi yo\'nalishni muvaffaqiyatli tamomladi',
        ],
        'gratitude' => [
            'name'      => 'Tashakkurnoma',
            'prefix'    => 'T',
            'icon'      => '🤝',
            'title'     => 'Tashakkurnoma',
            'subtitle'  => 'Hurmat va minnatdorchilik',
            'badge_bg'  => '#dcfce7',
            'badge_fg'  => '#15803d',
            'present_text' => 'Faol ishtirok va munosib hissasi uchun',
        ],
        'honor' => [
            'name'      => 'Faxriy yorliq',
            'prefix'    => 'FY',
            'icon'      => '🏅',
            'title'     => 'Faxriy yorliq',
            'subtitle'  => 'Faxriy mukofot',
            'badge_bg'  => '#fee2e2',
            'badge_fg'  => '#991b1b',
            'present_text' => 'Yuksak natijalar va xizmatlari uchun',
        ],
        'commendation' => [
            'name'      => 'Maqtov yorlig\'i',
            'prefix'    => 'MY',
            'icon'      => '🌟',
            'title'     => 'Maqtov yorlig\'i',
            'subtitle'  => 'Tan olish va e\'tirof',
            'badge_bg'  => '#fef9c3',
            'badge_fg'  => '#854d0e',
            'present_text' => 'Namunali harakat va yutuqlari uchun',
        ],
    ];

    public static function normalize(?string $type): string {
        $type = (string)$type;
        return isset(self::TYPES[$type]) ? $type : 'certificate';
    }

    public static function get(string $type, string $key, string $default = ''): string {
        $type = self::normalize($type);
        return (string)(self::TYPES[$type][$key] ?? $default);
    }

    public static function info(string $type): array {
        return self::TYPES[self::normalize($type)];
    }
}
