<?php

require_once __DIR__ . '/../includes/auth.php';

// Ensures the additive ticket_logs table exists before log writes happen.
function ticket_log_service_ensure_table(PDO $pdo): void
{
    static $ready = false;

    if ($ready) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS ticket_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            ticket_id BIGINT NOT NULL,
            action VARCHAR(50) NOT NULL,
            sender_email VARCHAR(150) NULL,
            subject TEXT NULL,
            message_id VARCHAR(255) NULL,
            in_reply_to TEXT NULL,
            references_header TEXT NULL,
            message TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ticket_logs_ticket_created (ticket_id, created_at),
            INDEX idx_ticket_logs_message_id (message_id),
            CONSTRAINT fk_ticket_logs_ticket_id
                FOREIGN KEY (ticket_id) REFERENCES tickets (ticket_id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM ticket_logs')->fetchAll() as $column) {
        $field = (string) ($column['Field'] ?? '');
        if ($field !== '') {
            $columns[$field] = true;
        }
    }

    $alterStatements = [];
    if (!isset($columns['sender_email'])) {
        $alterStatements[] = 'ADD COLUMN sender_email VARCHAR(150) NULL AFTER action';
    }
    if (!isset($columns['subject'])) {
        $alterStatements[] = 'ADD COLUMN subject TEXT NULL AFTER sender_email';
    }
    if (!isset($columns['message_id'])) {
        $alterStatements[] = 'ADD COLUMN message_id VARCHAR(255) NULL AFTER subject';
    }
    if (!isset($columns['in_reply_to'])) {
        $alterStatements[] = 'ADD COLUMN in_reply_to TEXT NULL AFTER message_id';
    }
    if (!isset($columns['references_header'])) {
        $alterStatements[] = 'ADD COLUMN references_header TEXT NULL AFTER in_reply_to';
    }

    foreach ($alterStatements as $alterStatement) {
        $pdo->exec('ALTER TABLE ticket_logs ' . $alterStatement);
    }

    $ready = true;
}

/**
 * Stores a ticket activity row for audit, email thread tracking, and history views.
 *
 * NOTE:
 * ticket_logs table must be created via migration/deployment scripts.
 * No CREATE TABLE or ALTER TABLE statements should run at runtime.
 */
function ticket_log_service_add(
    PDO $pdo,
    int $ticketId,
    string $action,
    string $message,
    ?string $createdAt = null,
    array $meta = []
): void {
    if ($ticketId <= 0) {
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO ticket_logs (
            ticket_id,
            action,
            sender_email,
            subject,
            message_id,
            in_reply_to,
            references_header,
            message,
            created_at
        ) VALUES (
            :ticket_id,
            :action,
            :sender_email,
            :subject,
            :message_id,
            :in_reply_to,
            :references_header,
            :message,
            ' . (!empty($createdAt) ? ':created_at' : 'NOW()') . '
        )'
    );

    $params = [
        ':ticket_id'         => $ticketId,
        ':action'            => trim($action),
        ':sender_email'      => trim((string)($meta['sender_email'] ?? '')) ?: null,
        ':subject'           => trim((string)($meta['subject'] ?? '')) ?: null,
        ':message_id'        => trim((string)($meta['message_id'] ?? '')) ?: null,
        ':in_reply_to'       => trim((string)($meta['in_reply_to'] ?? '')) ?: null,
        ':references_header' => trim((string)($meta['references_header'] ?? '')) ?: null,
        ':message'           => trim($message),
    ];

    if (!empty($createdAt)) {
        $params[':created_at'] = $createdAt;
    }

    $stmt->execute($params);
}

/**
 * Returns full ticket history.
 */
function ticket_log_service_for_ticket(PDO $pdo, int $ticketId): array
{
    ticket_log_service_ensure_table($pdo);

    $stmt = $pdo->prepare(
        'SELECT
            id,
            ticket_id,
            action,
            sender_email,
            subject,
            message_id,
            in_reply_to,
            references_header,
            message,
            created_at
         FROM ticket_logs
         WHERE ticket_id = :ticket_id
         ORDER BY created_at ASC, id ASC'
    );

    $stmt->execute([
        ':ticket_id' => $ticketId
    ]);

    return $stmt->fetchAll();
}

/**
 * Returns email conversation thread.
 */
function ticket_log_service_email_thread(PDO $pdo, int $ticketId): array
{
    ticket_log_service_ensure_table($pdo);

    $stmt = $pdo->prepare(
        'SELECT
            id,
            ticket_id,
            action,
            sender_email,
            subject,
            message_id,
            in_reply_to,
            references_header,
            message,
            created_at
         FROM ticket_logs
         WHERE ticket_id = :ticket_id
           AND action IN (
                "email_received",
                "incoming_reply",
                "email_reply",
                "email_sent",
                "email_failed"
           )
         ORDER BY created_at ASC, id ASC'
    );

    $stmt->execute([
        ':ticket_id' => $ticketId
    ]);

    return $stmt->fetchAll();
}