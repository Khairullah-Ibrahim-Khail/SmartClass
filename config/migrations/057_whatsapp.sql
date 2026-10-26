-- SmartClass migration 057 — WhatsApp, optional and off until somebody configures it:
--   level 1 "click to chat" (a button opens WhatsApp with the message ready; the person presses Send) and
--   level 2 automatic sending through the official WhatsApp Business Cloud API, with each person's own sender or the school's.
-- A person's number and agreement live on the user; senders, editable message texts and the send queue have their own tables.
-- Run ONCE (bin/update_all.php / bin/migrate.php do it).

ALTER TABLE users
    ADD COLUMN whatsapp_number VARCHAR(20) NULL AFTER guardian_alerts,
    ADD COLUMN whatsapp_consent_at DATETIME NULL AFTER whatsapp_number;

CREATE TABLE IF NOT EXISTS whatsapp_senders (
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

CREATE TABLE IF NOT EXISTS whatsapp_texts (
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

CREATE TABLE IF NOT EXISTS whatsapp_queue (
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
