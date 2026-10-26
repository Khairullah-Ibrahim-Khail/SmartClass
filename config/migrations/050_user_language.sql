-- SmartClass migration 050 — each person's language (en / ps / fa); empty = follow the school default
-- Run ONCE (bin/update_all.php / bin/migrate.php do it).
ALTER TABLE users ADD COLUMN lang CHAR(2) NOT NULL DEFAULT '' COMMENT 'interface language: en, ps (Pashto), fa (Dari); empty = school default' AFTER photo;
