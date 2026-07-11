-- ticket_referrals table
CREATE TABLE IF NOT EXISTS ticket_referrals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ticket_id BIGINT NOT NULL,
    referred_user_id VARCHAR(100) NOT NULL,
    referred_by_user_id VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ticket_referrals_ticket_user (ticket_id, referred_user_id),
    INDEX idx_ticket_referrals_ticket (ticket_id),
    INDEX idx_ticket_referrals_user (referred_user_id),
    CONSTRAINT fk_ticket_referrals_ticket_id
        FOREIGN KEY (ticket_id) REFERENCES tickets (ticket_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- message_templates table
CREATE TABLE IF NOT EXISTS message_templates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(190) NOT NULL,
    content LONGTEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_message_templates_user_updated (user_id, updated_at),
    INDEX idx_message_templates_user_title (user_id, title)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- email_attachments table
CREATE TABLE IF NOT EXISTS email_attachments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    outbox_id BIGINT UNSIGNED NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_outbox_id (outbox_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- email_log_flags table
CREATE TABLE IF NOT EXISTS email_log_flags (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    mail_direction ENUM('incoming', 'outgoing') NOT NULL,
    log_id INT UNSIGNED NOT NULL,
    flagged_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_email_log_flags_user_mail (user_id, mail_direction, log_id),
    KEY idx_email_log_flags_user_flagged (user_id, flagged_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- user_email_signatures table
CREATE TABLE IF NOT EXISTS user_email_signatures (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    signature_html TEXT NULL,
    signature_text TEXT NULL,
    logo_url VARCHAR(2048) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_email_signatures_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- assistant_managers table
CREATE TABLE IF NOT EXISTS assistant_managers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(190) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_assistant_managers_email (email),
    INDEX idx_assistant_managers_active (is_active, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- vendor_am_mapping table (complete schema with all columns)
CREATE TABLE IF NOT EXISTS vendor_am_mapping (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    vendor_name VARCHAR(190) NULL,
    vendor_email VARCHAR(190) NOT NULL,
    assistant_manager_id INT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    business_manager_email VARCHAR(190) NULL AFTER assistant_manager_id,
    party_id INT UNSIGNED NULL AFTER id,
    UNIQUE KEY uq_vendor_am_mapping_email (vendor_email),
    INDEX idx_vendor_am_mapping_vendor_name (vendor_name),
    INDEX idx_vendor_am_mapping_active (is_active),
    INDEX idx_vendor_am_mapping_am (assistant_manager_id),
    INDEX idx_vendor_am_mapping_party_id (party_id),
    CONSTRAINT fk_vendor_am_mapping_am
        FOREIGN KEY (assistant_manager_id) REFERENCES assistant_managers (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- users table ALTER TABLE (role enum fix)
SET @sql := (
    SELECT CONCAT('ALTER TABLE users MODIFY COLUMN role ENUM(''Admin'',''Agent'',''finance'',''sales'',''SuperAdmin'') NOT NULL')
    FROM `information_schema`.`COLUMNS`
    WHERE `TABLE_SCHEMA` = DATABASE()
    AND `TABLE_NAME` = 'users'
    AND `COLUMN_NAME` = 'role'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
