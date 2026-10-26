-- SmartClass migration 017 — students sign in with a one-time email code
-- login_codes: a 6-digit code emailed to a student; only its HMAC is stored. Valid
--   10 minutes, at most 5 tries, used once. A few codes per 15 minutes per account.
-- users.email_verified_at: set the first time the student signs in with a code
--   (shown to teachers on join requests).
-- Run ONCE.
--
--   mysql -u USER -p smartclass_db < config/migrations/017_login_codes.sql

ALTER TABLE users
    ADD COLUMN email_verified_at DATETIME DEFAULT NULL COMMENT 'proved by signing in with an emailed code' AFTER email;

CREATE TABLE login_codes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    code_hash CHAR(64) NOT NULL COMMENT 'HMAC-SHA256 of the code',
    expires_at DATETIME NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    used_at DATETIME DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_created (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
