-- SnakeBet Pro Database Schema Completo (Atualizado)

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE,
    phone VARCHAR(20),
    cpf VARCHAR(14) UNIQUE,
    invitedBy VARCHAR(255),
    balance DECIMAL(10, 2) DEFAULT 0.00,
    bonusBalance DECIMAL(10, 2) DEFAULT 0.00,
    cpa_earnings DECIMAL(10, 2) DEFAULT 0.00,
    revshare_earnings DECIMAL(10, 2) DEFAULT 0.00,
    totalDeposited DECIMAL(10, 2) DEFAULT 0.00,
    totalWagered DECIMAL(10, 2) DEFAULT 0.00,
    isVip TINYINT(1) DEFAULT 0,
    vipExpiry DATETIME NULL,
    inventory TEXT,
    lastDailyBonus BIGINT DEFAULT 0,
    is_demo TINYINT(1) DEFAULT 0,
    demoBalance DECIMAL(10, 2) DEFAULT 0.00,
    custom_revshare DECIMAL(5, 2) DEFAULT NULL,
    custom_cpa DECIMAL(10, 2) DEFAULT NULL,
    accepts_pix TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS game_sessions (
    id VARCHAR(100) PRIMARY KEY,
    user_id INT NOT NULL,
    bet_amount DECIMAL(10, 2) NOT NULL,
    multiplier DECIMAL(10, 2) DEFAULT 0.00,
    win_amount DECIMAL(10, 2) DEFAULT 0.00,
    status VARCHAR(20) DEFAULT 'ACTIVE',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type VARCHAR(50) NOT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    status VARCHAR(50) DEFAULT 'PENDING',
    details TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS referrals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    referrer_id INT NOT NULL,
    referred_user_id INT NOT NULL,
    status VARCHAR(50) DEFAULT 'PENDING',
    cpa_paid TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (referrer_id) REFERENCES users(id),
    FOREIGN KEY (referred_user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(255) NOT NULL UNIQUE,
    setting_value TEXT
);

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES 
('cpaValue', '10'),
('cpaMinDeposit', '20'),
('realRevShare', '20'),
('fakeRevShare', '50'),
('minDeposit', '10'),
('minWithdraw', '50'),
('rollover_multiplier', '1'),
('pagViva', '{"token":"","secret":"","apiKey":""}');

-- ============================================================
-- MIGRATION: Execute these on EXISTING databases
-- ============================================================
ALTER TABLE users ADD COLUMN IF NOT EXISTS is_demo TINYINT(1) DEFAULT 0;
ALTER TABLE users ADD COLUMN IF NOT EXISTS demoBalance DECIMAL(10, 2) DEFAULT 0.00;
ALTER TABLE users ADD COLUMN IF NOT EXISTS custom_revshare DECIMAL(5, 2) DEFAULT NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS custom_cpa DECIMAL(10, 2) DEFAULT NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS accepts_pix TINYINT(1) DEFAULT 1;
