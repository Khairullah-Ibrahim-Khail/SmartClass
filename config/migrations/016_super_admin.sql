-- SmartClass migration 016 — super admin
-- is_admin marks the school's super admin(s): a teacher account that also owns the
-- school-wide pages - inviting teachers, the teacher directory, Email and AI settings,
-- backups. Other teachers see only their own classes and data.
-- Grant it to your own account after running this file, e.g.
--   UPDATE users SET is_admin = 1 WHERE email = 'you@school.edu' AND role = 'teacher';
-- (setup.php makes the first teacher it creates the super admin.)
-- Run ONCE.
--
--   mysql -u USER -p smartclass_db < config/migrations/016_super_admin.sql

ALTER TABLE users
    ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'school super admin (a teacher)' AFTER role;
