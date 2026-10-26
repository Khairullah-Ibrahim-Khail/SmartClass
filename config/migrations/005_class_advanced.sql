-- SmartClass migration 005 — advanced class features
--   * class settings: who may post on the stream, colour theme
--   * pinned announcements
--   * topics (units) for classwork
--   * private comments between a student and the teacher on an assignment
-- Run ONCE on a database installed before these features existed.
-- Additive only: new columns default to the previous behaviour.
--
--   mysql -u USER -p smartclass_db < config/migrations/005_class_advanced.sql

ALTER TABLE classes
    ADD COLUMN stream_permission ENUM('post', 'comment', 'teacher_only') NOT NULL DEFAULT 'comment'
        COMMENT 'post = students post + comment, comment = students comment only, teacher_only = read only' AFTER image,
    ADD COLUMN theme VARCHAR(20) NOT NULL DEFAULT 'auto' AFTER stream_permission;

ALTER TABLE announcements
    ADD COLUMN pinned TINYINT(1) NOT NULL DEFAULT 0 AFTER body;

CREATE TABLE class_topics (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    position INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    INDEX idx_class (class_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE lectures    ADD COLUMN topic_id INT UNSIGNED DEFAULT NULL AFTER class_id,
    ADD CONSTRAINT fk_lectures_topic    FOREIGN KEY (topic_id) REFERENCES class_topics(id) ON DELETE SET NULL;
ALTER TABLE resources   ADD COLUMN topic_id INT UNSIGNED DEFAULT NULL AFTER class_id,
    ADD CONSTRAINT fk_resources_topic   FOREIGN KEY (topic_id) REFERENCES class_topics(id) ON DELETE SET NULL;
ALTER TABLE assignments ADD COLUMN topic_id INT UNSIGNED DEFAULT NULL AFTER class_id,
    ADD CONSTRAINT fk_assignments_topic FOREIGN KEY (topic_id) REFERENCES class_topics(id) ON DELETE SET NULL;
ALTER TABLE quizzes     ADD COLUMN topic_id INT UNSIGNED DEFAULT NULL AFTER class_id,
    ADD CONSTRAINT fk_quizzes_topic     FOREIGN KEY (topic_id) REFERENCES class_topics(id) ON DELETE SET NULL;

-- One private thread per (assignment, student): only that student and the class teacher see it
CREATE TABLE assignment_comments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    assignment_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL COMMENT 'whose private thread this is',
    user_id INT UNSIGNED NOT NULL COMMENT 'author - the student or the teacher',
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_thread (assignment_id, student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
