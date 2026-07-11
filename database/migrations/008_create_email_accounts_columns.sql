-- email_accounts table column additions
ALTER TABLE email_accounts ADD COLUMN IF NOT EXISTS username VARCHAR(255) NULL AFTER email;
ALTER TABLE email_accounts ADD COLUMN IF NOT EXISTS from_name VARCHAR(150) NULL AFTER username;
ALTER TABLE email_accounts ADD COLUMN IF NOT EXISTS smtp_encryption ENUM('ssl', 'tls', 'none') NULL DEFAULT 'tls' AFTER smtp_port;
ALTER TABLE email_accounts ADD COLUMN IF NOT EXISTS cron_enabled TINYINT(1) DEFAULT 1 NOT NULL AFTER smtp_encryption;
ALTER TABLE email_accounts ADD COLUMN IF NOT EXISTS is_auto_reply_account TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active;
ALTER TABLE email_accounts ADD COLUMN IF NOT EXISTS import_cutoff_at DATETIME NULL AFTER last_checked_at;
ALTER TABLE email_accounts ADD COLUMN IF NOT EXISTS last_seen_uid BIGINT NULL AFTER import_cutoff_at;

-- Backfill data
UPDATE email_accounts SET username = email WHERE username IS NULL OR username = '';
UPDATE email_accounts SET smtp_encryption = COALESCE(NULLIF(encryption, ''), 'tls') WHERE smtp_encryption IS NULL OR smtp_encryption = '';

-- Fix enum to include 'none' if missing
SET @enc_type = (SELECT LOWER(`Type`) FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'email_accounts' AND `COLUMN_NAME` = 'encryption');
SET @smtp_type = (SELECT LOWER(`Type`) FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'email_accounts' AND `COLUMN_NAME` = 'smtp_encryption');

-- We can't use IF NOT EXISTS for ENUM modifications, so we do it conditionally via PHP logic in the migration runner.
-- These ALTERs are left as no-ops if the enum already contains all required values.
