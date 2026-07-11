-- Additional emails per party account (primary stays on party_accounts.party_email).
CREATE TABLE IF NOT EXISTS `party_account_emails` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `party_account_id` bigint UNSIGNED NOT NULL,
  `email` varchar(255) NOT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` smallint UNSIGNED NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_party_account_emails_email` (`email`(191)),
  UNIQUE KEY `uq_party_account_emails_account_email` (`party_account_id`, `email`(191)),
  KEY `idx_party_account_emails_account` (`party_account_id`),
  KEY `idx_party_account_emails_primary` (`party_account_id`, `is_primary`),
  CONSTRAINT `fk_party_account_emails_account`
    FOREIGN KEY (`party_account_id`) REFERENCES `party_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
