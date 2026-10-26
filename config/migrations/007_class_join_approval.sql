-- SmartClass migration 007 — teacher approval to join a class
--   * classes.require_approval: 1 = a class-code join becomes a request the teacher approves
--   * class_join_requests: pending / approved / declined requests (class_members stays
--     "approved members only", so every existing query keeps working unchanged)
-- Run ONCE. Existing members stay members. Existing classes default to requiring approval.
--
--   mysql -u USER -p smartclass_db < config/migrations/007_class_join_approval.sql

ALTER TABLE classes
    ADD COLUMN require_approval TINYINT(1) NOT NULL DEFAULT 1
        COMMENT '1 = students who use the class code wait for teacher approval' AFTER stream_permission;

CREATE TABLE class_join_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    status ENUM('pending', 'approved', 'declined') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_by INT UNSIGNED DEFAULT NULL,
    decided_at DATETIME DEFAULT NULL,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_request (class_id, student_id),
    INDEX idx_class_status (class_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
