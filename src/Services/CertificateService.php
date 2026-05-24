<?php
namespace App\Services;

use PDO;
use App\Models\SubscriptionModel;
use App\Helpers\DocType;

class CertificateService {
    private PDO $db;

    public function __construct() {
        $this->db = \Database::getInstance();
    }

    public function generate(int $userId, array $data): array {
        $subModel = new SubscriptionModel();
        if (!$subModel->tryIncrementUsed($userId)) {
            throw new \RuntimeException('Sertifikat limiti tugadi. Obunangizni yangilang.');
        }

        try {
            $docType  = DocType::normalize($data['doc_type'] ?? 'certificate');
            $data['doc_type'] = $docType;
            $certId   = $this->generateCertId(DocType::get($docType, 'prefix', 'CERT'));
            $qrPath   = $this->generateQrCode($certId);
            $fields   = $data['fields'] ?? [];
            $sub      = $subModel->getActive($userId);
            $watermark = $sub ? ($sub['plan'] === 'free') : true;
            $certHash = $this->makeCertHash($certId, $userId, $data);

            // Foydalanuvchi (issuer) ma'lumotlari — logo, imzo, muhr va tashkilot nomi
            $issuerStmt = $this->db->prepare('SELECT name, company, logo_url, signature_url, seal_url FROM users WHERE id = ?');
            $issuerStmt->execute([$userId]);
            $issuer = $issuerStmt->fetch() ?: [];
            $data['issuer_name']    ??= ($issuer['company'] ?? $issuer['name'] ?? 'Tashkilot');
            $data['issuer_company'] ??= ($issuer['company'] ?? null);
            $data['logo_path']        = !empty($issuer['logo_url']) ? $issuer['logo_url'] : null;
            $data['signature_path']   = !empty($issuer['signature_url']) ? $issuer['signature_url'] : null;
            $data['seal_path']        = !empty($issuer['seal_url']) ? $issuer['seal_url'] : null;

            $orientation = ($data['orientation'] ?? 'landscape') === 'portrait' ? 'portrait' : 'landscape';
            $data['orientation'] = $orientation;

            $stmt = $this->db->prepare(
                'INSERT INTO certificates
                    (cert_id, user_id, template_id, recipient_name, recipient_email,
                     course_name, issued_date, expiry_date, fields, qr_code, watermark, cert_hash, orientation, doc_type)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 RETURNING id, uuid, cert_id'
            );
            $stmt->execute([
                $certId,
                $userId,
                $data['template_id'] ?? null,
                $data['recipient_name'],
                $data['recipient_email'] ?? null,
                $data['course_name'] ?? null,
                $data['issued_date'] ?? date('Y-m-d'),
                $data['expiry_date'] ?? null,
                json_encode($fields),
                $qrPath,
                $watermark ? 1 : 0,
                $certHash,
                $orientation,
                $docType,
            ]);
            $cert = $stmt->fetch();
            $data['cert_id'] = $certId;
            $data['qr_path'] = $qrPath;
            $data['verify_url'] = "/verify.html?id={$certId}";
            $data['template_path'] = $this->resolveTemplatePath($data);

            // PNG avval yaratiladi, PDF esa shu tayyor rasm asosida yig'iladi.
            $pngPath = $this->renderPng($cert['id'], $data, $qrPath, $watermark);
            $pdfPath = $this->renderPdf($cert['id'], $data, $qrPath, $watermark);

            $this->db->prepare(
                'UPDATE certificates SET file_pdf = ?, file_png = ? WHERE id = ?'
            )->execute([$pdfPath, $pngPath, $cert['id']]);

            $result = [...$cert, 'file_pdf' => $pdfPath, 'file_png' => $pngPath, 'cert_id' => $certId, 'cert_hash' => $certHash];

            // Oluvchiga email yuborish (agar email bo'lsa)
            if (!empty($data['recipient_email'])) {
                $verifyUrl = "/verify.html?id={$certId}";
                @EmailService::sendCertificateCreated(
                    $data['recipient_email'],
                    $data['recipient_name'],
                    $data['course_name'] ?? 'Kurs',
                    $certId,
                    $verifyUrl
                );
            }

            return $result;
        } catch (\Throwable $e) {
            $subModel->decrementUsed($userId);
            throw $e;
        }
    }

    public function bulkGenerate(int $userId, array $rows, array $baseData): array {
        $results = [];
        foreach ($rows as $index => $row) {
            try {
                $data = [...$baseData, ...$row];
                if (empty($data['recipient_name'])) {
                    throw new \InvalidArgumentException('recipient_name majburiy');
                }
                $results[] = ['success' => true, 'row' => $row, 'cert' => $this->generate($userId, $data)];
            } catch (\Throwable $e) {
                $results[] = [
                    'success' => false,
                    'row' => $row,
                    'line' => $index + 2,
                    'error' => $e->getMessage(),
                ];
            }
        }
        return $results;
    }

    public function verify(string $certId, array $scanMeta = []): ?array {
        $certId = strtoupper(trim($certId));
        $stmt = $this->db->prepare(
            'SELECT c.*, u.name as issuer_name, u.company as issuer_company
             FROM certificates c
             JOIN users u ON u.id = c.user_id
             WHERE (c.cert_id = ? OR c.uuid::text = ?)'
        );
        $stmt->execute([$certId, $certId]);
        $cert = $stmt->fetch();

        if (!$cert) return null;
        $cert['trust_status'] = $this->trustStatus($cert);
        $cert['hash_valid'] = $this->verifyCertHash($cert);

        if (empty($scanMeta['no_log'])) {
            // view_count oshirish
            $this->db->prepare(
                'UPDATE certificates SET view_count = view_count + 1 WHERE id = ?'
            )->execute([$cert['id']]);

            // Skan logini saqlash
            $this->db->prepare(
                'INSERT INTO cert_scans (cert_id, ip, user_agent, referer, scanned_at)
                 VALUES (?, ?, ?, ?, NOW())'
            )->execute([
                $cert['id'],
                $scanMeta['ip']         ?? null,
                $scanMeta['user_agent'] ?? null,
                $scanMeta['referer']    ?? null,
            ]);
        }

        // Oxirgi 10 ta skan
        $stmt2 = $this->db->prepare(
            'SELECT ip, user_agent, scanned_at FROM cert_scans
             WHERE cert_id = ? ORDER BY scanned_at DESC LIMIT 10'
        );
        $stmt2->execute([$cert['id']]);
        $cert['recent_scans'] = $stmt2->fetchAll();

        return $cert;
    }

    private function makeCertHash(string $certId, int $userId, array $data): string {
        $payload = implode('|', [
            $certId,
            $userId,
            trim((string)($data['recipient_name'] ?? '')),
            trim((string)($data['course_name'] ?? '')),
            (string)($data['issued_date'] ?? date('Y-m-d')),
            (string)($data['expiry_date'] ?? ''),
        ]);
        return hash_hmac('sha256', $payload, env('JWT_SECRET', 'local_secret'));
    }

