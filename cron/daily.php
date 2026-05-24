<?php
// Kunlik cron: php cron/daily.php
// Windows Task Scheduler yoki Linux crontab: 0 9 * * * php /path/to/cron/daily.php
chdir(__DIR__ . '/..');

require_once 'src/Helpers/env.php';
loadEnv(__DIR__ . '/../.env');
require_once 'config/database.php';
require_once 'src/Models/SubscriptionModel.php';
require_once 'src/Services/EmailService.php';

$log = fn(string $msg) => print('[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL);

// 1. Muddati o'tgan obunalarni expire qilish
$subModel = new SubscriptionModel();
$expired  = $subModel->expireOld();
$log("Muddati o'tgan obunalar: {$expired} ta");

// 2. Tugayotgan obunalarga xabar yuborish
$notified = $subModel->notifyExpiring();
$log("Xabar yuborildi: {$notified} ta");

$log('Cron bajarildi.');
