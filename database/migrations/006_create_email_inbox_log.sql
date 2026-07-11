-- email_inbox_log table with all columns and indexes
CREATE TABLE IF NOT EXISTS email_inbox_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    message_id VARCHAR(255) NULL,
    in_reply_to TEXT NULL,
    references_header TEXT NULL,
    from_email VARCHAR(150) NULL,
    subject TEXT NULL,
    body LONGTEXT NULL,
    raw_message LONGTEXT NULL,
    received_at DATETIME NULL,
    processed TINYINT(1) NOT NULL DEFAULT 0,
    processed_at DATETIME NULL,
    processing_result VARCHAR(20) NOT NULL DEFAULT 'pending',
    ignored_reason TEXT NULL,
    ticket_id BIGINT NULL,
    external_ticket_id VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_email_inbox_message_id (message_id),
    INDEX idx_email_inbox_ticket_id (ticket_id),
    INDEX idx_email_inbox_received_at (received_at),
    INDEX idx_email_inbox_external_ticket_id (external_ticket_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Ensure all columns exist
ALTER TABLE email_inbox_log ADD COLUMN IF NOT EXISTS body LONGTEXT NULL AFTER subject;
ALTER TABLE email_inbox_log ADD COLUMN IF NOT EXISTS in_reply_to TEXT NULL AFTER message_id;
ALTER TABLE email_inbox_log ADD COLUMN IF NOT EXISTS references_header TEXT NULL AFTER in_reply_to;
ALTER TABLE email_inbox_log ADD COLUMN IF NOT EXISTS raw_message LONGTEXT NULL AFTER body;
ALTER TABLE email_inbox_log ADD COLUMN IF NOT EXISTS processed_at DATETIME NULL AFTER processed;
ALTER TABLE email_inbox_log ADD COLUMN IF NOT EXISTS processing_result VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER processed_at;
ALTER TABLE email_inbox_log ADD COLUMN IF NOT EXISTS ignored_reason TEXT NULL AFTER processing_result;
ALTER TABLE email_inbox_log ADD COLUMN IF NOT EXISTS external_ticket_id VARCHAR(255) NULL AFTER ticket_id;
ALTER TABLE email_inbox_log MODIFY COLUMN IF EXISTS body LONGTEXT NULL;

-- Fixup: any previously 'pending' inbox rows that already have a ticket should be 'created'
UPDATE email_inbox_log SET processing_result = 'created' WHERE processed = 1 AND ticket_id IS NOT NULL AND processing_result = 'pending';
