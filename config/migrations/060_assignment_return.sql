-- "Return for changes": the teacher sends a submission back with a note, the student fixes it and hands it in again.
-- status 'returned' = waiting for the student (not graded, not waiting for the teacher); revision counts the hand-ins.
ALTER TABLE submissions
    MODIFY status ENUM('submitted','graded','late','returned') NOT NULL DEFAULT 'submitted',
    ADD COLUMN returned_at DATETIME NULL AFTER graded_at,
    ADD COLUMN return_note TEXT NULL AFTER returned_at,
    ADD COLUMN revision INT UNSIGNED NOT NULL DEFAULT 1 AFTER return_note;
