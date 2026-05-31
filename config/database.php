<?php

class Database {
    private static ?PDO $instance = null;

    public static function getInstance(): PDO {
        if (self::$instance === null) {
            $host = $_ENV['DB_HOST'] ?? 'localhost';
            $port = $_ENV['DB_PORT'] ?? '5432';
            $name = $_ENV['DB_NAME'] ?? 'sertifikat_db';
            $user = $_ENV['DB_USER'] ?? 'postgres';
            $pass = $_ENV['DB_PASS'] ?? 'postgres';

            $dsn = "pgsql:host={$host};port={$port};dbname={$name}";

            self::$instance = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);

            // Auto-create user_sessions table if it doesn't exist (e.g. on live servers)
            try {
                self::$instance->exec("
                    CREATE TABLE IF NOT EXISTS user_sessions (
                        id SERIAL PRIMARY KEY,
                        user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                        sid VARCHAR(64) UNIQUE NOT NULL,
                        ip_address VARCHAR(45) NOT NULL,
                        user_agent TEXT,
                        device_type VARCHAR(50),
                        is_revoked BOOLEAN DEFAULT FALSE,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    );
                    CREATE INDEX IF NOT EXISTS idx_user_sessions_user_id ON user_sessions(user_id);
                ");
            } catch (\Throwable $e) {
                // Ignore any DB bootstrap issues gracefully
            }
        }
        return self::$instance;
    }
}
