-- Bulk import audit log for Party Account module.
CREATE TABLE IF NOT EXISTS `party_account_import_logs` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_user_id` varchar(50) DEFAULT NULL,
  `actor_name` varchar(180) DEFAULT NULL,
  `filename` varchar(255) NOT NULL,
  `total_rows` int UNSIGNED NOT NULL DEFAULT 0,
  `success_count` int UNSIGNED NOT NULL DEFAULT 0,
  `skipped_count` int UNSIGNED NOT NULL DEFAULT 0,
  `failed_count` int UNSIGNED NOT NULL DEFAULT 0,
  `errors_json` json DEFAULT NULL,
  `created_ids_json` json DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pa_import_logs_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
