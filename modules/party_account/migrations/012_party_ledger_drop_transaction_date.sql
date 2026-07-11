-- Drop transaction_date column from party_ledger_transactions
-- This migration removes the transaction_date field as it's now derived from invoice_period

ALTER TABLE `party_ledger_transactions` DROP COLUMN `transaction_date`;
ALTER TABLE `party_ledger_transactions` DROP INDEX `idx_party_ledger_date`;

-- Add payment_in_date and payment_out_date columns
ALTER TABLE `party_ledger_transactions` ADD COLUMN `payment_in_date` date DEFAULT NULL;
ALTER TABLE `party_ledger_transactions` ADD COLUMN `payment_out_date` date DEFAULT NULL;