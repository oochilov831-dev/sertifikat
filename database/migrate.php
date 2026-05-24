<?php
/**
 * CLI Migration Runner
 * Usage: php database/migrate.php
 */

require_once __DIR__ . '/../src/Helpers/env.php';
loadEnv(__DIR__ . '/../.env');
require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "--- PostgreSQL Migration Runner ---\n";

    // 1. Create tracking table if not exists
    $db->exec("
        CREATE TABLE IF NOT EXISTS migrations (
            id SERIAL PRIMARY KEY,
            name VARCHAR(255) NOT NULL UNIQUE,
            executed_at TIMESTAMP DEFAULT NOW()
        )
    ");

    // 2. Scan migrations directory
    $dir = __DIR__ . '/migrations';
    if (!is_dir($dir)) {
        die("Error: Migrations directory not found at {$dir}\n");
    }

    $files = glob($dir . '/*.sql');
    sort($files); // Ensure sorted order

    $executedCount = 0;
    foreach ($files as $file) {
        $filename = basename($file);

        // Check if already executed
        $stmt = $db->prepare("SELECT 1 FROM migrations WHERE name = ?");
        $stmt->execute([$filename]);
        if ($stmt->fetchColumn()) {
            continue;
        }

        echo "Applying migration: {$filename}... ";

        $sql = file_get_contents($file);
        if (trim($sql) === '') {
            echo "Empty, skipped.\n";
            continue;
        }

        // Run inside transaction for safety
        $db->beginTransaction();
        try {
            $db->exec($sql);
            $logStmt = $db->prepare("INSERT INTO migrations (name) VALUES (?)");
            $logStmt->execute([$filename]);
            $db->commit();
            echo "SUCCESS\n";
            $executedCount++;
        } catch (Exception $e) {
            $db->rollBack();
            echo "FAILED\n";
            die("Migration error in {$filename}: " . $e->getMessage() . "\nMigrations aborted.\n");
        }
    }

    echo "Migrations complete! Applied {$executedCount} new migration(s).\n";

} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage() . "\n");
}
