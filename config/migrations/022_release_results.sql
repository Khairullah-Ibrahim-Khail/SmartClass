-- SmartClass migration 022 — release results
-- release_mode: 'instant' = students see a grade / score as soon as it exists (as before),
--   'manual' = hidden from students until the teacher releases the results.
-- results_released_at: when the teacher released them (NULL = not yet). Releasing notifies every
--   student with a result once (in-app + email); grades made after that are visible at once.
-- Existing assignments and quizzes stay 'instant'.
-- Run ONCE.
--
--   mysql -u USER -p smartclass_db < config/migrations/022_release_results.sql

ALTER TABLE assignments
    ADD COLUMN release_mode ENUM('instant', 'manual') NOT NULL DEFAULT 'instant' AFTER rubric,
    ADD COLUMN results_released_at DATETIME DEFAULT NULL AFTER release_mode;

ALTER TABLE quizzes
    ADD COLUMN release_mode ENUM('instant', 'manual') NOT NULL DEFAULT 'instant' AFTER due_date,
    ADD COLUMN results_released_at DATETIME DEFAULT NULL AFTER release_mode;
