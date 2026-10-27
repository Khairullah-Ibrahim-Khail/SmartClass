-- SmartClass schema for a new customer: every table, no rows (rebuilt from the full migrated schema, migrations 001-054). Keep in step with config/migrations/ — see docs/ARCHITECTURE.md.
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `academic_years`;

CREATE TABLE `academic_years` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(40) NOT NULL,
  `starts_on` date NOT NULL,
  `ends_on` date NOT NULL,
  `closed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_year_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `activity_log`;

CREATE TABLE `activity_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `module` varchar(50) NOT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_module` (`module`),
  KEY `idx_created` (`created_at`),
  KEY `idx_user_created` (`user_id`,`created_at`),
  CONSTRAINT `activity_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `ai_work_reviews`;
CREATE TABLE `ai_work_reviews` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `submission_id` int(10) unsigned NOT NULL,
  `content_hash` char(64) NOT NULL COMMENT 'sha256 of the answer that was checked, plus the note language',
  `verdict` enum('ready','changes') NOT NULL,
  `reason` varchar(400) DEFAULT NULL,
  `note` text DEFAULT NULL COMMENT 'drafted return note (empty when ready)',
  `lang` varchar(5) NOT NULL DEFAULT 'en',
  `flagged` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'the answer tries to influence the AI',
  `flag_reason` varchar(255) DEFAULT NULL,
  `provider` varchar(30) DEFAULT NULL,
  `model` varchar(150) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_submission` (`submission_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `ai_work_reviews_ibfk_1` FOREIGN KEY (`submission_id`) REFERENCES `submissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ai_work_reviews_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `ai_grade_suggestions`;

CREATE TABLE `ai_grade_suggestions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `submission_id` int(10) unsigned NOT NULL,
  `content_hash` char(64) NOT NULL COMMENT 'sha256 of the answer that was assessed',
  `marks` decimal(6,2) NOT NULL,
  `feedback` text DEFAULT NULL,
  `breakdown` text DEFAULT NULL COMMENT 'JSON [{title, points, max, comment}]',
  `flagged` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'the answer tries to influence the grader',
  `flag_reason` varchar(255) DEFAULT NULL,
  `provider` varchar(30) DEFAULT NULL,
  `model` varchar(150) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_submission` (`submission_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `ai_grade_suggestions_ibfk_1` FOREIGN KEY (`submission_id`) REFERENCES `submissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ai_grade_suggestions_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `ai_providers`;

CREATE TABLE `ai_providers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `owner_id` int(10) unsigned DEFAULT NULL COMMENT 'NULL = school provider, else the user who owns it',
  `provider` varchar(30) NOT NULL COMMENT 'registry key in includes/ai.php - openai, anthropic, groq',
  `api_key` text DEFAULT NULL COMMENT 'encrypted - empty for providers without a key (Ollama)',
  `key_hint` varchar(8) DEFAULT NULL,
  `base_url` varchar(255) DEFAULT NULL COMMENT 'NULL = the provider default',
  `model` varchar(150) DEFAULT NULL COMMENT 'default model for this provider',
  `models_cache` text DEFAULT NULL COMMENT 'JSON list loaded from the provider models endpoint',
  `price_in` decimal(10,4) DEFAULT NULL COMMENT 'USD per 1M input tokens - optional, for cost estimates',
  `price_out` decimal(10,4) DEFAULT NULL COMMENT 'USD per 1M output tokens',
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `share_students` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'teacher lets students of their classes use it',
  `share_limit` int(10) unsigned NOT NULL DEFAULT 20000 COMMENT 'tokens per student per month on this key - 0 = no limit',
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `updated_by` (`updated_by`),
  KEY `idx_owner` (`owner_id`,`provider`),
  CONSTRAINT `ai_providers_ibfk_1` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ai_providers_owner` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `ai_reports`;

CREATE TABLE `ai_reports` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `class_id` int(10) unsigned DEFAULT NULL,
  `feature` varchar(30) NOT NULL COMMENT 'tutor, study, explain_code, other',
  `answer` varchar(600) NOT NULL COMMENT 'the start of the answer that was reported',
  `note` varchar(255) DEFAULT NULL,
  `status` enum('open','reviewed') NOT NULL DEFAULT 'open',
  `reviewed_by` int(10) unsigned DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `class_id` (`class_id`),
  KEY `reviewed_by` (`reviewed_by`),
  KEY `idx_status` (`status`,`created_at`),
  CONSTRAINT `ai_reports_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ai_reports_ibfk_2` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ai_reports_ibfk_3` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `ai_tutor_chats`;

CREATE TABLE `ai_tutor_chats` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `student_id` int(10) unsigned NOT NULL,
  `class_id` int(10) unsigned NOT NULL,
  `lecture_id` int(10) unsigned DEFAULT NULL COMMENT 'the lecture the chat was opened from (optional)',
  `title` varchar(120) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `class_id` (`class_id`),
  KEY `lecture_id` (`lecture_id`),
  KEY `idx_tutor_chat` (`student_id`,`class_id`,`updated_at`),
  CONSTRAINT `ai_tutor_chats_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ai_tutor_chats_ibfk_2` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ai_tutor_chats_ibfk_3` FOREIGN KEY (`lecture_id`) REFERENCES `lectures` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `ai_tutor_messages`;

CREATE TABLE `ai_tutor_messages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `chat_id` int(10) unsigned NOT NULL,
  `role` enum('student','tutor') NOT NULL,
  `body` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_tutor_msg` (`chat_id`,`id`),
  CONSTRAINT `ai_tutor_messages_ibfk_1` FOREIGN KEY (`chat_id`) REFERENCES `ai_tutor_chats` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `ai_usage`;

CREATE TABLE `ai_usage` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `feature` varchar(40) NOT NULL,
  `provider` varchar(30) NOT NULL,
  `key_owner` int(10) unsigned DEFAULT NULL COMMENT 'NULL = school key',
  `class_id` int(10) unsigned DEFAULT NULL,
  `model` varchar(150) DEFAULT NULL,
  `input_tokens` int(10) unsigned NOT NULL DEFAULT 0,
  `output_tokens` int(10) unsigned NOT NULL DEFAULT 0,
  `cost` decimal(12,6) DEFAULT NULL COMMENT 'USD estimate - NULL when no prices are set',
  `status` enum('ok','error') NOT NULL DEFAULT 'ok',
  `error` varchar(500) DEFAULT NULL,
  `duration_ms` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_month` (`user_id`,`created_at`),
  KEY `idx_created` (`created_at`),
  KEY `idx_key_owner` (`key_owner`,`user_id`,`created_at`),
  CONSTRAINT `ai_usage_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `announcement_comments`;

CREATE TABLE `announcement_comments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `announcement_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `body` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `idx_announcement` (`announcement_id`),
  CONSTRAINT `announcement_comments_ibfk_1` FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `announcement_comments_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `announcements`;

