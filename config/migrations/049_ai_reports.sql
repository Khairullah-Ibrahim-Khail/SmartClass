-- SmartClass migration 049 — "this AI answer is wrong": reports from students and teachers, reviewed by the administrator
-- Run ONCE (bin/update_all.php / bin/migrate.php do it).
CREATE TABLE IF NOT EXISTS ai_reports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    class_id INT UNSIGNED DEFAULT NULL,
    feature VARCHAR(30) NOT NULL COMMENT 'tutor, study, explain_code, other',
    answer VARCHAR(600) NOT NULL COMMENT 'the start of the answer that was reported',
    note VARCHAR(255) DEFAULT NULL,
    status ENUM('open', 'reviewed') NOT NULL DEFAULT 'open',
    reviewed_by INT UNSIGNED DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
