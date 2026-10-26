-- SmartClass migration 026 — lecture study helper
-- Saved AI summary / key terms per lecture, shared by the class and rewritten when the lecture text changes.
-- Run ONCE.
--
--   mysql -u USER -p smartclass_db < config/migrations/026_lecture_study_cache.sql

CREATE TABLE lecture_study (
    lecture_id INT UNSIGNED NOT NULL,
    kind ENUM('summary', 'terms') NOT NULL,
    content_hash CHAR(64) NOT NULL COMMENT 'sha256 of the lecture text it was written from',
    content TEXT NOT NULL,
    provider VARCHAR(30) DEFAULT NULL,
    model VARCHAR(150) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (lecture_id, kind),
    FOREIGN KEY (lecture_id) REFERENCES lectures(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
