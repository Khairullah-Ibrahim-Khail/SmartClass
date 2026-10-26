-- SmartClass migration 028 — Events and Schedules (sidebar modules)
-- class_events: one-off events of a class (trip, meeting, exam day) with place and time.
-- class_schedule: the weekly timetable of a class (day, start, end, room).
-- Run ONCE.
--
--   mysql -u USER -p smartclass_db < config/migrations/028_events_schedules.sql

CREATE TABLE class_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id INT UNSIGNED NOT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT DEFAULT NULL,
    location VARCHAR(200) DEFAULT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_class_start (class_id, starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE class_schedule (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id INT UNSIGNED NOT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    day_of_week TINYINT UNSIGNED NOT NULL COMMENT '1 = Monday ... 7 = Sunday',
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    room VARCHAR(100) DEFAULT NULL,
    note VARCHAR(200) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_class_day (class_id, day_of_week, start_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
