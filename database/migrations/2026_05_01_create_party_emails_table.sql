-- Email addresses for unified parties.
-- Safe/additive: email is unique so one address maps to only one party.

CREATE TABLE IF NOT EXISTS party_emails (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    party_id INT UNSIGNED NOT NULL,
    email VARCHAR(190) NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_party_emails_email (email),
    INDEX idx_party_emails_party (party_id),
    INDEX idx_party_emails_primary (party_id, is_primary),
    CONSTRAINT fk_party_emails_party
        FOREIGN KEY (party_id) REFERENCES parties (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
