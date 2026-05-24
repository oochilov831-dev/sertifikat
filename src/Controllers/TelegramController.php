<?php
namespace App\Controllers;

use PDO;
use App\Services\AuditLogger;

class TelegramController {
    private PDO $db;

    public function __construct() {
        $this->db = \Database::getInstance();
    }

    // POST /api/telegram/webhook
    public function webhook(): never {
        $input = file_get_contents('php://input');
        $update = json_decode($input, true) ?? [];

        if (empty($update['message'])) {
            // Agar xabar bo'lmasa, shunchaki OK qaytaramiz (masalan, webhook tasdiqlash uchun)
            success(null, 'OK');
        }

        $message = $update['message'];
        $chatId  = (int)($message['chat']['id'] ?? 0);
        $text    = trim((string)($message['text'] ?? ''));

        if ($chatId <= 0) {
            success(null, 'OK');
        }

        if (str_starts_with($text, '/start')) {
            $this->sendStartMessage($chatId);
        } else {
            // Sertifikat ID sini tekshirish
            $this->handleCertificateLookup($chatId, $text);
        }

        success(null, 'OK');
    }

    private function sendStartMessage(int $chatId): void {
        $msg = "🎓 *Sertifikat Tekshirish Botiga xush kelibsiz!*\n\n"
             . "Ushbu bot yordamida siz tizimimiz orqali berilgan sertifikatlar, diplomlar va yorliqlarni haqiqiyligini tekshirishingiz mumkin.\n\n"
             . "🔍 *Sertifikatni tekshirish uchun:*\n"
             . "Sertifikat ID raqamini to'g'ridan-to'g'ri yuboring (masalan: `S-A1B2C3D4`).";
        
        $this->sendMessage($chatId, $msg);
    }

    private function handleCertificateLookup(int $chatId, string $text): void {
        // ID formatini tozalash (agar foydalanuvchi bo'shliqlar bilan yozgan bo'lsa)
        $certId = strtoupper(trim(preg_replace('/\s+/', '', $text)));
        
        // Agar /verify CERT-XXXX ko'rinishida bo'lsa
        if (preg_match('/^\/verify\s+(.+)$/i', $text, $matches)) {
            $certId = strtoupper(trim($matches[1]));
        }

        // Sertifikat ID regex tekshiruvi (S-XXXXXXXX, D-XXXXXXXX, CERT-XXXXXXXX va h.k.)
        if (!preg_match('/^(S|D|T|FY|MY|CERT|DIPL|TASH|FAXR|MAQT)-[A-Z0-9]{8}$/i', $certId)) {
            $this->sendMessage($chatId, "⚠️ *Xato format.*\n\nIltimos, sertifikat ID raqamini to'g'ri formatda yuboring.\nNamuna: `S-A1B2C3D4` Yoki `D-DAFCD8DB`.");
            return;
        }

        // Bazadan sertifikatni qidirish
        $stmt = $this->db->prepare(
            'SELECT c.*, u.name as issuer_name, u.company as issuer_company
             FROM certificates c
             JOIN users u ON u.id = c.user_id
             WHERE c.cert_id = ?'
        );
        $stmt->execute([$certId]);
        $cert = $stmt->fetch();

        if (!$cert) {
            $this->sendMessage($chatId, "❌ *Sertifikat topilmadi!*\n\n`{$certId}` identifikatorli hujjat tizimda ro'yxatdan o'tmagan. ID raqami to'g'ri kiritilganini tekshiring.");
            return;
        }

        // Hujjat turi
        $docType = match($cert['doc_type']) {
            'diploma'      => '📜 Diplom',
            'gratitude'    => '🤝 Tashakkurnoma',
            'honor'        => '🏅 Faxriy yorliq',
            'commendation' => '🌟 Maqtov yorlig\'i',
            default        => '🎓 Sertifikat'
        };

        // Status
        $status = '✅ Haqiqiy (Faol)';
        if (empty($cert['is_valid'])) {
            $status = '❌ Bekor qilingan';
            if (!empty($cert['revoke_reason'])) {
                $status .= " (Sababi: " . htmlspecialchars($cert['revoke_reason']) . ")";
            }
        } elseif (!empty($cert['expiry_date']) && strtotime($cert['expiry_date'] . ' 23:59:59') < time()) {
            $status = '⏰ Amal qilish muddati tugagan';
        }

        $appUrl = env('APP_URL', 'https://sertifikat.uz');
        $pdfLink = $cert['file_pdf'] ? "{$appUrl}/" . ltrim($cert['file_pdf'], '/') : 'Mavjud emas';

        $msg = "{$docType} *tasdiqlandi!* ✨\n\n"
             . "🆔 *Hujjat ID:* `{$cert['cert_id']}`\n"
             . "👤 *Oluvchi ismi:* *{$cert['recipient_name']}*\n"
             . "📚 *Kurs/Mavzu:* *{$cert['course_name']}*\n"
             . "🏢 *Beruvchi tashkilot:* {$cert['issuer_company']}\n"
             . "📅 *Berilgan sana:* {$cert['issued_date']}\n"
             . "⏳ *Holati:* {$status}\n\n"
             . "🔗 *PDF faylni yuklab olish:* [Yuklab olish]({$pdfLink})";

        $this->sendMessage($chatId, $msg);

        // View sonini oshirish
        $this->db->prepare('UPDATE certificates SET view_count = view_count + 1 WHERE id = ?')
                 ->execute([$cert['id']]);
    }

    private function sendMessage(int $chatId, string $text): void {
        $token = env('TELEGRAM_BOT_TOKEN');
        if (!$token) return;

        $url = "https://api.telegram.org/bot{$token}/sendMessage";
        $data = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'Markdown',
            'disable_web_page_preview' => false
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        @curl_exec($ch);
        curl_close($ch);
    }
}
