-- Multi-Currency Party Account Support
-- Allows a single party to operate in multiple currencies with independent ledgers.

ALTER TABLE `party_accounts`
  ADD COLUMN `is_multi_currency` TINYINT(1) NOT NULL DEFAULT 0 AFTER `currency`;

CREATE TABLE IF NOT EXISTS `party_currency_ledgers` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `party_account_id` bigint UNSIGNED NOT NULL,
  `currency` varchar(10) NOT NULL,
  `opening_balance` decimal(15,2) DEFAULT NULL,
  `opening_balance_type` enum('receivable','payable') DEFAULT NULL,
  `status` varchar(40) NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_party_currency` (`party_account_id`, `currency`),
  KEY `idx_party_currency_status` (`status`),
  KEY `idx_party_currency_deleted` (`deleted_at`),
  CONSTRAINT `fk_party_currency_party`
    FOREIGN KEY (`party_account_id`) REFERENCES `party_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add currency column to existing ledger tables for multi-currency support
ALTER TABLE `party_ledger_transactions`
  ADD COLUMN `currency` varchar(10) NOT NULL DEFAULT 'INR' AFTER `party_account_id`;

ALTER TABLE `party_ledger_monthly_closing`
  ADD COLUMN `currency` varchar(10) NOT NULL DEFAULT 'INR' AFTER `party_account_id`;

-- Add indexes for currency-based queries
ALTER TABLE `party_ledger_transactions`
  ADD INDEX `idx_ledger_party_currency` (`party_account_id`, `currency`, `invoice_period`);

ALTER TABLE `party_ledger_monthly_closing`
  ADD INDEX `idx_closing_party_currency` (`party_account_id`, `currency`, `period_month`);