-- Add is_auto_reply_account flag to email_accounts
-- Safe: uses IF NOT EXISTS to avoid errors on re-run
ALTER TABLE email_accounts
  ADD COLUMN IF NOT EXISTS is_auto_reply_account TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active;

-- Optional index to speed up lookup of the auto-reply account
ALTER TABLE email_accounts
  ADD INDEX IF NOT EXISTS idx_email_accounts_auto_reply (is_auto_reply_account, is_active);
