-- Prevent large email HTML/preview bodies from failing with "Data too long".
-- Runtime code also strips inline base64 from DB writes when USE_MINIO=true.

ALTER TABLE email_inbox_log
    MODIFY COLUMN body LONGTEXT NULL;

ALTER TABLE email_outbox_log
    MODIFY COLUMN body LONGTEXT NULL;
