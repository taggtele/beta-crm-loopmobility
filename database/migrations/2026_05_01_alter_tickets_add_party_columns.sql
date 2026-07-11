-- Adds party and stable internal ticket references to tickets.
-- Safe/additive: existing rows and existing ticket_id primary key are preserved.

ALTER TABLE tickets
    ADD COLUMN IF NOT EXISTS initiator_party_id INT UNSIGNED NULL AFTER source,
    ADD COLUMN IF NOT EXISTS assigned_vendor_id INT UNSIGNED NULL AFTER initiator_party_id,
    ADD COLUMN IF NOT EXISTS internal_ticket_id VARCHAR(50) NULL AFTER assigned_vendor_id;

CREATE INDEX IF NOT EXISTS idx_tickets_initiator_party ON tickets (initiator_party_id);
CREATE INDEX IF NOT EXISTS idx_tickets_assigned_vendor ON tickets (assigned_vendor_id);
CREATE UNIQUE INDEX IF NOT EXISTS uq_tickets_internal_ticket_id ON tickets (internal_ticket_id);

-- Optional one-time backfill for existing rows so old tickets have a stored
-- internal_ticket_id too. This preserves the app's existing LM-YYYYMMDD-NN
-- display format.
UPDATE tickets t
JOIN (
    SELECT
        t1.ticket_id,
        CONCAT(
            'LM-',
            DATE_FORMAT(t1.created_at, '%Y%m%d'),
            '-',
            LPAD(COUNT(t2.ticket_id), 2, '0')
        ) AS generated_internal_ticket_id
    FROM tickets t1
    JOIN tickets t2
        ON DATE(t2.created_at) = DATE(t1.created_at)
        AND t2.ticket_id <= t1.ticket_id
    GROUP BY t1.ticket_id, t1.created_at
) seq ON seq.ticket_id = t.ticket_id
SET t.internal_ticket_id = seq.generated_internal_ticket_id
WHERE internal_ticket_id IS NULL OR internal_ticket_id = '';
