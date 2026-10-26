-- SmartClass migration 006 — Attendance (per class, per day)
-- Run ONCE on a database installed before attendance existed. Additive only.
--
--   mysql -u USER -p smartclass_db < config/migrations/006_attendance.sql

CREATE TABLE attendance (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    date DATE NOT NULL,
    status ENUM('present', 'late', 'absent', 'official') NOT NULL COMMENT 'official = official present (on duty, counts as present)',
    note VARCHAR(255) DEFAULT NULL,
    marked_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (marked_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_mark (class_id, student_id, date),
    INDEX idx_class_date (class_id, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
