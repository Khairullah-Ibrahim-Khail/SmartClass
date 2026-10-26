-- SmartClass migration 047 — indexes for lists that grow with time (activity log, notifications)
-- Run ONCE (bin/update_all.php / bin/migrate.php do it).
ALTER TABLE activity_log ADD INDEX idx_created (created_at), ADD INDEX idx_user_created (user_id, created_at);
ALTER TABLE notifications ADD INDEX idx_user_created (user_id, created_at);
