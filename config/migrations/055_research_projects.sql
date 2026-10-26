-- SmartClass migration 055 — Research as a PROCESS between a student and the class teacher:
-- a project goes proposal → teacher decision → milestones (submit / review) → completed with a grade, with a running timeline.
-- The old research library (research_items) is untouched.
-- Run ONCE (bin/update_all.php / bin/migrate.php do it).

CREATE TABLE IF NOT EXISTS research_projects (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id       INT UNSIGNED NOT NULL,
    student_id     INT UNSIGNED NOT NULL,
    supervisor_id  INT UNSIGNED NULL COMMENT 'the teacher who approved it',
    title          VARCHAR(200) NOT NULL,
    question       VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'the research question',
    plan           TEXT NULL COMMENT 'what the student will do and how',
    status         ENUM('draft','proposed','changes','active','completed','closed') NOT NULL DEFAULT 'draft',
    grade          DECIMAL(5,2) NULL,
    final_feedback TEXT NULL,
    submitted_at   DATETIME NULL,
    approved_at    DATETIME NULL,
    completed_at   DATETIME NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_rp_class (class_id, status),
    KEY idx_rp_student (student_id),
    CONSTRAINT fk_rp_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    CONSTRAINT fk_rp_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_rp_supervisor FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS research_milestones (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT UNSIGNED NOT NULL,
    title      VARCHAR(150) NOT NULL,
    due_date   DATE NULL,
    status     ENUM('open','submitted','accepted','changes') NOT NULL DEFAULT 'open',
    position   INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_rm_project (project_id, position),
    CONSTRAINT fk_rm_project FOREIGN KEY (project_id) REFERENCES research_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS research_updates (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id   INT UNSIGNED NOT NULL,
    milestone_id INT UNSIGNED NULL,
    author_id    INT UNSIGNED NULL,
    kind         ENUM('comment','submission','decision','system') NOT NULL DEFAULT 'comment',
    body         TEXT NULL,
    file_path    VARCHAR(255) NULL,
    file_name    VARCHAR(255) NULL,
    file_type    VARCHAR(10) NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ru_project (project_id, id),
    CONSTRAINT fk_ru_project FOREIGN KEY (project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_ru_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
