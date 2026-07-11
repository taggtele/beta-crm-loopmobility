-- parties table (complete schema)
CREATE TABLE IF NOT EXISTS parties (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    country VARCHAR(120) NULL DEFAULT NULL AFTER name,
    INDEX idx_parties_status_name (status, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- party_emails table (complete schema)
CREATE TABLE IF NOT EXISTS party_emails (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    party_id INT UNSIGNED NOT NULL,
    email VARCHAR(190) NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_party_emails_email (email),
    INDEX idx_party_emails_party (party_id),
    INDEX idx_party_emails_primary (party_id, is_primary),
    CONSTRAINT fk_party_emails_party
        FOREIGN KEY (party_id) REFERENCES parties (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- tickets table column additions
ALTER TABLE tickets ADD COLUMN IF NOT EXISTS initiator_party_id INT UNSIGNED NULL AFTER source;
ALTER TABLE tickets ADD COLUMN IF NOT EXISTS assigned_vendor_id INT UNSIGNED NULL AFTER initiator_party_id;
ALTER TABLE tickets ADD COLUMN IF NOT EXISTS internal_ticket_id VARCHAR(50) NULL AFTER assigned_vendor_id;
ALTER TABLE tickets ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER closed_at;
ALTER TABLE tickets ADD COLUMN IF NOT EXISTS updated_by VARCHAR(100) NULL AFTER updated_at;

ALTER TABLE tickets ADD COLUMN IF NOT EXISTS send_auto_acknowledgement TINYINT(1) NOT NULL DEFAULT 1 AFTER reference;
UPDATE tickets SET send_auto_acknowledgement = 1 WHERE send_auto_acknowledgement IS NULL;

-- Backfill updated_at and updated_by for existing tickets
UPDATE tickets SET updated_at = GREATEST(created_at, COALESCE(closed_at, created_at)) WHERE updated_at IS NULL;
UPDATE tickets SET updated_by = created_by WHERE updated_by IS NULL OR updated_by = '';
