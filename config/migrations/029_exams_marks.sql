-- SmartClass migration 029 — Exams and Marks (sidebar modules)
-- exams: a scheduled exam of a class (date, duration, place, max marks, instructions).
--   results_published_at: NULL = students cannot see marks yet; set by "Publish results" (students are notified).
-- exam_marks: one row per student per exam: marks (NULL = not entered), absent flag, remarks.
-- Run ONCE.
--
--   mysql -u USER -p smartclass_db < config/migrations/029_exams_marks.sql

CREATE TABLE exams (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id INT UNSIGNED NOT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    title VARCHAR(200) NOT NULL,
    exam_date DATETIME NOT NULL,
    duration_min SMALLINT UNSIGNED DEFAULT NULL,
    venue VARCHAR(200) DEFAULT NULL,
    max_marks SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    instructions TEXT DEFAULT NULL,
    results_published_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_class_date (class_id, exam_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE exam_marks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    exam_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    marks DECIMAL(6,2) DEFAULT NULL,
    absent TINYINT(1) NOT NULL DEFAULT 0,
    remarks VARCHAR(300) DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_exam_student (exam_id, student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
