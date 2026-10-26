-- SmartClass migration 040 — minutes late on a "Late" mark (optional)
-- Run ONCE (bin/update_all.php / bin/migrate.php do it).
ALTER TABLE attendance ADD COLUMN late_minutes SMALLINT UNSIGNED DEFAULT NULL COMMENT 'how many minutes late (status late only)' AFTER reason;
