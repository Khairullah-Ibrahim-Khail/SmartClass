-- SmartClass migration 003 — repair text stored HTML-encoded
-- Older versions of register.php / classes/create.php / ajax/handler.php ran
-- input through sanitize() (htmlspecialchars) before saving, so "O'Brien" was
-- stored as "O&#039;Brien" and shown that way. Input is now stored raw and
-- escaped on output, so decode the existing rows once.
-- Run ONCE. &amp; is decoded last so "&amp;lt;" is not double-decoded.
--
--   mysql -u USER -p smartclass_db < config/migrations/003_decode_double_encoded_text.sql

UPDATE users SET name =
    REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(name, '&#039;', ''''), '&quot;', '"'), '&lt;', '<'), '&gt;', '>'), '&amp;', '&')
WHERE name LIKE '%&%;%';

UPDATE classes SET
    class_name  = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(class_name, '&#039;', ''''), '&quot;', '"'), '&lt;', '<'), '&gt;', '>'), '&amp;', '&'),
    description = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(description, '&#039;', ''''), '&quot;', '"'), '&lt;', '<'), '&gt;', '>'), '&amp;', '&'),
    tags        = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(tags, '&#039;', ''''), '&quot;', '"'), '&lt;', '<'), '&gt;', '>'), '&amp;', '&')
WHERE CONCAT_WS(' ', class_name, description, tags) LIKE '%&%;%';
