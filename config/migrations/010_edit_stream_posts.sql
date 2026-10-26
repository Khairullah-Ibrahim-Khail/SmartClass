-- SmartClass migration 010 — edit stream posts
-- edited_at is set when the author changes the text of a post (shown as "Edited").
-- updated_at cannot be used for this: pinning a post changes it too.
-- Run ONCE.
--
--   mysql -u USER -p smartclass_db < config/migrations/010_edit_stream_posts.sql

ALTER TABLE announcements
    ADD COLUMN edited_at DATETIME DEFAULT NULL COMMENT 'last time the author edited the text' AFTER pinned;
