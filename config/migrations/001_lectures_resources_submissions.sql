-- SmartClass migration 001 — Lectures / Resources / Assignments modules
-- Run ONCE on a database that was installed from an older smartclass_db.sql.
-- (Fresh installs via setup.php already include these columns.)
-- Additive only: no data is changed or removed.
--
--   mysql -u USER -p smartclass_db < config/migrations/001_lectures_resources_submissions.sql

-- Lectures: teacher notes shown under the lecture content (designs/pages/Viewlectures.html)
ALTER TABLE lectures
    ADD COLUMN notes TEXT DEFAULT NULL AFTER content;

-- Resources: description, category and download counter (designs/pages/resources/),
-- plus the original filename for display/download only (files are stored under a random name)
ALTER TABLE resources
    ADD COLUMN description TEXT DEFAULT NULL AFTER title,
    ADD COLUMN category VARCHAR(30) NOT NULL DEFAULT 'other' AFTER description,
    ADD COLUMN original_name VARCHAR(255) DEFAULT NULL AFTER file_path,
    ADD COLUMN download_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER file_size;

-- Submissions: original filename for display/download only
ALTER TABLE submissions
    ADD COLUMN original_name VARCHAR(255) DEFAULT NULL AFTER file_path;
