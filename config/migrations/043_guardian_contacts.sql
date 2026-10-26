-- SmartClass migration 043 — a student's parent / guardian contact (optional) for absence alerts
-- Run ONCE (bin/update_all.php / bin/migrate.php do it).
ALTER TABLE users
    ADD COLUMN guardian_name VARCHAR(100) DEFAULT NULL,
    ADD COLUMN guardian_email VARCHAR(100) DEFAULT NULL,
    ADD COLUMN guardian_phone VARCHAR(40) DEFAULT NULL,
    ADD COLUMN guardian_alerts TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = the guardian asked to stop (or the student turned it off)';