CREATE TABLE `announcements` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `class_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL COMMENT 'author - the class teacher',
  `body` text NOT NULL,
  `pinned` tinyint(1) NOT NULL DEFAULT 0,
  `edited_at` datetime DEFAULT NULL COMMENT 'last time the author edited the text',
  `publish_at` datetime DEFAULT NULL COMMENT 'visible to students from this time - NULL = right away',
  `notified_at` datetime DEFAULT NULL COMMENT 'class notified about the post - NULL = not yet',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `idx_class_created` (`class_id`,`created_at`),
  KEY `idx_publish` (`notified_at`,`publish_at`),
  CONSTRAINT `announcements_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `announcements_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `app_settings`;

CREATE TABLE `app_settings` (
  `setting_key` varchar(64) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`),
  KEY `updated_by` (`updated_by`),
  CONSTRAINT `app_settings_ibfk_1` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `assignment_comments`;

CREATE TABLE `assignment_comments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `assignment_id` int(10) unsigned NOT NULL,
  `student_id` int(10) unsigned NOT NULL COMMENT 'whose private thread this is',
  `user_id` int(10) unsigned NOT NULL COMMENT 'author - the student or the teacher',
  `body` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `student_id` (`student_id`),
  KEY `user_id` (`user_id`),
  KEY `idx_thread` (`assignment_id`,`student_id`),
  CONSTRAINT `assignment_comments_ibfk_1` FOREIGN KEY (`assignment_id`) REFERENCES `assignments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `assignment_comments_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `assignment_comments_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `assignment_materials`;

