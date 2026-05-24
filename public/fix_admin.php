<?php
require_once __DIR__ . '/../src/Helpers/env.php';
loadEnv(__DIR__ . '/../.env');

if (($_ENV['APP_ENV'] ?? 'development') === 'production' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    die("Access denied. This utility script is disabled in production or remote environments.");
}

$host   = $_ENV['DB_HOST'] ?? 'localhost';
$port   = $_ENV['DB_PORT'] ?? '5432';
$user   = $_ENV['DB_USER'] ?? 'postgres';
$pass   = $_ENV['DB_PASS'] ?? '';
$dbname = $_ENV['DB_NAME'] ?? 'sertifikat_db';

try {
    $db = new PDO(
        "pgsql:host={$host};port={$port};dbname={$dbname}",
        $user, $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $hash = password_hash('password', PASSWORD_BCRYPT, ['cost' => 12]);

    $db->exec("ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check");
    $db->exec("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('user', 'admin', 'super_admin'))");

    // Admin mavjud bo'lsa parolini yangilash, yo'q bo'lsa yaratish
    $exists = $db->query("SELECT id FROM users WHERE email = 'admin@sertifikat.uz'")->fetchColumn();

    if ($exists) {
        $db->prepare("UPDATE users SET password = ?, role = 'super_admin' WHERE email = 'admin@sertifikat.uz'")
           ->execute([$hash]);
        echo "<p style='color:green'>✅ Admin paroli yangilandi!</p>";
    } else {
        $db->prepare(
            "INSERT INTO users (name, email, password, role, is_active, is_verified)
             VALUES ('Administrator', 'admin@sertifikat.uz', ?, 'super_admin', true, true)"
        )->execute([$hash]);

        $adminId = $db->lastInsertId();

        $db->prepare(
            "INSERT INTO subscriptions (user_id, plan, status, cert_limit, expires_at)
             VALUES (?, 'pro', 'active', -1, '2099-12-31')"
        )->execute([$adminId]);

        echo "<p style='color:green'>✅ Admin foydalanuvchi yaratildi!</p>";
    }

    // Tekshirish
    $admin = $db->query("SELECT id, email, role FROM users WHERE email = 'admin@sertifikat.uz'")->fetch();
    echo "<p>ID: {$admin['id']} | Email: {$admin['email']} | Role: {$admin['role']}</p>";
    echo "<p style='color:green'>✅ Kirish ma'lumotlari: <strong>admin@sertifikat.uz</strong> / <strong>password</strong></p>";
    echo "<p><a href='/login.html' style='color:#4f46e5;font-weight:bold;'>→ Login sahifasiga o'tish</a></p>";
    echo "<p style='color:red;margin-top:20px;'>⚠️ fix_admin.php faylini o'chiring!</p>";

} catch (Exception $e) {
    echo "<p style='color:red'>❌ Xato: " . htmlspecialchars($e->getMessage()) . "</p>";
}
