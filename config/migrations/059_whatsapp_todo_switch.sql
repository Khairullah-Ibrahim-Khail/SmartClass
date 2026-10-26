-- A teacher can keep their number saved but stop the morning "Today" list.
ALTER TABLE users ADD COLUMN whatsapp_todo TINYINT(1) NOT NULL DEFAULT 1 AFTER whatsapp_relation;
