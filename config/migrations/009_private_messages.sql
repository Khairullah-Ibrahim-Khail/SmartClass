-- SmartClass migration 009 — private messages (Messages tab)
-- One table for every private conversation between a student and the class teacher:
--   context 'class'      general "Message teacher" conversation
--   context 'assignment' private comments on an assignment (was assignment_comments)
--   context 'quiz'       private comments on a quiz
-- A conversation = (class_id, student_id, context_type, context_id).
-- is_read = read by the recipient (the other side of the conversation).
-- Existing assignment_comments are copied in (marked read); that table is kept, unused.
-- Run ONCE.
--
--   mysql -u USER -p smartclass_db < config/migrations/009_private_messages.sql

CREATE TABLE private_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL COMMENT 'whose conversation this is',
    user_id INT UNSIGNED NOT NULL COMMENT 'author - the student or the teacher',
    context_type ENUM('class', 'assignment', 'quiz') NOT NULL DEFAULT 'class',
    context_id INT UNSIGNED DEFAULT NULL COMMENT 'assignment / quiz id - NULL for class',
    body TEXT NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'read by the recipient',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_conversation (class_id, student_id, context_type, context_id),
    INDEX idx_unread (class_id, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO private_messages (class_id, student_id, user_id, context_type, context_id, body, is_read, created_at)
SELECT a.class_id, ac.student_id, ac.user_id, 'assignment', ac.assignment_id, ac.body, 1, ac.created_at
FROM assignment_comments ac INNER JOIN assignments a ON ac.assignment_id = a.id;
