-- SmartClass migration 039 — academic years: every class belongs to a year; a year can be closed and the next one started
-- (classes are copied forward, the old ones stay readable as a record). Run ONCE (bin/update_all.php / bin/migrate.php do it).

CREATE TABLE IF NOT EXISTS academic_years (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(40) NOT NULL,
    starts_on DATE NOT NULL,
    ends_on DATE NOT NULL,
    closed_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_year_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE classes ADD COLUMN year_id INT UNSIGNED DEFAULT NULL AFTER is_active, ADD INDEX idx_classes_year (year_id);

-- the year we are in now (school years start in September): 2026–2027 …
INSERT INTO academic_years (name, starts_on, ends_on)
SELECT CONCAT(y, '–', y + 1), STR_TO_DATE(CONCAT(y, '-09-01'), '%Y-%m-%d'), STR_TO_DATE(CONCAT(y + 1, '-06-30'), '%Y-%m-%d')
FROM (SELECT YEAR(CURDATE()) - (MONTH(CURDATE()) < 8) AS y) t
WHERE NOT EXISTS (SELECT 1 FROM academic_years);

UPDATE classes SET year_id = (SELECT id FROM academic_years ORDER BY starts_on DESC LIMIT 1) WHERE year_id IS NULL;
