-- Add bank_branch_address column to party_accounts table
-- This field stores the branch address of the bank for reference

ALTER TABLE `party_accounts` 
ADD COLUMN `bank_branch_address` varchar(500) DEFAULT NULL AFTER `iban_number`;