-- Opening balance fields for future outstanding-balance tracking (optional per party).
-- Safe to run multiple times only if columns absent (app also auto-ALTERs via party_account_ensure_schema).

ALTER TABLE `party_accounts`
  ADD COLUMN `opening_balance` decimal(15,2) DEFAULT NULL AFTER `currency`,
  ADD COLUMN `opening_balance_type` enum('receivable','payable') DEFAULT NULL AFTER `opening_balance`;
