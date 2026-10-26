-- SmartClass - Google Classroom Clone
-- Simplified Database Schema

-- Create Database
CREATE DATABASE IF NOT EXISTS smartclass_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE smartclass_db;

-- Allow DROP/CREATE in any order on re-install (restored at the end)
SET FOREIGN_KEY_CHECKS = 0;

-- =====================================================
-- TABLE: Users (Teachers & Students)
-- =====================================================
DROP TABLE IF EXISTS users;
CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    email_verified_at DATETIME DEFAULT NULL COMMENT 'proved by signing in with an emailed code',
    password VARCHAR(255) NOT NULL,
    role ENUM('teacher', 'student') NOT NULL DEFAULT 'student',
    is_admin TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'school super admin (a teacher)',
    photo VARCHAR(255) DEFAULT NULL,
    lang CHAR(2) NOT NULL DEFAULT '' COMMENT 'interface language: en, ps (Pashto), fa (Dari); empty = school default',
    email_token VARCHAR(64) DEFAULT NULL COMMENT 'secret for one-click unsubscribe links',
    is_approved TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0=pending, 1=approved',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    totp_secret VARCHAR(255) DEFAULT NULL,
    totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
    totp_last_step INT UNSIGNED NOT NULL DEFAULT 0,
    guardian_name VARCHAR(100) DEFAULT NULL,
    guardian_email VARCHAR(100) DEFAULT NULL,
    guardian_phone VARCHAR(40) DEFAULT NULL,
    guardian_alerts TINYINT(1) NOT NULL DEFAULT 1,
    last_login DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_role (role),
    INDEX idx_approved (is_approved)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert sample teacher (password: password)
