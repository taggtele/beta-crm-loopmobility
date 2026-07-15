<?php
require_once __DIR__ . '/../includes/auth.php';

function party_service_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS parties (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(190) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT \'active\',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_parties_name (name),
            INDEX idx_parties_status_name (status, name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $partyColumns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM parties')->fetchAll() as $column) {
        $field = (string) ($column['Field'] ?? '');
        if ($field !== '') {
            $partyColumns[$field] = true;
        }
    }
    if (!isset($partyColumns['country'])) {
        $pdo->exec('ALTER TABLE parties ADD COLUMN country VARCHAR(120) NULL DEFAULT NULL AFTER name');
    }

    $partyIndexes = [];
    foreach ($pdo->query('SHOW INDEX FROM parties')->fetchAll() as $index) {
        $key = (string) ($index['Key_name'] ?? '');
        if ($key !== '') {
            $partyIndexes[$key] = true;
        }
    }
    if (!isset($partyIndexes['uq_parties_name'])) {
        try {
            $pdo->exec('ALTER TABLE parties ADD UNIQUE KEY uq_parties_name (name)');
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
        }
    }

    $partyColumns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM parties')->fetchAll() as $column) {
        $field = (string) ($column['Field'] ?? '');
        if ($field !== '') {
            $partyColumns[$field] = true;
        }
    }
    if (!isset($partyColumns['deleted_at'])) {
        $pdo->exec('ALTER TABLE parties ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL AFTER updated_at');
    }

    $ticketColumns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM tickets')->fetchAll() as $column) {
        $ticketColumns[(string) ($column['Field'] ?? '')] = true;
    }

    if (!isset($ticketColumns['initiator_party_id'])) {
        $pdo->exec('ALTER TABLE tickets ADD COLUMN initiator_party_id INT UNSIGNED NULL AFTER source');
    }

    if (!isset($ticketColumns['assigned_vendor_id'])) {
        $pdo->exec('ALTER TABLE tickets ADD COLUMN assigned_vendor_id INT UNSIGNED NULL AFTER initiator_party_id');
    }

    if (!isset($ticketColumns['internal_ticket_id'])) {
        $pdo->exec('ALTER TABLE tickets ADD COLUMN internal_ticket_id VARCHAR(50) NULL AFTER assigned_vendor_id');
    }

    if (!isset($ticketColumns['updated_at'])) {
        $pdo->exec(
            'ALTER TABLE tickets
             ADD COLUMN updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER closed_at'
        );
        $pdo->exec(
            'UPDATE tickets
             SET updated_at = GREATEST(created_at, COALESCE(closed_at, created_at))'
        );
    }

    if (!isset($ticketColumns['vendor_email_initiated'])) {
        $pdo->exec('ALTER TABLE tickets ADD COLUMN vendor_email_initiated TINYINT(1) NOT NULL DEFAULT 0 AFTER assigned_vendor_id');
        $pdo->exec(
            'UPDATE tickets t
             SET vendor_email_initiated = 1
             WHERE t.vendor_email_initiated = 0
               AND t.assigned_vendor_id > 0
               AND EXISTS (
                   SELECT 1
                   FROM email_outbox_log e
                   WHERE e.ticket_id = t.ticket_id
                     AND e.status = \'sent\'
                     AND LOWER(e.to_email) IN (
                         SELECT LOWER(pe.email)
                         FROM party_emails pe
                         WHERE pe.party_id = t.assigned_vendor_id
                     )
               )'
        );
    }

    $ready = true;
}

function party_service_normalize_email(string $email): string
{
    return strtolower(trim($email));
}

function party_service_find_by_email(PDO $pdo, string $email): ?array
{
    party_service_ensure_schema($pdo);

    $email = party_service_normalize_email($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT p.id, p.name, p.status, pe.email, pe.is_primary
         FROM party_emails pe
         INNER JOIN parties p ON p.id = pe.party_id
         WHERE LOWER(pe.email) = LOWER(:email)
         LIMIT 1'
    );
    $stmt->execute([':email' => $email]);
    $party = $stmt->fetch();

    if (!$party || strtolower((string) ($party['status'] ?? '')) !== 'active') {
        return null;
    }

    return $party;
}

function party_service_create(PDO $pdo, string $name, string $status = 'active'): int
{
    party_service_ensure_schema($pdo);

    $name = trim($name);
    $status = strtolower(trim($status));

    if ($name === '') {
        throw new RuntimeException('Party name is required.');
    }

    if (!in_array($status, ['active', 'inactive'], true)) {
        throw new RuntimeException('Invalid party status.');
    }

    $existing = $pdo->prepare('SELECT id FROM parties WHERE LOWER(name) = LOWER(:name) LIMIT 1');
    $existing->execute([':name' => $name]);
    if ($existing->fetchColumn()) {
        throw new RuntimeException('A party with this name already exists.');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO parties (name, status, created_at)
         VALUES (:name, :status, NOW())'
    );
    $stmt->execute([
        ':name' => $name,
        ':status' => $status,
    ]);

    return (int) $pdo->lastInsertId();
}

