-- SmartClass migration 052 — teacher's question bank: questions kept for reuse in later quizzes (private to each teacher)
-- Run ONCE (bin/update_all.php / bin/migrate.php do it).

CREATE TABLE IF NOT EXISTS question_bank (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT UNSIGNED NOT NULL,
    tag VARCHAR(60) DEFAULT NULL COMMENT 'a subject / unit name chosen by the teacher',
    question_type ENUM('choice', 'short') NOT NULL DEFAULT 'choice',
    question TEXT NOT NULL,
    model_answer TEXT DEFAULT NULL,
    marks INT UNSIGNED NOT NULL DEFAULT 1,
    options TEXT DEFAULT NULL COMMENT 'JSON: [{"t": "text", "c": 1 for the correct one}] (choice questions)',
    question_hash CHAR(40) NOT NULL COMMENT 'sha1 of the question text + options — the same question is kept once per teacher',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_teacher_question (teacher_id, question_hash),
    INDEX idx_teacher_tag (teacher_id, tag)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
