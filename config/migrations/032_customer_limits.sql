-- SmartClass migration 032 — per-customer limits (set by the provider on the Customers page; NULL = no limit)
-- Run ONCE (bin/update_all.php / bin/migrate.php do it).

ALTER TABLE customers
    ADD COLUMN max_teachers INT UNSIGNED DEFAULT NULL AFTER services_off,
    ADD COLUMN max_students INT UNSIGNED DEFAULT NULL AFTER max_teachers,
    ADD COLUMN max_classes INT UNSIGNED DEFAULT NULL AFTER max_students;
