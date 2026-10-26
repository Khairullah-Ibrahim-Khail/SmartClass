-- AI check of one submission for the teacher: "ready to grade" or "needs changes", with a drafted return note in the student's language.
-- One saved result per submission; it is reused until the student changes the answer (or the note language changes).
CREATE TABLE ai_work_reviews (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    submission_id INT UNSIGNED NOT NULL,
    content_hash CHAR(64) NOT NULL COMMENT 'sha256 of the answer that was checked, plus the note language',
    verdict      ENUM('ready','changes') NOT NULL,
    reason       VARCHAR(400) DEFAULT NULL,
    note         TEXT DEFAULT NULL COMMENT 'drafted return note (empty when ready)',
    lang         VARCHAR(5) NOT NULL DEFAULT 'en',
    flagged      TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'the answer tries to influence the AI',
    flag_reason  VARCHAR(255) DEFAULT NULL,
    provider     VARCHAR(30) DEFAULT NULL,
    model        VARCHAR(150) DEFAULT NULL,
    created_by   INT UNSIGNED DEFAULT NULL,
    created_at   DATETIME NOT NULL DEFAULT current_timestamp(),
    UNIQUE KEY unique_submission (submission_id),
    CONSTRAINT ai_work_reviews_ibfk_1 FOREIGN KEY (submission_id) REFERENCES submissions (id) ON DELETE CASCADE,
    CONSTRAINT ai_work_reviews_ibfk_2 FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
ALTER TABLE submissions ADD COLUMN return_source ENUM('teacher','ai_accepted','ai_edited') DEFAULT NULL AFTER return_note;
