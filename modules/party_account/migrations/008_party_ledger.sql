-- Isolated Party Ledger tables for modules/party_account.

CREATE TABLE IF NOT EXISTS `party_ledger_transactions` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `party_account_id` bigint UNSIGNED NOT NULL,
  `invoice_period` char(7) NOT NULL,
  `customer_invoice_no` varchar(120) DEFAULT NULL,
  `customer_invoice_value` decimal(15,2) NOT NULL DEFAULT 0.00,
  `vendor_invoice_no` varchar(120) DEFAULT NULL,
  `vendor_invoice_value` decimal(15,2) NOT NULL DEFAULT 0.00,
  `payment_in` decimal(15,2) NOT NULL DEFAULT 0.00,
  `payment_out` decimal(15,2) NOT NULL DEFAULT 0.00,
  `notes` varchar(500) DEFAULT NULL,
  `created_by` bigint UNSIGNED DEFAULT NULL,
  `updated_by` bigint UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_party_ledger_party_period` (`party_account_id`, `invoice_period`),
  CONSTRAINT `fk_party_ledger_party`
    FOREIGN KEY (`party_account_id`) REFERENCES `party_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `party_ledger_monthly_closing` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `party_account_id` bigint UNSIGNED NOT NULL,
  `period_month` char(7) NOT NULL,
  `opening_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `closing_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `closed_by` bigint UNSIGNED DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `reopened_by` bigint UNSIGNED DEFAULT NULL,
  `reopened_at` datetime DEFAULT NULL,
  `status` enum('closed','reopened') NOT NULL DEFAULT 'closed',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_party_ledger_closing` (`party_account_id`, `period_month`),
  KEY `idx_party_ledger_closing_status` (`status`),
  CONSTRAINT `fk_party_ledger_closing_party`
    FOREIGN KEY (`party_account_id`) REFERENCES `party_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
