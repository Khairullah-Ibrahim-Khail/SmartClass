-- SmartClass migration 012 — scheduled stream posts
-- publish_at: when a teacher's post becomes visible to students (NULL = right away).
-- notified_at: when the class was notified about it (NULL = not yet - a scheduled post
--   that is due gets its notification from cron/send_emails.php or the first class-page
--   visit, once). Existing posts are marked as already notified.
-- Run ONCE.
--
--   mysql -u USER -p smartclass_db < config/migrations/012_scheduled_posts.sql

ALTER TABLE announcements
    ADD COLUMN publish_at DATETIME DEFAULT NULL COMMENT 'visible to students from this time - NULL = right away' AFTER edited_at,
    ADD COLUMN notified_at DATETIME DEFAULT NULL COMMENT 'class notified about the post - NULL = not yet' AFTER publish_at,
    ADD INDEX idx_publish (notified_at, publish_at);

UPDATE announcements SET notified_at = created_at WHERE notified_at IS NULL;
