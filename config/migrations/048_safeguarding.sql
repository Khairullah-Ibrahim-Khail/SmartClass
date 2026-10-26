-- SmartClass migration 048 — safeguarding flags: when a student's message to the AI Tutor mentions harm, a teacher is told
-- Run ONCE (bin/update_all.php / bin/migrate.php do it).
CREATE TABLE IF NOT EXISTS safeguarding_flags (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    class_id INT UNSIGNED NOT NULL,
    chat_id INT UNSIGNED DEFAULT NULL,
    category VARCHAR(20) NOT NULL COMMENT 'self_harm, abuse, violence, bullying',
    snippet VARCHAR(300) NOT NULL COMMENT 'the start of the message, so the teacher knows what to look for',
    status ENUM('open', 'handled') NOT NULL DEFAULT 'open',
    handled_by INT UNSIGNED DEFAULT NULL,
    handled_at DATETIME DEFAULT NULL,
    note VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (chat_id) REFERENCES ai_tutor_chats(id) ON DELETE SET NULL,
    FOREIGN KEY (handled_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_class_status (class_id, status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
