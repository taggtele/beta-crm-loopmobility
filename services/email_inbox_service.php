<?php

// Ensures the inbox log table can store full incoming message data.
function email_inbox_service_ensure_table(PDO $pdo): void
{
    static $ready = false;

    if ($ready) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS email_inbox_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            message_id VARCHAR(255) NULL,
            in_reply_to TEXT NULL,
            references_header TEXT NULL,
            from_email VARCHAR(150) NULL,
            subject TEXT NULL,
            body LONGTEXT NULL,
            raw_message LONGTEXT NULL,
            received_at DATETIME NULL,
            processed TINYINT(1) NOT NULL DEFAULT 0,
            processed_at DATETIME NULL,
            processing_result VARCHAR(20) NOT NULL DEFAULT \'pending\',
            ignored_reason TEXT NULL,
            ticket_id BIGINT NULL,
            external_ticket_id VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_email_inbox_message_id (message_id),
            INDEX idx_email_inbox_ticket_id (ticket_id),
            INDEX idx_email_inbox_received_at (received_at),
            INDEX idx_email_inbox_external_ticket_id (external_ticket_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
    );

    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM email_inbox_log')->fetchAll() as $column) {
        $columns[(string) ($column['Field'] ?? '')] = true;
    }

    $alterStatements = [];

    if (!isset($columns['body'])) {
        $alterStatements[] = 'ADD COLUMN body LONGTEXT NULL AFTER subject';
    }

    if (!isset($columns['in_reply_to'])) {
        $alterStatements[] = 'ADD COLUMN in_reply_to TEXT NULL AFTER message_id';
    }

    if (!isset($columns['references_header'])) {
        $alterStatements[] = 'ADD COLUMN references_header TEXT NULL AFTER in_reply_to';
    }

    if (!isset($columns['raw_message'])) {
        $alterStatements[] = 'ADD COLUMN raw_message LONGTEXT NULL AFTER body';
    }

    if (!isset($columns['processed_at'])) {
        $alterStatements[] = 'ADD COLUMN processed_at DATETIME NULL AFTER processed';
    }

    if (!isset($columns['processing_result'])) {
        $alterStatements[] = 'ADD COLUMN processing_result VARCHAR(20) NOT NULL DEFAULT \'pending\' AFTER processed_at';
    }

    if (!isset($columns['ignored_reason'])) {
        $alterStatements[] = 'ADD COLUMN ignored_reason TEXT NULL AFTER processing_result';
    }

    if (!isset($columns['external_ticket_id'])) {
        $alterStatements[] = 'ADD COLUMN external_ticket_id VARCHAR(255) NULL AFTER ticket_id';
    }

    foreach ($alterStatements as $alterStatement) {
        $pdo->exec('ALTER TABLE email_inbox_log ' . $alterStatement);
    }

    $bodyColumn = null;
    foreach ($pdo->query("SHOW COLUMNS FROM email_inbox_log LIKE 'body'")->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $bodyColumn = $column;
        break;
    }
    if ($bodyColumn && stripos((string) ($bodyColumn['Type'] ?? ''), 'longtext') === false) {
        $pdo->exec('ALTER TABLE email_inbox_log MODIFY COLUMN body LONGTEXT NULL');
    }

    $pdo->exec(
        "UPDATE email_inbox_log
         SET processing_result = 'created'
         WHERE processed = 1
         AND ticket_id IS NOT NULL
         AND processing_result = 'pending'"
    );

    $ready = true;
}

// Validates a YYYY-MM-DD date string used by dashboard and email filters.
function email_inbox_service_normalize_date(?string $value): string
{
    $value = trim((string) $value);

    if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return '';
    }

    return $value;
}
