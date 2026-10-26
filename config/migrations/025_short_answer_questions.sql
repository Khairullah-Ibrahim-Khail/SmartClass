-- SmartClass migration 025 — short-answer quiz questions
-- quiz_questions.question_type: 'choice' (as before) or 'short' (the student types an answer).
-- quiz_questions.model_answer: for short questions, what a good answer must contain (teacher only).
-- quiz_answers: the typed answer, the marks given (NULL = not marked yet), feedback, and who
--   marked it: auto (empty answer), ai (the teacher's AI suggestion, reviewable) or teacher.
-- Existing questions stay multiple choice. Run ONCE.
--
--   mysql -u USER -p smartclass_db < config/migrations/025_short_answer_questions.sql

ALTER TABLE quiz_questions
    ADD COLUMN question_type ENUM('choice', 'short') NOT NULL DEFAULT 'choice' AFTER question,
    ADD COLUMN model_answer TEXT DEFAULT NULL AFTER question_type;

ALTER TABLE quiz_answers
    ADD COLUMN answer_text TEXT DEFAULT NULL AFTER option_id,
    ADD COLUMN marks_awarded DECIMAL(6,2) DEFAULT NULL AFTER is_correct,
    ADD COLUMN feedback TEXT DEFAULT NULL AFTER marks_awarded,
    ADD COLUMN mark_source ENUM('auto', 'ai', 'teacher') DEFAULT NULL AFTER feedback,
    ADD COLUMN marked_at DATETIME DEFAULT NULL AFTER mark_source;
