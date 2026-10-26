-- SmartClass migration 045 — several attendance sessions (periods) per class per day
-- Every existing mark becomes session 1. Run ONCE (bin/update_all.php / bin/migrate.php do it).
ALTER TABLE attendance
    ADD COLUMN session TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'session (period) of the day, 1-8' AFTER date,
    DROP INDEX unique_mark,
    ADD UNIQUE KEY unique_mark (class_id, student_id, date, session);
ALTER TABLE attendance_log
    ADD COLUMN session TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER date;
ALTER TABLE attendance_checkin
    ADD COLUMN session TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'the session the code marks' AFTER class_id;
