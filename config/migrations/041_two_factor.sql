-- SmartClass migration 041 — two-step sign-in (authenticator app, TOTP) with one-time backup codes
-- Run ONCE (bin/update_all.php / bin/migrate.php do it).
ALTER TABLE users
    ADD COLUMN totp_secret VARCHAR(255) DEFAULT NULL COMMENT 'encrypted (settings.php encryptSecret)',
    ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN totp_last_step INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'last accepted 30-second step — a code works once';

CREATE TABLE IF NOT EXISTS user_backup_codes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    code_hash CHAR(64) NOT NULL,
    used_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_backup_user (user_id, used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
