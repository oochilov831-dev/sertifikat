<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

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

$log  = [];
$done = false;

function log_ok(string $msg): void  { global $log; $log[] = ['msg' => $msg, 'type' => 'ok']; }
function log_err(string $msg): void { global $log; $log[] = ['msg' => $msg, 'type' => 'err']; }
function log_info(string $msg): void{ global $log; $log[] = ['msg' => $msg, 'type' => 'info']; }

if (isset($_POST['install'])) {

    // 1. postgres bazasiga ulanish (baza yaratish uchun)
    try {
        $pdo = new PDO(
            "pgsql:host={$host};port={$port};dbname=postgres",
            $user, $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        log_ok("PostgreSQL serveriga ulandi ({$host}:{$port})");
    } catch (Exception $e) {
        log_err("Ulanib bo'lmadi: " . $e->getMessage());
        goto render;
    }

    // 2. Baza yaratish (mavjud bo'lsa o'tkazib yuborish)
    $check = $pdo->prepare("SELECT 1 FROM pg_database WHERE datname = ?");
    $check->execute([$dbname]);

    if (!$check->fetchColumn()) {
        $pdo->exec("CREATE DATABASE \"{$dbname}\" ENCODING 'UTF8'");
        log_ok("'{$dbname}' bazasi yaratildi");
    } else {
        log_info("'{$dbname}' bazasi allaqachon mavjud — o'tkazildi");
    }
    unset($pdo);

    // 3. Yangi bazaga ulanish
    try {
        $db = new PDO(
            "pgsql:host={$host};port={$port};dbname={$dbname}",
            $user, $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        log_ok("'{$dbname}' bazasiga ulandi");
    } catch (Exception $e) {
        log_err("'{$dbname}' ga ulanib bo'lmadi: " . $e->getMessage());
        goto render;
    }

    // 4. SQL faylni TO'LIQ bir blokda bajarish
    $sqlFile = __DIR__ . '/../database/schema.sql';

    if (!file_exists($sqlFile)) {
        log_err("SQL fayl topilmadi: {$sqlFile}");
        goto render;
    }

    $sql = file_get_contents($sqlFile);

    // Izohlarni olib tashlaymiz va bo'sh qatorlarni tozalaymiz
    $sql = preg_replace('/^--.*$/m', '', $sql);

    // SQL ni alohida buyruqlarga ajratamiz — faqat ; belgisi bo'yicha,
    // lekin har bir buyruqni trim qilib, bo'shlarini o'tkazib yuboramiz
    $parts = preg_split('/;\s*\n/', $sql);

    $success = 0;
    $skipped = 0;
    $errors  = 0;

    foreach ($parts as $part) {
        $part = trim($part);
        if (empty($part)) continue;

        try {
            $db->exec($part);
            $success++;
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            // "already exists" xatolarini e'tiborsiz qoldiramiz
            if (
                stripos($msg, 'already exists') !== false ||
                stripos($msg, 'duplicate key')  !== false
            ) {
                $skipped++;
            } else {
                log_err("SQL xato: " . trim(preg_replace('/\s+/', ' ', $msg)));
                $errors++;
            }
        }
    }

    log_ok("SQL bajarildi: {$success} ta muvaffaqiyatli" .
           ($skipped > 0 ? ", {$skipped} ta mavjud (o'tkazildi)" : '') .
           ($errors  > 0 ? ", {$errors} ta xato" : ''));

    // 5. Jadvallar tekshiruvi
    $tables = [
        'users', 'subscriptions', 'payments', 'plan_settings', 'templates',
        'certificates', 'constructor_layouts', 'bulk_jobs', 'password_resets', 'refresh_tokens',
    ];

    $allOk = true;
    foreach ($tables as $t) {
        $exists = $db->query("SELECT to_regclass('public.{$t}')")->fetchColumn();
        if ($exists) {
            log_ok("Jadval: {$t} ✓");
        } else {
            log_err("Jadval yaratilmadi: {$t}");
            $allOk = false;
        }
    }

    if (!$allOk) {
        log_err("Ba'zi jadvallar yaratilmadi. Qayta urinib ko'ring.");
        goto render;
    }

    // 6. Admin tekshiruvi
    try {
        $admin = $db->query(
            "SELECT id, email FROM users WHERE role IN ('admin', 'super_admin') LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);

        if ($admin) {
            log_ok("Admin foydalanuvchi tayyor: " . $admin['email']);
        } else {
            log_err("Admin foydalanuvchi topilmadi — INSERT ishlamagan bo'lishi mumkin");
        }
    } catch (Exception $e) {
        log_err("Admin tekshiruvida xato: " . $e->getMessage());
    }

    $done = true;
}

render:
?>
<!DOCTYPE html>
<html lang="uz">
<head>
<meta charset="UTF-8">
<title>O'rnatish — Sertifikat Tizimi</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
  font-family: -apple-system, sans-serif; background: #f1f5f9;
  min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px;
}
.card {
  background: #fff; border-radius: 16px; padding: 40px;
  width: 100%; max-width: 600px; box-shadow: 0 10px 30px rgba(0,0,0,.12);
}
h1 { font-size: 26px; font-weight: 800; color: #4f46e5; margin-bottom: 4px; }
.sub { color: #64748b; font-size: 14px; margin-bottom: 28px; }
.info-grid {
  background: #f8fafc; border-radius: 10px; padding: 16px;
  margin-bottom: 20px; font-size: 14px;
}
.info-row {
  display: flex; justify-content: space-between; align-items: center;
  padding: 8px 0; border-bottom: 1px solid #e2e8f0;
}
.info-row:last-child { border: none; }
.info-label { color: #64748b; }
.info-val { font-family: monospace; font-weight: 600; color: #1e293b; }
.warn {
  background: #fffbeb; border: 1px solid #f59e0b; border-radius: 10px;
  padding: 14px 16px; font-size: 13px; color: #92400e; margin-bottom: 20px;
}
.btn {
  display: block; width: 100%; padding: 14px; background: #4f46e5; color: #fff;
  border: none; border-radius: 10px; font-size: 16px; font-weight: 700;
  cursor: pointer; transition: background .2s;
}
.btn:hover { background: #3730a3; }
.btn-red { background: #dc2626; }
.btn-red:hover { background: #b91c1c; }
.log { margin-top: 24px; display: flex; flex-direction: column; gap: 6px; }
.log-item {
  padding: 10px 14px; border-radius: 8px; font-size: 13px;
  display: flex; align-items: flex-start; gap: 10px; line-height: 1.5;
}
.ok   { background: #f0fdf4; color: #166534; }
.err  { background: #fef2f2; color: #991b1b; }
.info { background: #eff6ff; color: #1e40af; }
.success-box {
  margin-top: 24px; padding: 24px; background: #f0fdf4;
  border: 2px solid #16a34a; border-radius: 12px; text-align: center;
}
.success-box h2 { color: #166534; font-size: 22px; margin-bottom: 10px; }
.success-box .creds {
  background: #fff; border-radius: 8px; padding: 14px; margin: 14px 0;
  font-size: 14px; color: #374151;
}
.success-box .creds strong { font-family: monospace; color: #4f46e5; }
.go-btn {
  display: inline-block; padding: 12px 32px; background: #4f46e5; color: #fff;
  border-radius: 8px; text-decoration: none; font-weight: 700; font-size: 15px;
}
.delete-warn {
  margin-top: 14px; font-size: 12px; color: #dc2626;
  background: #fef2f2; padding: 8px 12px; border-radius: 6px;
}
</style>
</head>
<body>
<div class="card">
  <h1>🎓 Sertifikat Tizimi</h1>
  <p class="sub">Ma'lumotlar bazasini bir marta o'rnatish</p>

  <?php if (empty($log)): ?>

  <div class="info-grid">
    <div class="info-row">
      <span class="info-label">Server</span>
      <span class="info-val"><?= htmlspecialchars($host) ?>:<?= htmlspecialchars($port) ?></span>
    </div>
    <div class="info-row">
      <span class="info-label">Baza nomi</span>
      <span class="info-val"><?= htmlspecialchars($dbname) ?></span>
    </div>
    <div class="info-row">
      <span class="info-label">Foydalanuvchi</span>
      <span class="info-val"><?= htmlspecialchars($user) ?></span>
    </div>
    <div class="info-row">
      <span class="info-label">Parol</span>
      <span class="info-val"><?= $pass ? str_repeat('•', strlen($pass)) : '(bo\'sh)' ?></span>
    </div>
  </div>

  <div class="warn">
    ⚠️ PostgreSQL ishga tushgan bo'lishi va <strong><?= htmlspecialchars($user) ?></strong>
    foydalanuvchisi <strong>baza yaratish</strong> huquqiga ega bo'lishi kerak.
    <br><br>
    Parol noto'g'ri bo'lsa — <code>.env</code> faylida <code>DB_PASS</code> ni o'zgartiring.
  </div>

  <form method="POST">
    <button type="submit" name="install" value="1" class="btn">
      🚀 Bazani yaratish va jadvallarni sozlash
    </button>
  </form>

  <?php else: ?>

  <div class="log">
    <?php foreach ($log as $item): ?>
      <div class="log-item <?= $item['type'] ?>">
        <span><?= $item['type'] === 'ok' ? '✅' : ($item['type'] === 'info' ? 'ℹ️' : '❌') ?></span>
        <span><?= htmlspecialchars($item['msg']) ?></span>
      </div>
    <?php endforeach; ?>
  </div>

  <?php
  $hasErr = in_array('err', array_column($log, 'type'));
  ?>

  <?php if ($done && !$hasErr): ?>
  <div class="success-box">
    <h2>🎉 Muvaffaqiyatli o'rnatildi!</h2>
    <div class="creds">
      <div>Email: <strong>admin@sertifikat.uz</strong></div>
      <div style="margin-top:6px;">Parol: <strong>password</strong></div>
    </div>
    <a href="/login.html" class="go-btn">Saytga kirish →</a>
    <div class="delete-warn">
      ⚠️ Xavfsizlik uchun <code>public/install.php</code> faylini o'chiring!
    </div>
  </div>

  <?php elseif ($hasErr): ?>
  <div style="margin-top:20px;">
    <div class="warn">
      ❌ Xato yuz berdi. <code>.env</code> faylida <code>DB_PASS</code> to'g'riligini
      tekshiring va qayta urining.
    </div>
    <form method="POST">
      <button type="submit" name="install" value="1" class="btn btn-red">🔄 Qayta urinish</button>
    </form>
  </div>
  <?php endif; ?>

  <?php endif; ?>
</div>
</body>
</html>
