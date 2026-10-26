-- SmartClass migration 042 — a file can be attached to a forum topic or reply
-- Run ONCE (bin/update_all.php / bin/migrate.php do it).
CREATE TABLE IF NOT EXISTS forum_attachments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    thread_id INT UNSIGNED NOT NULL,
    post_id INT UNSIGNED DEFAULT NULL COMMENT 'NULL = attached to the topic itself',
    user_id INT UNSIGNED NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    file_type VARCHAR(10) NOT NULL,
    file_size INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (thread_id) REFERENCES forum_threads(id) ON DELETE CASCADE,
    FOREIGN KEY (post_id) REFERENCES forum_posts(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_forum_att (thread_id, post_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
