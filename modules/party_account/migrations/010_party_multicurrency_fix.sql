-- Multi-Currency Ledger Unique Key Fix
-- Existing 008 closing rows used (party_account_id, period_month). Multi-currency closings must be unique per currency.

DROP PROCEDURE IF EXISTS `party_account_fix_closing_currency_unique`;
DELIMITER //
CREATE PROCEDURE `party_account_fix_closing_currency_unique`()
BEGIN
  IF EXISTS (
    SELECT 1
    FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'party_ledger_monthly_closing'
      AND CONSTRAINT_NAME = 'uq_party_ledger_closing'
  ) THEN
    ALTER TABLE `party_ledger_monthly_closing` DROP INDEX `uq_party_ledger_closing`;
  END IF;

  IF NOT EXISTS (
    SELECT 1
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'party_ledger_monthly_closing'
      AND INDEX_NAME = 'uq_party_ledger_closing_currency'
  ) THEN
    ALTER TABLE `party_ledger_monthly_closing`
      ADD UNIQUE KEY `uq_party_ledger_closing_currency` (`party_account_id`, `currency`, `period_month`);
  END IF;
END//
DELIMITER ;
CALL `party_account_fix_closing_currency_unique`();
DROP PROCEDURE `party_account_fix_closing_currency_unique`;
