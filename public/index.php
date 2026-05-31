<?php
declare(strict_types=1);

// --- Autoload ---
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}
require_once __DIR__ . '/../src/Helpers/env.php';
loadEnv(__DIR__ . '/../.env');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Helpers/response.php';

use App\Router;
use App\Controllers\AuthController;
use App\Controllers\CertificateController;
use App\Controllers\PaymentController;
use App\Controllers\AdminController;

// --- CORS ---
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$appUrl = env('APP_URL', 'http://localhost:8000');
$appOrigin = $appUrl ? parse_url($appUrl, PHP_URL_SCHEME) . '://' . parse_url($appUrl, PHP_URL_HOST) . (parse_url($appUrl, PHP_URL_PORT) ? ':' . parse_url($appUrl, PHP_URL_PORT) : '') : '';
$allowedOrigins = array_filter(array_map('trim', explode(',', (string)env('CORS_ORIGINS', $appOrigin))));

if ($origin && in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: {$origin}");
    header('Vary: Origin');
} elseif (!$origin && $appOrigin) {
    header("Access-Control-Allow-Origin: {$appOrigin}");
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// --- Error handler ---
set_error_handler(function ($errno, $errstr) {
    error("Server xatosi: {$errstr}", 500);
});

set_exception_handler(function (\Throwable $e) {
    $debug = env('APP_DEBUG', 'false') === 'true';
    error($debug ? $e->getMessage() : 'Server xatosi', 500);
});

// --- Router ---
$router = new Router();

// Auth
$router->post('/api/auth/register',         fn() => (new AuthController)->register());
$router->post('/api/auth/login',             fn() => (new AuthController)->login());
$router->get('/api/auth/google',             fn() => (new AuthController)->googleRedirect());
$router->get('/api/auth/google/callback',    fn() => (new AuthController)->googleCallback());
$router->post('/api/auth/forgot-password',   fn() => (new AuthController)->forgotPassword());
$router->post('/api/auth/reset-password',    fn() => (new AuthController)->resetPassword());
$router->post('/api/auth/verify-email',      fn() => (new AuthController)->verifyEmail());
$router->post('/api/auth/resend-verification', fn() => (new AuthController)->resendVerification());
$router->get('/api/auth/2fa/status',         fn() => (new AuthController)->status2FA());
$router->post('/api/auth/2fa/setup',         fn() => (new AuthController)->setup2FA());
$router->post('/api/auth/2fa/confirm',       fn() => (new AuthController)->confirm2FA());
$router->post('/api/auth/2fa/disable',       fn() => (new AuthController)->disable2FA());
$router->get('/api/auth/me',                 fn() => (new AuthController)->me());
$router->put('/api/auth/profile',            fn() => (new AuthController)->updateProfile());
$router->put('/api/auth/change-password',    fn() => (new AuthController)->changePassword());
$router->post('/api/auth/api-key',           fn() => (new AuthController)->createApiKey());
$router->delete('/api/auth/api-key',         fn() => (new AuthController)->deleteApiKey());
$router->post('/api/auth/avatar',            fn() => (new AuthController)->uploadAvatar());
$router->delete('/api/auth/avatar',          fn() => (new AuthController)->deleteAvatar());
$router->post('/api/auth/logo',              fn() => (new AuthController)->uploadLogo());
$router->delete('/api/auth/logo',            fn() => (new AuthController)->deleteLogo());
$router->post('/api/auth/signature',         fn() => (new AuthController)->uploadSignature());
$router->delete('/api/auth/signature',       fn() => (new AuthController)->deleteSignature());
$router->post('/api/auth/seal',              fn() => (new AuthController)->uploadSeal());
$router->delete('/api/auth/seal',            fn() => (new AuthController)->deleteSeal());
$router->delete('/api/auth/account',         fn() => (new AuthController)->deleteAccount());
$router->get('/api/auth/export',             fn() => (new AuthController)->exportData());
$router->get('/api/auth/sessions',           fn() => (new AuthController)->getSessions());
$router->delete('/api/auth/sessions',        fn() => (new AuthController)->revokeSession());

// Sertifikatlar
$router->get('/api/certificates',            fn() => (new CertificateController)->index());
$router->post('/api/certificates',           fn() => (new CertificateController)->create());
$router->post('/api/v1/certificates',         fn() => (new CertificateController)->create());
$router->post('/api/certificates/bulk',      fn() => (new CertificateController)->bulk());
$router->get('/api/certificates/bulk-jobs/:id', fn($id) => (new CertificateController)->bulkJob((int)$id));
$router->post('/api/certificates/bulk-download', fn() => (new CertificateController)->bulkDownload());
$router->get('/api/constructor/layout',      fn() => (new CertificateController)->loadConstructorLayout());
$router->post('/api/constructor/layout',     fn() => (new CertificateController)->saveConstructorLayout());
$router->get('/api/certificates/:id',        fn($id) => (new CertificateController)->show((int)$id));
$router->delete('/api/certificates/:id',      fn($id) => (new CertificateController)->delete((int)$id));
$router->put('/api/certificates/:id/revoke',  fn($id) => (new CertificateController)->revoke((int)$id));
$router->put('/api/certificates/:id/restore', fn($id) => (new CertificateController)->restore((int)$id));
$router->get('/api/certificates/:id/scans',   fn($id) => (new CertificateController)->scans((int)$id));
$router->get('/api/templates',                fn() => (new CertificateController)->templates());
$router->post('/api/templates/submit',        fn() => (new CertificateController)->submitTemplate());
$router->get('/api/templates/my',             fn() => (new CertificateController)->myTemplates());

// Tekshirish (ommaviy)
$router->get('/verify/:certId',              fn($id) => (new CertificateController)->verify($id));
$router->post('/api/telegram/webhook',        fn() => (new \App\Controllers\TelegramController)->webhook());

// To'lovlar
$router->get('/api/plans',                   fn() => (new PaymentController)->plans());
$router->post('/api/payments/initiate',      fn() => (new PaymentController)->initiate());
$router->post('/api/payments/free-plan',     fn() => (new PaymentController)->activateFree());
$router->post('/api/payments/check-promo',   fn() => (new PaymentController)->checkPromo());
$router->post('/api/payments/callback/click',fn() => (new PaymentController)->clickCallback());
$router->post('/api/payments/callback/payme',fn() => (new PaymentController)->paymeCallback());
$router->get('/api/payments/history',        fn() => (new PaymentController)->history());

// Admin
$router->get('/api/admin/stats',                      fn() => (new AdminController)->stats());
$router->get('/api/admin/users',                      fn() => (new AdminController)->users());
$router->put('/api/admin/users/:id/block',            fn($id) => (new AdminController)->blockUser((int)$id));
$router->put('/api/admin/users/:id/subscription',     fn($id) => (new AdminController)->manageSubscription((int)$id));
$router->get('/api/admin/plans',                      fn() => (new AdminController)->plans());
$router->put('/api/admin/plans/:plan',                fn($plan) => (new AdminController)->updatePlan((string)$plan));
$router->get('/api/admin/templates',                  fn() => (new AdminController)->templates());
$router->post('/api/admin/templates',                 fn() => (new AdminController)->createTemplate());
$router->delete('/api/admin/templates/:id',           fn($id) => (new AdminController)->deleteTemplate((int)$id));
$router->get('/api/admin/payments',                   fn() => (new AdminController)->payments());
$router->put('/api/admin/payments/:id/approve',       fn($id) => (new AdminController)->approvePayment((int)$id));
$router->put('/api/admin/payments/:id/reject',        fn($id) => (new AdminController)->rejectPayment((int)$id));
$router->get('/api/admin/audit-logs',                 fn() => (new AdminController)->auditLogs());
$router->get('/api/admin/health',                     fn() => (new AdminController)->serverHealth());
$router->get('/api/admin/templates/pending',          fn() => (new AdminController)->pendingTemplates());
$router->post('/api/admin/templates/:id/approve',     fn($id) => (new AdminController)->approveTemplate((int)$id));
$router->post('/api/admin/templates/:id/reject',      fn($id) => (new AdminController)->rejectTemplate((int)$id));
$router->get('/api/admin/promo-codes',                fn() => (new AdminController)->listPromoCodes());
$router->post('/api/admin/promo-codes',               fn() => (new AdminController)->createPromoCode());
$router->delete('/api/admin/promo-codes/:id',         fn($id) => (new AdminController)->deletePromoCode((int)$id));
$router->get('/api/admin/broadcasts',                 fn() => (new AdminController)->listBroadcasts());
$router->post('/api/admin/broadcasts',                fn() => (new AdminController)->createBroadcast());
$router->post('/api/admin/broadcasts/:id/send',       fn($id) => (new AdminController)->sendBroadcast((int)$id));
$router->delete('/api/admin/broadcasts/:id',          fn($id) => (new AdminController)->deleteBroadcast((int)$id));

// Health check
$router->get('/health', fn() => success(['status' => 'OK', 'time' => date('c')]));

// Root: tokenli bo'lsa dashboard, yo'q bo'lsa landing
$router->get('/', function () {
    $token = $_COOKIE['token'] ?? '';
    $dest  = $token ? '/dashboard.html' : '/landing.html';
    header('Content-Type: text/html; charset=utf-8');
    header("Location: {$dest}", true, 302);
    exit;
});

// Dispatch
$router->dispatch(
    $_SERVER['REQUEST_METHOD'],
    $_SERVER['REQUEST_URI']
);
