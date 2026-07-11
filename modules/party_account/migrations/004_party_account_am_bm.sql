-- Party Account: standalone AM/BM name fields (not linked to assistant_managers / Party AM mapping)
-- Run on production after backup.

ALTER TABLE `party_accounts`
  ADD COLUMN `assistant_manager_name` VARCHAR(180) NULL DEFAULT NULL AFTER `loop_entity_id`,
  ADD COLUMN `business_manager_name` VARCHAR(180) NULL DEFAULT NULL AFTER `assistant_manager_name`;

-- If an earlier build added link columns, remove them manually when safe:
-- ALTER TABLE `party_accounts` DROP INDEX `idx_party_accounts_am`;
-- ALTER TABLE `party_accounts` DROP COLUMN `assistant_manager_id`, DROP COLUMN `business_manager_email`;

