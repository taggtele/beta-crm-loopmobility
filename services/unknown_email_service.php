<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/party_service.php';

function unknown_email_service_ensure_table(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    party_service_ensure_schema($pdo);

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS unknown_emails (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            message_id VARCHAR(255) NULL,
            from_email VARCHAR(190) NOT NULL,
            from_name VARCHAR(190) NULL,
            subject TEXT NULL,
            body LONGTEXT NULL,
            raw_message LONGTEXT NULL,
            received_at DATETIME NULL,
            review_status VARCHAR(20) NOT NULL DEFAULT \'pending\',
            converted_party_id INT UNSIGNED NULL,
            reviewed_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_unknown_emails_message_id (message_id),
            INDEX idx_unknown_emails_from_email (from_email),
            INDEX idx_unknown_emails_status_created (review_status, created_at),
            CONSTRAINT fk_unknown_emails_party
                FOREIGN KEY (converted_party_id) REFERENCES parties (id)
                ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $ready = true;
}

function unknown_email_service_store(PDO $pdo, array $message): void
{
    unknown_email_service_ensure_table($pdo);

    $messageId = trim((string) ($message['message_id'] ?? ''));
    $fromEmail = party_service_normalize_email((string) ($message['from_email'] ?? ''));

    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        $fromEmail = 'unknown@example.invalid';
    }

    $stmt = $pdo->prepare(
        'INSERT INTO unknown_emails (
            message_id,
            from_email,
            from_name,
            subject,
            body,
            raw_message,
            received_at,
            review_status,
            created_at
        ) VALUES (
            :message_id,
            :from_email,
            :from_name,
            :subject,
            :body,
            :raw_message,
            :received_at,
            :review_status,
            NOW()
        )
        ON DUPLICATE KEY UPDATE
            from_email = VALUES(from_email),
            from_name = VALUES(from_name),
            subject = VALUES(subject),
            body = VALUES(body),
            raw_message = VALUES(raw_message),
            received_at = VALUES(received_at)'
    );
    $stmt->execute([
        ':message_id' => $messageId !== '' ? $messageId : null,
        ':from_email' => $fromEmail,
        ':from_name' => trim((string) ($message['from_name'] ?? '')) ?: null,
        ':subject' => trim((string) ($message['subject'] ?? '')) ?: null,
        ':body' => trim((string) ($message['body'] ?? '')) ?: null,
        ':raw_message' => trim((string) ($message['raw_message'] ?? '')) ?: null,
        ':received_at' => trim((string) ($message['received_at'] ?? '')) ?: null,
        ':review_status' => 'pending',
    ]);
}

function unknown_email_service_list(PDO $pdo, string $status = 'pending', string $search = '', int $limit = 100): array
{
    unknown_email_service_ensure_table($pdo);

    $where = ' WHERE 1=1';
    $params = [];

    if (in_array($status, ['pending', 'converted', 'ignored'], true)) {
        $where .= ' AND ue.review_status = :status';
        $params[':status'] = $status;
    }

    $search = trim($search);
    if ($search !== '') {
        $where .= ' AND (ue.from_email LIKE :search1 OR ue.from_name LIKE :search2 OR ue.subject LIKE :search3 OR ue.body LIKE :search4)';
        $searchParam = '%' . $search . '%';
        $params[':search1'] = $searchParam;
        $params[':search2'] = $searchParam;
        $params[':search3'] = $searchParam;
        $params[':search4'] = $searchParam;
    }

    $stmt = $pdo->prepare(
        'SELECT ue.*, p.name AS converted_party_name
         FROM unknown_emails ue
         LEFT JOIN parties p ON p.id = ue.converted_party_id' . $where . '
         ORDER BY COALESCE(ue.received_at, ue.created_at) DESC, ue.id DESC
         LIMIT ' . max(1, min(250, $limit))
    );
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function unknown_email_service_convert_to_party(PDO $pdo, int $unknownEmailId, string $partyName): int
{
    unknown_email_service_ensure_table($pdo);

    $stmt = $pdo->prepare(
        'SELECT id, from_email, from_name, review_status
         FROM unknown_emails
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $unknownEmailId]);
    $unknown = $stmt->fetch();

    if (!$unknown) {
        throw new RuntimeException('Unknown email record not found.');
    }

    $email = party_service_normalize_email((string) $unknown['from_email']);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Unknown email is not valid.');
    }

    $partyName = trim($partyName);
    if ($partyName === '') {
        $partyName = trim((string) ($unknown['from_name'] ?? '')) ?: $email;
    }

    $pdo->beginTransaction();
    try {
        $party = party_service_find_by_email($pdo, $email);
        if ($party) {
            $partyId = (int) $party['id'];
            // Reactivate if the party exists but is inactive
            $pdo->prepare('UPDATE parties SET status = ? WHERE id = ? AND status = ?')
                ->execute(['active', $partyId, 'inactive']);
        } else {
            $partyId = party_service_create($pdo, $partyName, 'active');
            party_service_add_email($pdo, $partyId, $email, true);
        }

        $updateStmt = $pdo->prepare(
            'UPDATE unknown_emails
             SET review_status = :review_status,
                 converted_party_id = :party_id,
                 reviewed_at = NOW()
             WHERE id = :id'
        );
        $updateStmt->execute([
            ':review_status' => 'converted',
            ':party_id' => $partyId,
            ':id' => $unknownEmailId,
        ]);

        $pdo->commit();
        return $partyId;
    } catch (Throwable $throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $throwable;
    }
}
