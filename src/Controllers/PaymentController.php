<?php
namespace App\Controllers;

use PDO;
use App\Models\SubscriptionModel;
use App\Middleware\AuthMiddleware;
use App\Services\AuditLogger;
use App\Services\PlanService;

class PaymentController {
    private PDO $db;
    private SubscriptionModel $subscriptions;

    public function __construct() {
        $this->db            = \Database::getInstance();
        $this->subscriptions = new SubscriptionModel();
    }

    // GET /api/plans
    public function plans(): never {
        $plans = array_filter((new PlanService())->all(), fn($plan) => $plan['is_active']);
        success($plans);
    }

    // POST /api/payments/initiate
    public function initiate(): never {
        $user = AuthMiddleware::handle();
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        $plan     = $data['plan'] ?? '';
        $provider = $data['provider'] ?? 'click';
        $months   = (int) ($data['months'] ?? 1);
        $promo    = trim((string)($data['promo_code'] ?? ''));

        $plans = (new PlanService())->all();

        if (!isset($plans[$plan])) error('Noto\'g\'ri tarif tanlandi', 422);
        if (!$plans[$plan]['is_active']) error('Bu tarif hozir faol emas', 422);
        if ($plan === 'free') error('Bepul tarif to\'lovsiz faollashtiriladi', 400);
        if (!in_array($months, [1, 3, 6, 12], true)) error('Muddat noto\'g\'ri tanlangan', 422);

        $baseAmount = $plans[$plan]['price'] * $months;
        $durationRate = $months >= 12 ? 0.8 : ($months >= 6 ? 0.85 : ($months >= 3 ? 0.9 : 1));
        $durationDiscount = (int)round($baseAmount * (1 - $durationRate));
        $amount     = max(0, $baseAmount - $durationDiscount);
        $promoDiscount = 0;
        $promoId    = null;

        if ($promo !== '') {
            $promoRow = $this->validatePromoCode($promo, $plan, $user['id']);
            $promoId  = $promoRow['id'];
            $promoDiscount = $promoRow['type'] === 'percent'
                ? (int)round($amount * $promoRow['discount'] / 100)
                : min((int)$promoRow['discount'], $amount);
            $amount   = max(0, $amount - $promoDiscount);
        }

        $discount = $durationDiscount + $promoDiscount;
        $meta = ['months' => $months, 'duration_discount' => $durationDiscount];
        if ($promoId) $meta['promo'] = ['code' => $promo, 'discount' => $promoDiscount, 'promo_id' => $promoId];

        $stmt = $this->db->prepare(
            'INSERT INTO payments (user_id, provider, amount, plan, status, meta)
             VALUES (?, ?, ?, ?, \'pending\', ?)
             RETURNING id'
        );
        $stmt->execute([
            $user['id'],
            $provider,
            $amount,
            $plan,
            json_encode($meta),
        ]);
        $paymentId = $stmt->fetchColumn();

        // Promokod ishlatilganini belgilash
        if ($promoId) {
            $this->db->prepare(
                'INSERT INTO discount_code_uses (code_id, user_id, payment_id) VALUES (?, ?, ?)'
            )->execute([$promoId, $user['id'], $paymentId]);
            $this->db->prepare('UPDATE discount_codes SET used_count = used_count + 1 WHERE id = ?')
                     ->execute([$promoId]);
        }

        $redirectUrl = $this->buildPaymentUrl($provider, $paymentId, $amount);

        AuditLogger::log('payment.initiate', $user['id'], target: 'payment', targetId: (int)$paymentId, meta: ['plan' => $plan, 'amount' => $amount, 'promo' => $promo ?: null]);

        success([
            'payment_id'   => $paymentId,
            'amount'       => $amount,
            'base_amount'  => $baseAmount,
            'discount'     => $discount,
            'provider'     => $provider,
            'redirect_url' => $redirectUrl,
        ], 'To\'lov URL muvaffaqiyatli yaratildi');
    }

    // POST /api/payments/check-promo  { code, plan }
    public function checkPromo(): never {
        $user = AuthMiddleware::handle();
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $code = trim((string)($data['code'] ?? ''));
        $plan = trim((string)($data['plan'] ?? ''));

        if ($code === '') error('Kod kiritilmagan', 422);

        try {
            $row = $this->validatePromoCode($code, $plan, $user['id']);
            success([
                'valid'    => true,
                'discount' => (int)$row['discount'],
                'type'     => $row['type'],
                'message'  => $row['type'] === 'percent'
                    ? "{$row['discount']}% chegirma"
                    : number_format((int)$row['discount'], 0, '.', ' ') . " so'm chegirma",
            ]);
        } catch (\Throwable $e) {
            error($e->getMessage(), 400);
        }
    }

    private function validatePromoCode(string $code, string $plan, int $userId): array {
        $stmt = $this->db->prepare(
            'SELECT * FROM discount_codes WHERE code = ? AND is_active = true'
        );
        $stmt->execute([strtoupper($code)]);
        $row = $stmt->fetch();

        if (!$row) throw new \RuntimeException('Promokod topilmadi');

        // Vaqt cheklovi
        if ($row['valid_from'] && strtotime($row['valid_from']) > time()) {
            throw new \RuntimeException("Kod hali faollashtirilmagan");
        }
        if ($row['valid_to'] && strtotime($row['valid_to']) < time()) {
            throw new \RuntimeException("Kod muddati tugagan");
        }

        // Plan cheklovi
        if (!empty($row['plan_filter']) && $row['plan_filter'] !== $plan) {
            throw new \RuntimeException("Kod faqat '{$row['plan_filter']}' tarifi uchun");
        }

        // Max foydalanish
        if ($row['max_uses'] && (int)$row['used_count'] >= (int)$row['max_uses']) {
            throw new \RuntimeException("Kod foydalanish chegarasiga yetdi");
        }

        // Foydalanuvchi bir marta ishlatishi mumkin
        $check = $this->db->prepare(
            'SELECT 1 FROM discount_code_uses WHERE code_id = ? AND user_id = ?'
        );
        $check->execute([$row['id'], $userId]);
        if ($check->fetch()) {
            throw new \RuntimeException("Siz bu kodni allaqachon ishlatgansiz");
        }

        return $row;
    }

    // POST /api/payments/callback/click
    public function clickCallback(): never {
        $data = $_POST;

        $merchantId = env('CLICK_MERCHANT_ID');
        $secretKey  = env('CLICK_SECRET_KEY');

        // Click imzosini tekshirish
        $signString = $data['click_trans_id'] . $data['service_id'] . $secretKey
            . $data['merchant_trans_id'] . $data['amount'] . $data['action'] . $data['sign_time'];
        $expectedSign = md5($signString);

        if ($data['sign_string'] !== $expectedSign) {
            jsonResponse(['error' => -1, 'error_note' => 'SIGN CHECK FAILED!']);
        }

        $paymentId = (int) $data['merchant_trans_id'];
        $this->processPayment($paymentId, $data['click_trans_id'], (float) $data['amount']);

        jsonResponse(['error' => 0, 'error_note' => 'Success']);
    }

    // POST /api/payments/callback/payme
    public function paymeCallback(): never {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        // Payme Basic Auth tekshirish
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $credentials = base64_decode(substr($authHeader, 7));
        [, $key] = explode(':', $credentials, 2);

        if ($key !== env('PAYME_SECRET_KEY')) {
            jsonResponse(['error' => ['code' => -32504, 'message' => 'Insufficient privilege to perform this method']]);
        }

        $method = $body['method'] ?? '';
        $params = $body['params'] ?? [];

        match ($method) {
            'CheckPerformTransaction' => $this->paymeCheck($params),
            'CreateTransaction'       => $this->paymeCreate($params),
            'PerformTransaction'      => $this->paymePerform($params),
            'CancelTransaction'       => $this->paymeCancel($params),
            default                   => jsonResponse(['error' => ['code' => -32601, 'message' => 'Method not found']]),
        };
    }

    // GET /api/payments/history
    public function history(): never {
        $user   = AuthMiddleware::handle();
        $page   = max(1, (int) ($_GET['page'] ?? 1));
        $limit  = 10;
        $offset = ($page - 1) * $limit;

        $countStmt = $this->db->prepare('SELECT COUNT(*) FROM payments WHERE user_id = ?');
        $countStmt->execute([$user['id']]);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->prepare(
            'SELECT id, provider, amount, plan, status, paid_at, created_at
             FROM payments WHERE user_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?'
        );
        $stmt->execute([$user['id'], $limit, $offset]);
        $items = $stmt->fetchAll();

        success(paginate($items, $total, $page, $limit));
    }

    // POST /api/payments/free-plan
    public function activateFree(): never {
        $user = AuthMiddleware::handle();

        $sub = $this->subscriptions->getActive($user['id']);
        if ($sub && $sub['plan'] !== 'free') error('Siz allaqachon pullik tarifdasiz', 400);

        $this->subscriptions->activate($user['id'], 'free');
        success(null, 'Bepul tarif faollashtirildi');
    }

    private function processPayment(int $paymentId, string $transactionId, float $amount): void {
        $stmt = $this->db->prepare('SELECT * FROM payments WHERE id = ?');
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch();

        if (!$payment || $payment['status'] !== 'pending') return;
        if (abs($payment['amount'] - $amount) > 1) return;

        $meta   = json_decode($payment['meta'] ?? '{}', true) ?: [];
        $months = (int)($meta['months'] ?? 1);

        // Obunani faollashtirish
        $subId = $this->subscriptions->activate($payment['user_id'], $payment['plan'], $months);

        // To'lovni yangilash
        $this->db->prepare(
            'UPDATE payments SET status = \'success\', transaction_id = ?, subscription_id = ?, paid_at = NOW()
             WHERE id = ?'
        )->execute([$transactionId, $subId, $paymentId]);
    }

    private function buildPaymentUrl(string $provider, int $paymentId, float $amount): string {
        return "/plans.html?payment_id={$paymentId}&offline=1";
    }

    private function paymeCheck(array $params): never {
        jsonResponse(['result' => ['allow' => true]]);
    }

    private function paymeCreate(array $params): never {
        $time = (int) ($params['time'] ?? time() * 1000);
        jsonResponse(['result' => [
            'create_time'    => $time,
            'transaction'    => $params['id'],
            'state'          => 1,
        ]]);
    }

    private function paymePerform(array $params): never {
        $this->processPayment((int) $params['account']['order_id'], $params['id'], 0);
        jsonResponse(['result' => [
            'transaction'    => $params['id'],
            'perform_time'   => time() * 1000,
            'state'          => 2,
        ]]);
    }

    private function paymeCancel(array $params): never {
        jsonResponse(['result' => [
            'transaction'  => $params['id'],
            'cancel_time'  => time() * 1000,
            'state'        => -1,
        ]]);
    }
}