function party_service_update(PDO $pdo, int $partyId, string $name, string $status): void
{
    party_service_ensure_schema($pdo);

    $name = trim($name);
    $status = strtolower(trim($status));

    if ($partyId <= 0 || $name === '' || !in_array($status, ['active', 'inactive'], true)) {
        throw new RuntimeException('Valid party details are required.');
    }

    $existing = $pdo->prepare('SELECT id FROM parties WHERE LOWER(name) = LOWER(:name) AND id != :id LIMIT 1');
    $existing->execute([':name' => $name, ':id' => $partyId]);
    if ($existing->fetchColumn()) {
        throw new RuntimeException('A party with this name already exists.');
    }

    $stmt = $pdo->prepare(
        'UPDATE parties
         SET name = :name,
             status = :status
         WHERE id = :id'
    );
    $stmt->execute([
        ':name' => $name,
        ':status' => $status,
        ':id' => $partyId,
    ]);
}

function party_service_soft_delete(PDO $pdo, int $partyId): void
{
    party_service_ensure_schema($pdo);

    if ($partyId <= 0) {
        throw new RuntimeException('Valid party is required.');
    }

    $stmt = $pdo->prepare('UPDATE parties SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL');
    $stmt->execute([':id' => $partyId]);
}

function party_service_add_email(PDO $pdo, int $partyId, string $email, bool $isPrimary = false): void
{
    party_service_ensure_schema($pdo);

    $email = party_service_normalize_email($email);
    if ($partyId <= 0 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Valid party and email are required.');
    }

    $partyStmt = $pdo->prepare('SELECT id FROM parties WHERE id = :id LIMIT 1');
    $partyStmt->execute([':id' => $partyId]);
    if (!$partyStmt->fetchColumn()) {
        throw new RuntimeException('Party not found.');
    }

    $manageTransaction = !$pdo->inTransaction();
    if ($manageTransaction) {
        $pdo->beginTransaction();
    }
    try {
        if ($isPrimary) {
            $clearStmt = $pdo->prepare('UPDATE party_emails SET is_primary = 0 WHERE party_id = :party_id');
            $clearStmt->execute([':party_id' => $partyId]);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO party_emails (party_id, email, is_primary, created_at)
             VALUES (:party_id, :email, :is_primary, NOW())'
        );
        $stmt->execute([
            ':party_id' => $partyId,
            ':email' => $email,
            ':is_primary' => $isPrimary ? 1 : 0,
        ]);

        if ($manageTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $throwable) {
        if ($manageTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($throwable instanceof PDOException && $throwable->getCode() === '23000') {
            throw new RuntimeException('This email is already registered with a party.');
        }
        throw $throwable;
    }
}

function party_service_save_cc_emails(PDO $pdo, int $partyId, string $primaryEmail, array $ccEmails): void
{
    party_service_ensure_schema($pdo);

    if ($partyId <= 0) {
        return;
    }

    $primaryNorm = strtolower(trim($primaryEmail));
    $seen = [];
    if ($primaryNorm !== '' && filter_var($primaryNorm, FILTER_VALIDATE_EMAIL)) {
        $seen[$primaryNorm] = true;
    }

    foreach ($ccEmails as $email) {
        $email = trim((string) $email);
        if ($email === '') {
            continue;
        }
        $key = strtolower($email);
        if ($key === $primaryNorm) {
            continue;
        }
        if (isset($seen[$key])) {
            continue;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        $seen[$key] = true;
        try {
            party_service_add_email($pdo, $partyId, $email, false);
        } catch (RuntimeException $e) {
            if (strpos($e->getMessage(), 'already registered') === false) {
                throw $e;
            }
        }
    }
}

function party_service_list(PDO $pdo, string $search = '', int $limit = 150, string $status = 'all'): array
{
    party_service_ensure_schema($pdo);

    $where = ' WHERE p.deleted_at IS NULL';
    $params = [];
    $search = trim($search);
    $status = strtolower(trim($status));

    if ($status === 'active' || $status === 'inactive') {
        $where .= ' AND p.status = :party_status';
        $params[':party_status'] = $status;
    }

    if ($search !== '') {
        $where .= ' AND (p.name LIKE :search_name OR pe.email LIKE :search_email)';
        $params[':search_name'] = '%' . $search . '%';
        $params[':search_email'] = '%' . $search . '%';
    }

    $stmt = $pdo->prepare(
        'SELECT
            p.id,
            p.name,
            p.status,
            p.created_at,
            p.updated_at,
            GROUP_CONCAT(pe.email ORDER BY pe.is_primary DESC, pe.email ASC SEPARATOR \', \') AS emails,
            MAX(CASE WHEN pe.is_primary = 1 THEN pe.email ELSE NULL END) AS primary_email
         FROM parties p
         LEFT JOIN party_emails pe ON pe.party_id = p.id' . $where . '
         GROUP BY p.id, p.name, p.status, p.created_at, p.updated_at
         ORDER BY p.created_at DESC, p.id DESC
         LIMIT ' . max(1, min(250, $limit))
    );
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function party_service_active_options(PDO $pdo): array
{
    party_service_ensure_schema($pdo);

    $stmt = $pdo->prepare(
        'SELECT
            p.id,
            p.name,
            p.country,
            p.status,
            GROUP_CONCAT(pe.email ORDER BY pe.is_primary DESC, pe.email ASC SEPARATOR \', \') AS emails
         FROM parties p
         LEFT JOIN party_emails pe ON pe.party_id = p.id
         WHERE p.status = :status
         AND p.deleted_at IS NULL
         GROUP BY p.id, p.name, p.country, p.status
         ORDER BY p.name ASC
         LIMIT 250'
    );
    $stmt->execute([':status' => 'active']);

    return $stmt->fetchAll();
}

/**
 * @return list<array{email: string, is_primary: int}>
 */
function party_service_update_emails(PDO $pdo, int $partyId, string $primaryEmail, array $ccEmails): void
{
    party_service_ensure_schema($pdo);

    $partyId = (int) $partyId;
    if ($partyId <= 0) {
        throw new RuntimeException('Valid party is required.');
    }

    $primaryNorm = strtolower(trim($primaryEmail));
    $primaryTrimmed = trim($primaryEmail);
    if ($primaryTrimmed === '' || !filter_var($primaryTrimmed, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Valid primary email is required.');
    }

    $manageTransaction = !$pdo->inTransaction();
    if ($manageTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $deleteStmt = $pdo->prepare('DELETE FROM party_emails WHERE party_id = :party_id');
        $deleteStmt->execute([':party_id' => $partyId]);

        $insertStmt = $pdo->prepare(
            'INSERT INTO party_emails (party_id, email, is_primary, created_at)
             VALUES (:party_id, :email, :is_primary, NOW())'
        );

        try {
            $insertStmt->execute([
                ':party_id' => $partyId,
                ':email' => $primaryTrimmed,
                ':is_primary' => 1,
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new RuntimeException('This email is already registered with a party.');
            }
            throw $e;
        }

        $seen = [];
        $seen[$primaryNorm] = true;

        foreach ($ccEmails as $cc) {
            $cc = trim((string) $cc);
            if ($cc === '') {
                continue;
            }
            $key = strtolower($cc);
            if ($key === $primaryNorm || isset($seen[$key])) {
                continue;
            }
            if (!filter_var($cc, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $seen[$key] = true;
            try {
                $insertStmt->execute([
                    ':party_id' => $partyId,
                    ':email' => $cc,
                    ':is_primary' => 0,
                ]);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    continue;
                }
                throw $e;
            }
        }

        if ($manageTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $throwable) {
        if ($manageTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $throwable;
    }
}

function party_service_get_emails(PDO $pdo, int $partyId): array
{
    party_service_ensure_schema($pdo);

    $stmt = $pdo->prepare(
        'SELECT id, email, is_primary, created_at
         FROM party_emails
         WHERE party_id = :party_id
         ORDER BY is_primary DESC, email ASC'
    );
    $stmt->execute([':party_id' => $partyId]);

    return $stmt->fetchAll();
}

function party_service_party_emails_ordered(PDO $pdo, int $partyId): array
{
    party_service_ensure_schema($pdo);

    if ($partyId <= 0) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT pe.email, pe.is_primary
         FROM party_emails pe
         INNER JOIN parties p ON p.id = pe.party_id
         WHERE pe.party_id = :party_id
         AND p.status = :status
         ORDER BY pe.is_primary DESC, pe.email ASC'
    );
    $stmt->execute([
        ':party_id' => $partyId,
        ':status' => 'active',
    ]);

    return $stmt->fetchAll() ?: [];
}

function party_service_get_active_party(PDO $pdo, int $partyId): ?array
{
    party_service_ensure_schema($pdo);

    if ($partyId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id, name, status, country
         FROM parties
         WHERE id = :id
         AND status = :status
         LIMIT 1'
    );
    $stmt->execute([
        ':id' => $partyId,
        ':status' => 'active',
    ]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * @return list<array{id: int, name: string, primary_email: string}>
 */
function party_service_search_active(PDO $pdo, string $query, int $limit = 25): array
{
    party_service_ensure_schema($pdo);

    $query = trim($query);
    if ($query === '') {
        return [];
    }

    $limit = max(1, min(50, $limit));
    $like = '%' . $query . '%';

    $stmt = $pdo->prepare(
        'SELECT DISTINCT p.id, p.name, p.country,
            COALESCE(
                (SELECT pe2.email FROM party_emails pe2 WHERE pe2.party_id = p.id AND pe2.is_primary = 1 LIMIT 1),
                (SELECT MIN(pe3.email) FROM party_emails pe3 WHERE pe3.party_id = p.id)
            ) AS primary_email
         FROM parties p
         LEFT JOIN party_emails pe ON pe.party_id = p.id
         WHERE p.status = :status
         AND (
            p.name LIKE :q1
            OR pe.email LIKE :q2
            OR CAST(p.id AS CHAR) = :q_exact
            OR (p.country IS NOT NULL AND p.country LIKE :q_country)
         )
         ORDER BY p.name ASC
         LIMIT ' . $limit
    );
    $stmt->execute([
        ':status' => 'active',
        ':q1' => $like,
        ':q2' => $like,
        ':q_exact' => $query,
        ':q_country' => $like,
    ]);

    return $stmt->fetchAll() ?: [];
}

/**
 * @return list<string>
 */
function party_service_ticket_country_options(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    party_service_ensure_schema($pdo);
    require_once __DIR__ . '/../includes/ticket_country_list.php';

    $out = ticket_country_list_standard();

    try {
        $stmt = $pdo->query(
            "SELECT DISTINCT TRIM(country) AS c FROM parties WHERE country IS NOT NULL AND TRIM(country) <> ''"
        );
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $row) {
            if (is_string($row) && trim($row) !== '') {
                $out[] = trim($row);
            }
        }
    } catch (Throwable) {
        /* ignore */
    }

    try {
        $stmt = $pdo->query(
            "SELECT DISTINCT TRIM(country) AS c FROM tickets WHERE country IS NOT NULL AND TRIM(country) <> '' AND deleted_at IS NULL AND is_deleted = 0"
        );
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $row) {
            if (is_string($row) && trim($row) !== '') {
                $out[] = trim($row);
            }
        }
    } catch (Throwable) {
        /* ignore */
    }

    $out = array_values(array_unique(array_map(static fn(string $s): string => trim($s), $out)));
    sort($out, SORT_STRING | SORT_FLAG_CASE);
    $cache = $out;

    return $cache;
}

/**
 * Resolve ticket country: known suggestions use canonical casing; unknown non-empty values pass through.
 */
function party_service_ticket_country_canonical(PDO $pdo, string $input): ?string
{
    $input = trim($input);
    if ($input === '' || strlen($input) > 120) {
        return null;
    }
    foreach (party_service_ticket_country_options($pdo) as $opt) {
        if (strcasecmp($input, $opt) === 0) {
            return $opt;
        }
    }

    return $input;
}

function party_service_ticket_customer_vendor_conflict_message(): string
{
    return 'The customer party and the assigned vendor cannot be the same organisation. '
        . 'Use a different party for one of those roles.';
}

function party_service_ticket_columns(PDO $pdo): array
{
    party_service_ensure_schema($pdo);

    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM tickets')->fetchAll() as $column) {
        $field = (string) ($column['Field'] ?? '');
        if ($field !== '') {
            $columns[$field] = true;
        }
    }

    return $columns;
}

function party_service_ticket_select_columns(PDO $pdo): string
{
    $columns = party_service_ticket_columns($pdo);

    return (isset($columns['internal_ticket_id']) ? 't.internal_ticket_id' : 'NULL AS internal_ticket_id') . ',
            ' . (isset($columns['initiator_party_id']) ? 't.initiator_party_id' : 'NULL AS initiator_party_id') . ',
            ' . (isset($columns['assigned_vendor_id']) ? 't.assigned_vendor_id' : 'NULL AS assigned_vendor_id') . ',
            ' . (isset($columns['vendor_email_initiated']) ? 't.vendor_email_initiated' : '0 AS vendor_email_initiated') . ',
            ' . (isset($columns['send_auto_acknowledgement']) ? 't.send_auto_acknowledgement' : '1 AS send_auto_acknowledgement');
}

function party_service_set_ticket_internal_id(PDO $pdo, int $ticketId, string $internalTicketId): void
{
    party_service_ensure_schema($pdo);

    if ($ticketId <= 0 || trim($internalTicketId) === '') {
        return;
    }

    $stmt = $pdo->prepare(
        'UPDATE tickets
         SET internal_ticket_id = :internal_ticket_id
         WHERE ticket_id = :ticket_id
         AND (internal_ticket_id IS NULL OR internal_ticket_id = \'\')'
    );
    $stmt->execute([
        ':internal_ticket_id' => trim($internalTicketId),
        ':ticket_id' => $ticketId,
    ]);
}