    private function verifyCertHash(array $cert): bool {
        if (empty($cert['cert_hash'])) return false;
        $expected = $this->makeCertHash((string)$cert['cert_id'], (int)$cert['user_id'], $cert);
        return hash_equals($expected, (string)$cert['cert_hash']);
    }

    private function trustStatus(array $cert): string {
        if (empty($cert['is_valid'])) return 'revoked';
        if (!empty($cert['expiry_date']) && strtotime((string)$cert['expiry_date'] . ' 23:59:59') < time()) {
            return 'expired';
        }
        return 'valid';
    }

    public function revoke(int $certId, int $userId, string $reason = ''): bool {
        $stmt = $this->db->prepare(
            'UPDATE certificates SET is_valid = false, revoked_at = NOW(), revoke_reason = ?
             WHERE id = ? AND user_id = ? RETURNING id'
        );
        $stmt->execute([$reason ?: null, $certId, $userId]);
        return (bool) $stmt->fetch();
    }

    public function restore(int $certId, int $userId): bool {
        $stmt = $this->db->prepare(
            'UPDATE certificates SET is_valid = true, revoked_at = NULL, revoke_reason = NULL
             WHERE id = ? AND user_id = ? RETURNING id'
        );
        $stmt->execute([$certId, $userId]);
        return (bool) $stmt->fetch();
    }

    public function getScanStats(int $certId, int $userId): array {
        // Egalik tekshiruvi
        $stmt = $this->db->prepare('SELECT id FROM certificates WHERE id = ? AND user_id = ?');
        $stmt->execute([$certId, $userId]);
        if (!$stmt->fetch()) return [];

        $stmt = $this->db->prepare(
            "SELECT
                COUNT(*) as total,
                COUNT(DISTINCT ip) as unique_ips,
                MAX(scanned_at) as last_scan,
                DATE_TRUNC('day', scanned_at) as day,
                COUNT(*) as day_count
             FROM cert_scans WHERE cert_id = ?
             GROUP BY DATE_TRUNC('day', scanned_at)
             ORDER BY day DESC LIMIT 30"
        );
        $stmt->execute([$certId]);
        $daily = $stmt->fetchAll();

        $stmt2 = $this->db->prepare(
            'SELECT COUNT(*) as total, COUNT(DISTINCT ip) as unique_ips, MAX(scanned_at) as last_scan
             FROM cert_scans WHERE cert_id = ?'
        );
        $stmt2->execute([$certId]);
        $totals = $stmt2->fetch();

        return ['totals' => $totals, 'daily' => $daily];
    }

    private function generateCertId(string $prefix = 'CERT'): string {
        $prefix = strtoupper(trim($prefix));
        do {
            $id   = "{$prefix}-" . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
            $stmt = $this->db->prepare('SELECT id FROM certificates WHERE cert_id = ?');
            $stmt->execute([$id]);
        } while ($stmt->fetch());

        return $id;
    }

    private function publicPath(string $relative): string {
        return __DIR__ . '/../../public/' . ltrim($relative, '/');
    }

    private function fontPath(): string {
        $candidates = [
            $this->publicPath('fonts/arial.ttf'),
            'C:/Windows/Fonts/arial.ttf',
            'C:/Windows/Fonts/segoeui.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) return $path;
        }

        return '';
    }

    private function isBoldWeight(mixed $weight): bool {
        $weight = strtolower(trim((string)$weight));
        if ($weight === 'bold' || $weight === 'bolder') return true;
        return is_numeric($weight) && (int)$weight >= 600;
    }

    private function tcpdfFontFamily(?string $fontFamily): string {
        $selected = strtolower(trim((string)$fontFamily));
        if (str_contains($selected, 'courier')) return 'courier';
        if (str_contains($selected, 'playfair') || str_contains($selected, 'georgia') || str_contains($selected, 'times')) return 'times';
        if (str_contains($selected, 'arial') || str_contains($selected, 'roboto') || str_contains($selected, 'montserrat') || str_contains($selected, 'segoe')) return 'helvetica';
        return 'dejavusans';
    }

    private function gdFontPath(?string $fontFamily = null, mixed $fontWeight = null): string {
        $selected = strtolower(trim((string)$fontFamily));
        $bold = $this->isBoldWeight($fontWeight);

        $families = [
            'courier' => $bold
                ? ['C:/Windows/Fonts/courbd.ttf', '/usr/share/fonts/truetype/liberation/LiberationMono-Bold.ttf']
                : ['C:/Windows/Fonts/cour.ttf', '/usr/share/fonts/truetype/liberation/LiberationMono-Regular.ttf'],
            'serif' => $bold
                ? ['C:/Windows/Fonts/georgiab.ttf', 'C:/Windows/Fonts/timesbd.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSerif-Bold.ttf']
                : ['C:/Windows/Fonts/georgia.ttf', 'C:/Windows/Fonts/times.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSerif.ttf'],
            'sans' => $bold
                ? ['C:/Windows/Fonts/arialbd.ttf', 'C:/Windows/Fonts/segoeuib.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf']
                : ['C:/Windows/Fonts/arial.ttf', 'C:/Windows/Fonts/segoeui.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'],
        ];

        $bucket = 'sans';
        if (str_contains($selected, 'courier')) {
            $bucket = 'courier';
        } elseif (str_contains($selected, 'playfair') || str_contains($selected, 'georgia') || str_contains($selected, 'times')) {
            $bucket = 'serif';
        }

        foreach ($families[$bucket] as $path) {
            if (is_file($path)) return $path;
        }

        return $this->fontPath();
    }

    private function resolveTemplatePath(array $data): ?string {
        if (!empty($data['template_path'])) {
            return $data['template_path'];
        }

        $templateId = (int)($data['template_id'] ?? 0);
        if ($templateId <= 0) {
            return null;
        }

        $stmt = $this->db->prepare(
            'SELECT file_url FROM templates WHERE id = ? AND is_active = true'
        );
        $stmt->execute([$templateId]);
        $path = $stmt->fetchColumn();

        return $path ? (string)$path : null;
    }

    private function generateQrCode(string $certId): string {
        require_once __DIR__ . '/QrGenerator.php';

        $url     = "/verify.html?id={$certId}";
        $dir     = $this->publicPath('uploads/certificates/qr');
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $filename = "{$dir}/{$certId}.png";

        QrGenerator::savePng($url, $filename, 8, 4);

        return "uploads/certificates/qr/{$certId}.png";
    }

