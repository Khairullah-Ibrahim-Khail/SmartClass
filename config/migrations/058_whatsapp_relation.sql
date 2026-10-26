-- Whose WhatsApp number is it (the student's own, or a father / uncle / elder brother …), and no default daily cap on a sender.
ALTER TABLE users ADD COLUMN whatsapp_relation VARCHAR(12) DEFAULT NULL AFTER whatsapp_consent_at;
ALTER TABLE whatsapp_senders MODIFY daily_limit INT NOT NULL DEFAULT 0;
