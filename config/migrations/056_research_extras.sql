-- SmartClass migration 056 — research projects: reminders (what was already sent), milestone templates, sources.
-- Run ONCE (bin/update_all.php / bin/migrate.php do it).

ALTER TABLE research_milestones
    ADD COLUMN remind_soon_at DATETIME NULL AFTER position,
    ADD COLUMN remind_late_at DATETIME NULL AFTER remind_soon_at;

ALTER TABLE research_projects
    ADD COLUMN stale_notified_at DATETIME NULL AFTER completed_at;

CREATE TABLE IF NOT EXISTS research_templates (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT UNSIGNED NOT NULL,
    name       VARCHAR(100) NOT NULL,
    items      TEXT NOT NULL COMMENT 'JSON list of {title, days} (days = how many days after adding the milestone is due; 0 = no date)',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_rt_teacher (teacher_id),
    CONSTRAINT fk_rt_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS research_sources (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT UNSIGNED NOT NULL,
    item_id    INT UNSIGNED NULL COMMENT 'a Library item, or NULL for a source typed in',
    title      VARCHAR(250) NOT NULL,
    authors    VARCHAR(250) NULL,
    year       SMALLINT NULL,
    url        VARCHAR(500) NULL,
    note       VARCHAR(300) NULL,
    added_by   INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_rs_project (project_id),
    CONSTRAINT fk_rs_project FOREIGN KEY (project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_rs_item FOREIGN KEY (item_id) REFERENCES research_items(id) ON DELETE SET NULL,
    CONSTRAINT fk_rs_user FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
