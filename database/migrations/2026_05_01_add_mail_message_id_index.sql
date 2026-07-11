-- Add index for mail_message_id lookups (ticket matching by Message-ID header)
-- Improves email_processor_find_ticket_by_message_headers() performance
ALTER TABLE tickets 
  ADD INDEX IF NOT EXISTS idx_tickets_mail_message_id (mail_message_id(191));
