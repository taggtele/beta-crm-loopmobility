-- Tracks vendor/client external ticket IDs seen on incoming emails.
-- Safe/additive: does not modify tickets or any existing table.

CREATE TABLE IF NOT EXISTS external_ticket_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ticket_id BIGINT NOT NULL,
    external_ticket_id VARCHAR(255) NOT NULL,
    source_email VARCHAR(190) NULL,
    message_id VARCHAR(255) NULL,
    source VARCHAR(50) NOT NULL DEFAULT 'email',
    first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NULL DEFAULT NULL,
    seen_count INT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY uq_external_ticket_history_ticket_ref (ticket_id, external_ticket_id),
    INDEX idx_external_ticket_history_ticket (ticket_id),
    INDEX idx_external_ticket_history_ref (external_ticket_id),
    INDEX idx_external_ticket_history_source_email (source_email),
    CONSTRAINT fk_external_ticket_history_ticket
        FOREIGN KEY (ticket_id) REFERENCES tickets (ticket_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
