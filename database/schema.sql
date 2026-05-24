-- =============================================================
--🎓 Professional Sertifikat Berish Tizimi — PostgreSQL Schema
-- Baza to'liq sozlanishi va ishga tushishi uchun yagona SQL fayli.
-- =============================================================

CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pgcrypto";

-- =============================================
-- 1. USERS jadvali
-- =============================================
CREATE TABLE IF NOT EXISTS users (
    id            SERIAL PRIMARY KEY,
    uuid          UUID NOT NULL DEFAULT uuid_generate_v4() UNIQUE,
    name          VARCHAR(255) NOT NULL,
    email         VARCHAR(255) UNIQUE,
    phone         VARCHAR(20) UNIQUE,
    password      VARCHAR(255) NOT NULL,
    role          VARCHAR(20) NOT NULL DEFAULT 'user' CHECK (role IN ('user', 'admin', 'super_admin')),
    is_active     BOOLEAN NOT NULL DEFAULT true,
    is_verified   BOOLEAN NOT NULL DEFAULT false,
    avatar        VARCHAR(500),
    company       VARCHAR(255),
    logo_url      VARCHAR(500),
    signature_url VARCHAR(500),
    seal_url      VARCHAR(500),
    totp_secret   VARCHAR(64),
    totp_enabled  BOOLEAN NOT NULL DEFAULT false,
    recovery_codes JSONB,
    api_key       VARCHAR(64) UNIQUE,
    created_at    TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at    TIMESTAMP NOT NULL DEFAULT NOW(),
    deleted_at    TIMESTAMP
);

ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check;
ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('user', 'admin', 'super_admin'));

CREATE INDEX IF NOT EXISTS idx_users_email ON users(email);
CREATE INDEX IF NOT EXISTS idx_users_phone ON users(phone);
CREATE INDEX IF NOT EXISTS idx_users_uuid  ON users(uuid);
CREATE INDEX IF NOT EXISTS idx_users_api_key ON users(api_key);

-- =============================================
-- 2. VERIFICATION CODES (email/sms tasdiqlash)
-- =============================================
CREATE TABLE IF NOT EXISTS verification_codes (
    id         SERIAL PRIMARY KEY,
    user_id    INTEGER REFERENCES users(id) ON DELETE CASCADE,
    code       VARCHAR(10) NOT NULL,
    type       VARCHAR(20) NOT NULL CHECK (type IN ('email', 'phone', 'password_reset')),
    expires_at TIMESTAMP NOT NULL,
    used       BOOLEAN NOT NULL DEFAULT false,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

-- =============================================
-- 3. SUBSCRIPTIONS (obunalar)
-- =============================================
CREATE TABLE IF NOT EXISTS subscriptions (
    id              SERIAL PRIMARY KEY,
    user_id         INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    plan            VARCHAR(20) NOT NULL DEFAULT 'free' CHECK (plan IN ('free', 'standard', 'pro')),
    status          VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'expired', 'cancelled')),
    cert_limit      INTEGER NOT NULL DEFAULT 5,
    cert_used       INTEGER NOT NULL DEFAULT 0,
    started_at      TIMESTAMP NOT NULL DEFAULT NOW(),
    expires_at      TIMESTAMP,
    auto_renew      BOOLEAN NOT NULL DEFAULT false,
    notified_expiry BOOLEAN NOT NULL DEFAULT false,
    created_at      TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_subscriptions_user_id ON subscriptions(user_id);

-- =============================================
-- 4. PAYMENTS (to'lovlar)
-- =============================================
CREATE TABLE IF NOT EXISTS payments (
    id              SERIAL PRIMARY KEY,
    user_id         INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    subscription_id INTEGER REFERENCES subscriptions(id),
    provider        VARCHAR(20) NOT NULL CHECK (provider IN ('click', 'payme', 'uzum', 'free')),
    transaction_id  VARCHAR(255),
    amount          DECIMAL(12,2) NOT NULL DEFAULT 0,
    currency        VARCHAR(3) NOT NULL DEFAULT 'UZS',
    status          VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'success', 'failed', 'refunded')),
    plan            VARCHAR(20) NOT NULL,
    meta            JSONB,
    paid_at         TIMESTAMP,
    created_at      TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_payments_user_id        ON payments(user_id);
