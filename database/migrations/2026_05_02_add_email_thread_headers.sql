-- Add email threading headers for reliable reply mapping and ticket thread display.
-- Safe to run repeatedly on MariaDB/XAMPP because every column uses IF NOT EXISTS.

ALTER TABLE email_inbox_log
  ADD COLUMN IF NOT EXISTS in_reply_to TEXT NULL AFTER message_id,
  ADD COLUMN IF NOT EXISTS references_header TEXT NULL AFTER in_reply_to;

ALTER TABLE email_outbox_log
  ADD COLUMN IF NOT EXISTS message_id VARCHAR(255) NULL AFTER cc_email,
  ADD COLUMN IF NOT EXISTS in_reply_to TEXT NULL AFTER message_id,
  ADD COLUMN IF NOT EXISTS references_header TEXT NULL AFTER in_reply_to;

ALTER TABLE ticket_logs
  ADD COLUMN IF NOT EXISTS sender_email VARCHAR(150) NULL AFTER action,
  ADD COLUMN IF NOT EXISTS subject TEXT NULL AFTER sender_email,
  ADD COLUMN IF NOT EXISTS message_id VARCHAR(255) NULL AFTER subject,
  ADD COLUMN IF NOT EXISTS in_reply_to TEXT NULL AFTER message_id,
  ADD COLUMN IF NOT EXISTS references_header TEXT NULL AFTER in_reply_to,
  ADD INDEX IF NOT EXISTS idx_ticket_logs_message_id (message_id);
