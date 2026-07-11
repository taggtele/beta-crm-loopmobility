-- Email accounts table for IMAP/SMTP
CREATE TABLE IF NOT EXISTS email_accounts (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(100) NULL,
    email VARCHAR(150) NOT NULL,
    username VARCHAR(255) NULL,
    from_name VARCHAR(150) NULL,
    password VARCHAR(255) NOT NULL,
    imap_host VARCHAR(255) NULL,
    imap_port INT DEFAULT 993,
    encryption ENUM('ssl', 'tls', 'none') DEFAULT 'ssl',
    smtp_host VARCHAR(255) NULL,
    smtp_port INT DEFAULT 587,
    smtp_encryption ENUM('ssl', 'tls', 'none') DEFAULT 'tls',
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    last_checked_at DATETIME NULL,
    import_cutoff_at DATETIME NULL,
    last_seen_uid BIGINT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_accounts_active (is_active, email),
    INDEX idx_email_accounts_imap (imap_host)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
