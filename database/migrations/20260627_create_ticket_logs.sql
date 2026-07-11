CREATE TABLE IF NOT EXISTS ticket_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ticket_id BIGINT NOT NULL,
    action VARCHAR(50) NOT NULL,
    sender_email VARCHAR(150) NULL,
    subject TEXT NULL,
    message_id VARCHAR(255) NULL,
    in_reply_to TEXT NULL,
    references_header TEXT NULL,
    message TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_ticket_logs_ticket_created (ticket_id, created_at),
    INDEX idx_ticket_logs_message_id (message_id),

    CONSTRAINT fk_ticket_logs_ticket_id
        FOREIGN KEY (ticket_id)
        REFERENCES tickets(ticket_id)
        ON DELETE CASCADE
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;