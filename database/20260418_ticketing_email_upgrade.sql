-- Ticketing/email upgrade for the plain PHP helpdesk.
-- Compatibility note:
-- This app uses `tickets.ticket_id` as the internal auto-generated system ID.
-- `external_ticket_id` stores the customer/vendor reference parsed from email.

CREATE TABLE IF NOT EXISTS tickets (
    ticket_id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    external_ticket_id VARCHAR(255) NULL,
    customer TEXT NULL,
    country VARCHAR(100) NULL,
    issue VARCHAR(255) NULL,
    description TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status ENUM('Open', 'In-Progress', 'Closed') NOT NULL DEFAULT 'Open',
    closed_at DATETIME NULL,
    reference VARCHAR(255) NULL,
    priority ENUM('Low', 'Medium', 'High') NOT NULL DEFAULT 'Medium',
    assign_to VARCHAR(100) NULL,
    created_by VARCHAR(100) NULL,
    source ENUM('manual', 'email', 'api') NOT NULL DEFAULT 'manual',
    customer_email VARCHAR(150) NULL,
    mail_message_id VARCHAR(255) NULL,
    mail_thread_id VARCHAR(255) NULL,
    INDEX idx_tickets_external_ticket_id (external_ticket_id),
    INDEX idx_tickets_status_created_at (status, created_at),
    INDEX idx_tickets_mail_thread_id (mail_thread_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS ticket_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ticket_id BIGINT NOT NULL,
    action VARCHAR(50) NOT NULL,
    message TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ticket_logs_ticket_created (ticket_id, created_at),
    CONSTRAINT fk_ticket_logs_ticket_id
        FOREIGN KEY (ticket_id) REFERENCES tickets (ticket_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(100) NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    type VARCHAR(50) NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notifications_user_read_created (user_id, is_read, created_at),
    INDEX idx_notifications_type_created (type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_inbox_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    message_id VARCHAR(255) NULL,
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

CREATE TABLE IF NOT EXISTS email_outbox_log (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NULL,
    email_account_id INT NULL,
    from_email VARCHAR(150) NULL,
    to_email VARCHAR(150) NULL,
    cc_email TEXT NULL,
    subject TEXT NULL,
    body TEXT NULL,
    status ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
    error_message TEXT NULL,
    sent_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_outbox_status_created_at (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
