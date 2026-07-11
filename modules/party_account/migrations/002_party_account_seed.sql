-- Optional starter loop entities — run manually once (may duplicate names if executed twice).

START TRANSACTION;

INSERT INTO `loop_entities` (`name`, `code`, `status`, `sort_order`, `deleted_at`) VALUES
('Loop Mobility Pvt Ltd', 'LMPL', 'active', 10, NULL),
('TAGG Operations', 'TAGG', 'active', 20, NULL),
('International Holdings', 'INTL', 'active', 30, NULL);

COMMIT;
