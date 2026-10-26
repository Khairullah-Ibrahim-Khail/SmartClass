-- SmartClass migration 030 — Forums and Research (sidebar modules)
-- forum_threads / forum_posts: class discussion boards (teacher can pin, lock and delete; authors delete their own).
-- research_items: a shared research library per class (papers, articles, books, datasets, projects with a link).
-- Run ONCE.
--
--   mysql -u USER -p smartclass_db < config/migrations/030_forums_research.sql

CREATE TABLE forum_threads (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    body TEXT NOT NULL,
    pinned TINYINT(1) NOT NULL DEFAULT 0,
    locked TINYINT(1) NOT NULL DEFAULT 0,
    last_post_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_class_activity (class_id, pinned, last_post_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE forum_posts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    thread_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (thread_id) REFERENCES forum_threads(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_thread (thread_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE research_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id INT UNSIGNED NOT NULL,
    added_by INT UNSIGNED DEFAULT NULL,
    kind ENUM('paper', 'article', 'book', 'dataset', 'project', 'other') NOT NULL DEFAULT 'paper',
    title VARCHAR(250) NOT NULL,
    authors VARCHAR(250) DEFAULT NULL,
    year SMALLINT UNSIGNED DEFAULT NULL,
    url VARCHAR(500) DEFAULT NULL,
    summary TEXT DEFAULT NULL,
    tags VARCHAR(200) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_class_kind (class_id, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
