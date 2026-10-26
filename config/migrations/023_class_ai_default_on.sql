-- SmartClass migration 023 — AI for students is on by default in a class
-- The class switch used to default to off, so students saw no AI even after the school
-- and teacher set AI up. Teachers can still switch it off per class (Edit class).
-- Run ONCE.
--
--   mysql -u USER -p smartclass_db < config/migrations/023_class_ai_default_on.sql

ALTER TABLE classes MODIFY ai_students TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'students may use AI helpers in this class';
UPDATE classes SET ai_students = 1;
