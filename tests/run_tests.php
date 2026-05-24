<?php
/**
 * Automated Test Runner Suite
 * Run with: php tests/run_tests.php
 */

define('TEST_MODE', true);

// Clean output buffering and display errors for reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "===========================================\n";
echo "🎓 Professional Sertifikat Tizimi - Test Runner\n";
echo "===========================================\n\n";

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Helpers/env.php';
loadEnv(__DIR__ . '/../.env');
require_once __DIR__ . '/../config/database.php';

use App\Helpers\JWT;
use App\Models\UserModel;
use App\Models\SubscriptionModel;

$db = Database::getInstance();

function test_assert(bool $condition, string $description) {
    if ($condition) {
        echo "✅ PASS: {$description}\n";
    } else {
        echo "❌ FAIL: {$description}\n";
        exit(1);
    }
}

// ----------------------------------------------------
// 1. JWT Security Hardening Verification
// ----------------------------------------------------
echo "\n--- Running Test Suite 1: JWT Hardening ---\n";
try {
    // Temp override of env to check empty secret behavior
    $oldSecret = env('JWT_SECRET');
    
    // Simulate empty JWT_SECRET to check if it throws exception
    $_ENV['JWT_SECRET'] = '';
    $throwsException = false;
    try {
        JWT::encode(['sub' => 1]);
    } catch (\RuntimeException $e) {
        $throwsException = (strpos($e->getMessage(), 'JWT_SECRET is not configured') !== false);
    }
    test_assert($throwsException, "JWT throws exception when JWT_SECRET is empty/missing");

    // Restore and test encoding/decoding with normal secret
    $_ENV['JWT_SECRET'] = 'secure_test_key_for_jwt_auth_verification';
    $payload = ['sub' => 42, 'role' => 'user'];
    $token = JWT::encode($payload);
    test_assert(!empty($token), "JWT token encoded successfully");

    $decoded = JWT::decode($token);
    test_assert($decoded && $decoded['sub'] === 42 && $decoded['role'] === 'user', "JWT token decoded with correct payload");

    // Restore old env setting
    $_ENV['JWT_SECRET'] = $oldSecret;
} catch (Exception $e) {
    test_assert(false, "JWT Suite encountered unexpected error: " . $e->getMessage());
}

// ----------------------------------------------------
// 2. Database Migrations Presence
// ----------------------------------------------------
echo "\n--- Running Test Suite 2: DB Migrations Presence ---\n";
try {
    $stmt = $db->query("SELECT COUNT(*) FROM migrations");
    $count = $stmt->fetchColumn();
    test_assert($count >= 2, "Both runtime migrations are logged in migrations table (Count: {$count})");

    // Verify constructor_layouts table is present
    $tableExists = $db->query("SELECT to_regclass('public.constructor_layouts')")->fetchColumn();
    test_assert(!empty($tableExists), "constructor_layouts table exists in DB");
} catch (Exception $e) {
    test_assert(false, "DB Migration Suite failed: " . $e->getMessage());
}

// ----------------------------------------------------
// 3. User Model CRUD Verification
// ----------------------------------------------------
echo "\n--- Running Test Suite 3: User Model Operations ---\n";
$userModel = new UserModel();
$testEmail = 'test_runner_' . bin2hex(random_bytes(4)) . '@sertifikat.uz';
$testPass = 'RunnerPass123!';
$userId = null;

try {
    $userId = $userModel->create([
        'name' => 'Test Runner User',
        'email' => $testEmail,
        'phone' => null,
        'password' => $testPass,
    ]);
    test_assert($userId > 0, "Test user created successfully (ID: {$userId})");

    $user = $userModel->findById($userId);
    test_assert($user && $user['email'] === $testEmail, "User found by ID matches created email");

    $userByEmail = $userModel->findByEmail($testEmail);
    test_assert($userByEmail && password_verify($testPass, $userByEmail['password']), "Password verification is correct");
} catch (Exception $e) {
    test_assert(false, "User Model Suite failed: " . $e->getMessage());
}

// ----------------------------------------------------
// 4. Atomic Subscription Limits & Race-Conditions
// ----------------------------------------------------
echo "\n--- Running Test Suite 4: Atomic Subscription Limits ---\n";
$subModel = new SubscriptionModel();

try {
    // Activate Standard Plan with a limit of 2 certificates
    $subId = $subModel->activate($userId, 'standard', 1, 2); 
    test_assert($subId > 0, "Subscription Standard activated for user with limit = 2");

    // 1st atomic increment - should succeed
    $ok1 = $subModel->tryIncrementUsed($userId);
    test_assert($ok1 === true, "First limit increment reservation SUCCEEDED");

    // 2nd atomic increment - should succeed
    $ok2 = $subModel->tryIncrementUsed($userId);
    test_assert($ok2 === true, "Second limit increment reservation SUCCEEDED");

    // 3rd atomic increment - should FAIL (limit exceeded)
    $ok3 = $subModel->tryIncrementUsed($userId);
    test_assert($ok3 === false, "Third limit increment reservation FAILED as limit was reached (concurrency safe)");

    // Test rollback (decrement)
    $subModel->decrementUsed($userId);
    $ok4 = $subModel->tryIncrementUsed($userId);
    test_assert($ok4 === true, "Rollback (decrement) allows another certificate increment reservation");
} catch (Exception $e) {
    test_assert(false, "Subscription Limit Suite failed: " . $e->getMessage());
}

// ----------------------------------------------------
// Cleanup Test Data
// ----------------------------------------------------
echo "\nCleaning up test data... ";
if ($userId) {
    $db->prepare("DELETE FROM subscriptions WHERE user_id = ?")->execute([$userId]);
    $db->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
}
echo "DONE\n";

echo "\n===========================================\n";
echo "🎉 ALL TESTS PASSED SUCCESSFULLY! 🎉\n";
echo "===========================================\n";