CREATE INDEX IF NOT EXISTS idx_payments_transaction_id ON payments(transaction_id);
CREATE INDEX IF NOT EXISTS idx_payments_status         ON payments(status);

-- =============================================
-- 5. PLAN SETTINGS (tarif sozlamalari)
-- =============================================
CREATE TABLE IF NOT EXISTS plan_settings (
    plan_key        VARCHAR(20) PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    price           DECIMAL(12,2) NOT NULL DEFAULT 0,
    cert_limit      INTEGER NOT NULL DEFAULT 0,
    watermark       BOOLEAN NOT NULL DEFAULT false,
    custom_template BOOLEAN NOT NULL DEFAULT false,
    is_active       BOOLEAN NOT NULL DEFAULT true,
    sort_order      INTEGER NOT NULL DEFAULT 0,
    updated_by      INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMP NOT NULL DEFAULT NOW()
);

INSERT INTO plan_settings (plan_key, name, price, cert_limit, watermark, custom_template, sort_order)
VALUES
    ('free',     'Bepul',    0,     5,   true,  false, 1),
    ('standard', 'Standart', 35000, 100, false, true,  2),
    ('pro',      'Pro',      99000, -1,  false, true,  3)
ON CONFLICT (plan_key) DO NOTHING;

-- =============================================
-- 6. TEMPLATES (shablonlar)
-- =============================================
CREATE TABLE IF NOT EXISTS templates (
    id            SERIAL PRIMARY KEY,
    name          VARCHAR(255) NOT NULL,
    description   TEXT,
    preview_url   VARCHAR(500),
    file_url      VARCHAR(500) NOT NULL,
    category      VARCHAR(100) DEFAULT 'general',
    is_premium    BOOLEAN NOT NULL DEFAULT false,
    is_active     BOOLEAN NOT NULL DEFAULT true,
    width         INTEGER NOT NULL DEFAULT 1280,
    height        INTEGER NOT NULL DEFAULT 960,
    orientation   VARCHAR(20) DEFAULT 'landscape',
    doc_type      VARCHAR(20) DEFAULT 'certificate',
    fields        JSONB NOT NULL DEFAULT '[]',
    status        VARCHAR(20) NOT NULL DEFAULT 'approved',
    submitted_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
    reject_reason VARCHAR(500),
    submitted_at  TIMESTAMP,
    reviewed_at   TIMESTAMP,
    created_by    INTEGER REFERENCES users(id),
    created_at    TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at    TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_templates_category ON templates(category) WHERE is_active = true;
CREATE INDEX IF NOT EXISTS idx_templates_status ON templates(status) WHERE is_active = true;

-- =============================================
-- 7. CERTIFICATES (sertifikatlar)
-- =============================================
CREATE TABLE IF NOT EXISTS certificates (
    id              SERIAL PRIMARY KEY,
    uuid            UUID NOT NULL DEFAULT uuid_generate_v4() UNIQUE,
    cert_id         VARCHAR(20) NOT NULL UNIQUE,
    user_id         INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    template_id     INTEGER REFERENCES templates(id),
    recipient_name  VARCHAR(255) NOT NULL,
    recipient_email VARCHAR(255),
    course_name     VARCHAR(500),
    issued_date     DATE NOT NULL DEFAULT CURRENT_DATE,
    expiry_date     DATE,
    orientation     VARCHAR(20) DEFAULT 'landscape',
    doc_type        VARCHAR(20) DEFAULT 'certificate',
    fields          JSONB NOT NULL DEFAULT '{}',
    file_pdf        VARCHAR(500),
    file_png        VARCHAR(500),
    qr_code         VARCHAR(500),
    watermark       BOOLEAN NOT NULL DEFAULT false,
    is_valid        BOOLEAN NOT NULL DEFAULT true,
    view_count      INTEGER NOT NULL DEFAULT 0,
    cert_hash       VARCHAR(64),
    revoked_at      TIMESTAMP,
    revoke_reason   VARCHAR(500),
    created_at      TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_certificates_user_id       ON certificates(user_id);
CREATE INDEX IF NOT EXISTS idx_certificates_uuid          ON certificates(uuid);
CREATE INDEX IF NOT EXISTS idx_certificates_cert_id       ON certificates(cert_id);
CREATE INDEX IF NOT EXISTS idx_certificates_recipient_name ON certificates(recipient_name);
CREATE INDEX IF NOT EXISTS idx_certificates_cert_hash     ON certificates(cert_hash);
CREATE INDEX IF NOT EXISTS idx_certificates_doc_type      ON certificates(doc_type);

-- =============================================
-- 8. CONSTRUCTOR LAYOUTS (foydalanuvchi saqlagan ishlar)
-- =============================================
CREATE TABLE IF NOT EXISTS constructor_layouts (
    id         SERIAL PRIMARY KEY,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    name       VARCHAR(255) NOT NULL DEFAULT 'Asosiy layout',
    layout     JSONB NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE(user_id)
);

-- =============================================
-- 9. BULK JOBS (ommaviy generatsiya)
-- =============================================
CREATE TABLE IF NOT EXISTS bulk_jobs (
    id           SERIAL PRIMARY KEY,
    user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    template_id  INTEGER REFERENCES templates(id),
    filename     VARCHAR(255),
    total        INTEGER NOT NULL DEFAULT 0,
    processed    INTEGER NOT NULL DEFAULT 0,
    failed       INTEGER NOT NULL DEFAULT 0,
    status       VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'processing', 'completed', 'failed')),
    error_log    TEXT,
    created_at   TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at   TIMESTAMP NOT NULL DEFAULT NOW()
);