INSERT INTO users (name, email, password, role, is_approved) 
VALUES ('Mr. Ahmad Khan', 'teacher@test.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'teacher', 1);

-- Insert sample student (password: password)
INSERT INTO users (name, email, password, role, is_approved) 
VALUES ('Ahmed Khan', 'student@test.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 1);

-- =====================================================
-- TABLE: Classes
-- =====================================================
DROP TABLE IF EXISTS classes;
CREATE TABLE classes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT UNSIGNED NOT NULL,
    class_name VARCHAR(100) NOT NULL,
    class_code VARCHAR(20) NOT NULL UNIQUE,
    description TEXT,
    tags VARCHAR(255) DEFAULT NULL,
    image VARCHAR(255) DEFAULT NULL,
    stream_permission ENUM('post', 'comment', 'teacher_only') NOT NULL DEFAULT 'comment' COMMENT 'post = students post + comment, comment = students comment only, teacher_only = read only',
    require_approval TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = students who use the class code wait for teacher approval',
    theme VARCHAR(20) NOT NULL DEFAULT 'auto',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    year_id INT UNSIGNED DEFAULT NULL,
    ai_students TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'students may use AI helpers in this class',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_teacher (teacher_id),
    INDEX idx_code (class_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sample Classes
INSERT INTO classes (teacher_id, class_name, class_code, description) VALUES
(1, 'Python Programming', 'PYTHON2024', 'Learn Python from basics to advanced'),
(1, 'Web Development', 'WEB2024', 'HTML, CSS, JavaScript fundamentals'),
(1, 'Data Science', 'DATA2024', 'Introduction to Data Science');

-- =====================================================
-- TABLE: Class Members (Students in Classes)
-- =====================================================
DROP TABLE IF EXISTS class_members;
CREATE TABLE class_members (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_member (class_id, student_id),
    INDEX idx_class (class_id),
    INDEX idx_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sample class members
INSERT INTO class_members (class_id, student_id) VALUES (1, 2);

-- =====================================================
-- TABLE: Class join requests (teacher approves students who use the class code)
-- =====================================================
DROP TABLE IF EXISTS class_join_requests;
CREATE TABLE class_join_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    status ENUM('pending', 'approved', 'declined') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_by INT UNSIGNED DEFAULT NULL,
    decided_at DATETIME DEFAULT NULL,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_request (class_id, student_id),
    INDEX idx_class_status (class_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- TABLE: Class topics (units that organise classwork)
-- =====================================================
DROP TABLE IF EXISTS class_topics;
CREATE TABLE class_topics (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    position INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    INDEX idx_class (class_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- TABLE: Lectures
-- =====================================================
DROP TABLE IF EXISTS lectures;
CREATE TABLE lectures (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id INT UNSIGNED NOT NULL,
    topic_id INT UNSIGNED DEFAULT NULL,
    teacher_id INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    summary VARCHAR(300) DEFAULT NULL COMMENT 'one line shown in lists',
    content TEXT,
    notes TEXT DEFAULT NULL,
    video_url VARCHAR(500),
    status ENUM('draft', 'published') NOT NULL DEFAULT 'published',
    publish_at DATETIME DEFAULT NULL COMMENT 'published lecture shown from this time - NULL = now',
    notified_at DATETIME DEFAULT NULL COMMENT 'students notified - NULL = not yet',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (topic_id) REFERENCES class_topics(id) ON DELETE SET NULL,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_class (class_id),
    INDEX idx_publish (status, notified_at, publish_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sample lectures
INSERT INTO lectures (class_id, teacher_id, title, content, video_url) VALUES
(1, 1, 'Introduction to Python', 'Learn what Python is and why it is popular.', 'https://youtube.com/watch?v=python-intro'),
(1, 1, 'Variables and Data Types', 'Understanding Python variables and data types.', 'https://youtube.com/watch?v=python-variables');

-- =====================================================
-- TABLE: Resources (Study Materials)
-- =====================================================
DROP TABLE IF EXISTS resources;
CREATE TABLE resources (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id INT UNSIGNED NOT NULL,
    topic_id INT UNSIGNED DEFAULT NULL,
    uploaded_by INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT DEFAULT NULL,
    category VARCHAR(30) NOT NULL DEFAULT 'other',
    file_path VARCHAR(255),
    original_name VARCHAR(255) DEFAULT NULL COMMENT 'display/download name only - stored under a random name',
    file_type VARCHAR(50),
    file_size INT UNSIGNED DEFAULT 0,
    download_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (topic_id) REFERENCES class_topics(id) ON DELETE SET NULL,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_class (class_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- TABLE: Assignments
-- =====================================================
DROP TABLE IF EXISTS assignments;
CREATE TABLE assignments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id INT UNSIGNED NOT NULL,
    topic_id INT UNSIGNED DEFAULT NULL,
    teacher_id INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    due_date DATETIME,
    max_marks INT UNSIGNED DEFAULT 100,
    rubric TEXT DEFAULT NULL COMMENT 'JSON list of criteria - points add up to max_marks',
    release_mode ENUM('instant', 'manual') NOT NULL DEFAULT 'instant',
    results_released_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (topic_id) REFERENCES class_topics(id) ON DELETE SET NULL,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_class (class_id),
    INDEX idx_due (due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sample assignments
INSERT INTO assignments (class_id, teacher_id, title, description, due_date) VALUES
(1, 1, 'Python Basics Quiz', 'Test your knowledge of Python basics.', DATE_ADD(NOW(), INTERVAL 7 DAY));

-- =====================================================
-- TABLE: Submissions (Assignment Submissions)
-- =====================================================
DROP TABLE IF EXISTS submissions;
CREATE TABLE submissions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    assignment_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    content TEXT,
    file_path VARCHAR(255),
    original_name VARCHAR(255) DEFAULT NULL COMMENT 'display/download name only - stored under a random name',
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    marks_obtained DECIMAL(6,2) DEFAULT NULL,
    feedback TEXT DEFAULT NULL,
    graded_by INT UNSIGNED DEFAULT NULL,
    graded_at DATETIME DEFAULT NULL,
    grade_source ENUM('teacher', 'ai_accepted', 'ai_edited') DEFAULT NULL,
    status ENUM('submitted', 'graded', 'late') NOT NULL DEFAULT 'submitted',
    FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (graded_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_submission (assignment_id, student_id),
    INDEX idx_assignment (assignment_id),
    INDEX idx_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- TABLE: Private comments on assignments (student <-> teacher)
-- =====================================================
DROP TABLE IF EXISTS assignment_comments;
CREATE TABLE assignment_comments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    assignment_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL COMMENT 'whose private thread this is',
    user_id INT UNSIGNED NOT NULL COMMENT 'author - the student or the teacher',
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_thread (assignment_id, student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- TABLE: Private messages (student <-> teacher: general, per assignment, per quiz)
-- =====================================================
DROP TABLE IF EXISTS private_messages;
CREATE TABLE private_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL COMMENT 'whose conversation this is',
    user_id INT UNSIGNED NOT NULL COMMENT 'author - the student or the teacher',
    context_type ENUM('class', 'assignment', 'quiz', 'lecture') NOT NULL DEFAULT 'class',
    context_id INT UNSIGNED DEFAULT NULL COMMENT 'assignment / quiz id - NULL for class',
    body TEXT NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'read by the recipient',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_conversation (class_id, student_id, context_type, context_id),
    INDEX idx_unread (class_id, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- TABLES: Quizzes (multiple choice, auto-graded)
-- =====================================================
DROP TABLE IF EXISTS quizzes;
CREATE TABLE quizzes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id INT UNSIGNED NOT NULL,
    topic_id INT UNSIGNED DEFAULT NULL,
    teacher_id INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT DEFAULT NULL,
    time_limit INT UNSIGNED DEFAULT NULL COMMENT 'minutes - NULL = no limit',
    due_date DATETIME DEFAULT NULL COMMENT 'no new attempts after this - NULL = open',
    release_mode ENUM('instant', 'manual') NOT NULL DEFAULT 'instant',
    results_released_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (topic_id) REFERENCES class_topics(id) ON DELETE SET NULL,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_class (class_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS quiz_questions;
CREATE TABLE quiz_questions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    quiz_id INT UNSIGNED NOT NULL,
    question TEXT NOT NULL,
    question_type ENUM('choice', 'short') NOT NULL DEFAULT 'choice',
    model_answer TEXT DEFAULT NULL COMMENT 'short questions: what a good answer contains (teacher only)',
    marks INT UNSIGNED NOT NULL DEFAULT 1,
    position INT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE,
    INDEX idx_quiz (quiz_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS quiz_options;
CREATE TABLE quiz_options (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    question_id INT UNSIGNED NOT NULL,
    option_text VARCHAR(500) NOT NULL,
    is_correct TINYINT(1) NOT NULL DEFAULT 0,
    position INT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (question_id) REFERENCES quiz_questions(id) ON DELETE CASCADE,
    INDEX idx_question (question_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One attempt per student per quiz. submitted_at NULL = in progress.
DROP TABLE IF EXISTS quiz_attempts;
CREATE TABLE quiz_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    quiz_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    submitted_at DATETIME DEFAULT NULL,
    score DECIMAL(7,2) DEFAULT NULL,
    max_score INT UNSIGNED NOT NULL DEFAULT 0,
    timed_out TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = submitted after the time limit - answers not counted',
    FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_attempt (quiz_id, student_id),
    INDEX idx_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS quiz_answers;
CREATE TABLE quiz_answers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attempt_id INT UNSIGNED NOT NULL,
    question_id INT UNSIGNED NOT NULL,
    option_id INT UNSIGNED DEFAULT NULL,
    answer_text TEXT DEFAULT NULL,
    is_correct TINYINT(1) NOT NULL DEFAULT 0,
    marks_awarded DECIMAL(6,2) DEFAULT NULL COMMENT 'short answers: NULL = not marked yet',
    feedback TEXT DEFAULT NULL,
    mark_source ENUM('auto', 'ai', 'teacher') DEFAULT NULL,
    marked_at DATETIME DEFAULT NULL,
    FOREIGN KEY (attempt_id) REFERENCES quiz_attempts(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES quiz_questions(id) ON DELETE CASCADE,
    FOREIGN KEY (option_id) REFERENCES quiz_options(id) ON DELETE SET NULL,
    UNIQUE KEY unique_answer (attempt_id, question_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- TABLES: Class stream (announcements + comments)
-- =====================================================
DROP TABLE IF EXISTS announcements;
CREATE TABLE announcements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL COMMENT 'author - the class teacher',
    body TEXT NOT NULL,
    pinned TINYINT(1) NOT NULL DEFAULT 0,
    edited_at DATETIME DEFAULT NULL COMMENT 'last time the author edited the text',
    publish_at DATETIME DEFAULT NULL COMMENT 'visible to students from this time - NULL = right away',
    notified_at DATETIME DEFAULT NULL COMMENT 'class notified about the post - NULL = not yet',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_class_created (class_id, created_at),
    INDEX idx_publish (notified_at, publish_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS announcement_comments;
CREATE TABLE announcement_comments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    announcement_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (announcement_id) REFERENCES announcements(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_announcement (announcement_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- TABLE: Attendance (per class, per day)
-- =====================================================
DROP TABLE IF EXISTS attendance;
CREATE TABLE attendance (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    date DATE NOT NULL,
    session TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'session (period) of the day, 1-8',
    status ENUM('present', 'late', 'absent', 'official') NOT NULL COMMENT 'official = official present (on duty, counts as present)',
    note VARCHAR(255) DEFAULT NULL,
    excused TINYINT(1) DEFAULT NULL COMMENT '1 excused, 0 unexcused, NULL not stated (late / absent only)',
    reason VARCHAR(20) DEFAULT NULL COMMENT 'sick, family, travel, transport, other',
    late_minutes SMALLINT UNSIGNED DEFAULT NULL COMMENT 'how many minutes late (status late only)',
    marked_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (marked_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_mark (class_id, student_id, date, session),
    INDEX idx_class_date (class_id, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE attendance_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    date DATE NOT NULL,
    session TINYINT UNSIGNED NOT NULL DEFAULT 1,
    old_status VARCHAR(20) DEFAULT NULL,
    new_status VARCHAR(20) DEFAULT NULL COMMENT 'NULL = the day was cleared',
    old_note VARCHAR(255) DEFAULT NULL,
    new_note VARCHAR(255) DEFAULT NULL,
    changed_by INT UNSIGNED DEFAULT NULL,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_log_day (class_id, date, changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE attendance_days (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id INT UNSIGNED NOT NULL,
    date DATE NOT NULL,
    label VARCHAR(100) NOT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_noclass (class_id, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='days with no class (holiday, exam week …): nobody is marked';


-- =====================================================
-- TABLES: Email notifications (settings, queue, preferences)
-- =====================================================
DROP TABLE IF EXISTS app_settings;
CREATE TABLE app_settings (
    setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
    setting_value TEXT,
    updated_by INT UNSIGNED DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS email_queue;
CREATE TABLE email_queue (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED DEFAULT NULL,
    to_email VARCHAR(100) NOT NULL,
    to_name VARCHAR(100) DEFAULT NULL,
    category VARCHAR(20) NOT NULL DEFAULT 'general',
    subject VARCHAR(255) NOT NULL,
    body_html MEDIUMTEXT NOT NULL,
    body_text MEDIUMTEXT NOT NULL,
    status ENUM('pending', 'held', 'sent', 'failed', 'digested') NOT NULL DEFAULT 'pending'
        COMMENT 'held = waiting for the daily digest',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_status (status, created_at),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS email_preferences;
CREATE TABLE email_preferences (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    mode ENUM('instant', 'daily', 'off') NOT NULL DEFAULT 'instant',
    classwork TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'new lectures, resources, assignments, quizzes, announcements',
    grades TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'graded work, quiz results, submissions received',
    attendance TINYINT(1) NOT NULL DEFAULT 1,
    comments TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'class + private comments, posts',
    approvals TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'registrations, join requests, approvals',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- TABLE: Notifications (fed by every module via notify())
-- =====================================================
DROP TABLE IF EXISTS notifications;
CREATE TABLE notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL COMMENT 'recipient',
    sender_id INT UNSIGNED DEFAULT NULL,
    class_id INT UNSIGNED DEFAULT NULL,
    type VARCHAR(50) NOT NULL COMMENT 'e.g. student_registered, lecture_added, assignment_created, submission_received, student_approved',
    title VARCHAR(200) NOT NULL,
    message TEXT,
    link VARCHAR(255) DEFAULT NULL COMMENT 'relative URL to open when clicked',
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    INDEX idx_user_unread (user_id, is_read),
    INDEX idx_created (created_at),
    INDEX idx_user_created (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- TABLE: Activity Log
-- =====================================================
DROP TABLE IF EXISTS activity_log;
CREATE TABLE activity_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED,
    action VARCHAR(100) NOT NULL,
    module VARCHAR(50) NOT NULL,
    details TEXT,
    ip_address VARCHAR(45),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user (user_id),
    INDEX idx_module (module),
    INDEX idx_created (created_at),
    INDEX idx_user_created (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- CLASSWORK PROGRESS (opened / done) + ASSIGNMENT MATERIALS
-- =====================================================
DROP TABLE IF EXISTS content_progress;
CREATE TABLE content_progress (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    item_type ENUM('lecture', 'resource') NOT NULL,
    item_id INT UNSIGNED NOT NULL COMMENT 'lectures.id or resources.id',
    first_opened_at DATETIME DEFAULT NULL,
    last_opened_at DATETIME DEFAULT NULL,
    open_count INT UNSIGNED NOT NULL DEFAULT 0,
    done_at DATETIME DEFAULT NULL COMMENT 'marked done by the student',
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_progress (student_id, item_type, item_id),
    INDEX idx_item (class_id, item_type, item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS assignment_materials;
CREATE TABLE assignment_materials (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    assignment_id INT UNSIGNED NOT NULL,
    kind ENUM('link', 'resource') NOT NULL,
    resource_id INT UNSIGNED DEFAULT NULL COMMENT 'kind = resource - a resource of the same class',
    url VARCHAR(500) DEFAULT NULL COMMENT 'kind = link - http(s) only',
    title VARCHAR(200) DEFAULT NULL,
    position INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE,
    FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE CASCADE,
    INDEX idx_assignment (assignment_id, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- TEACHER INVITES
-- =====================================================
DROP TABLE IF EXISTS teacher_invites;
CREATE TABLE teacher_invites (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    token_hash CHAR(64) NOT NULL COMMENT 'sha256 of the token in the invite link',
    invited_by INT UNSIGNED DEFAULT NULL,
    expires_at DATETIME NOT NULL,
    accepted_at DATETIME DEFAULT NULL,
    accepted_user_id INT UNSIGNED DEFAULT NULL,
    revoked_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invited_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (accepted_user_id) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_token (token_hash),
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- CLASS TEACHERS (owner + co-teachers)
-- =====================================================
DROP TABLE IF EXISTS class_teachers;
CREATE TABLE class_teachers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id INT UNSIGNED NOT NULL,
    teacher_id INT UNSIGNED NOT NULL,
    role ENUM('owner', 'co_teacher') NOT NULL DEFAULT 'co_teacher',
    added_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_class_teacher (class_id, teacher_id),
    INDEX idx_teacher (teacher_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- AI PROVIDERS + USAGE
-- =====================================================
DROP TABLE IF EXISTS ai_providers;
CREATE TABLE ai_providers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_id INT UNSIGNED DEFAULT NULL COMMENT 'NULL = school provider, else the user who owns it',
    provider VARCHAR(30) NOT NULL COMMENT 'registry key in includes/ai.php - openai, anthropic, groq',
    api_key TEXT DEFAULT NULL COMMENT 'encrypted - empty for providers without a key (Ollama)',
    key_hint VARCHAR(8) DEFAULT NULL,
    base_url VARCHAR(255) DEFAULT NULL COMMENT 'NULL = the provider default',
    model VARCHAR(150) DEFAULT NULL COMMENT 'default model for this provider',
    models_cache TEXT DEFAULT NULL COMMENT 'JSON list loaded from the provider models endpoint',
    price_in DECIMAL(10,4) DEFAULT NULL COMMENT 'USD per 1M input tokens - optional, for cost estimates',
    price_out DECIMAL(10,4) DEFAULT NULL COMMENT 'USD per 1M output tokens',
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    share_students TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'teacher lets students of their classes use it',
    share_limit INT UNSIGNED NOT NULL DEFAULT 20000 COMMENT 'tokens per student per month on this key - 0 = no limit',
    updated_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_owner (owner_id, provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS ai_usage;
CREATE TABLE ai_usage (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED DEFAULT NULL,
    feature VARCHAR(40) NOT NULL,
    provider VARCHAR(30) NOT NULL,
    key_owner INT UNSIGNED DEFAULT NULL COMMENT 'NULL = school key',
    class_id INT UNSIGNED DEFAULT NULL,
    model VARCHAR(150) DEFAULT NULL,
    input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    cost DECIMAL(12,6) DEFAULT NULL COMMENT 'USD estimate - NULL when no prices are set',
    status ENUM('ok', 'error') NOT NULL DEFAULT 'ok',
    error VARCHAR(500) DEFAULT NULL,
    duration_ms INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_month (user_id, created_at),
    INDEX idx_created (created_at),
    INDEX idx_key_owner (key_owner, user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- LOGIN CODES (students sign in with an emailed code)
-- =====================================================
DROP TABLE IF EXISTS login_codes;
CREATE TABLE login_codes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    code_hash CHAR(64) NOT NULL COMMENT 'HMAC-SHA256 of the code',
    expires_at DATETIME NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    used_at DATETIME DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_created (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- LECTURE MATERIALS + AI CODE EXPLANATIONS
-- =====================================================
DROP TABLE IF EXISTS lecture_materials;
CREATE TABLE lecture_materials (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lecture_id INT UNSIGNED NOT NULL,
    kind ENUM('link', 'resource') NOT NULL,
    resource_id INT UNSIGNED DEFAULT NULL COMMENT 'kind = resource - a resource of the same class',
    url VARCHAR(500) DEFAULT NULL COMMENT 'kind = link - http(s) only (YouTube links are embedded)',
    title VARCHAR(200) DEFAULT NULL,
    position INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lecture_id) REFERENCES lectures(id) ON DELETE CASCADE,
    FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE CASCADE,
    INDEX idx_lecture (lecture_id, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS lecture_explanations;
CREATE TABLE lecture_explanations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lecture_id INT UNSIGNED NOT NULL,
    code_hash CHAR(64) NOT NULL COMMENT 'sha256 of the code block - edited code gets a new explanation',
    explanation MEDIUMTEXT NOT NULL,
    provider VARCHAR(30) DEFAULT NULL,
    model VARCHAR(150) DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL COMMENT 'whose click produced it',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lecture_id) REFERENCES lectures(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_block (lecture_id, code_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS lecture_images;
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

DROP TABLE IF EXISTS ai_grade_suggestions;
CREATE TABLE ai_grade_suggestions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    submission_id INT UNSIGNED NOT NULL,
    content_hash CHAR(64) NOT NULL COMMENT 'sha256 of the answer that was assessed',
    marks DECIMAL(6,2) NOT NULL,
    feedback TEXT DEFAULT NULL,
    breakdown TEXT DEFAULT NULL COMMENT 'JSON [{title, points, max, comment}]',
    flagged TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'the answer tries to influence the grader',
    flag_reason VARCHAR(255) DEFAULT NULL,
    provider VARCHAR(30) DEFAULT NULL,
    model VARCHAR(150) DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_submission (submission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS quiz_explanations;
CREATE TABLE quiz_explanations (
    attempt_id INT UNSIGNED NOT NULL PRIMARY KEY,
    content TEXT NOT NULL COMMENT 'JSON {question_id: explanation}',
    provider VARCHAR(30) DEFAULT NULL,
    model VARCHAR(150) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (attempt_id) REFERENCES quiz_attempts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS quiz_insights;
CREATE TABLE quiz_insights (
    quiz_id INT UNSIGNED NOT NULL PRIMARY KEY,
    attempts INT UNSIGNED NOT NULL COMMENT 'submitted attempts when it was written',
    content TEXT NOT NULL COMMENT 'JSON {summary, weak_points[], suggestions[]}',
    provider VARCHAR(30) DEFAULT NULL,
    model VARCHAR(150) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS lecture_study;
CREATE TABLE lecture_study (
    lecture_id INT UNSIGNED NOT NULL,
    kind ENUM('summary', 'terms') NOT NULL,
    content_hash CHAR(64) NOT NULL COMMENT 'sha256 of the lecture text it was written from',
    content TEXT NOT NULL,
    provider VARCHAR(30) DEFAULT NULL,
    model VARCHAR(150) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (lecture_id, kind),
    FOREIGN KEY (lecture_id) REFERENCES lectures(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS password_resets;
CREATE TABLE password_resets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_token (token_hash),
    INDEX idx_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS class_events;
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

DROP TABLE IF EXISTS class_schedule;
CREATE TABLE class_schedule (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id INT UNSIGNED NOT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    day_of_week TINYINT UNSIGNED NOT NULL COMMENT '1 = Monday to 7 = Sunday',
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    room VARCHAR(100) DEFAULT NULL,
    note VARCHAR(200) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_class_day (class_id, day_of_week, start_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS exams;
CREATE TABLE exams (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id INT UNSIGNED NOT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    title VARCHAR(200) NOT NULL,
    kind ENUM('mid', 'final', 'activity', 'other') NOT NULL DEFAULT 'other' COMMENT 'which part of the final marks it counts in',
    exam_date DATETIME NOT NULL,
    duration_min SMALLINT UNSIGNED DEFAULT NULL,
    venue VARCHAR(200) DEFAULT NULL,
    max_marks SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    instructions TEXT DEFAULT NULL,
    results_published_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_class_date (class_id, exam_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS exam_marks;
CREATE TABLE exam_marks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    exam_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    marks DECIMAL(6,2) DEFAULT NULL,
    absent TINYINT(1) NOT NULL DEFAULT 0,
    remarks VARCHAR(300) DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_exam_student (exam_id, student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS forum_threads;
CREATE TABLE forum_threads (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    body TEXT NOT NULL,
    pinned TINYINT(1) NOT NULL DEFAULT 0,
    locked TINYINT(1) NOT NULL DEFAULT 0,
    last_post_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_class_activity (class_id, pinned, last_post_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS forum_posts;
CREATE TABLE forum_posts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    thread_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (thread_id) REFERENCES forum_threads(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_thread (thread_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS research_items;
CREATE TABLE research_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id INT UNSIGNED NOT NULL,
    added_by INT UNSIGNED DEFAULT NULL,
    kind ENUM('paper', 'article', 'book', 'dataset', 'project', 'other') NOT NULL DEFAULT 'paper',
    title VARCHAR(250) NOT NULL,
    authors VARCHAR(250) DEFAULT NULL,
    year SMALLINT UNSIGNED DEFAULT NULL,
    url VARCHAR(500) DEFAULT NULL,
    summary TEXT DEFAULT NULL,
    tags VARCHAR(200) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_class_kind (class_id, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS schema_migrations;
CREATE TABLE schema_migrations (
    name VARCHAR(100) NOT NULL PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS customers;
CREATE TABLE customers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    domain VARCHAR(190) NOT NULL,
    slug VARCHAR(60) NOT NULL,
    db_name VARCHAR(64) NOT NULL,
    db_user VARCHAR(64) NOT NULL,
    contact VARCHAR(190) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    status ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
    suspended_reason VARCHAR(255) DEFAULT NULL,
    suspended_at DATETIME DEFAULT NULL,
    services_off VARCHAR(200) NOT NULL DEFAULT '' COMMENT 'comma list: ai, email, uploads, signups',
    max_teachers INT UNSIGNED DEFAULT NULL,
    max_students INT UNSIGNED DEFAULT NULL,
    max_classes INT UNSIGNED DEFAULT NULL,
    support_secret CHAR(64) NOT NULL,
    terms_accepted_at DATETIME DEFAULT NULL,
    last_seen_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_domain (domain),
    UNIQUE KEY unique_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS customer_events;
CREATE TABLE customer_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED DEFAULT NULL,
    customer_name VARCHAR(120) DEFAULT NULL,
    event VARCHAR(60) NOT NULL,
    details VARCHAR(500) DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_customer (customer_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- sample lectures are already published (no 'new lecture' notification on first visit)
UPDATE lectures SET notified_at = created_at WHERE notified_at IS NULL;

-- every class's teacher is its owner
INSERT INTO class_teachers (class_id, teacher_id, role) SELECT id, teacher_id, 'owner' FROM classes;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================
-- SETUP INSTRUCTIONS
-- =====================================================
-- 1. mysql -u root -p
-- 2. CREATE DATABASE smartclass_db;
-- 3. USE smartclass_db;
-- 4. SOURCE smartclass_db.sql;

-- =====================================================
-- DEFAULT CREDENTIALS
-- =====================================================
-- Teacher: teacher@test.com / password
-- Student: student@test.com / password

-- =====================================================
-- TABLES: Quiz reactivation (retake for chosen students)
-- =====================================================
CREATE TABLE quiz_reopens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    quiz_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    reopened_by INT UNSIGNED DEFAULT NULL,
    reopened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    until DATETIME DEFAULT NULL COMMENT 'the student may start the quiz again until then (NULL = no deadline)',
    prev_state VARCHAR(20) NOT NULL DEFAULT 'none' COMMENT 'none | open | done | timeout — what the student had before',
    prev_score DECIMAL(7,2) DEFAULT NULL,
    prev_max INT UNSIGNED DEFAULT NULL,
    FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reopened_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_quiz_student (quiz_id, student_id, reopened_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE class_marking_scheme (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id INT UNSIGNED NOT NULL,
    component ENUM('mid', 'final', 'activity', 'quiz', 'assignment') NOT NULL,
    weight DECIMAL(6,2) NOT NULL DEFAULT 0 COMMENT 'marks this part is worth in the final total (0 = not used)',
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    UNIQUE KEY unique_class_component (class_id, component)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE class_grading_scales (
    class_id INT UNSIGNED NOT NULL PRIMARY KEY,
    scale TEXT NOT NULL COMMENT 'JSON: {show_gpa, basis, bands[{label,min,gpa,tone}]}',
    updated_by INT UNSIGNED DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE attendance_excuses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    date DATE NOT NULL,
    message VARCHAR(255) NOT NULL,
    status ENUM('pending', 'accepted', 'declined') NOT NULL DEFAULT 'pending',
    decided_by INT UNSIGNED DEFAULT NULL,
    decided_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_excuse (class_id, student_id, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE attendance_checkin (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id INT UNSIGNED NOT NULL,
    session TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'the session the code marks',
    code CHAR(6) NOT NULL,
    opened_by INT UNSIGNED DEFAULT NULL,
    opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    closed_at DATETIME DEFAULT NULL,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (opened_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_class_open (class_id, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE attendance_checkin_tries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    tried_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ok TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_try (class_id, student_id, tried_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE academic_years (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(40) NOT NULL,
    starts_on DATE NOT NULL,
    ends_on DATE NOT NULL,
    closed_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_year_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE user_backup_codes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    code_hash CHAR(64) NOT NULL,
    used_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_backup_user (user_id, used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE forum_attachments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    thread_id INT UNSIGNED NOT NULL,
    post_id INT UNSIGNED DEFAULT NULL COMMENT 'NULL = attached to the topic itself',
    user_id INT UNSIGNED NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    file_type VARCHAR(10) NOT NULL,
    file_size INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (thread_id) REFERENCES forum_threads(id) ON DELETE CASCADE,
    FOREIGN KEY (post_id) REFERENCES forum_posts(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_forum_att (thread_id, post_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



DROP TABLE IF EXISTS ai_tutor_messages;
DROP TABLE IF EXISTS ai_tutor_chats;
CREATE TABLE ai_tutor_chats (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    class_id INT UNSIGNED NOT NULL,
    lecture_id INT UNSIGNED DEFAULT NULL COMMENT 'the lecture the chat was opened from (optional)',
    title VARCHAR(120) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (lecture_id) REFERENCES lectures(id) ON DELETE SET NULL,
    INDEX idx_tutor_chat (student_id, class_id, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ai_tutor_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chat_id INT UNSIGNED NOT NULL,
    role ENUM('student', 'tutor') NOT NULL,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (chat_id) REFERENCES ai_tutor_chats(id) ON DELETE CASCADE,
    INDEX idx_tutor_msg (chat_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Phone (web push) notifications: one row per browser/phone; the push carries no text (migration 054)
DROP TABLE IF EXISTS push_subscriptions;

CREATE TABLE push_subscriptions (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,
    endpoint_hash CHAR(64) NOT NULL,
    endpoint      TEXT NOT NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_ok_at    TIMESTAMP NULL DEFAULT NULL,
    fail_count    INT NOT NULL DEFAULT 0,
    UNIQUE KEY uq_push_endpoint (endpoint_hash),
    KEY idx_push_user (user_id),
    CONSTRAINT fk_push_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS rate_limits;
CREATE TABLE rate_limits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bucket VARCHAR(40) NOT NULL COMMENT 'what is limited: login_account, login_ip, ai_user ...',
    rkey CHAR(64) NOT NULL COMMENT 'sha256 of the thing counted (e-mail, IP, user id)',
    hits INT UNSIGNED NOT NULL DEFAULT 0,
    window_start DATETIME NOT NULL,
    UNIQUE KEY unique_limit (bucket, rkey),
    INDEX idx_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS safeguarding_flags;
CREATE TABLE safeguarding_flags (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    class_id INT UNSIGNED NOT NULL,
    chat_id INT UNSIGNED DEFAULT NULL,
    category VARCHAR(20) NOT NULL COMMENT 'self_harm, abuse, violence, bullying',
    snippet VARCHAR(300) NOT NULL COMMENT 'the start of the message, so the teacher knows what to look for',
    status ENUM('open', 'handled') NOT NULL DEFAULT 'open',
    handled_by INT UNSIGNED DEFAULT NULL,
    handled_at DATETIME DEFAULT NULL,
    note VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (chat_id) REFERENCES ai_tutor_chats(id) ON DELETE SET NULL,
    FOREIGN KEY (handled_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_class_status (class_id, status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS ai_reports;
CREATE TABLE ai_reports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    class_id INT UNSIGNED DEFAULT NULL,
    feature VARCHAR(30) NOT NULL COMMENT 'tutor, study, explain_code, other',
    answer VARCHAR(600) NOT NULL COMMENT 'the start of the answer that was reported',
    note VARCHAR(255) DEFAULT NULL,
    status ENUM('open', 'reviewed') NOT NULL DEFAULT 'open',
    reviewed_by INT UNSIGNED DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