CREATE TABLE `assignment_materials` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `assignment_id` int(10) unsigned NOT NULL,
  `kind` enum('link','resource') NOT NULL,
  `resource_id` int(10) unsigned DEFAULT NULL COMMENT 'kind = resource - a resource of the same class',
  `url` varchar(500) DEFAULT NULL COMMENT 'kind = link - http(s) only',
  `title` varchar(200) DEFAULT NULL,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `resource_id` (`resource_id`),
  KEY `idx_assignment` (`assignment_id`,`position`),
  CONSTRAINT `assignment_materials_ibfk_1` FOREIGN KEY (`assignment_id`) REFERENCES `assignments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `assignment_materials_ibfk_2` FOREIGN KEY (`resource_id`) REFERENCES `resources` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `assignments`;

CREATE TABLE `assignments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `class_id` int(10) unsigned NOT NULL,
  `topic_id` int(10) unsigned DEFAULT NULL,
  `teacher_id` int(10) unsigned NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `due_date` datetime DEFAULT NULL,
  `max_marks` int(10) unsigned DEFAULT 100,
  `rubric` text DEFAULT NULL COMMENT 'JSON list of criteria - points add up to max_marks',
  `release_mode` enum('instant','manual') NOT NULL DEFAULT 'instant',
  `results_released_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `teacher_id` (`teacher_id`),
  KEY `idx_class` (`class_id`),
  KEY `idx_due` (`due_date`),
  KEY `fk_assignments_topic` (`topic_id`),
  CONSTRAINT `assignments_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `assignments_ibfk_2` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_assignments_topic` FOREIGN KEY (`topic_id`) REFERENCES `class_topics` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `attendance`;

CREATE TABLE `attendance` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `class_id` int(10) unsigned NOT NULL,
  `student_id` int(10) unsigned NOT NULL,
  `date` date NOT NULL,
  `session` tinyint(3) unsigned NOT NULL DEFAULT 1 COMMENT 'session (period) of the day, 1-8',
  `status` enum('present','late','absent','official') NOT NULL COMMENT 'official = official present (on duty, counts as present)',
  `note` varchar(255) DEFAULT NULL,
  `excused` tinyint(1) DEFAULT NULL COMMENT '1 excused, 0 unexcused, NULL not stated (late / absent only)',
  `reason` varchar(20) DEFAULT NULL COMMENT 'sick, family, travel, transport, other',
  `late_minutes` smallint(5) unsigned DEFAULT NULL COMMENT 'how many minutes late (status late only)',
  `marked_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_mark` (`class_id`,`student_id`,`date`,`session`),
  KEY `student_id` (`student_id`),
  KEY `marked_by` (`marked_by`),
  KEY `idx_class_date` (`class_id`,`date`),
  CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `attendance_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `attendance_ibfk_3` FOREIGN KEY (`marked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `attendance_checkin`;

CREATE TABLE `attendance_checkin` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `class_id` int(10) unsigned NOT NULL,
  `session` tinyint(3) unsigned NOT NULL DEFAULT 1 COMMENT 'the session the code marks',
  `code` char(6) NOT NULL,
  `opened_by` int(10) unsigned DEFAULT NULL,
  `opened_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  `closed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `opened_by` (`opened_by`),
  KEY `idx_class_open` (`class_id`,`expires_at`),
  CONSTRAINT `attendance_checkin_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `attendance_checkin_ibfk_2` FOREIGN KEY (`opened_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `attendance_checkin_tries`;

CREATE TABLE `attendance_checkin_tries` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `class_id` int(10) unsigned NOT NULL,
  `student_id` int(10) unsigned NOT NULL,
  `tried_at` datetime NOT NULL DEFAULT current_timestamp(),
  `ok` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `student_id` (`student_id`),
  KEY `idx_try` (`class_id`,`student_id`,`tried_at`),
  CONSTRAINT `attendance_checkin_tries_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `attendance_checkin_tries_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `attendance_days`;

CREATE TABLE `attendance_days` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `class_id` int(10) unsigned NOT NULL,
  `date` date NOT NULL,
  `label` varchar(100) NOT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_noclass` (`class_id`,`date`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `attendance_days_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `attendance_days_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci COMMENT='days with no class (holiday, exam week …): nobody is marked';

DROP TABLE IF EXISTS `attendance_excuses`;

CREATE TABLE `attendance_excuses` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `class_id` int(10) unsigned NOT NULL,
  `student_id` int(10) unsigned NOT NULL,
  `date` date NOT NULL,
  `message` varchar(255) NOT NULL,
  `status` enum('pending','accepted','declined') NOT NULL DEFAULT 'pending',
  `decided_by` int(10) unsigned DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_excuse` (`class_id`,`student_id`,`date`),
  KEY `student_id` (`student_id`),
  KEY `decided_by` (`decided_by`),
  CONSTRAINT `attendance_excuses_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `attendance_excuses_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `attendance_excuses_ibfk_3` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `attendance_log`;

CREATE TABLE `attendance_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `class_id` int(10) unsigned NOT NULL,
  `student_id` int(10) unsigned NOT NULL,
  `date` date NOT NULL,
  `session` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `old_status` varchar(20) DEFAULT NULL,
  `new_status` varchar(20) DEFAULT NULL COMMENT 'NULL = the day was cleared',
  `old_note` varchar(255) DEFAULT NULL,
  `new_note` varchar(255) DEFAULT NULL,
  `changed_by` int(10) unsigned DEFAULT NULL,
  `changed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `student_id` (`student_id`),
  KEY `changed_by` (`changed_by`),
  KEY `idx_log_day` (`class_id`,`date`,`changed_at`),
  CONSTRAINT `attendance_log_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `attendance_log_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `attendance_log_ibfk_3` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `class_events`;

CREATE TABLE `class_events` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `class_id` int(10) unsigned NOT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `location` varchar(200) DEFAULT NULL,
  `starts_at` datetime NOT NULL,
  `ends_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  KEY `idx_class_start` (`class_id`,`starts_at`),
  CONSTRAINT `class_events_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `class_events_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `class_grading_scales`;

CREATE TABLE `class_grading_scales` (
  `class_id` int(10) unsigned NOT NULL,
  `scale` text NOT NULL COMMENT 'JSON: {show_gpa, basis, bands[{label,min,gpa,tone}]}',
  `updated_by` int(10) unsigned DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`class_id`),
  KEY `updated_by` (`updated_by`),
  CONSTRAINT `class_grading_scales_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `class_grading_scales_ibfk_2` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `class_join_requests`;

CREATE TABLE `class_join_requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `class_id` int(10) unsigned NOT NULL,
  `student_id` int(10) unsigned NOT NULL,
  `status` enum('pending','approved','declined') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `decided_by` int(10) unsigned DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_request` (`class_id`,`student_id`),
  KEY `student_id` (`student_id`),
  KEY `decided_by` (`decided_by`),
  KEY `idx_class_status` (`class_id`,`status`),
  CONSTRAINT `class_join_requests_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `class_join_requests_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `class_join_requests_ibfk_3` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `class_marking_scheme`;

CREATE TABLE `class_marking_scheme` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `class_id` int(10) unsigned NOT NULL,
  `component` enum('mid','final','activity','quiz','assignment') NOT NULL,
  `weight` decimal(6,2) NOT NULL DEFAULT 0.00 COMMENT 'marks this part is worth in the final total (0 = not used)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_class_component` (`class_id`,`component`),
  CONSTRAINT `class_marking_scheme_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `class_members`;

CREATE TABLE `class_members` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `class_id` int(10) unsigned NOT NULL,
  `student_id` int(10) unsigned NOT NULL,
  `joined_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_member` (`class_id`,`student_id`),
  KEY `idx_class` (`class_id`),
  KEY `idx_student` (`student_id`),
  CONSTRAINT `class_members_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `class_members_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `class_schedule`;

CREATE TABLE `class_schedule` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `class_id` int(10) unsigned NOT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `day_of_week` tinyint(3) unsigned NOT NULL COMMENT '1 = Monday ... 7 = Sunday',
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `room` varchar(100) DEFAULT NULL,
  `note` varchar(200) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  KEY `idx_class_day` (`class_id`,`day_of_week`,`start_time`),
  CONSTRAINT `class_schedule_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `class_schedule_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `class_teachers`;

CREATE TABLE `class_teachers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `class_id` int(10) unsigned NOT NULL,
  `teacher_id` int(10) unsigned NOT NULL,
  `role` enum('owner','co_teacher') NOT NULL DEFAULT 'co_teacher',
  `added_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_class_teacher` (`class_id`,`teacher_id`),
  KEY `added_by` (`added_by`),
  KEY `idx_teacher` (`teacher_id`),
  CONSTRAINT `class_teachers_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `class_teachers_ibfk_2` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `class_teachers_ibfk_3` FOREIGN KEY (`added_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `class_topics`;

CREATE TABLE `class_topics` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `class_id` int(10) unsigned NOT NULL,
  `name` varchar(100) NOT NULL,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_class` (`class_id`),
  CONSTRAINT `class_topics_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `classes`;

CREATE TABLE `classes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `teacher_id` int(10) unsigned NOT NULL,
  `class_name` varchar(100) NOT NULL,
  `class_code` varchar(20) NOT NULL,
  `description` text DEFAULT NULL,
  `tags` varchar(255) DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `stream_permission` enum('post','comment','teacher_only') NOT NULL DEFAULT 'comment' COMMENT 'post = students post + comment, comment = students comment only, teacher_only = read only',
  `require_approval` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = students who use the class code wait for teacher approval',
  `theme` varchar(20) NOT NULL DEFAULT 'auto',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `year_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `ai_students` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'students may use AI helpers in this class',
  `ai_student_writing` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'students see the AI writing help (language check, hints) in this class',
  `ai_language` enum('screen','en','question') NOT NULL DEFAULT 'screen',
  PRIMARY KEY (`id`),
  UNIQUE KEY `class_code` (`class_code`),
  KEY `idx_teacher` (`teacher_id`),
  KEY `idx_code` (`class_code`),
  KEY `idx_classes_year` (`year_id`),
  CONSTRAINT `classes_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `content_progress`;

CREATE TABLE `content_progress` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `class_id` int(10) unsigned NOT NULL,
  `student_id` int(10) unsigned NOT NULL,
  `item_type` enum('lecture','resource') NOT NULL,
  `item_id` int(10) unsigned NOT NULL COMMENT 'lectures.id or resources.id',
  `first_opened_at` datetime DEFAULT NULL,
  `last_opened_at` datetime DEFAULT NULL,
  `open_count` int(10) unsigned NOT NULL DEFAULT 0,
  `done_at` datetime DEFAULT NULL COMMENT 'marked done by the student',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_progress` (`student_id`,`item_type`,`item_id`),
  KEY `idx_item` (`class_id`,`item_type`,`item_id`),
  CONSTRAINT `content_progress_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `content_progress_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `customer_events`;

CREATE TABLE `customer_events` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` int(10) unsigned DEFAULT NULL,
  `customer_name` varchar(120) DEFAULT NULL,
  `event` varchar(60) NOT NULL,
  `details` varchar(500) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_customer` (`customer_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `customers`;

CREATE TABLE `customers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `domain` varchar(190) NOT NULL,
  `slug` varchar(60) NOT NULL,
  `db_name` varchar(64) NOT NULL,
  `db_user` varchar(64) NOT NULL,
  `contact` varchar(190) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `plan` varchar(60) DEFAULT NULL,
  `price` varchar(40) DEFAULT NULL,
  `paid_until` date DEFAULT NULL,
  `billing_note` varchar(500) DEFAULT NULL,
  `status` enum('active','suspended') NOT NULL DEFAULT 'active',
  `suspended_reason` varchar(255) DEFAULT NULL,
  `suspended_at` datetime DEFAULT NULL,
  `services_off` varchar(200) NOT NULL DEFAULT '' COMMENT 'comma list: ai, email, uploads, signups',
  `max_teachers` int(10) unsigned DEFAULT NULL,
  `max_students` int(10) unsigned DEFAULT NULL,
  `max_classes` int(10) unsigned DEFAULT NULL,
  `support_secret` char(64) NOT NULL,
  `terms_accepted_at` datetime DEFAULT NULL,
  `last_seen_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_domain` (`domain`),
  UNIQUE KEY `unique_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `email_preferences`;

CREATE TABLE `email_preferences` (
  `user_id` int(10) unsigned NOT NULL,
  `mode` enum('instant','daily','off') NOT NULL DEFAULT 'instant',
  `classwork` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'new lectures, resources, assignments, quizzes, announcements',
  `grades` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'graded work, quiz results, submissions received',
  `attendance` tinyint(1) NOT NULL DEFAULT 1,
  `comments` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'class + private comments, posts',
  `approvals` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'registrations, join requests, approvals',
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`),
  CONSTRAINT `email_preferences_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `email_queue`;

CREATE TABLE `email_queue` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `to_email` varchar(100) NOT NULL,
  `to_name` varchar(100) DEFAULT NULL,
  `category` varchar(20) NOT NULL DEFAULT 'general',
  `subject` varchar(255) NOT NULL,
  `body_html` mediumtext NOT NULL,
  `body_text` mediumtext NOT NULL,
  `status` enum('pending','held','sent','failed','digested') NOT NULL DEFAULT 'pending' COMMENT 'held = waiting for the daily digest',
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `last_error` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `sent_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`,`created_at`),
  KEY `idx_user` (`user_id`),
  CONSTRAINT `email_queue_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `exam_marks`;

CREATE TABLE `exam_marks` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `exam_id` int(10) unsigned NOT NULL,
  `student_id` int(10) unsigned NOT NULL,
  `marks` decimal(6,2) DEFAULT NULL,
  `absent` tinyint(1) NOT NULL DEFAULT 0,
  `remarks` varchar(300) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_exam_student` (`exam_id`,`student_id`),
  KEY `student_id` (`student_id`),
  CONSTRAINT `exam_marks_ibfk_1` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `exam_marks_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `exams`;

CREATE TABLE `exams` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `class_id` int(10) unsigned NOT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `title` varchar(200) NOT NULL,
  `kind` enum('mid','final','activity','other') NOT NULL DEFAULT 'other' COMMENT 'which part of the final marks it counts in',
  `exam_date` datetime NOT NULL,
  `duration_min` smallint(5) unsigned DEFAULT NULL,
  `venue` varchar(200) DEFAULT NULL,
  `max_marks` smallint(5) unsigned NOT NULL DEFAULT 100,
  `instructions` text DEFAULT NULL,
  `results_published_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  KEY `idx_class_date` (`class_id`,`exam_date`),
  CONSTRAINT `exams_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `exams_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `forum_attachments`;

CREATE TABLE `forum_attachments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `thread_id` int(10) unsigned NOT NULL,
  `post_id` int(10) unsigned DEFAULT NULL COMMENT 'NULL = attached to the topic itself',
  `user_id` int(10) unsigned NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `file_type` varchar(10) NOT NULL,
  `file_size` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `post_id` (`post_id`),
  KEY `user_id` (`user_id`),
  KEY `idx_forum_att` (`thread_id`,`post_id`),
  CONSTRAINT `forum_attachments_ibfk_1` FOREIGN KEY (`thread_id`) REFERENCES `forum_threads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `forum_attachments_ibfk_2` FOREIGN KEY (`post_id`) REFERENCES `forum_posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `forum_attachments_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `forum_posts`;

CREATE TABLE `forum_posts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `thread_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `body` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `idx_thread` (`thread_id`,`created_at`),
  CONSTRAINT `forum_posts_ibfk_1` FOREIGN KEY (`thread_id`) REFERENCES `forum_threads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `forum_posts_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `forum_threads`;

CREATE TABLE `forum_threads` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `class_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `title` varchar(200) NOT NULL,
  `body` text NOT NULL,
  `pinned` tinyint(1) NOT NULL DEFAULT 0,
  `locked` tinyint(1) NOT NULL DEFAULT 0,
  `last_post_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `idx_class_activity` (`class_id`,`pinned`,`last_post_at`),
  CONSTRAINT `forum_threads_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `forum_threads_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `lecture_explanations`;

CREATE TABLE `lecture_explanations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `lecture_id` int(10) unsigned NOT NULL,
  `code_hash` char(64) NOT NULL COMMENT 'sha256 of the code block - edited code gets a new explanation',
  `explanation` mediumtext NOT NULL,
  `provider` varchar(30) DEFAULT NULL,
  `model` varchar(150) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL COMMENT 'whose click produced it',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_block` (`lecture_id`,`code_hash`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `lecture_explanations_ibfk_1` FOREIGN KEY (`lecture_id`) REFERENCES `lectures` (`id`) ON DELETE CASCADE,
  CONSTRAINT `lecture_explanations_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `lecture_images`;

CREATE TABLE `lecture_images` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `class_id` int(10) unsigned NOT NULL,
  `uploaded_by` int(10) unsigned DEFAULT NULL,
  `file_path` varchar(255) NOT NULL COMMENT 'uploads/lectures/<random>.<ext>',
  `mime` varchar(30) NOT NULL,
  `width` int(10) unsigned NOT NULL,
  `height` int(10) unsigned NOT NULL,
  `file_size` int(10) unsigned NOT NULL,
  `original_name` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `uploaded_by` (`uploaded_by`),
  KEY `idx_class` (`class_id`,`created_at`),
  CONSTRAINT `lecture_images_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `lecture_images_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `lecture_materials`;

CREATE TABLE `lecture_materials` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `lecture_id` int(10) unsigned NOT NULL,
  `kind` enum('link','resource') NOT NULL,
  `resource_id` int(10) unsigned DEFAULT NULL COMMENT 'kind = resource - a resource of the same class',
  `url` varchar(500) DEFAULT NULL COMMENT 'kind = link - http(s) only (YouTube links are embedded)',
  `title` varchar(200) DEFAULT NULL,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `resource_id` (`resource_id`),
  KEY `idx_lecture` (`lecture_id`,`position`),
  CONSTRAINT `lecture_materials_ibfk_1` FOREIGN KEY (`lecture_id`) REFERENCES `lectures` (`id`) ON DELETE CASCADE,
  CONSTRAINT `lecture_materials_ibfk_2` FOREIGN KEY (`resource_id`) REFERENCES `resources` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `lecture_study`;

CREATE TABLE `lecture_study` (
  `lecture_id` int(10) unsigned NOT NULL,
  `kind` enum('summary','terms') NOT NULL,
  `content_hash` char(64) NOT NULL COMMENT 'sha256 of the lecture text it was written from',
  `content` text NOT NULL,
  `provider` varchar(30) DEFAULT NULL,
  `model` varchar(150) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`lecture_id`,`kind`),
  CONSTRAINT `lecture_study_ibfk_1` FOREIGN KEY (`lecture_id`) REFERENCES `lectures` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `lectures`;

CREATE TABLE `lectures` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `class_id` int(10) unsigned NOT NULL,
  `topic_id` int(10) unsigned DEFAULT NULL,
  `teacher_id` int(10) unsigned NOT NULL,
  `title` varchar(200) NOT NULL,
  `summary` varchar(300) DEFAULT NULL COMMENT 'one line shown in lists',
  `content` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `video_url` varchar(500) DEFAULT NULL,
  `status` enum('draft','published') NOT NULL DEFAULT 'published',
  `publish_at` datetime DEFAULT NULL COMMENT 'published lecture shown from this time - NULL = now',
  `notified_at` datetime DEFAULT NULL COMMENT 'students notified - NULL = not yet',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `teacher_id` (`teacher_id`),
  KEY `idx_class` (`class_id`),
  KEY `fk_lectures_topic` (`topic_id`),
  KEY `idx_publish` (`status`,`notified_at`,`publish_at`),
  CONSTRAINT `fk_lectures_topic` FOREIGN KEY (`topic_id`) REFERENCES `class_topics` (`id`) ON DELETE SET NULL,
  CONSTRAINT `lectures_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `lectures_ibfk_2` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `login_codes`;

CREATE TABLE `login_codes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `code_hash` char(64) NOT NULL COMMENT 'HMAC-SHA256 of the code',
  `expires_at` datetime NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `used_at` datetime DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_created` (`user_id`,`created_at`),
  CONSTRAINT `login_codes_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `notifications`;

CREATE TABLE `notifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL COMMENT 'recipient',
  `sender_id` int(10) unsigned DEFAULT NULL,
  `class_id` int(10) unsigned DEFAULT NULL,
  `type` varchar(50) NOT NULL,
  `title` varchar(200) NOT NULL,
  `message` text DEFAULT NULL,
  `link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `sender_id` (`sender_id`),
  KEY `class_id` (`class_id`),
  KEY `idx_user_unread` (`user_id`,`is_read`),
  KEY `idx_created` (`created_at`),
  KEY `idx_user_created` (`user_id`,`created_at`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `notifications_ibfk_3` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `password_resets`;

CREATE TABLE `password_resets` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_token` (`token_hash`),
  KEY `idx_user` (`user_id`,`created_at`),
  CONSTRAINT `password_resets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `private_messages`;

CREATE TABLE `private_messages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `class_id` int(10) unsigned NOT NULL,
  `student_id` int(10) unsigned NOT NULL COMMENT 'whose conversation this is',
  `user_id` int(10) unsigned NOT NULL COMMENT 'author - the student or the teacher',
  `context_type` enum('class','assignment','quiz','lecture') NOT NULL DEFAULT 'class',
  `context_id` int(10) unsigned DEFAULT NULL COMMENT 'assignment / quiz id - NULL for class',
  `body` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'read by the recipient',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `student_id` (`student_id`),
  KEY `user_id` (`user_id`),
  KEY `idx_conversation` (`class_id`,`student_id`,`context_type`,`context_id`),
  KEY `idx_unread` (`class_id`,`is_read`),
  CONSTRAINT `private_messages_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `private_messages_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `private_messages_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `push_subscriptions`;

CREATE TABLE `push_subscriptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `endpoint_hash` char(64) NOT NULL,
  `endpoint` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_ok_at` timestamp NULL DEFAULT NULL,
  `fail_count` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_push_endpoint` (`endpoint_hash`),
  KEY `idx_push_user` (`user_id`),
  CONSTRAINT `fk_push_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `question_bank`;

CREATE TABLE `question_bank` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `teacher_id` int(10) unsigned NOT NULL,
  `tag` varchar(60) DEFAULT NULL COMMENT 'a subject / unit name chosen by the teacher',
  `question_type` enum('choice','short') NOT NULL DEFAULT 'choice',
  `question` text NOT NULL,
  `model_answer` text DEFAULT NULL,
  `marks` int(10) unsigned NOT NULL DEFAULT 1,
  `options` text DEFAULT NULL COMMENT 'JSON: [{"t": "text", "c": 1 for the correct one}] (choice questions)',
  `question_hash` char(40) NOT NULL COMMENT 'sha1 of the question text + options — the same question is kept once per teacher',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_teacher_question` (`teacher_id`,`question_hash`),
  KEY `idx_teacher_tag` (`teacher_id`,`tag`),
  CONSTRAINT `question_bank_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `quiz_answers`;

CREATE TABLE `quiz_answers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `attempt_id` int(10) unsigned NOT NULL,
  `question_id` int(10) unsigned NOT NULL,
  `option_id` int(10) unsigned DEFAULT NULL,
  `answer_text` text DEFAULT NULL,
  `is_correct` tinyint(1) NOT NULL DEFAULT 0,
  `marks_awarded` decimal(6,2) DEFAULT NULL,
  `feedback` text DEFAULT NULL,
  `mark_source` enum('auto','ai','teacher') DEFAULT NULL,
  `marked_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_answer` (`attempt_id`,`question_id`),
  KEY `question_id` (`question_id`),
  KEY `option_id` (`option_id`),
  CONSTRAINT `quiz_answers_ibfk_1` FOREIGN KEY (`attempt_id`) REFERENCES `quiz_attempts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `quiz_answers_ibfk_2` FOREIGN KEY (`question_id`) REFERENCES `quiz_questions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `quiz_answers_ibfk_3` FOREIGN KEY (`option_id`) REFERENCES `quiz_options` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `quiz_attempts`;

CREATE TABLE `quiz_attempts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `quiz_id` int(10) unsigned NOT NULL,
  `student_id` int(10) unsigned NOT NULL,
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `submitted_at` datetime DEFAULT NULL,
  `score` decimal(7,2) DEFAULT NULL,
  `max_score` int(10) unsigned NOT NULL DEFAULT 0,
  `timed_out` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = submitted after the time limit; answers not counted',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_attempt` (`quiz_id`,`student_id`),
  KEY `idx_student` (`student_id`),
  CONSTRAINT `quiz_attempts_ibfk_1` FOREIGN KEY (`quiz_id`) REFERENCES `quizzes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `quiz_attempts_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `quiz_explanations`;

CREATE TABLE `quiz_explanations` (
  `attempt_id` int(10) unsigned NOT NULL,
  `content` text NOT NULL COMMENT 'JSON {question_id: explanation}',
  `provider` varchar(30) DEFAULT NULL,
  `model` varchar(150) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`attempt_id`),
  CONSTRAINT `quiz_explanations_ibfk_1` FOREIGN KEY (`attempt_id`) REFERENCES `quiz_attempts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `quiz_insights`;

CREATE TABLE `quiz_insights` (
  `quiz_id` int(10) unsigned NOT NULL,
  `attempts` int(10) unsigned NOT NULL COMMENT 'submitted attempts when it was written',
  `content` text NOT NULL COMMENT 'JSON {summary, weak_points[], suggestions[]}',
  `provider` varchar(30) DEFAULT NULL,
  `model` varchar(150) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`quiz_id`),
  CONSTRAINT `quiz_insights_ibfk_1` FOREIGN KEY (`quiz_id`) REFERENCES `quizzes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `quiz_options`;

CREATE TABLE `quiz_options` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `question_id` int(10) unsigned NOT NULL,
  `option_text` varchar(500) NOT NULL,
  `is_correct` tinyint(1) NOT NULL DEFAULT 0,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_question` (`question_id`),
  CONSTRAINT `quiz_options_ibfk_1` FOREIGN KEY (`question_id`) REFERENCES `quiz_questions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `quiz_questions`;

CREATE TABLE `quiz_questions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `quiz_id` int(10) unsigned NOT NULL,
  `question` text NOT NULL,
  `question_type` enum('choice','short') NOT NULL DEFAULT 'choice',
  `model_answer` text DEFAULT NULL,
  `marks` int(10) unsigned NOT NULL DEFAULT 1,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_quiz` (`quiz_id`),
  CONSTRAINT `quiz_questions_ibfk_1` FOREIGN KEY (`quiz_id`) REFERENCES `quizzes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `quiz_reopens`;

CREATE TABLE `quiz_reopens` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `quiz_id` int(10) unsigned NOT NULL,
  `student_id` int(10) unsigned NOT NULL,
  `reopened_by` int(10) unsigned DEFAULT NULL,
  `reopened_at` datetime NOT NULL DEFAULT current_timestamp(),
  `until` datetime DEFAULT NULL COMMENT 'the student may start the quiz again until then (NULL = no deadline)',
  `prev_state` varchar(20) NOT NULL DEFAULT 'none' COMMENT 'none | open | done | timeout — what the student had before',
  `prev_score` decimal(7,2) DEFAULT NULL,
  `prev_max` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `student_id` (`student_id`),
  KEY `reopened_by` (`reopened_by`),
  KEY `idx_quiz_student` (`quiz_id`,`student_id`,`reopened_at`),
  CONSTRAINT `quiz_reopens_ibfk_1` FOREIGN KEY (`quiz_id`) REFERENCES `quizzes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `quiz_reopens_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `quiz_reopens_ibfk_3` FOREIGN KEY (`reopened_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `quizzes`;

CREATE TABLE `quizzes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `class_id` int(10) unsigned NOT NULL,
  `topic_id` int(10) unsigned DEFAULT NULL,
  `teacher_id` int(10) unsigned NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `time_limit` int(10) unsigned DEFAULT NULL COMMENT 'minutes; NULL = no limit',
  `due_date` datetime DEFAULT NULL COMMENT 'no new attempts after this; NULL = open',
  `release_mode` enum('instant','manual') NOT NULL DEFAULT 'instant',
  `results_released_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `teacher_id` (`teacher_id`),
  KEY `idx_class` (`class_id`),
  KEY `fk_quizzes_topic` (`topic_id`),
  CONSTRAINT `fk_quizzes_topic` FOREIGN KEY (`topic_id`) REFERENCES `class_topics` (`id`) ON DELETE SET NULL,
  CONSTRAINT `quizzes_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `quizzes_ibfk_2` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `rate_limits`;

CREATE TABLE `rate_limits` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `bucket` varchar(40) NOT NULL COMMENT 'what is limited: login_account, login_ip, ai_user ...',
  `rkey` char(64) NOT NULL COMMENT 'sha256 of the thing counted (e-mail, IP, user id)',
  `hits` int(10) unsigned NOT NULL DEFAULT 0,
  `window_start` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_limit` (`bucket`,`rkey`),
  KEY `idx_window` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `research_items`;

CREATE TABLE `research_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `class_id` int(10) unsigned NOT NULL,
  `added_by` int(10) unsigned DEFAULT NULL,
  `kind` enum('paper','article','book','dataset','project','other') NOT NULL DEFAULT 'paper',
  `title` varchar(250) NOT NULL,
  `authors` varchar(250) DEFAULT NULL,
  `year` smallint(5) unsigned DEFAULT NULL,
  `url` varchar(500) DEFAULT NULL,
  `summary` text DEFAULT NULL,
  `tags` varchar(200) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `added_by` (`added_by`),
  KEY `idx_class_kind` (`class_id`,`kind`),
  CONSTRAINT `research_items_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `research_items_ibfk_2` FOREIGN KEY (`added_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `resources`;

CREATE TABLE `resources` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `class_id` int(10) unsigned NOT NULL,
  `topic_id` int(10) unsigned DEFAULT NULL,
  `uploaded_by` int(10) unsigned NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `category` varchar(30) NOT NULL DEFAULT 'other',
  `file_path` varchar(255) DEFAULT NULL,
  `original_name` varchar(255) DEFAULT NULL,
  `file_type` varchar(50) DEFAULT NULL,
  `file_size` int(10) unsigned DEFAULT 0,
  `download_count` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `uploaded_by` (`uploaded_by`),
  KEY `idx_class` (`class_id`),
  KEY `fk_resources_topic` (`topic_id`),
  CONSTRAINT `fk_resources_topic` FOREIGN KEY (`topic_id`) REFERENCES `class_topics` (`id`) ON DELETE SET NULL,
  CONSTRAINT `resources_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `resources_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `safeguarding_flags`;

CREATE TABLE `safeguarding_flags` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `student_id` int(10) unsigned NOT NULL,
  `class_id` int(10) unsigned NOT NULL,
  `chat_id` int(10) unsigned DEFAULT NULL,
  `category` varchar(20) NOT NULL COMMENT 'self_harm, abuse, violence, bullying',
  `snippet` varchar(300) NOT NULL COMMENT 'the start of the message, so the teacher knows what to look for',
  `status` enum('open','handled') NOT NULL DEFAULT 'open',
  `handled_by` int(10) unsigned DEFAULT NULL,
  `handled_at` datetime DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `student_id` (`student_id`),
  KEY `chat_id` (`chat_id`),
  KEY `handled_by` (`handled_by`),
  KEY `idx_class_status` (`class_id`,`status`,`created_at`),
  CONSTRAINT `safeguarding_flags_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `safeguarding_flags_ibfk_2` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `safeguarding_flags_ibfk_3` FOREIGN KEY (`chat_id`) REFERENCES `ai_tutor_chats` (`id`) ON DELETE SET NULL,
  CONSTRAINT `safeguarding_flags_ibfk_4` FOREIGN KEY (`handled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `schema_migrations`;

CREATE TABLE `schema_migrations` (
  `name` varchar(100) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `submissions`;

CREATE TABLE `submissions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `assignment_id` int(10) unsigned NOT NULL,
  `student_id` int(10) unsigned NOT NULL,
  `content` text DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `original_name` varchar(255) DEFAULT NULL,
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `marks_obtained` decimal(6,2) DEFAULT NULL,
  `feedback` text DEFAULT NULL,
  `graded_by` int(10) unsigned DEFAULT NULL,
  `graded_at` datetime DEFAULT NULL,
  `grade_source` enum('teacher','ai_accepted','ai_edited') DEFAULT NULL,
  `returned_at` datetime DEFAULT NULL,
  `return_note` text DEFAULT NULL,
  `return_source` enum('teacher','ai_accepted','ai_edited') DEFAULT NULL,
  `revision` int(10) unsigned NOT NULL DEFAULT 1,
  `status` enum('submitted','graded','late','returned') NOT NULL DEFAULT 'submitted',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_submission` (`assignment_id`,`student_id`),
  KEY `graded_by` (`graded_by`),
  KEY `idx_assignment` (`assignment_id`),
  KEY `idx_student` (`student_id`),
  CONSTRAINT `submissions_ibfk_1` FOREIGN KEY (`assignment_id`) REFERENCES `assignments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `submissions_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `submissions_ibfk_3` FOREIGN KEY (`graded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `teacher_invites`;

CREATE TABLE `teacher_invites` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `token_hash` char(64) NOT NULL COMMENT 'sha256 of the token in the invite link',
  `invited_by` int(10) unsigned DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `accepted_at` datetime DEFAULT NULL,
  `accepted_user_id` int(10) unsigned DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_token` (`token_hash`),
  KEY `invited_by` (`invited_by`),
  KEY `accepted_user_id` (`accepted_user_id`),
  KEY `idx_email` (`email`),
  CONSTRAINT `teacher_invites_ibfk_1` FOREIGN KEY (`invited_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `teacher_invites_ibfk_2` FOREIGN KEY (`accepted_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `user_backup_codes`;

CREATE TABLE `user_backup_codes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `code_hash` char(64) NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_backup_user` (`user_id`,`used_at`),
  CONSTRAINT `user_backup_codes_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `users`;

CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `email_verified_at` datetime DEFAULT NULL COMMENT 'proved by signing in with an emailed code',
  `password` varchar(255) NOT NULL,
  `role` enum('teacher','student') NOT NULL DEFAULT 'student',
  `is_admin` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'school super admin (a teacher)',
  `photo` varchar(255) DEFAULT NULL,
  `lang` char(2) NOT NULL DEFAULT '' COMMENT 'interface language: en, ps (Pashto), fa (Dari); empty = school default',
  `email_token` varchar(64) DEFAULT NULL COMMENT 'secret for one-click unsubscribe links',
  `is_approved` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0=pending, 1=approved',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `totp_secret` varchar(255) DEFAULT NULL COMMENT 'encrypted (settings.php encryptSecret)',
  `totp_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `totp_last_step` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'last accepted 30-second step — a code works once',
  `guardian_name` varchar(100) DEFAULT NULL,
  `guardian_email` varchar(100) DEFAULT NULL,
  `guardian_phone` varchar(40) DEFAULT NULL,
  `guardian_alerts` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0 = the guardian asked to stop (or the student turned it off)',
  `whatsapp_number` varchar(20) DEFAULT NULL,
  `whatsapp_consent_at` datetime DEFAULT NULL,
  `whatsapp_relation` varchar(12) DEFAULT NULL,
  `whatsapp_todo` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_email` (`email`),
  KEY `idx_role` (`role`),
  KEY `idx_approved` (`is_approved`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `research_projects`;

CREATE TABLE research_projects (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id       INT UNSIGNED NOT NULL,
    student_id     INT UNSIGNED NOT NULL,
    supervisor_id  INT UNSIGNED NULL COMMENT 'the teacher who approved it',
    title          VARCHAR(200) NOT NULL,
    question       VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'the research question',
    plan           TEXT NULL COMMENT 'what the student will do and how',
    status         ENUM('draft','proposed','changes','active','completed','closed') NOT NULL DEFAULT 'draft',
    grade          DECIMAL(5,2) NULL,
    final_feedback TEXT NULL,
    submitted_at   DATETIME NULL,
    approved_at    DATETIME NULL,
    completed_at   DATETIME NULL,
    stale_notified_at DATETIME NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_rp_class (class_id, status),
    KEY idx_rp_student (student_id),
    CONSTRAINT fk_rp_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    CONSTRAINT fk_rp_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_rp_supervisor FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `research_milestones`;

CREATE TABLE research_milestones (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT UNSIGNED NOT NULL,
    title      VARCHAR(150) NOT NULL,
    due_date   DATE NULL,
    status     ENUM('open','submitted','accepted','changes') NOT NULL DEFAULT 'open',
    position   INT NOT NULL DEFAULT 0,
    remind_soon_at DATETIME NULL,
    remind_late_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_rm_project (project_id, position),
    CONSTRAINT fk_rm_project FOREIGN KEY (project_id) REFERENCES research_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `research_updates`;

CREATE TABLE research_updates (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id   INT UNSIGNED NOT NULL,
    milestone_id INT UNSIGNED NULL,
    author_id    INT UNSIGNED NULL,
    kind         ENUM('comment','submission','decision','system') NOT NULL DEFAULT 'comment',
    body         TEXT NULL,
    file_path    VARCHAR(255) NULL,
    file_name    VARCHAR(255) NULL,
    file_type    VARCHAR(10) NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ru_project (project_id, id),
    CONSTRAINT fk_ru_project FOREIGN KEY (project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_ru_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `research_templates`;

CREATE TABLE research_templates (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT UNSIGNED NOT NULL,
    name       VARCHAR(100) NOT NULL,
    items      TEXT NOT NULL COMMENT 'JSON list of {title, days} (days = how many days after adding the milestone is due; 0 = no date)',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_rt_teacher (teacher_id),
    CONSTRAINT fk_rt_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `research_sources`;

CREATE TABLE research_sources (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT UNSIGNED NOT NULL,
    item_id    INT UNSIGNED NULL COMMENT 'a Library item, or NULL for a source typed in',
    title      VARCHAR(250) NOT NULL,
    authors    VARCHAR(250) NULL,
    year       SMALLINT NULL,
    url        VARCHAR(500) NULL,
    note       VARCHAR(300) NULL,
    added_by   INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_rs_project (project_id),
    CONSTRAINT fk_rs_project FOREIGN KEY (project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_rs_item FOREIGN KEY (item_id) REFERENCES research_items(id) ON DELETE SET NULL,
    CONSTRAINT fk_rs_user FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `whatsapp_senders`;

CREATE TABLE whatsapp_senders (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_id        INT UNSIGNED NULL COMMENT 'the teacher whose own sender this is; NULL = the school''s sender',
    label           VARCHAR(100) NOT NULL DEFAULT '',
    phone_number_id VARCHAR(40) NOT NULL DEFAULT '',
    business_id     VARCHAR(40) NOT NULL DEFAULT '',
    token           TEXT NULL COMMENT 'encrypted access token',
    is_enabled      TINYINT(1) NOT NULL DEFAULT 0,
    daily_limit     INT NOT NULL DEFAULT 0,
    kinds           TEXT NULL COMMENT 'JSON list of the message kinds this sender sends automatically',
    last_test_at    DATETIME NULL,
    last_test_ok    TINYINT(1) NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_wa_owner (owner_id),
    CONSTRAINT fk_wa_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `whatsapp_texts`;

CREATE TABLE whatsapp_texts (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_id      INT UNSIGNED NULL COMMENT 'NULL = the school''s wording; a teacher''s own wording wins for their sender',
    kind          VARCHAR(30) NOT NULL,
    lang          CHAR(2) NOT NULL,
    body          TEXT NOT NULL,
    template_name VARCHAR(100) NULL COMMENT 'the name of the approved Meta template for automatic sending',
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_wa_text (owner_id, kind, lang),
    CONSTRAINT fk_wat_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `whatsapp_queue`;

CREATE TABLE whatsapp_queue (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sender_id     INT UNSIGNED NOT NULL,
    recipient_id  INT UNSIGNED NOT NULL,
    kind          VARCHAR(30) NOT NULL,
    ref           VARCHAR(60) NOT NULL DEFAULT '' COMMENT 'what it is about (e.g. the attendance date) — one message per kind, person and ref',
    lang          CHAR(2) NOT NULL DEFAULT 'en',
    params        TEXT NULL,
    body          TEXT NULL COMMENT 'the finished text, for the log',
    status        ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
    error         VARCHAR(300) NULL,
    attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    send_after    DATETIME NOT NULL DEFAULT current_timestamp(),
    sent_at       DATETIME NULL,
    wa_message_id VARCHAR(100) NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_wa_once (kind, recipient_id, ref),
    KEY idx_wa_due (status, send_after),
    KEY idx_wa_sender (sender_id, sent_at),
    CONSTRAINT fk_waq_sender FOREIGN KEY (sender_id) REFERENCES whatsapp_senders(id) ON DELETE CASCADE,
    CONSTRAINT fk_waq_recipient FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE suggestion_dismissals (
  user_id INT UNSIGNED NOT NULL,
  suggestion_key VARCHAR(120) NOT NULL,
  dismissed_until DATETIME NOT NULL,
  PRIMARY KEY (user_id, suggestion_key),
  CONSTRAINT fk_sugg_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
