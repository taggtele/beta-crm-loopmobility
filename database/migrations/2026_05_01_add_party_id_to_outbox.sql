-- Add party_id column to email_outbox_log for linking outgoing emails to parties
-- This improves traceability of which party/vendor an outgoing email was sent to
ALTER TABLE email_outbox_log 
  ADD COLUMN IF NOT EXISTS party_id INT UNSIGNED NULL AFTER ticket_id,
  ADD CONSTRAINT fk_email_outbox_party 
    FOREIGN KEY (party_id) REFERENCES parties(id) 
    ON DELETE SET NULL;
