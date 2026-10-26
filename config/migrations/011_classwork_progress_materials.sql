-- SmartClass migration 011 — classwork progress + assignment materials
-- content_progress: per student, per lecture / resource — when they first and last
--   opened it, how often, and when they marked it done (NULL = not done).
--   item_id points to lectures.id or resources.id (by item_type), so it has no
--   foreign key - the lecture / resource delete pages remove its rows.
-- assignment_materials: links and class resources attached to an assignment.
-- Run ONCE.
--
--   mysql -u USER -p smartclass_db < config/migrations/011_classwork_progress_materials.sql

CREATE TABLE content_progress (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    item_type ENUM('lecture', 'resource') NOT NULL,
    item_id INT UNSIGNED NOT NULL COMMENT 'lectures.id or resources.id',
    first_opened_at DATETIME DEFAULT NULL,
    last_opened_at DATETIME DEFAULT NULL,
    open_count INT UNSIGNED NOT NULL DEFAULT 0,
    done_at DATETIME DEFAULT NULL COMMENT 'marked done by the student',
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_progress (student_id, item_type, item_id),
    INDEX idx_item (class_id, item_type, item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE assignment_materials (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    assignment_id INT UNSIGNED NOT NULL,
    kind ENUM('link', 'resource') NOT NULL,
    resource_id INT UNSIGNED DEFAULT NULL COMMENT 'kind = resource - a resource of the same class',
    url VARCHAR(500) DEFAULT NULL COMMENT 'kind = link - http(s) only',
    title VARCHAR(200) DEFAULT NULL,
    position INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE,
    FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE CASCADE,
    INDEX idx_assignment (assignment_id, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
