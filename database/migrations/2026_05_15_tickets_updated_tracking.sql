-- Track last ticket update for list "Last updated" column.
ALTER TABLE tickets
    ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER closed_at;

ALTER TABLE tickets
    ADD COLUMN IF NOT EXISTS updated_by VARCHAR(100) NULL AFTER updated_at;

UPDATE tickets
SET updated_at = GREATEST(created_at, COALESCE(closed_at, created_at))
WHERE updated_at IS NULL;

UPDATE tickets
SET updated_by = created_by
WHERE updated_by IS NULL OR updated_by = '';
