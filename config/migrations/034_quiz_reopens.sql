-- SmartClass migration 034 — teachers can reactivate a quiz for chosen students (a fresh attempt, optionally after the due date)
-- Run ONCE (bin/update_all.php / bin/migrate.php do it).

CREATE TABLE IF NOT EXISTS quiz_reopens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    quiz_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    reopened_by INT UNSIGNED DEFAULT NULL,
    reopened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    until DATETIME DEFAULT NULL COMMENT 'the student may start the quiz again until then (NULL = no deadline)',
    prev_state VARCHAR(20) NOT NULL DEFAULT 'none' COMMENT 'none | open | done | timeout — what the student had before',
    prev_score DECIMAL(7,2) DEFAULT NULL,
    prev_max INT UNSIGNED DEFAULT NULL,
    FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reopened_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_quiz_student (quiz_id, student_id, reopened_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