-- =============================================
-- 10. PASSWORD RESETS
-- =============================================
CREATE TABLE IF NOT EXISTS password_resets (
    id         SERIAL PRIMARY KEY,
    email      VARCHAR(255),
    phone      VARCHAR(20),
    token      VARCHAR(255) NOT NULL UNIQUE,
    expires_at TIMESTAMP NOT NULL,
    used       BOOLEAN NOT NULL DEFAULT false,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

-- =============================================
-- 11. REFRESH TOKENS
-- =============================================
CREATE TABLE IF NOT EXISTS refresh_tokens (
    id         SERIAL PRIMARY KEY,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token      VARCHAR(500) NOT NULL UNIQUE,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

-- =============================================
-- 12. RATE LIMITS
-- =============================================
CREATE TABLE IF NOT EXISTS rate_limits (
    id         BIGSERIAL PRIMARY KEY,
    key        VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_rate_limits_key_time ON rate_limits(key, created_at);

-- =============================================
-- 13. CERT SCANS (skan statistikasi)
-- =============================================
CREATE TABLE IF NOT EXISTS cert_scans (
    id         BIGSERIAL PRIMARY KEY,
    cert_id    INTEGER NOT NULL REFERENCES certificates(id) ON DELETE CASCADE,
    ip         VARCHAR(45),
    user_agent VARCHAR(500),
    referer    VARCHAR(500),
    country    VARCHAR(100),
    scanned_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_cert_scans_cert_id ON cert_scans(cert_id);
CREATE INDEX IF NOT EXISTS idx_cert_scans_time    ON cert_scans(scanned_at);

-- =============================================
-- 14. ADMIN ACTIVITY LOGS
-- =============================================
CREATE TABLE IF NOT EXISTS admin_activity_logs (
    id          BIGSERIAL PRIMARY KEY,
    admin_id    INTEGER REFERENCES users(id) ON DELETE SET NULL,
    action      VARCHAR(100) NOT NULL,
    entity_type VARCHAR(80),
    entity_id   INTEGER,
    meta        JSONB NOT NULL DEFAULT '{}',
    created_at  TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_admin_activity_logs_admin_id ON admin_activity_logs(admin_id);
CREATE INDEX IF NOT EXISTS idx_admin_activity_logs_created_at ON admin_activity_logs(created_at);

-- =============================================
-- 15. EMAIL VERIFICATIONS
-- =============================================
CREATE TABLE IF NOT EXISTS email_verifications (
    id         BIGSERIAL PRIMARY KEY,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    code       VARCHAR(10) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    used       BOOLEAN NOT NULL DEFAULT false,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_email_verifications_user ON email_verifications(user_id, used);

-- =============================================
-- 16. AUDIT LOGS
-- =============================================
CREATE TABLE IF NOT EXISTS audit_logs (
    id         BIGSERIAL PRIMARY KEY,
    user_id    INTEGER REFERENCES users(id) ON DELETE SET NULL,
    action     VARCHAR(50) NOT NULL,
    target     VARCHAR(255),
    target_id  INTEGER,
    meta       JSONB,
    ip         VARCHAR(45),
    user_agent VARCHAR(500),
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_audit_logs_user_time ON audit_logs(user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_audit_logs_action ON audit_logs(action);

-- =============================================
-- 17. DISCOUNT CODES (promokodlar)
-- =============================================
CREATE TABLE IF NOT EXISTS discount_codes (
    id          BIGSERIAL PRIMARY KEY,
    code        VARCHAR(50) NOT NULL UNIQUE,
    discount    INTEGER NOT NULL,
    type        VARCHAR(20) NOT NULL DEFAULT 'percent',
    max_uses    INTEGER,
    used_count  INTEGER NOT NULL DEFAULT 0,
    plan_filter VARCHAR(20),
    valid_from  TIMESTAMP,
    valid_to    TIMESTAMP,
    is_active   BOOLEAN NOT NULL DEFAULT true,
    created_at  TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_discount_codes_code ON discount_codes(code) WHERE is_active = true;

CREATE TABLE IF NOT EXISTS discount_code_uses (
    id          BIGSERIAL PRIMARY KEY,
    code_id     INTEGER NOT NULL REFERENCES discount_codes(id) ON DELETE CASCADE,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    payment_id  INTEGER,
    used_at     TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_dcu_user ON discount_code_uses(user_id);

-- =============================================
-- 18. BROADCASTS (email ommaviy xabarlar)
-- =============================================
CREATE TABLE IF NOT EXISTS broadcasts (
    id              BIGSERIAL PRIMARY KEY,
    subject         VARCHAR(255) NOT NULL,
    body_html       TEXT NOT NULL,
    filter_plan     VARCHAR(20),
    filter_verified BOOLEAN,
    sent_count      INTEGER NOT NULL DEFAULT 0,
    failed_count    INTEGER NOT NULL DEFAULT 0,
    status          VARCHAR(20) NOT NULL DEFAULT 'draft',
    created_by      INTEGER REFERENCES users(id),
    sent_at         TIMESTAMP,
    created_at      TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_broadcasts_status ON broadcasts(status, created_at DESC);

-- =============================================================
-- 19. DEFAULT SEED DATA (Foydalanuvchi, Obuna va Shablonlar)
-- =============================================================

-- Defolt Super Admin foydalanuvchi: admin@sertifikat.uz / parol: password
INSERT INTO users (name, email, password, role, is_active, is_verified)
VALUES (
    'Administrator',
    'admin@sertifikat.uz',
    '$2y$12$6W/1knqGn.BeC8e8rzSyu.O6eR6QO3cXiW.F8n/Q/EYFmlC/jDnoi',
    'super_admin',
    true,
    true
) ON CONFLICT (email) DO NOTHING;

-- Admin uchun Pro obuna
INSERT INTO subscriptions (user_id, plan, status, cert_limit, expires_at)
SELECT id, 'pro', 'active', -1, '2099-12-31'
FROM users WHERE email = 'admin@sertifikat.uz'
ON CONFLICT DO NOTHING;

-- Elegant Gold shabloni
INSERT INTO templates (name, description, file_url, preview_url, category, is_premium, width, height, fields)
SELECT
    'Elegant Gold Innovatsion',
    'Premium ko''rinishdagi oltin geometrik elementlar va zamonaviy markaziy kompozitsiya.',
    'uploads/templates/cert_classic_gold.png',
    'uploads/templates/cert_classic_gold.png',
    'certificate',
    false,
    1280,
    960,
    '[
      {"id":1,"type":"text","text":"SERTIFIKAT","variable":"","x":410,"y":245,"w":460,"h":70,"fontSize":54,"color":"#2a251e","fontWeight":"800","align":"center"},
      {"id":2,"type":"text","text":"Ism Familiya","variable":"{{recipient_name}}","x":290,"y":365,"w":700,"h":70,"fontSize":48,"color":"#1f2937","fontWeight":"bold","align":"center"},
      {"id":3,"type":"text","text":"Kurs nomi","variable":"{{course_name}}","x":330,"y":470,"w":620,"h":54,"fontSize":30,"color":"#9a6b12","fontWeight":"bold","align":"center"},
      {"id":4,"type":"text","text":"Sana","variable":"{{issued_date}}","x":250,"y":610,"w":300,"h":36,"fontSize":20,"color":"#374151","fontWeight":"normal","align":"center"},
      {"id":5,"type":"text","text":"Tashkilot","variable":"{{issuer_name}}","x":730,"y":610,"w":300,"h":36,"fontSize":20,"color":"#374151","fontWeight":"normal","align":"center"},
      {"id":6,"type":"text","text":"ID","variable":"{{cert_id}}","x":485,"y":750,"w":310,"h":30,"fontSize":18,"color":"#6b7280","fontWeight":"normal","align":"center"}
    ]'::jsonb
WHERE NOT EXISTS (SELECT 1 FROM templates WHERE file_url = 'uploads/templates/cert_classic_gold.png');

-- NextGen Blue shabloni
INSERT INTO templates (name, description, file_url, preview_url, category, is_premium, width, height, fields)
SELECT
    'NextGen Blue Creative',
    'Dinamik ko''k-cyan shakllar, nuqtali harakat patterni va zamonaviy texnologik kompozitsiya.',
    'uploads/templates/cert_modern_blue.png',
    'uploads/templates/cert_modern_blue.png',
    'certificate',
    false,
    1280,
    960,
    '[
      {"id":1,"type":"text","text":"CERTIFICATE","variable":"","x":355,"y":165,"w":570,"h":70,"fontSize":58,"color":"#0f172a","fontWeight":"800","align":"center"},
      {"id":2,"type":"text","text":"Ism Familiya","variable":"{{recipient_name}}","x":315,"y":330,"w":650,"h":70,"fontSize":46,"color":"#1d4ed8","fontWeight":"bold","align":"center"},
      {"id":3,"type":"text","text":"Kurs nomi","variable":"{{course_name}}","x":340,"y":450,"w":600,"h":50,"fontSize":28,"color":"#334155","fontWeight":"bold","align":"center"},
      {"id":4,"type":"text","text":"Sana","variable":"{{issued_date}}","x":140,"y":650,"w":300,"h":36,"fontSize":20,"color":"#0f172a","fontWeight":"normal","align":"center"},
      {"id":5,"type":"text","text":"Tashkilot","variable":"{{issuer_name}}","x":850,"y":650,"w":300,"h":36,"fontSize":20,"color":"#0f172a","fontWeight":"normal","align":"center"}
    ]'::jsonb
WHERE NOT EXISTS (SELECT 1 FROM templates WHERE file_url = 'uploads/templates/cert_modern_blue.png');

-- Academic Green shabloni
INSERT INTO templates (name, description, file_url, preview_url, category, is_premium, width, height, fields)
SELECT
    'Academic Green Prestige',
    'Akademik diplomlar uchun zamonaviy yashil premium uslub, yumshoq pattern va rasmiy chiziqlar.',
    'uploads/templates/diploma_academic_green.png',
    'uploads/templates/diploma_academic_green.png',
    'diploma',
    false,
    1280,
    960,
    '[
      {"id":1,"type":"text","text":"DIPLOM","variable":"","x":430,"y":185,"w":420,"h":80,"fontSize":64,"color":"#166534","fontWeight":"800","align":"center"},
      {"id":2,"type":"text","text":"Ism Familiya","variable":"{{recipient_name}}","x":275,"y":355,"w":730,"h":70,"fontSize":48,"color":"#18281e","fontWeight":"bold","align":"center"},
      {"id":3,"type":"text","text":"Yo''nalish","variable":"{{course_name}}","x":300,"y":475,"w":680,"h":58,"fontSize":30,"color":"#166534","fontWeight":"bold","align":"center"},
      {"id":4,"type":"text","text":"Berilgan sana","variable":"{{issued_date}}","x":250,"y":635,"w":310,"h":34,"fontSize":20,"color":"#374151","fontWeight":"normal","align":"center"},
      {"id":5,"type":"text","text":"Tashkilot","variable":"{{issuer_name}}","x":720,"y":635,"w":310,"h":34,"fontSize":20,"color":"#374151","fontWeight":"normal","align":"center"}
    ]'::jsonb
WHERE NOT EXISTS (SELECT 1 FROM templates WHERE file_url = 'uploads/templates/diploma_academic_green.png');

-- Mono Editorial shabloni
INSERT INTO templates (name, description, file_url, preview_url, category, is_premium, width, height, fields)
SELECT
    'Mono Editorial Modern',
    'Qora-oq editorial uslubdagi minimal, zamonaviy va chop etishga qulay sertifikat.',
    'uploads/templates/minimal_black_white.png',
    'uploads/templates/minimal_black_white.png',
    'certificate',
    false,
    1280,
    960,
    '[
      {"id":1,"type":"text","text":"SERTIFIKAT","variable":"","x":400,"y":230,"w":480,"h":70,"fontSize":58,"color":"#111827","fontWeight":"800","align":"center"},
      {"id":2,"type":"text","text":"Ism Familiya","variable":"{{recipient_name}}","x":285,"y":365,"w":710,"h":70,"fontSize":46,"color":"#111827","fontWeight":"bold","align":"center"},
      {"id":3,"type":"text","text":"Kurs nomi","variable":"{{course_name}}","x":330,"y":485,"w":620,"h":50,"fontSize":28,"color":"#374151","fontWeight":"bold","align":"center"},
      {"id":4,"type":"text","text":"Sana","variable":"{{issued_date}}","x":200,"y":610,"w":320,"h":34,"fontSize":20,"color":"#111827","fontWeight":"normal","align":"center"},
      {"id":5,"type":"text","text":"Tashkilot","variable":"{{issuer_name}}","x":760,"y":610,"w":320,"h":34,"fontSize":20,"color":"#111827","fontWeight":"normal","align":"center"}
    ]'::jsonb
WHERE NOT EXISTS (SELECT 1 FROM templates WHERE file_url = 'uploads/templates/minimal_black_white.png');

-- Luxury Emerald Gold shabloni
INSERT INTO templates (name, description, file_url, preview_url, category, is_premium, width, height, fields)
SELECT
    'Luxury Emerald Gold',
    'Zumrad yashil fon va boy oltin burchak naqshlari bilan bezatilgan shohona dizayn.',
    'uploads/templates/cert_luxury_emerald.png',
    'uploads/templates/cert_luxury_emerald.png',
    'korporativ',
    true,
    1280,
    960,
    '[
      {"id":1,"type":"text","variable":"{{recipient_name}}","text":"Ism Familiya","x":140,"y":500,"w":1000,"h":80,"fontSize":38,"color":"#ffffff","fontWeight":"bold","align":"center"},
      {"id":2,"type":"text","variable":"{{course_name}}","text":"Kurs nomi","x":140,"y":630,"w":1000,"h":50,"fontSize":22,"color":"#f4d75e","fontWeight":"bold","align":"center"},
      {"id":3,"type":"text","variable":"{{issued_date}}","text":"Sana","x":140,"y":820,"w":260,"h":32,"fontSize":16,"color":"#374151","fontWeight":"normal","align":"left"},
      {"id":4,"type":"text","variable":"{{cert_id}}","text":"ID","x":500,"y":820,"w":300,"h":32,"fontSize":14,"color":"#6b7280","fontWeight":"normal","align":"center"},
      {"id":5,"type":"qr","variable":"{{cert_id}}","text":"QR","x":1110,"y":800,"w":120,"h":120,"fontSize":12,"color":"#111827","fontWeight":"normal","align":"center"}
    ]'::jsonb
WHERE NOT EXISTS (SELECT 1 FROM templates WHERE file_url = 'uploads/templates/cert_luxury_emerald.png');

-- Royal Purple Velvet shabloni
INSERT INTO templates (name, description, file_url, preview_url, category, is_premium, width, height, fields)
SELECT
    'Royal Purple Velvet',
    'Royal binafsha gradient va sehrli oltin yulduzlar uyg''unligidagi premium dizayn.',
    'uploads/templates/cert_royal_purple.png',
    'uploads/templates/cert_royal_purple.png',
    'zamonaviy',
    true,
    1280,
    960,
    '[
      {"id":1,"type":"text","variable":"{{recipient_name}}","text":"Ism Familiya","x":140,"y":460,"w":1000,"h":80,"fontSize":38,"color":"#ffffff","fontWeight":"bold","align":"center"},
      {"id":2,"type":"text","variable":"{{course_name}}","text":"Kurs nomi","x":140,"y":590,"w":1000,"h":50,"fontSize":22,"color":"#fda4b4","fontWeight":"bold","align":"center"},
      {"id":3,"type":"text","variable":"{{issued_date}}","text":"Sana","x":140,"y":820,"w":260,"h":32,"fontSize":16,"color":"#374151","fontWeight":"normal","align":"left"},
      {"id":4,"type":"text","variable":"{{cert_id}}","text":"ID","x":500,"y":820,"w":300,"h":32,"fontSize":14,"color":"#6b7280","fontWeight":"normal","align":"center"},
      {"id":5,"type":"qr","variable":"{{cert_id}}","text":"QR","x":1110,"y":800,"w":120,"h":120,"fontSize":12,"color":"#111827","fontWeight":"normal","align":"center"}
    ]'::jsonb
WHERE NOT EXISTS (SELECT 1 FROM templates WHERE file_url = 'uploads/templates/cert_royal_purple.png');

-- Futuristic Cyberpunk shabloni
INSERT INTO templates (name, description, file_url, preview_url, category, is_premium, width, height, fields)
SELECT
    'Futuristic Cyberpunk',
    'Glow neon cyan va binafsha kiber-panjara, yuqori texnologik va Dasturchilar uchun dizayn.',
    'uploads/templates/cert_cyberpunk.png',
    'uploads/templates/cert_cyberpunk.png',
    'zamonaviy',
    true,
    1280,
    960,
    '[
      {"id":1,"type":"text","variable":"{{recipient_name}}","text":"Ism Familiya","x":140,"y":400,"w":1000,"h":80,"fontSize":38,"color":"#cffafe","fontWeight":"bold","align":"center"},
      {"id":2,"type":"text","variable":"{{course_name}}","text":"Kurs nomi","x":140,"y":540,"w":1000,"h":50,"fontSize":22,"color":"#a855f7","fontWeight":"bold","align":"center"},
      {"id":3,"type":"text","variable":"{{issued_date}}","text":"Sana","x":140,"y":820,"w":260,"h":32,"fontSize":16,"color":"#374151","fontWeight":"normal","align":"left"},
      {"id":4,"type":"text","variable":"{{cert_id}}","text":"ID","x":500,"y":820,"w":300,"h":32,"fontSize":14,"color":"#6b7280","fontWeight":"normal","align":"center"},
      {"id":5,"type":"qr","variable":"{{cert_id}}","text":"QR","x":1110,"y":800,"w":120,"h":120,"fontSize":12,"color":"#111827","fontWeight":"normal","align":"center"}
    ]'::jsonb
WHERE NOT EXISTS (SELECT 1 FROM templates WHERE file_url = 'uploads/templates/cert_cyberpunk.png');

-- Premium Landscape Waves shabloni
INSERT INTO templates (name, description, file_url, preview_url, category, is_premium, width, height, fields)
SELECT
    'Premium Tashakkurnoma — Landscape Waves',
    'Qarshi davlat texnika universiteti mehmonlari uchun so\'ralgan abstrakt landshaft dizayn.',
    'uploads/templates/cert_landscape_waves.png',
    'uploads/templates/cert_landscape_waves.png',
    'akademik',
    true,
    1280,
    960,
    '[
      {"id":1,"type":"text","variable":"","text":"Tashakkurnoma","x":240,"y":180,"w":800,"h":90,"fontSize":64,"color":"#111111","fontFamily":"Playfair Display","fontWeight":"bold","align":"center"},
      {"id":2,"type":"text","variable":"","text":"OLIY DARAJADAGI MINNATDORCHILIK","x":240,"y":275,"w":800,"h":30,"fontSize":13,"color":"#b38728","fontWeight":"bold","align":"center"},
      {"id":3,"type":"text","variable":"{{recipient_name}}","text":"Ism Familiya","x":140,"y":360,"w":1000,"h":70,"fontSize":44,"color":"#111111","fontFamily":"Playfair Display","fontWeight":"bold","align":"center"},
      {"id":4,"type":"text","variable":"{{course_name}}","text":"OʻZBEKISTON RESPUBLIKASI FAN ARBOBI, TARIX FANLARI DOKTORI, PROFESSOR","x":140,"y":455,"w":1000,"h":40,"fontSize":12,"color":"#1b4d4f","fontWeight":"bold","align":"center"},
      {"id":5,"type":"text","variable":"","text":"Tashkil etilgan “Akademik va yoshlar uchrashuvi” doirasidagi ishtirokingiz, yurtimiz boy tarixi, milliy davlatchilik asoslari hamda ilm-fan taraqqiyoti yuzasidan o\'rtoqlashgan qimmatli fikr-mulohazalaringiz talaba-yoshlarning ilmiy izlanishlarga boʻlgan qiziqishini oshirishda beqiyos ahamiyat kasb etdi.","x":240,"y":510,"w":800,"h":120,"fontSize":11,"color":"#666666","fontWeight":"normal","align":"center"},
      {"id":6,"type":"text","variable":"{{issued_date}}","text":"14-MAY, 2026","x":230,"y":700,"w":240,"h":30,"fontSize":14,"color":"#1b4d4f","fontWeight":"bold","align":"center"},
      {"id":7,"type":"text","variable":"","text":"TADBIR SANASI","x":230,"y":745,"w":240,"h":20,"fontSize":10,"color":"#333333","fontWeight":"bold","align":"center"},
      {"id":8,"type":"text","variable":"","text":"Sh. Nematov","x":650,"y":700,"w":240,"h":30,"fontSize":22,"color":"#1b4d4f","fontFamily":"Playfair Display","fontWeight":"normal","align":"center"},
      {"id":9,"type":"text","variable":"","text":"UNIVERSITET REKTORI","x":650,"y":745,"w":240,"h":20,"fontSize":10,"color":"#333333","fontWeight":"bold","align":"center"}
    ]'::jsonb
WHERE NOT EXISTS (SELECT 1 FROM templates WHERE file_url = 'uploads/templates/cert_landscape_waves.png');

