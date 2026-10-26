-- SmartClass migration 013 — invite teachers from the app
-- A teacher invites a new teacher by name + email; the invitee opens a one-time
-- link (valid 7 days) and sets their own password. Only a SHA-256 hash of the
-- link's token is stored, so a database leak does not leak working links.
-- Run ONCE.
--
--   mysql -u USER -p smartclass_db < config/migrations/013_teacher_invites.sql

CREATE TABLE teacher_invites (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    token_hash CHAR(64) NOT NULL COMMENT 'sha256 of the token in the invite link',
    invited_by INT UNSIGNED DEFAULT NULL,
    expires_at DATETIME NOT NULL,
    accepted_at DATETIME DEFAULT NULL,
    accepted_user_id INT UNSIGNED DEFAULT NULL,
    revoked_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invited_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (accepted_user_id) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_token (token_hash),
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