    private function renderPdf(int $certId, array $data, string $qrPath, bool $watermark): string {
        $dir = $this->publicPath('uploads/certificates/pdf');
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $isPortrait = ($data['orientation'] ?? 'landscape') === 'portrait';
        $canvasW = $isPortrait ?  960 : 1280;
        $canvasH = $isPortrait ? 1280 :  960;

        $orientation = $isPortrait ? 'P' : 'L';
        
        // TCPDF instansiyasini yaratish
        $pdf = new \TCPDF($orientation, 'pt', [$canvasW, $canvasH], true, 'UTF-8', false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();

        // 1. Orqa fon shabloni (Template background)
        $tplPath = !empty($data['template_path']) ? $this->publicPath($data['template_path']) : null;
        if ($tplPath && file_exists($tplPath)) {
            $pdf->Image($tplPath, 0, 0, $canvasW, $canvasH, '', '', '', false, 300, '', false, false, 0);
        } else {
            // Standart orqa fon rangi va chegarasi
            $pdf->SetFillColor(245, 245, 240);
            $pdf->Rect(0, 0, $canvasW, $canvasH, 'F');
            
            $pdf->SetDrawColor(200, 168, 75);
            $pdf->SetLineWidth(4);
            $pdf->Rect(20, 20, $canvasW - 40, $canvasH - 40);
            $pdf->SetLineWidth(2);
            $pdf->Rect(24, 24, $canvasW - 48, $canvasH - 48);
        }

        $fields = is_array($data['fields'] ?? null) ? $data['fields'] : [];

        // 2. Maydonlarni (Fields) chizish
        if (!empty($fields)) {
            foreach ($fields as $field) {
                if (!is_array($field)) continue;

                $type = $field['type'] ?? 'text';
                $x = (float)($field['x'] ?? 0);
                $y = (float)($field['y'] ?? 0);
                $w = max(1.0, (float)($field['w'] ?? 400));
                $h = max(1.0, (float)($field['h'] ?? 60));

                if ($type === 'image' && !empty($field['src'])) {
                    $src = $this->publicPath($field['src']);
                    if (file_exists($src)) {
                        $pdf->Image($src, $x, $y, $w, $h, '', '', '', false, 300, '', false, false, 0);
                    }
                } elseif ($type === 'logo') {
                    // Draw user's logo at the field's position/size
                    if (!empty($data['logo_path']) && file_exists($this->publicPath((string)$data['logo_path']))) {
                        $pdf->Image($this->publicPath((string)$data['logo_path']), $x, $y, $w, $h, '', '', '', false, 300, '', false, false, 0);
                    }
                } elseif ($type === 'qr') {
                    if ($qrPath && file_exists($this->publicPath($qrPath))) {
                        $pdf->Image($this->publicPath($qrPath), $x, $y, $w, $h, '', '', '', false, 300, '', false, false, 0);
                    }
                } elseif ($type === 'seal') {
                    // If user has a real seal image, use it; otherwise draw vector seal
                    if (!empty($data['seal_path']) && file_exists($this->publicPath((string)$data['seal_path']))) {
                        $pdf->Image($this->publicPath((string)$data['seal_path']), $x, $y, $w, $h, '', '', '', false, 300, '', false, false, 0);
                    } else {
                        $color = $this->hexToRgb($field['color'] ?? '#0f766e');
                        $pdf->SetDrawColor($color[0], $color[1], $color[2]);
                        $pdf->SetTextColor($color[0], $color[1], $color[2]);
                        $pdf->SetLineWidth(3);
                        $cx = $x + $w / 2;
                        $cy = $y + $h / 2;
                        $r = min($w, $h) / 2 - 4;
                        $pdf->Circle($cx, $cy, $r);
                        $pdf->SetLineWidth(1);
                        $pdf->Circle($cx, $cy, $r - 14);
                        $pdf->SetFont('dejavusans', 'B', 8);
                        $pdf->setXY($x, $cy - 6);
                        $pdf->Cell($w, 12, $field['text'] ?? 'TASDIQLANGAN', 0, 0, 'C');
                    }
                } elseif ($type === 'signature') {
                    // If user has a real signature image, use it; otherwise draw vector line
                    if (!empty($data['signature_path']) && file_exists($this->publicPath((string)$data['signature_path']))) {
                        $pdf->Image($this->publicPath((string)$data['signature_path']), $x, $y, $w, $h, '', '', '', false, 300, '', false, false, 0);
                    } else {
                        $color = $this->hexToRgb($field['color'] ?? '#111827');
                        $pdf->SetDrawColor($color[0], $color[1], $color[2]);
                        $pdf->SetTextColor($color[0], $color[1], $color[2]);
                        $pdf->SetLineWidth(2);
                        $pdf->Line($x + 10, $y + $h - 10, $x + $w - 10, $y + $h - 10);
                        $pdf->SetFont('dejavusans', 'I', 12);
                        $pdf->setXY($x, $y + 10);
                        $pdf->Cell($w, $h - 20, $field['text'] ?? 'Imzo', 0, 0, 'C');
                    }
                } else {
                    // Text maydoni
                    $text = $this->fieldText($field, $data);
                    if ($text === '') continue;

                    $fontSize = max(8, (int)round((float)($field['fontSize'] ?? 32)));
                    $color = $this->hexToRgb($field['color'] ?? '#1a1a1a');
                    $pdf->SetTextColor($color[0], $color[1], $color[2]);

                    $fontFamily = $this->tcpdfFontFamily($field['fontFamily'] ?? null);
                    $fontStyle = $this->isBoldWeight($field['fontWeight'] ?? null) ? 'B' : '';
                    
                    $pdf->SetFont($fontFamily, $fontStyle, $fontSize);

                    $alignMap = ['left' => 'L', 'center' => 'C', 'right' => 'R'];
                    $align = $alignMap[$field['align'] ?? 'left'] ?? 'L';

                    $pdf->setXY($x, $y);
                    $pdf->Cell($w, $h, $text, 0, 0, $align, false, '', 0, false, 'T', 'M');
                }
            }
        } else {
            // Defolt shablon
            $pdf->SetTextColor(30, 30, 30);
            $pdf->SetFont('dejavusans', 'B', 32);
            $pdf->setXY(200, 330);
            $pdf->Cell(880, 60, $data['recipient_name'] ?? '', 0, 0, 'L');

            $pdf->SetTextColor(180, 130, 0);
            $pdf->SetFont('dejavusans', 'B', 20);
            $pdf->setXY(200, 420);
            $pdf->Cell(880, 50, $data['course_name'] ?? '', 0, 0, 'L');

            $pdf->SetTextColor(30, 30, 30);
            $pdf->SetFont('dejavusans', '', 14);
            $pdf->setXY(200, 500);
            $pdf->Cell(880, 40, $data['issued_date'] ?? date('Y-m-d'), 0, 0, 'L');
        }

        // 3. Agar QR kod va logotip maydoni berilmagan bo'lsa, ularni defolt pozitsiyaga qo'shamiz
        if (!$this->hasQrField($fields) && file_exists($this->publicPath($qrPath))) {
            $pdf->Image($this->publicPath($qrPath), $canvasW - 184, $canvasH - 184, 138, 138, '', '', '', false, 300, '', false, false, 0);
        }

        if (!empty($data['logo_path']) && !$this->hasLogoOrImageField($fields) && file_exists($this->publicPath($data['logo_path']))) {
            $pdf->Image($this->publicPath($data['logo_path']), 50, $canvasH - 130, 80, 80, '', '', '', false, 300, '', false, false, 0);
        }

        // PDFni saqlash
        $pdfFile = "{$dir}/cert_{$certId}.pdf";
        $pdf->Output($pdfFile, 'F');

        return "uploads/certificates/pdf/cert_{$certId}.pdf";
    }

    private function hexToRgb(string $hex): array {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            $hex = '1a1a1a';
        }
        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2))
        ];
    }

    private function drawField(\GdImage $img, array $field, array $data, string $font): void {
        $type = $field['type'] ?? 'text';
        $x = (int)round((float)($field['x'] ?? 0));
        $y = (int)round((float)($field['y'] ?? 0));
        $w = max(1, (int)round((float)($field['w'] ?? 400)));
        $h = max(1, (int)round((float)($field['h'] ?? 60)));

        if ($type === 'image' && !empty($field['src'])) {
            $this->drawImageField($img, (string)$field['src'], $x, $y, $w, $h);
            return;
        }

        if ($type === 'logo') {
            // Draw user's logo from their profile at the field's position/size
            if (!empty($data['logo_path'])) {
                $this->drawImageField($img, (string)$data['logo_path'], $x, $y, $w, $h);
            }
            return;
        }

        if ($type === 'qr') {
            $this->drawQrField($img, (string)($data['qr_path'] ?? ''), $x, $y, $w, $h);
            return;
        }
        if ($type === 'seal') {
            $this->drawSealField($img, $field, $x, $y, $w, $h, $data['seal_path'] ?? null);
            return;
        }
        if ($type === 'signature') {
            $this->drawSignatureField($img, $field, $x, $y, $w, $h, $data['signature_path'] ?? null);
            return;
        }

        $text = $this->fieldText($field, $data);
        if ($text === '') return;

        $fontSize = max(8, (int)round((float)($field['fontSize'] ?? 32)));
        $color = $this->allocateHexColor($img, (string)($field['color'] ?? '#1a1a1a'));
        $align = $field['align'] ?? 'left';
        $tx = $x;

        $fieldFont = $this->gdFontPath($field['fontFamily'] ?? null, $field['fontWeight'] ?? null);

        if (file_exists($fieldFont)) {
            $box = imagettfbbox($fontSize, 0, $fieldFont, $text);
            $textWidth = $box ? abs($box[2] - $box[0]) : 0;
            if ($align === 'center') {
                $tx = $x + (int)(($w - $textWidth) / 2);
            } elseif ($align === 'right') {
                $tx = $x + $w - $textWidth;
            }
            imagettftext($img, $fontSize, 0, max(0, $tx), $y + $fontSize, $color, $fieldFont, $text);
            return;
        }

        $builtinFont = 5;
        $textWidth = imagefontwidth($builtinFont) * strlen($text);
        if ($align === 'center') {
            $tx = $x + (int)(($w - $textWidth) / 2);
        } elseif ($align === 'right') {
            $tx = $x + $w - $textWidth;
        }
        imagestring($img, $builtinFont, max(0, $tx), $y, $text, $color);
    }

    private function drawImageField(\GdImage $img, string $src, int $x, int $y, int $w, int $h): void {
        $raw = null;
        if (str_starts_with($src, 'data:image/')) {
            $comma = strpos($src, ',');
            if ($comma !== false) {
                $raw = base64_decode(substr($src, $comma + 1), true);
            }
        } else {
            $path = $this->publicPath($src);
            if (is_file($path)) {
                $raw = file_get_contents($path);
            }
        }

        if (!$raw) return;
        $fieldImg = @imagecreatefromstring($raw);
        if (!$fieldImg) return;

        imagecopyresampled($img, $fieldImg, $x, $y, 0, 0, $w, $h, imagesx($fieldImg), imagesy($fieldImg));
    }

    private function drawQrField(\GdImage $img, string $qrPath, int $x, int $y, int $w, int $h): void {
        if ($qrPath === '') return;

        $qrFull = $this->publicPath($qrPath);
        if (!is_file($qrFull)) return;

        $qr = @imagecreatefrompng($qrFull);
        if (!$qr) return;

        $box = max(48, min($w, $h));
        $padding = max(4, (int)round($box * 0.06));
        $size = max(32, $box - $padding * 2);
        $white = imagecolorallocate($img, 255, 255, 255);
        $border = imagecolorallocatealpha($img, 17, 24, 39, 105);

        imagefilledrectangle($img, $x, $y, $x + $box, $y + $box, $white);
        imagerectangle($img, $x, $y, $x + $box, $y + $box, $border);
        imagecopyresized($img, $qr, $x + $padding, $y + $padding, 0, 0, $size, $size, imagesx($qr), imagesy($qr));
    }

    private function drawSealField(\GdImage $img, array $field, int $x, int $y, int $w, int $h, ?string $sealPath = null): void {
        if ($sealPath && file_exists($this->publicPath($sealPath))) {
            // Haqiqiy muhr rasmini chizish
            $this->drawImageField($img, $sealPath, $x, $y, $w, $h);
            return;
        }

        $size = max(72, min($w, $h));
        $color = $this->allocateHexColor($img, (string)($field['color'] ?? '#0f766e'));
        $cx = $x + (int)($size / 2);
        $cy = $y + (int)($size / 2);
        imagesetthickness($img, 3);
        imageellipse($img, $cx, $cy, $size - 8, $size - 8, $color);
        imagesetthickness($img, 1);
        imageellipse($img, $cx, $cy, $size - 36, $size - 36, $color);

        $text = (string)($field['text'] ?? 'TASDIQLANGAN');
        $font = $this->fontPath();
        $fontSize = max(10, (int)($field['fontSize'] ?? 14));
        if (file_exists($font)) {
            $box = imagettfbbox($fontSize, 0, $font, $text);
            $tw = abs($box[2] - $box[0]);
            imagettftext($img, $fontSize, 0, $cx - (int)($tw / 2), $cy + (int)($fontSize / 2), $color, $font, $text);
        } else {
            imagestring($img, 4, $x + 16, $cy - 7, $text, $color);
        }
    }

    private function drawSignatureField(\GdImage $img, array $field, int $x, int $y, int $w, int $h, ?string $sigPath = null): void {
        $color = $this->allocateHexColor($img, (string)($field['color'] ?? '#111827'));
        
        // Chiziq tortish
        imagesetthickness($img, 2);
        imageline($img, $x + 12, $y + $h - 10, $x + $w - 12, $y + $h - 10, $color);
        imagesetthickness($img, 1);

        if ($sigPath && file_exists($this->publicPath($sigPath))) {
            // Haqiqiy imzo rasmini chizish
            $this->drawImageField($img, $sigPath, $x + 12, $y + 10, $w - 24, $h - 30);
        } else {
            // Standart mock imzo chizish
            imagesetthickness($img, 2);
            $baseY = $y + (int)($h * 0.55);
            imagearc($img, $x + 55, $baseY, 105, 55, 190, 350, $color);
            imagearc($img, $x + 145, $baseY, 115, 65, 185, 355, $color);
            imagesetthickness($img, 1);
        }

        $text = (string)($field['text'] ?? 'Imzo');
        $font = $this->fontPath();
        $fontSize = max(12, (int)($field['fontSize'] ?? 18));
        if (file_exists($font)) {
            $box = imagettfbbox($fontSize, 0, $font, $text);
            $tw = abs($box[2] - $box[0]);
            imagettftext($img, $fontSize, 0, $x + (int)(($w - $tw) / 2), $y + $h - 16, $color, $font, $text);
        } else {
            imagestring($img, 4, $x + (int)($w / 2) - 20, $y + $h - 28, $text, $color);
        }
    }

    private function hasQrField(array $fields): bool {
        foreach ($fields as $field) {
            if (is_array($field) && ($field['type'] ?? null) === 'qr') {
                return true;
            }
        }
        return false;
    }

    private function fieldText(array $field, array $data): string {
        $variable = (string)($field['variable'] ?? '');
        $vars = [
            '{{recipient_name}}' => (string)($data['recipient_name'] ?? ''),
            '{{course_name}}'    => (string)($data['course_name'] ?? ''),
            '{{issued_date}}'    => (string)($data['issued_date'] ?? date('Y-m-d')),
            '{{cert_id}}'        => (string)($data['cert_id'] ?? ''),
            '{{issuer_name}}'    => (string)($data['issuer_name'] ?? $data['issuer_company'] ?? 'Tashkilot'),
        ];

        if ($variable !== '' && array_key_exists($variable, $vars)) {
            return $vars[$variable];
        }

        return (string)($field['text'] ?? '');
    }

    private function allocateHexColor(\GdImage $img, string $hex): int {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            $hex = '1a1a1a';
        }

        return imagecolorallocate(
            $img,
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2))
        );
    }

    private function renderPng(int $certId, array $data, string $qrPath, bool $watermark): string {
        $dir      = $this->publicPath('uploads/certificates/png');
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $filename = "{$dir}/cert_{$certId}.png";

        $isPortrait = ($data['orientation'] ?? 'landscape') === 'portrait';
        $canvasW = $isPortrait ?  960 : 1280;
        $canvasH = $isPortrait ? 1280 :  960;

        $tplPath = !empty($data['template_path'])
            ? $this->publicPath($data['template_path'])
            : null;

        if ($tplPath && file_exists($tplPath)) {
            $templateImg = @imagecreatefromstring(file_get_contents($tplPath));
            if ($templateImg) {
                $img = imagecreatetruecolor($canvasW, $canvasH);
                imagecopyresampled(
                    $img,
                    $templateImg,
                    0,
                    0,
                    0,
                    0,
                    $canvasW,
                    $canvasH,
                    imagesx($templateImg),
                    imagesy($templateImg)
                );
            } else {
                $img = null;
            }
        } else {
            $img = null;
        }

        if (!$img) {
            $img = imagecreatetruecolor($canvasW, $canvasH);
            $bg  = imagecolorallocate($img, 245, 245, 240);
            imagefill($img, 0, 0, $bg);
            // Chegara chizish
            $border = imagecolorallocate($img, 200, 168, 75);
            imagerectangle($img, 20, 20, $canvasW - 21, $canvasH - 21, $border);
            imagerectangle($img, 24, 24, $canvasW - 25, $canvasH - 25, $border);
        }

        $font = $this->fontPath();
        $fields = is_array($data['fields'] ?? null) ? $data['fields'] : [];

        if (!empty($fields)) {
            foreach ($fields as $field) {
                if (!is_array($field)) continue;
                $this->drawField($img, $field, $data, $font);
            }
        } else {
            $dark = imagecolorallocate($img, 30, 30, 30);
            $gold = imagecolorallocate($img, 180, 130, 0);

            if (file_exists($font)) {
                imagettftext($img, 42, 0, 200, 380, $dark, $font, $data['recipient_name'] ?? '');
                imagettftext($img, 22, 0, 200, 460, $gold, $font, $data['course_name'] ?? '');
                imagettftext($img, 16, 0, 200, 540, $dark, $font, $data['issued_date'] ?? date('Y-m-d'));
            } else {
                // Shrift yo'q bo'lsa imagestring bilan yozish
            imagestring($img, 5, 200, 370, $data['recipient_name'] ?? '', $dark);
            imagestring($img, 4, 200, 450, $data['course_name'] ?? '', $gold);
            imagestring($img, 3, 200, 530, $data['issued_date'] ?? date('Y-m-d'), $dark);
            }
        }

        // Konstruktor QR maydoni bermasa, minimal default QR pastki o'ngda turadi.
        if (!$this->hasQrField($fields)) {
            $this->drawQrField($img, $qrPath, imagesx($img) - 150 - 34, imagesy($img) - 150 - 34, 138, 138);
        }

        // Tashkilot logosi — pastki chap (agar bor bo'lsa va konstruktor o'zi rasm field bermasa)
        if (!empty($data['logo_path']) && !$this->hasLogoOrImageField($fields)) {
            $this->drawImageField($img, (string)$data['logo_path'], 50, imagesy($img) - 110, 80, 80);
        }

        if (!imagepng($img, $filename)) {
            throw new \RuntimeException('Sertifikat PNG faylini yozib bo\'lmadi');
        }

        return "uploads/certificates/png/cert_{$certId}.png";
    }

    private function hasImageField(array $fields): bool {
        foreach ($fields as $f) {
            if (is_array($f) && ($f['type'] ?? null) === 'image') return true;
        }
        return false;
    }

    private function hasLogoOrImageField(array $fields): bool {
        foreach ($fields as $f) {
            if (is_array($f) && in_array($f['type'] ?? null, ['image', 'logo'], true)) return true;
        }
        return false;
    }

    private function buildCustomHtml(array $data, string $qrPath, bool $watermark): string {
        $certId = htmlspecialchars((string)($data['cert_id'] ?? ''), ENT_QUOTES, 'UTF-8');
        $qrUrl = htmlspecialchars('/' . ltrim($qrPath, '/'), ENT_QUOTES, 'UTF-8');
        $verifyUrl = htmlspecialchars("/verify.html?id={$certId}", ENT_QUOTES, 'UTF-8');
        $bgStyle = '#faf6ee';
        $isPortrait = ($data['orientation'] ?? 'landscape') === 'portrait';
        $cw = $isPortrait ?  960 : 1280;
        $ch = $isPortrait ? 1280 :  960;

        if (!empty($data['template_path'])) {
            $bgUrl = htmlspecialchars('/' . ltrim((string)$data['template_path'], '/'), ENT_QUOTES, 'UTF-8');
            $bgStyle = "url('{$bgUrl}') center / 100% 100% no-repeat";
        }

        $fieldHtml = '';
        foreach (($data['fields'] ?? []) as $field) {
            if (!is_array($field)) continue;

            $x = (int)round((float)($field['x'] ?? 0));
            $y = (int)round((float)($field['y'] ?? 0));
            $w = max(1, (int)round((float)($field['w'] ?? 400)));
            $h = max(1, (int)round((float)($field['h'] ?? 60)));
            $style = "left:{$x}px;top:{$y}px;width:{$w}px;height:{$h}px;";

            if (($field['type'] ?? 'text') === 'image' && !empty($field['src'])) {
                $src = (string)$field['src'];
                if (!str_starts_with($src, 'data:image/')) {
                    $src = '/' . ltrim($src, '/');
                }
                $src = htmlspecialchars($src, ENT_QUOTES, 'UTF-8');
                $fieldHtml .= "<img class=\"field-img\" src=\"{$src}\" style=\"{$style}\" alt=\"\">";
                continue;
            }

            if (($field['type'] ?? 'text') === 'qr') {
                $qrBox = $style . 'padding:6px;background:#fff;border:1px solid rgba(17,24,39,.18);';
                $fieldHtml .= "<a class=\"field-qr\" href=\"{$verifyUrl}\" style=\"{$qrBox}\"><img src=\"{$qrUrl}\" alt=\"QR\"></a>";
                continue;
            }

            if (($field['type'] ?? 'text') === 'seal') {
                $color = htmlspecialchars((string)($field['color'] ?? '#0f766e'), ENT_QUOTES, 'UTF-8');
                $text = htmlspecialchars((string)($field['text'] ?? 'TASDIQLANGAN'), ENT_QUOTES, 'UTF-8');
                
                if (!empty($data['seal_path']) && file_exists($this->publicPath($data['seal_path']))) {
                    $sealUrl = htmlspecialchars('/' . ltrim((string)$data['seal_path'], '/'), ENT_QUOTES, 'UTF-8');
                    $sealContent = "<img src=\"{$sealUrl}\" style=\"width:100%;height:100%;object-fit:contain;\" alt=\"\">";
                    $borderStyle = "border-style:none;";
                } else {
                    $sealContent = "<span>{$text}</span>";
                    $borderStyle = "";
                }
                
                $fieldHtml .= "<div class=\"field-seal\" style=\"{$style}color:{$color};border-color:{$color};{$borderStyle}\">{$sealContent}</div>";
                continue;
            }

            if (($field['type'] ?? 'text') === 'signature') {
                $color = htmlspecialchars((string)($field['color'] ?? '#111827'), ENT_QUOTES, 'UTF-8');
                $text = htmlspecialchars((string)($field['text'] ?? 'Imzo'), ENT_QUOTES, 'UTF-8');
                
                $sigContent = "<span>{$text}</span>";
                if (!empty($data['signature_path']) && file_exists($this->publicPath($data['signature_path']))) {
                    $sigUrl = htmlspecialchars('/' . ltrim((string)$data['signature_path'], '/'), ENT_QUOTES, 'UTF-8');
                    $sigContent = "<img src=\"{$sigUrl}\" style=\"max-width:90%;max-height:calc(100% - 24px);object-fit:contain;position:absolute;bottom:12px;left:50%;transform:translateX(-50%);\" alt=\"\"><span style=\"position:absolute;bottom:0;width:100%;text-align:center;\">{$text}</span>";
                } else {
                    $sigContent = "<span>{$text}</span>";
                }
                
                $fieldHtml .= "<div class=\"field-signature\" style=\"{$style}color:{$color};border-bottom-color:{$color};position:relative;\">{$sigContent}</div>";
                continue;
            }

            $fontSize = max(8, (int)round((float)($field['fontSize'] ?? 32)));
            $color = htmlspecialchars((string)($field['color'] ?? '#1a1a1a'), ENT_QUOTES, 'UTF-8');
            $weight = htmlspecialchars((string)($field['fontWeight'] ?? 'normal'), ENT_QUOTES, 'UTF-8');
            $family = htmlspecialchars((string)($field['fontFamily'] ?? 'Arial'), ENT_QUOTES, 'UTF-8');
            $align = htmlspecialchars((string)($field['align'] ?? 'left'), ENT_QUOTES, 'UTF-8');
            $text = htmlspecialchars($this->fieldText($field, $data), ENT_QUOTES, 'UTF-8');
            $fieldHtml .= "<div class=\"field-text\" style=\"{$style}font-size:{$fontSize}px;color:{$color};font-weight:{$weight};font-family:'{$family}', Arial, sans-serif;text-align:{$align};\">{$text}</div>";
        }

        $defaultQrHtml = $this->hasQrField($data['fields'] ?? [])
            ? ''
            : "<div class=\"qr-block\"><a href=\"{$verifyUrl}\"><img src=\"{$qrUrl}\" alt=\"QR\"></a><div>{$certId}</div></div>";

        return <<<HTML
<!DOCTYPE html>
<html lang="uz">
<head>
<meta charset="UTF-8">
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { width: {$cw}px; height: {$ch}px; background: #fff; font-family: Arial, sans-serif; }
  .cert { position: relative; width: {$cw}px; height: {$ch}px; overflow: hidden; background: {$bgStyle}; }
  .field-text { position: absolute; line-height: 1.2; white-space: nowrap; overflow: hidden; }
  .field-img { position: absolute; object-fit: fill; }
  .field-qr { position: absolute; display: block; }
  .field-qr img { width: 100%; height: 100%; display: block; }
  .field-seal { position: absolute; border: 3px solid; border-radius: 50%; display: flex; align-items: center; justify-content: center; text-align: center; font: 700 14px Arial, sans-serif; }
  .field-seal::before { content: ""; position: absolute; inset: 16px; border: 1px solid currentColor; border-radius: 50%; }
  .field-signature { position: absolute; border-bottom: 2px solid; font: italic 20px Georgia, serif; display: flex; align-items: center; justify-content: center; }
  .qr-block { position: absolute; right: 30px; bottom: 30px; text-align: center; font-size: 10px; color: #555; }
  .qr-block img { width: 138px; height: 138px; background: #fff; padding: 6px; border: 1px solid rgba(17,24,39,.18); }
</style>
</head>
<body>
<div class="cert">
  {$fieldHtml}
  {$defaultQrHtml}
</div>
</body>
</html>
HTML;
    }

    private function buildHtml(array $data, string $qrPath, bool $watermark): string {
        if (!empty($data['fields']) || !empty($data['template_path'])) {
            return $this->buildCustomHtml($data, $qrPath, $watermark);
        }

        $name      = htmlspecialchars($data['recipient_name'] ?? '');
        $course    = htmlspecialchars($data['course_name'] ?? '');
        $issuer    = htmlspecialchars($data['issuer_company'] ?? $data['issuer_name'] ?? 'Sertifikat Tizimi');
        $certId    = htmlspecialchars($data['cert_id'] ?? '');
        $qrUrl     = '/' . ltrim($qrPath, '/');
        $verifyUrl = "/verify.html?id={$certId}";

        // Hujjat turi
        $docInfo  = DocType::info($data['doc_type'] ?? 'certificate');
        $docTitle    = htmlspecialchars($docInfo['title']);
        $docSubtitle = htmlspecialchars($docInfo['subtitle']);
        $presentText = htmlspecialchars($docInfo['present_text']);
        $idLabel     = $data['doc_type'] === 'diploma' ? 'Diplom ID'
                     : ($data['doc_type'] === 'gratitude' ? 'Hujjat ID'
                     : ($data['doc_type'] === 'honor' ? 'Yorliq ID'
                     : ($data['doc_type'] === 'commendation' ? 'Yorliq ID' : 'Sertifikat ID')));

        $logoHtml = '';
        if (!empty($data['logo_path'])) {
            $logoUrl  = htmlspecialchars('/' . ltrim((string)$data['logo_path'], '/'), ENT_QUOTES, 'UTF-8');
            $logoHtml = "<img class=\"issuer-logo\" src=\"{$logoUrl}\" alt=\"logo\">";
        }

        // Sanani o'zbek formatida chiqarish
        $months = ['Yanvar','Fevral','Mart','Aprel','May','Iyun',
                   'Iyul','Avgust','Sentabr','Oktabr','Noyabr','Dekabr'];
        $ts   = strtotime($data['issued_date'] ?? date('Y-m-d'));
        $dateFormatted = date('d', $ts) . ' ' . $months[(int)date('m', $ts) - 1] . ' ' . date('Y', $ts);

        return <<<HTML
<!DOCTYPE html>
<html lang="uz">
<head>
<meta charset="UTF-8">
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    width: 1280px; height: 960px;
    background: #1a1a2e;
    display: flex; align-items: center; justify-content: center;
    font-family: Arial, sans-serif;
  }

  .cert {
    width: 1240px; height: 920px;
    position: relative;
    background: linear-gradient(160deg, #fefefe 0%, #faf6ee 50%, #fefefe 100%);
    overflow: hidden;
  }

  /* Tashqi oltin chegara */
  .border-outer {
    position: absolute; inset: 10px;
    border: 3px solid #c8a84b;
    pointer-events: none; z-index: 10;
  }
  .border-inner {
    position: absolute; inset: 16px;
    border: 1px solid #e8c96a;
    pointer-events: none; z-index: 10;
  }

  /* Burchak naqshlari */
  .corner {
    position: absolute; width: 60px; height: 60px;
    z-index: 11;
  }
  .corner svg { width: 60px; height: 60px; }
  .corner-tl { top: 10px; left: 10px; }
  .corner-tr { top: 10px; right: 10px; transform: scaleX(-1); }
  .corner-bl { bottom: 10px; left: 10px; transform: scaleY(-1); }
  .corner-br { bottom: 10px; right: 10px; transform: scale(-1); }

  /* Fon naqshi */
  .bg-pattern {
    position: absolute; inset: 0;
    background-image:
      radial-gradient(circle at 20% 20%, rgba(200,168,75,0.06) 0%, transparent 50%),
      radial-gradient(circle at 80% 80%, rgba(200,168,75,0.06) 0%, transparent 50%),
      radial-gradient(circle at 50% 50%, rgba(200,168,75,0.04) 0%, transparent 70%);
  }

  /* Yuqori dekorativ chiziq */
  .top-bar {
    position: absolute; top: 30px; left: 30px; right: 30px; height: 4px;
    background: linear-gradient(90deg, transparent, #c8a84b 20%, #f0d060 50%, #c8a84b 80%, transparent);
  }
  .bottom-bar {
    position: absolute; bottom: 30px; left: 30px; right: 30px; height: 4px;
    background: linear-gradient(90deg, transparent, #c8a84b 20%, #f0d060 50%, #c8a84b 80%, transparent);
  }

  /* Asosiy kontent */
  .content {
    position: absolute; inset: 0;
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    padding: 60px 100px;
    text-align: center;
  }

  /* Muhr */
  .seal {
    width: 90px; height: 90px; margin-bottom: 20px;
  }

  /* Sertifikat sarlavhasi */
  .cert-title {
    font-family: Georgia, 'Times New Roman', serif;
    font-size: 18px; font-weight: 400;
    letter-spacing: 8px; text-transform: uppercase;
    color: #8b6914; margin-bottom: 6px;
  }
  .cert-main-title {
    font-family: Georgia, 'Times New Roman', serif;
    font-size: 64px; font-weight: 900;
    letter-spacing: 4px; text-transform: uppercase;
    color: #1a1a1a;
    line-height: 1;
    background: linear-gradient(135deg, #8b6914 0%, #c8a84b 40%, #8b6914 100%);
    -webkit-background-clip: text; -webkit-text-fill-color: transparent;
    background-clip: text;
    margin-bottom: 24px;
  }

  /* Taqdim etiladi matni */
  .presented-to {
    font-size: 14px; font-weight: 300; letter-spacing: 3px;
    text-transform: uppercase; color: #888; margin-bottom: 12px;
  }

  /* Isim */
  .recipient-name {
    font-family: Georgia, 'Times New Roman', serif;
    font-size: 52px; font-weight: 700;
    color: #1a1a1a; line-height: 1.1;
    margin-bottom: 20px;
    text-shadow: 0 2px 4px rgba(0,0,0,0.08);
  }

  /* Ajratuvchi chiziq */
  .divider {
    display: flex; align-items: center; gap: 16px;
    width: 100%; max-width: 500px; margin-bottom: 20px;
  }
  .divider-line { flex: 1; height: 1px; background: linear-gradient(90deg, transparent, #c8a84b); }
  .divider-line.right { background: linear-gradient(90deg, #c8a84b, transparent); }
  .divider-diamond { width: 8px; height: 8px; background: #c8a84b; transform: rotate(45deg); }

  /* Kurs nomi */
  .course-label {
    font-size: 12px; letter-spacing: 3px; text-transform: uppercase;
    color: #aaa; margin-bottom: 6px;
  }
  .course-name {
    font-family: Georgia, 'Times New Roman', serif;
    font-size: 26px; font-weight: 700; color: #8b6914;
    margin-bottom: 28px;
  }

  /* Sana va ID */
  .meta-row {
    display: flex; gap: 60px; align-items: center; margin-bottom: 32px;
  }
  .meta-item { text-align: center; }
  .meta-label { font-size: 10px; letter-spacing: 2px; text-transform: uppercase; color: #aaa; margin-bottom: 4px; }
  .meta-value { font-size: 14px; font-weight: 700; color: #555; }
  .meta-sep { width: 1px; height: 40px; background: #ddd; }

  /* Imzolar */
  .signatures {
    display: flex; gap: 80px; align-items: flex-end; margin-bottom: 0;
  }
  .sig-item { text-align: center; }
  .sig-line { width: 160px; height: 1px; background: #bbb; margin-bottom: 6px; }
  .sig-name { font-size: 12px; font-weight: 700; color: #444; }
  .sig-title { font-size: 10px; color: #aaa; letter-spacing: 1px; }

  /* QR kod */
  .qr-block {
    position: absolute; bottom: 36px; right: 50px;
    display: flex; flex-direction: column; align-items: center; gap: 4px;
  }
  .qr-block img { width: 118px; height: 118px; border: 1px solid rgba(17,24,39,.18); padding: 6px; background: #fff; }
  .qr-label { font-size: 8px; letter-spacing: 1px; text-transform: uppercase; color: #aaa; display: none; }

  /* Tashkilot */
  .issuer-block {
    position: absolute; bottom: 36px; left: 50px;
    display: flex; align-items: center; gap: 12px;
  }
  .issuer-logo { width: 56px; height: 56px; object-fit: contain; border-radius: 8px; background: #fff; padding: 4px; }
  .issuer-text { display: flex; flex-direction: column; gap: 2px; }
  .issuer-label { font-size: 9px; letter-spacing: 2px; text-transform: uppercase; color: #aaa; }
  .issuer-name  { font-size: 13px; font-weight: 700; color: #555; }

</style>
</head>
<body>
<div class="cert">
  <div class="bg-pattern"></div>
  <div class="top-bar"></div>
  <div class="bottom-bar"></div>
  <div class="border-outer"></div>
  <div class="border-inner"></div>

  <!-- Burchak naqshlari -->
  <div class="corner corner-tl">
    <svg viewBox="0 0 60 60" fill="none" xmlns="http://www.w3.org/2000/svg">
      <path d="M4 4 L4 28 M4 4 L28 4" stroke="#c8a84b" stroke-width="2.5"/>
      <path d="M10 10 L10 22 M10 10 L22 10" stroke="#e8c96a" stroke-width="1"/>
      <circle cx="4" cy="4" r="3" fill="#c8a84b"/>
      <path d="M16 4 Q20 4 20 8" stroke="#c8a84b" stroke-width="1" fill="none"/>
      <path d="M4 16 Q4 20 8 20" stroke="#c8a84b" stroke-width="1" fill="none"/>
    </svg>
  </div>
  <div class="corner corner-tr">
    <svg viewBox="0 0 60 60" fill="none" xmlns="http://www.w3.org/2000/svg">
      <path d="M4 4 L4 28 M4 4 L28 4" stroke="#c8a84b" stroke-width="2.5"/>
      <path d="M10 10 L10 22 M10 10 L22 10" stroke="#e8c96a" stroke-width="1"/>
      <circle cx="4" cy="4" r="3" fill="#c8a84b"/>
      <path d="M16 4 Q20 4 20 8" stroke="#c8a84b" stroke-width="1" fill="none"/>
      <path d="M4 16 Q4 20 8 20" stroke="#c8a84b" stroke-width="1" fill="none"/>
    </svg>
  </div>
  <div class="corner corner-bl">
    <svg viewBox="0 0 60 60" fill="none" xmlns="http://www.w3.org/2000/svg">
      <path d="M4 4 L4 28 M4 4 L28 4" stroke="#c8a84b" stroke-width="2.5"/>
      <path d="M10 10 L10 22 M10 10 L22 10" stroke="#e8c96a" stroke-width="1"/>
      <circle cx="4" cy="4" r="3" fill="#c8a84b"/>
      <path d="M16 4 Q20 4 20 8" stroke="#c8a84b" stroke-width="1" fill="none"/>
      <path d="M4 16 Q4 20 8 20" stroke="#c8a84b" stroke-width="1" fill="none"/>
    </svg>
  </div>
  <div class="corner corner-br">
    <svg viewBox="0 0 60 60" fill="none" xmlns="http://www.w3.org/2000/svg">
      <path d="M4 4 L4 28 M4 4 L28 4" stroke="#c8a84b" stroke-width="2.5"/>
      <path d="M10 10 L10 22 M10 10 L22 10" stroke="#e8c96a" stroke-width="1"/>
      <circle cx="4" cy="4" r="3" fill="#c8a84b"/>
      <path d="M16 4 Q20 4 20 8" stroke="#c8a84b" stroke-width="1" fill="none"/>
      <path d="M4 16 Q4 20 8 20" stroke="#c8a84b" stroke-width="1" fill="none"/>
    </svg>
  </div>

  <!-- Asosiy kontent -->
  <div class="content">
    <!-- Muhr -->
    <svg class="seal" viewBox="0 0 90 90" fill="none" xmlns="http://www.w3.org/2000/svg">
      <circle cx="45" cy="45" r="42" stroke="#c8a84b" stroke-width="2"/>
      <circle cx="45" cy="45" r="36" stroke="#e8c96a" stroke-width="1"/>
      <circle cx="45" cy="45" r="28" fill="none" stroke="#c8a84b" stroke-width="1.5"/>
      <path d="M45 20 L47.5 30 L58 27 L51 35 L60 42 L49.5 42 L48 53 L45 43 L42 53 L40.5 42 L30 42 L39 35 L32 27 L42.5 30 Z" fill="#c8a84b"/>
      <text x="45" y="67" text-anchor="middle" font-size="6" fill="#8b6914" font-family="Arial" letter-spacing="1" font-weight="bold">TASDIQLANGAN</text>
    </svg>

    <div class="cert-title">{$docSubtitle}</div>
    <div class="cert-main-title">{$docTitle}</div>

    <div class="presented-to">Taqdim etiladi</div>

    <div class="recipient-name">{$name}</div>

    <div class="divider">
      <div class="divider-line"></div>
      <div class="divider-diamond"></div>
      <div class="divider-line right"></div>
    </div>

    <div class="course-label">{$presentText}</div>
    <div class="course-name">{$course}</div>

    <div class="meta-row">
      <div class="meta-item">
        <div class="meta-label">Berilgan sana</div>
        <div class="meta-value">{$dateFormatted}</div>
      </div>
      <div class="meta-sep"></div>
      <div class="meta-item">
        <div class="meta-label">{$idLabel}</div>
        <div class="meta-value">{$certId}</div>
      </div>
    </div>

    <div class="signatures">
      <div class="sig-item">
        <div class="sig-line"></div>
        <div class="sig-name">{$issuer}</div>
        <div class="sig-title">Tashkilot rahbari</div>
      </div>
      <div class="sig-item">
        <div class="sig-line"></div>
        <div class="sig-name">Tizim muhrи</div>
        <div class="sig-title">Elektron imzo</div>
      </div>
    </div>
  </div>

  <!-- Pastki chap: tashkilot -->
  <div class="issuer-block">
    {$logoHtml}
    <div class="issuer-text">
      <div class="issuer-label">Bergan tashkilot</div>
      <div class="issuer-name">{$issuer}</div>
    </div>
  </div>

  <!-- Pastki o'ng: QR kod -->
  <div class="qr-block">
    <a href="{$verifyUrl}"><img src="{$qrUrl}" alt="QR"></a>
    <div class="qr-label">Tekshirish uchun skaner qiling</div>
  </div>

</div>
</body>
</html>
HTML;
    }
}
