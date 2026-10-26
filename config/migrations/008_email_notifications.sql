-- SmartClass migration 008 — email notifications
--   * app_settings: key/value settings edited in the app (SMTP account, sender, delivery)
--   * email_queue: one row per email; sent by cron (or immediately, per setting)
--   * email_preferences: per user — instant / daily digest / off, per category
--   * users.email_token: secret for the one-click unsubscribe link
-- Run ONCE. Additive only. Email stays OFF until a teacher configures it.
--
--   mysql -u USER -p smartclass_db < config/migrations/008_email_notifications.sql

CREATE TABLE app_settings (
    setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
    setting_value TEXT,
    updated_by INT UNSIGNED DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE email_queue (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED DEFAULT NULL,
    to_email VARCHAR(100) NOT NULL,
    to_name VARCHAR(100) DEFAULT NULL,
    category VARCHAR(20) NOT NULL DEFAULT 'general',
    subject VARCHAR(255) NOT NULL,
    body_html MEDIUMTEXT NOT NULL,
    body_text MEDIUMTEXT NOT NULL,
    status ENUM('pending', 'held', 'sent', 'failed', 'digested') NOT NULL DEFAULT 'pending'
        COMMENT 'held = waiting for the daily digest',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_status (status, created_at),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE email_preferences (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    mode ENUM('instant', 'daily', 'off') NOT NULL DEFAULT 'instant',
    classwork TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'new lectures, resources, assignments, quizzes, announcements',
    grades TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'graded work, quiz results, submissions received',
    attendance TINYINT(1) NOT NULL DEFAULT 1,
    comments TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'class + private comments, posts',
    approvals TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'registrations, join requests, approvals',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE users
    ADD COLUMN email_token VARCHAR(64) DEFAULT NULL COMMENT 'secret for one-click unsubscribe links' AFTER photo;
