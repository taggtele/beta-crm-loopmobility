-- email_outbox_log table extensions
CREATE TABLE IF NOT EXISTS email_outbox_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ticket_id BIGINT NULL,
    to_email VARCHAR(190) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    body LONGTEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    error_message TEXT NULL,
    sent_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE email_outbox_log ADD COLUMN IF NOT EXISTS email_account_id INT NULL AFTER ticket_id;
ALTER TABLE email_outbox_log ADD COLUMN IF NOT EXISTS from_email VARCHAR(150) NULL AFTER email_account_id;
ALTER TABLE email_outbox_log ADD COLUMN IF NOT EXISTS cc_email TEXT NULL AFTER to_email;
ALTER TABLE email_outbox_log ADD COLUMN IF NOT EXISTS message_id VARCHAR(255) NULL AFTER cc_email;
ALTER TABLE email_outbox_log ADD COLUMN IF NOT EXISTS in_reply_to TEXT NULL AFTER message_id;
ALTER TABLE email_outbox_log ADD COLUMN IF NOT EXISTS references_header TEXT NULL AFTER in_reply_to;
ALTER TABLE email_outbox_log ADD COLUMN IF NOT EXISTS party_id INT UNSIGNED NULL AFTER ticket_id;
ALTER TABLE email_outbox_log ADD COLUMN IF NOT EXISTS body_is_html TINYINT(1) NOT NULL DEFAULT 0 AFTER body;
ALTER TABLE email_outbox_log MODIFY COLUMN IF EXISTS body LONGTEXT NULL;

-- Inline images preview table
CREATE TABLE IF NOT EXISTS email_outbox_inline_images (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    outbox_id BIGINT UNSIGNED NOT NULL,
    cid VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_outbox_id (outbox_id),
    INDEX idx_cid (cid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
