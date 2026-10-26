-- SmartClass migration 019 — lectures rebuild
-- lectures.status / publish_at / notified_at: draft, scheduled or published; students
--   see a lecture (and are notified, once) only when it is published. Existing lectures
--   are published and marked as already notified.
-- lectures.summary: one short line shown in lists.
-- lecture_materials: class resources and links / videos attached to a lecture.
-- lecture_explanations: AI explanations of the lecture's code blocks ("Explain this code"),
--   one per code block, reused for every student (saves tokens).
-- private_messages: 'lecture' context ("Ask the teacher about this lecture").
-- Run ONCE.
--
--   mysql -u USER -p smartclass_db < config/migrations/019_lectures_rebuild.sql

ALTER TABLE lectures
    ADD COLUMN summary VARCHAR(300) DEFAULT NULL COMMENT 'one line shown in lists' AFTER title,
    ADD COLUMN status ENUM('draft', 'published') NOT NULL DEFAULT 'published' AFTER video_url,
    ADD COLUMN publish_at DATETIME DEFAULT NULL COMMENT 'published lecture shown from this time - NULL = now' AFTER status,
    ADD COLUMN notified_at DATETIME DEFAULT NULL COMMENT 'students notified - NULL = not yet' AFTER publish_at,
    ADD INDEX idx_publish (status, notified_at, publish_at);

UPDATE lectures SET notified_at = created_at WHERE notified_at IS NULL;

CREATE TABLE lecture_materials (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lecture_id INT UNSIGNED NOT NULL,
    kind ENUM('link', 'resource') NOT NULL,
    resource_id INT UNSIGNED DEFAULT NULL COMMENT 'kind = resource - a resource of the same class',
    url VARCHAR(500) DEFAULT NULL COMMENT 'kind = link - http(s) only (YouTube links are embedded)',
    title VARCHAR(200) DEFAULT NULL,
    position INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lecture_id) REFERENCES lectures(id) ON DELETE CASCADE,
    FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE CASCADE,
    INDEX idx_lecture (lecture_id, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE lecture_explanations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lecture_id INT UNSIGNED NOT NULL,
    code_hash CHAR(64) NOT NULL COMMENT 'sha256 of the code block - edited code gets a new explanation',
    explanation MEDIUMTEXT NOT NULL,
    provider VARCHAR(30) DEFAULT NULL,
    model VARCHAR(150) DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL COMMENT 'whose click produced it',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lecture_id) REFERENCES lectures(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_block (lecture_id, code_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE private_messages
    MODIFY context_type ENUM('class', 'assignment', 'quiz', 'lecture') NOT NULL DEFAULT 'class';
