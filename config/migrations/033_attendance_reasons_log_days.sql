-- SmartClass migration 033 — attendance: reasons (excused / unexcused), edit history, no-class days
-- Run ONCE (bin/update_all.php / bin/migrate.php do it).

ALTER TABLE attendance
    ADD COLUMN excused TINYINT(1) DEFAULT NULL COMMENT '1 excused, 0 unexcused, NULL not stated (late / absent only)' AFTER note,
    ADD COLUMN reason VARCHAR(20) DEFAULT NULL COMMENT 'sick, family, travel, transport, other' AFTER excused;

CREATE TABLE IF NOT EXISTS attendance_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    date DATE NOT NULL,
    old_status VARCHAR(20) DEFAULT NULL,
    new_status VARCHAR(20) DEFAULT NULL COMMENT 'NULL = the day was cleared',
    old_note VARCHAR(255) DEFAULT NULL,
    new_note VARCHAR(255) DEFAULT NULL,
    changed_by INT UNSIGNED DEFAULT NULL,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_log_day (class_id, date, changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS attendance_days (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id INT UNSIGNED NOT NULL,
    date DATE NOT NULL,
    label VARCHAR(100) NOT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_noclass (class_id, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='days with no class (holiday, exam week …): nobody is marked';
