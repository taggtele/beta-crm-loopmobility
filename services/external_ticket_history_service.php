<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/ticket_log_service.php';

function external_ticket_history_ensure_table(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    if ($pdo->inTransaction()) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS external_ticket_history (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            ticket_id BIGINT NOT NULL,
            external_ticket_id VARCHAR(255) NOT NULL,
            source_email VARCHAR(190) NULL,
            message_id VARCHAR(255) NULL,
            source VARCHAR(50) NOT NULL DEFAULT \'email\',
            first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at DATETIME NULL DEFAULT NULL,
            seen_count INT UNSIGNED NOT NULL DEFAULT 1,
            UNIQUE KEY uq_external_ticket_history_ticket_ref (ticket_id, external_ticket_id),
            INDEX idx_external_ticket_history_ticket (ticket_id),
            INDEX idx_external_ticket_history_ref (external_ticket_id),
            INDEX idx_external_ticket_history_source_email (source_email),
            CONSTRAINT fk_external_ticket_history_ticket
                FOREIGN KEY (ticket_id) REFERENCES tickets (ticket_id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $ready = true;
}

function external_ticket_history_normalize_id(?string $externalTicketId): ?string
{
    $externalTicketId = trim((string) $externalTicketId);

    return $externalTicketId !== '' ? mb_substr($externalTicketId, 0, 255) : null;
}

function external_ticket_history_record(PDO $pdo, int $ticketId, ?string $externalTicketId, ?string $sourceEmail = null, ?string $messageId = null, string $source = 'email'): void
{
    $externalTicketId = external_ticket_history_normalize_id($externalTicketId);
    if ($ticketId <= 0 || $externalTicketId === null) {
        return;
    }

    external_ticket_history_ensure_table($pdo);

    $sourceEmail = trim((string) $sourceEmail);
    $messageId = trim((string) $messageId);
    $source = trim($source) !== '' ? trim($source) : 'email';

    $stmt = $pdo->prepare(
        'INSERT INTO external_ticket_history (
            ticket_id,
            external_ticket_id,
            source_email,
            message_id,
            source,
            first_seen_at,
            last_seen_at,
            seen_count
        ) VALUES (
            :ticket_id,
            :external_ticket_id,
            :source_email,
            :message_id,
            :source,
            NOW(),
            NOW(),
            1
        )
        ON DUPLICATE KEY UPDATE
            source_email = COALESCE(VALUES(source_email), source_email),
            message_id = COALESCE(VALUES(message_id), message_id),
            source = VALUES(source),
            last_seen_at = NOW(),
            seen_count = seen_count + 1'
    );
    $stmt->execute([
        ':ticket_id' => $ticketId,
        ':external_ticket_id' => $externalTicketId,
        ':source_email' => $sourceEmail !== '' ? mb_substr($sourceEmail, 0, 190) : null,
        ':message_id' => $messageId !== '' ? mb_substr($messageId, 0, 255) : null,
        ':source' => mb_substr($source, 0, 50),
    ]);
}

function external_ticket_history_apply_to_ticket(PDO $pdo, int $ticketId, ?string $externalTicketId, ?string $sourceEmail = null, ?string $messageId = null): void
{
    $externalTicketId = external_ticket_history_normalize_id($externalTicketId);
    if ($ticketId <= 0 || $externalTicketId === null) {
        return;
    }

    external_ticket_history_record($pdo, $ticketId, $externalTicketId, $sourceEmail, $messageId);

    $stmt = $pdo->prepare('SELECT external_ticket_id FROM tickets WHERE ticket_id = :ticket_id LIMIT 1');
    $stmt->execute([':ticket_id' => $ticketId]);
    $currentExternalId = trim((string) $stmt->fetchColumn());

    if ($currentExternalId === '') {
        $updateStmt = $pdo->prepare(
            'UPDATE tickets
             SET external_ticket_id = :external_ticket_id
             WHERE ticket_id = :ticket_id
             AND (external_ticket_id IS NULL OR external_ticket_id = \'\')'
        );
        $updateStmt->execute([
            ':external_ticket_id' => $externalTicketId,
            ':ticket_id' => $ticketId,
        ]);

        ticket_log_service_add(
            $pdo,
            $ticketId,
            'external_ticket_detected',
            'External ticket ID captured from incoming email: ' . $externalTicketId . '.'
        );
        return;
    }

    if (strcasecmp($currentExternalId, $externalTicketId) !== 0) {
        ticket_log_service_add(
            $pdo,
            $ticketId,
            'external_ticket_conflict',
            'Incoming email contained external ticket ID ' . $externalTicketId . ', but ticket already has ' . $currentExternalId . '. Existing ticket value was preserved and the new value was stored in history.'
        );
    }
}

function external_ticket_history_for_ticket(PDO $pdo, int $ticketId): array
{
    if ($ticketId <= 0) {
        return [];
    }

    external_ticket_history_ensure_table($pdo);

    $stmt = $pdo->prepare(
        'SELECT id, ticket_id, external_ticket_id, source_email, message_id, source, first_seen_at, last_seen_at, seen_count
         FROM external_ticket_history
         WHERE ticket_id = :ticket_id
         ORDER BY COALESCE(last_seen_at, first_seen_at) DESC, id DESC'
    );
    $stmt->execute([':ticket_id' => $ticketId]);

    return $stmt->fetchAll();
}
