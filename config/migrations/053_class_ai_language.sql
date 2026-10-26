-- SmartClass migration 053 — which language the AI answers in, per class (teacher's choice on the class settings page)
-- screen = the language of the student's screen (default) · en = always English · question = the language of the student's question
-- Run ONCE (bin/update_all.php / bin/migrate.php do it).

ALTER TABLE classes
    ADD COLUMN ai_language ENUM('screen', 'en', 'question') NOT NULL DEFAULT 'screen' AFTER ai_students;
