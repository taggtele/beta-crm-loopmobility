ALTER TABLE tickets ADD COLUMN IF NOT EXISTS vendor_email_initiated TINYINT(1) NOT NULL DEFAULT 0 AFTER assigned_vendor_id;
