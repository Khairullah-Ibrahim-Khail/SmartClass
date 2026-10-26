-- SmartClass migration 036 — each class's teacher decides the grading scale (A+, A, B … / ratings, GPA on or off)
-- A class without its own row uses the school's default scale (Settings › Grading scale).
-- Run ONCE (bin/update_all.php / bin/migrate.php do it).

CREATE TABLE IF NOT EXISTS class_grading_scales (
    class_id INT UNSIGNED NOT NULL PRIMARY KEY,
    scale TEXT NOT NULL COMMENT 'JSON: {show_gpa, basis, bands[{label,min,gpa,tone}]}',
    updated_by INT UNSIGNED DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
