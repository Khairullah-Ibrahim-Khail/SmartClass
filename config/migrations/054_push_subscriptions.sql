-- SmartClass migration 054 — phone (web push) notifications: one row per browser/phone a person turned them on in.
-- The push is only a "wake-up" with no text; the phone then asks SmartClass for the notification, so nothing private travels through the push service.
-- Run ONCE (bin/update_all.php / bin/migrate.php do it).

CREATE TABLE IF NOT EXISTS push_subscriptions (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,
    endpoint_hash CHAR(64) NOT NULL,
    endpoint      TEXT NOT NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_ok_at    TIMESTAMP NULL DEFAULT NULL,
    fail_count    INT NOT NULL DEFAULT 0,
    UNIQUE KEY uq_push_endpoint (endpoint_hash),
    KEY idx_push_user (user_id),
    CONSTRAINT fk_push_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
