-- Migration 01: Add signature and seal columns to users
ALTER TABLE users ADD COLUMN IF NOT EXISTS signature_url VARCHAR(500);
ALTER TABLE users ADD COLUMN IF NOT EXISTS seal_url VARCHAR(500);
