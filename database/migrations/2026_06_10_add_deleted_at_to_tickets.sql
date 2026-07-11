-- Add soft delete support to tickets table
ALTER TABLE tickets
    ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL DEFAULT NULL AFTER updated_by,
    ADD COLUMN IF NOT EXISTS is_deleted TINYINT(1) NOT NULL DEFAULT 0 AFTER deleted_at,
    ADD COLUMN IF NOT EXISTS delete_reason VARCHAR(500) NULL AFTER is_deleted;

CREATE INDEX IF NOT EXISTS idx_tickets_deleted_at ON tickets(deleted_at);
CREATE INDEX IF NOT EXISTS idx_tickets_is_deleted ON tickets(is_deleted);