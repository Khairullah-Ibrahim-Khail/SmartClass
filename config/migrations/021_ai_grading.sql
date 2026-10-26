-- SmartClass migration 021 — rubrics + AI grading assistant
-- assignments.rubric: optional JSON list of criteria [{title, description, points}] whose points
--   add up to max_marks (students see it on the assignment page).
-- ai_grade_suggestions: the AI's suggested grade for one submission (marks per criterion, feedback,
--   a possible-manipulation flag). Kept on the server so a grade that follows it can be recorded
--   truthfully; replaced when the student changes the answer (content_hash).
-- submissions.grade_source: how the grade was made - teacher, ai_accepted (saved exactly as
--   suggested) or ai_edited (teacher changed the suggestion).
-- Run ONCE.
--
--   mysql -u USER -p smartclass_db < config/migrations/021_ai_grading.sql

ALTER TABLE assignments
    ADD COLUMN rubric TEXT DEFAULT NULL COMMENT 'JSON list of criteria - points add up to max_marks' AFTER max_marks;

ALTER TABLE submissions
    ADD COLUMN grade_source ENUM('teacher', 'ai_accepted', 'ai_edited') DEFAULT NULL AFTER graded_at;

CREATE TABLE ai_grade_suggestions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    submission_id INT UNSIGNED NOT NULL,
    content_hash CHAR(64) NOT NULL COMMENT 'sha256 of the answer that was assessed',
    marks DECIMAL(6,2) NOT NULL,
    feedback TEXT DEFAULT NULL,
    breakdown TEXT DEFAULT NULL COMMENT 'JSON [{title, points, max, comment}]',
    flagged TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'the answer tries to influence the grader',
    flag_reason VARCHAR(255) DEFAULT NULL,
    provider VARCHAR(30) DEFAULT NULL,
    model VARCHAR(150) DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_submission (submission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
