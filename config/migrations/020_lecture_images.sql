-- SmartClass migration 020 — images in lectures
-- lecture_images: pictures a class's teachers upload for lectures (re-encoded on upload,
--   stored under uploads/lectures/ with a random name, served by pages/lectures/image.php
--   only to people in the class). Lectures refer to them as ![caption](img:ID 60% center).
-- Run ONCE.
--
--   mysql -u USER -p smartclass_db < config/migrations/020_lecture_images.sql

CREATE TABLE lecture_images (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id INT UNSIGNED NOT NULL,
    uploaded_by INT UNSIGNED DEFAULT NULL,
    file_path VARCHAR(255) NOT NULL COMMENT 'uploads/lectures/<random>.<ext>',
    mime VARCHAR(30) NOT NULL,
    width INT UNSIGNED NOT NULL,
    height INT UNSIGNED NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    original_name VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_class (class_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
