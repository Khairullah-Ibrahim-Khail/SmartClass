-- SmartClass migration 018 — personal AI providers + class AI switch
-- ai_providers.owner_id: NULL = the school's provider (super admin, AI Settings);
--   a user id = that teacher's / student's own provider (Settings › My AI, one per person).
-- share_students / share_limit: a teacher may let students of their classes use their
--   provider, with a monthly token limit per student (0 = no limit).
-- ai_usage.key_owner: whose key paid (NULL = school); class_id: the class it was used in.
-- classes.ai_students: students may use AI helpers in this class (teacher's switch, off by default).
-- Run ONCE.
--
--   mysql -u USER -p smartclass_db < config/migrations/018_personal_ai.sql

ALTER TABLE ai_providers
    ADD COLUMN owner_id INT UNSIGNED DEFAULT NULL COMMENT 'NULL = school provider, else the user who owns it' AFTER id,
    ADD COLUMN share_students TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'teacher lets students of their classes use it' AFTER is_enabled,
    ADD COLUMN share_limit INT UNSIGNED NOT NULL DEFAULT 20000 COMMENT 'tokens per student per month on this key - 0 = no limit' AFTER share_students,
    DROP INDEX unique_provider,
    ADD INDEX idx_owner (owner_id, provider),
    ADD CONSTRAINT fk_ai_providers_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE;

ALTER TABLE ai_usage
    ADD COLUMN key_owner INT UNSIGNED DEFAULT NULL COMMENT 'NULL = school key' AFTER provider,
    ADD COLUMN class_id INT UNSIGNED DEFAULT NULL AFTER key_owner,
    ADD INDEX idx_key_owner (key_owner, user_id, created_at);

ALTER TABLE classes
    ADD COLUMN ai_students TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'students may use AI helpers in this class';
