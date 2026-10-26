-- SmartClass migration 024 — saved AI answers for quizzes
-- quiz_explanations: the AI's "explain my mistakes" for one submitted attempt (one per attempt).
-- quiz_insights: the AI's class summary for one quiz, kept until more students have submitted.
-- Run ONCE.
--
--   mysql -u USER -p smartclass_db < config/migrations/024_quiz_ai_cache.sql

CREATE TABLE quiz_explanations (
    attempt_id INT UNSIGNED NOT NULL PRIMARY KEY,
    content TEXT NOT NULL COMMENT 'JSON {question_id: explanation}',
    provider VARCHAR(30) DEFAULT NULL,
    model VARCHAR(150) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (attempt_id) REFERENCES quiz_attempts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE quiz_insights (
    quiz_id INT UNSIGNED NOT NULL PRIMARY KEY,
    attempts INT UNSIGNED NOT NULL COMMENT 'submitted attempts when it was written',
    content TEXT NOT NULL COMMENT 'JSON {summary, weak_points[], suggestions[]}',
    provider VARCHAR(30) DEFAULT NULL,
    model VARCHAR(150) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
