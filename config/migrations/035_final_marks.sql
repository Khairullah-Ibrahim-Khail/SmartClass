-- SmartClass migration 035 — final marks: exam kind (mid / final / activity) and each class's marking scheme (weights)
-- Run ONCE (bin/update_all.php / bin/migrate.php do it).

ALTER TABLE exams
    ADD COLUMN kind ENUM('mid', 'final', 'activity', 'other') NOT NULL DEFAULT 'other' COMMENT 'which part of the final marks it counts in' AFTER title;

UPDATE exams SET kind = 'mid' WHERE LOWER(title) LIKE '%mid%';
UPDATE exams SET kind = 'final' WHERE kind = 'other' AND LOWER(title) LIKE '%final%';

CREATE TABLE IF NOT EXISTS class_marking_scheme (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id INT UNSIGNED NOT NULL,
    component ENUM('mid', 'final', 'activity', 'quiz', 'assignment') NOT NULL,
    weight DECIMAL(6,2) NOT NULL DEFAULT 0 COMMENT 'marks this part is worth in the final total (0 = not used)',
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    UNIQUE KEY unique_class_component (class_id, component)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
