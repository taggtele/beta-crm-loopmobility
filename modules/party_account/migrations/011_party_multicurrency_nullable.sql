-- Make currency/opening_balance nullable for multi-currency support
-- Multi-currency parties use currency_ledgers as source of truth

ALTER TABLE `party_accounts` 
  MODIFY COLUMN `currency` varchar(10) NULL DEFAULT NULL,
  MODIFY COLUMN `opening_balance` decimal(15,2) NULL DEFAULT NULL,
  MODIFY COLUMN `opening_balance_type` enum('receivable','payable') NULL DEFAULT NULL; 