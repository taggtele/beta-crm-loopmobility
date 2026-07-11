-- Party Account module schema — run on production after backup.
-- Charset: utf8mb4

START TRANSACTION;

CREATE TABLE IF NOT EXISTS `loop_entities` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(180) NOT NULL,
  `code` varchar(32) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `sort_order` int NOT NULL DEFAULT 0,
  `created_by` bigint UNSIGNED DEFAULT NULL,
  `updated_by` bigint UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_loop_entities_status` (`status`),
  KEY `idx_loop_entities_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `party_accounts` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `party_name` varchar(255) NOT NULL,
  `party_email` varchar(255) DEFAULT NULL,
  `party_phone` varchar(60) DEFAULT NULL,
  `address` varchar(500) DEFAULT NULL,
  `country` varchar(120) DEFAULT NULL,
  `bank_name` varchar(180) DEFAULT NULL,
  `account_holder_name` varchar(180) DEFAULT NULL,
  `account_number` varchar(64) DEFAULT NULL,
  `ifsc_swift_code` varchar(64) DEFAULT NULL,
  `iban_number` varchar(64) DEFAULT NULL,
  `credit_limit` decimal(15,2) DEFAULT NULL,
  `currency` varchar(10) NOT NULL DEFAULT 'INR',
  `payment_terms` varchar(255) DEFAULT NULL,
  `loop_entity_id` bigint UNSIGNED DEFAULT NULL,
  `assistant_manager_name` varchar(180) DEFAULT NULL,
  `business_manager_name` varchar(180) DEFAULT NULL,
  `notes` text,
  `status` varchar(40) NOT NULL DEFAULT 'draft',
  `created_by` bigint UNSIGNED DEFAULT NULL,
  `updated_by` bigint UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_party_accounts_party_name` (`party_name`(128)),
  KEY `idx_party_accounts_email` (`party_email`(128)),
  KEY `idx_party_accounts_status_deleted` (`status`,`deleted_at`),
  KEY `idx_party_accounts_country` (`country`(64)),
  KEY `fk_party_accounts_loop_entity` (`loop_entity_id`),
  CONSTRAINT `fk_party_accounts_loop_entity` FOREIGN KEY (`loop_entity_id`) REFERENCES `loop_entities` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Module-scoped audit trail (documented as "activity logs" for Party Account in README).
CREATE TABLE IF NOT EXISTS `party_account_activity_logs` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `party_account_id` bigint UNSIGNED DEFAULT NULL,
  `actor_user_id` varchar(50) DEFAULT NULL,
  `actor_name` varchar(180) DEFAULT NULL,
  `action` varchar(64) NOT NULL,
  `summary` varchar(500) DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pa_act_party` (`party_account_id`),
  KEY `idx_pa_act_created` (`created_at`),
  CONSTRAINT `fk_pa_act_party` FOREIGN KEY (`party_account_id`) REFERENCES `party_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
