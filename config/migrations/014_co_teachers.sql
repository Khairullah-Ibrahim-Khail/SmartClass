-- SmartClass migration 014 — co-teachers (several teachers per class)
-- class_teachers lists every teacher of a class: the owner (classes.teacher_id,
-- kept as it is) and co-teachers the owner adds from the People tab.
-- Co-teachers can do everything in the class except archive / restore it and
-- manage co-teachers. getAccessibleClass() reads this table.
-- Existing classes get their teacher as the owner.
-- Run ONCE.
--
--   mysql -u USER -p smartclass_db < config/migrations/014_co_teachers.sql

CREATE TABLE class_teachers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id INT UNSIGNED NOT NULL,
    teacher_id INT UNSIGNED NOT NULL,
    role ENUM('owner', 'co_teacher') NOT NULL DEFAULT 'co_teacher',
    added_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_class_teacher (class_id, teacher_id),
    INDEX idx_teacher (teacher_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO class_teachers (class_id, teacher_id, role, created_at)
SELECT id, teacher_id, 'owner', created_at FROM classes;
