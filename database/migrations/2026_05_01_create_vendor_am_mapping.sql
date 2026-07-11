-- Creates Vendor -> Assistant Manager mapping for automatic CC routing.
-- Safe/additive: vendors are keyed by email so no existing vendor table is required.

CREATE TABLE IF NOT EXISTS vendor_am_mapping (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    vendor_name VARCHAR(190) NULL,
    vendor_email VARCHAR(190) NOT NULL,
    assistant_manager_id INT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_vendor_am_mapping_email (vendor_email),
    INDEX idx_vendor_am_mapping_vendor_name (vendor_name),
    INDEX idx_vendor_am_mapping_active (is_active),
    INDEX idx_vendor_am_mapping_am (assistant_manager_id),
    CONSTRAINT fk_vendor_am_mapping_am
        FOREIGN KEY (assistant_manager_id) REFERENCES assistant_managers (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
