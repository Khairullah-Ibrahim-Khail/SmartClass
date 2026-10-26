-- A teacher can show or hide the AI writing help (language check and hints) for the students of a class. Off until the teacher switches it on.
-- The existing "AI for students in this class" switch (tutor, study helpers) is not changed.
ALTER TABLE classes ADD COLUMN ai_student_writing TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'students see the AI writing help (language check, hints) in this class' AFTER ai_students;
