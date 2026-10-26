-- SmartClass migration 044 — student AI Tutor: one conversation list per student and class
-- Run ONCE (bin/update_all.php / bin/migrate.php do it).
CREATE TABLE IF NOT EXISTS ai_tutor_chats (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    class_id INT UNSIGNED NOT NULL,
    lecture_id INT UNSIGNED DEFAULT NULL COMMENT 'the lecture the chat was opened from (optional)',
    title VARCHAR(120) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (lecture_id) REFERENCES lectures(id) ON DELETE SET NULL,
    INDEX idx_tutor_chat (student_id, class_id, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ai_tutor_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chat_id INT UNSIGNED NOT NULL,
    role ENUM('student', 'tutor') NOT NULL,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (chat_id) REFERENCES ai_tutor_chats(id) ON DELETE CASCADE,
    INDEX idx_tutor_msg (chat_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
