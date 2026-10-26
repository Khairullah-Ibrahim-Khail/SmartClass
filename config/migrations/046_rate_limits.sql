-- SmartClass migration 046 — counters for rate limits (sign-in tries, password resets, AI calls, uploads …)
-- Run ONCE (bin/update_all.php / bin/migrate.php do it).
CREATE TABLE IF NOT EXISTS rate_limits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bucket VARCHAR(40) NOT NULL COMMENT 'what is limited: login_account, login_ip, ai_user ...',
    rkey CHAR(64) NOT NULL COMMENT 'sha256 of the thing counted (e-mail, IP, user id)',
    hits INT UNSIGNED NOT NULL DEFAULT 0,
    window_start DATETIME NOT NULL,
    UNIQUE KEY unique_limit (bucket, rkey),
    INDEX idx_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
