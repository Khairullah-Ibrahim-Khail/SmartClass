-- SmartClass migration 038 — attendance self check-in: the teacher opens a short-lived code, students enter it
-- Run ONCE (bin/update_all.php / bin/migrate.php do it).

CREATE TABLE IF NOT EXISTS attendance_checkin (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id INT UNSIGNED NOT NULL,
    code CHAR(6) NOT NULL,
    opened_by INT UNSIGNED DEFAULT NULL,
    opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    closed_at DATETIME DEFAULT NULL,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (opened_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_class_open (class_id, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS attendance_checkin_tries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    tried_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ok TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_try (class_id, student_id, tried_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
