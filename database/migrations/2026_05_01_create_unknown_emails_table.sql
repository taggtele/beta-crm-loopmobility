-- Stores incoming email from unknown/unregistered senders for manual review.
-- Safe/additive: no existing workflow is removed.

CREATE TABLE IF NOT EXISTS unknown_emails (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    message_id VARCHAR(255) NULL,
    from_email VARCHAR(190) NOT NULL,
    from_name VARCHAR(190) NULL,
    subject TEXT NULL,
    body LONGTEXT NULL,
    raw_message LONGTEXT NULL,
    received_at DATETIME NULL,
    review_status VARCHAR(20) NOT NULL DEFAULT 'pending',
    converted_party_id INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_unknown_emails_message_id (message_id),
    INDEX idx_unknown_emails_from_email (from_email),
    INDEX idx_unknown_emails_status_created (review_status, created_at),
    CONSTRAINT fk_unknown_emails_party
        FOREIGN KEY (converted_party_id) REFERENCES parties (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
