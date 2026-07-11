<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/ticket_log_service.php';

function vendor_am_service_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS assistant_managers (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            email VARCHAR(190) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_assistant_managers_email (email),
            INDEX idx_assistant_managers_active (is_active, name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS vendor_am_mapping (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            vendor_name VARCHAR(190) NULL,
            vendor_email VARCHAR(190) NOT NULL,
            assistant_manager_id INT UNSIGNED NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_vendor_am_mapping_email (vendor_email),
            INDEX idx_vendor_am_mapping_vendor_name (vendor_name),
            INDEX idx_vendor_am_mapping_active (is_active),
            INDEX idx_vendor_am_mapping_am (assistant_manager_id),
            CONSTRAINT fk_vendor_am_mapping_am
                FOREIGN KEY (assistant_manager_id) REFERENCES assistant_managers (id)
                ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    try {
        $colStmt = $pdo->query("SHOW COLUMNS FROM vendor_am_mapping LIKE 'business_manager_email'");
        if ($colStmt && $colStmt->rowCount() === 0) {
            $pdo->exec(
                'ALTER TABLE vendor_am_mapping ADD COLUMN business_manager_email VARCHAR(190) NULL AFTER assistant_manager_id'
            );
        }
    } catch (Throwable $throwable) {
        // Mapping table may be missing in rare partial installs; ensure_schema will retry.
    }

    try {
        $colParty = $pdo->query("SHOW COLUMNS FROM vendor_am_mapping LIKE 'party_id'");
        if ($colParty && $colParty->rowCount() === 0) {
            $pdo->exec(
                'ALTER TABLE vendor_am_mapping ADD COLUMN party_id INT UNSIGNED NULL AFTER id,
                 ADD INDEX idx_vendor_am_mapping_party_id (party_id)'
            );
        }
    } catch (Throwable $throwable) {
        // Ignore if ALTER not permitted.
    }

    $ready = true;
}

function vendor_am_service_normalize_email(string $email): string
{
    return strtolower(trim($email));
}

function vendor_am_service_active_assistant_managers(PDO $pdo): array
{
    vendor_am_service_ensure_schema($pdo);

    $stmt = $pdo->prepare(
        'SELECT id, name, email, is_active
         FROM assistant_managers
         WHERE is_active = 1
         ORDER BY name ASC, email ASC'
    );
    $stmt->execute();

    return $stmt->fetchAll();
}

function vendor_am_service_all_assistant_managers(PDO $pdo): array
{
    vendor_am_service_ensure_schema($pdo);

    $stmt = $pdo->prepare(
        'SELECT id, name, email, is_active, created_at, updated_at
         FROM assistant_managers
         ORDER BY is_active DESC, name ASC, email ASC'
    );
    $stmt->execute();

    return $stmt->fetchAll();
}

function vendor_am_service_assistant_manager_by_id(PDO $pdo, int $id): ?array
{
    vendor_am_service_ensure_schema($pdo);

    if ($id <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id, name, email, is_active, created_at, updated_at
         FROM assistant_managers
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function vendor_am_service_save_assistant_manager(PDO $pdo, string $name, string $email, bool $isActive = true, int $id = 0): void
{
    vendor_am_service_ensure_schema($pdo);

    $name = trim($name);
    $email = vendor_am_service_normalize_email($email);

    if ($name === '') {
        throw new RuntimeException('Assistant Manager name is required.');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Assistant Manager email must be valid.');
    }

    if ($id > 0) {
        $stmt = $pdo->prepare(
            'UPDATE assistant_managers
             SET name = :name,
                 email = :email,
                 is_active = :is_active
             WHERE id = :id'
        );
        $stmt->execute([
            ':name' => $name,
            ':email' => $email,
            ':is_active' => $isActive ? 1 : 0,
            ':id' => $id,
        ]);
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO assistant_managers (name, email, is_active, created_at)
         VALUES (:name, :email, :is_active, NOW())
         ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            is_active = VALUES(is_active),
            updated_at = NOW()'
    );
    $stmt->execute([
        ':name' => $name,
        ':email' => $email,
        ':is_active' => $isActive ? 1 : 0,
    ]);
}

function vendor_am_service_delete_assistant_manager(PDO $pdo, int $id): void
{
    vendor_am_service_ensure_schema($pdo);

    if ($id <= 0) {
        throw new RuntimeException('Assistant Manager not found.');
    }

    $stmt = $pdo->prepare('DELETE FROM assistant_managers WHERE id = :id');
    $stmt->execute([':id' => $id]);
}

function vendor_am_service_save_mapping(PDO $pdo, string $vendorName, string $vendorEmail, ?int $assistantManagerId, bool $isActive = true, int $id = 0, ?string $businessManagerEmail = null, ?int $partyId = null): void
{
    vendor_am_service_ensure_schema($pdo);

    $vendorName = trim($vendorName);
    $vendorEmail = vendor_am_service_normalize_email($vendorEmail);
    $assistantManagerId = ($assistantManagerId ?? 0) > 0 ? (int) $assistantManagerId : null;
    $partyIdForRow = ($partyId ?? 0) > 0 ? (int) $partyId : null;

    $bmRaw = $businessManagerEmail !== null ? trim((string) $businessManagerEmail) : '';
    $businessManagerEmailNorm = $bmRaw === '' ? null : vendor_am_service_normalize_email($bmRaw);

    if (!filter_var($vendorEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Party email must be valid.');
    }

    if ($partyIdForRow !== null) {
        require_once __DIR__ . '/party_service.php';
        $partyRow = party_service_get_active_party($pdo, $partyIdForRow);
        if (!$partyRow) {
            throw new RuntimeException('Selected party not found or inactive.');
        }
        $allowedEmails = [];
        foreach (party_service_party_emails_ordered($pdo, $partyIdForRow) as $emailRow) {
            $e = vendor_am_service_normalize_email((string) ($emailRow['email'] ?? ''));
            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) {
                $allowedEmails[$e] = true;
            }
        }
        if ($allowedEmails === []) {
            throw new RuntimeException('Selected party has no registered email addresses. Add one on the Parties page first.');
        }
        if (!isset($allowedEmails[$vendorEmail])) {
            throw new RuntimeException('Party email must match one of the emails registered for the selected party.');
        }
        $vendorName = trim((string) ($partyRow['name'] ?? ''));
    }

    if ($id === 0 && $partyIdForRow === null) {
        throw new RuntimeException('Select a party for this mapping.');
    }

    if ($businessManagerEmailNorm !== null) {
        if (!filter_var($businessManagerEmailNorm, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Business Manager email must be valid.');
        }
        if ($businessManagerEmailNorm === $vendorEmail) {
            throw new RuntimeException('Business Manager email cannot be the same as the party email.');
        }
    }

    $amEmailNorm = null;
    if ($assistantManagerId !== null) {
        $amStmt = $pdo->prepare('SELECT id, email FROM assistant_managers WHERE id = :id LIMIT 1');
        $amStmt->execute([':id' => $assistantManagerId]);
        $amRow = $amStmt->fetch();
        if (!$amRow) {
            throw new RuntimeException('Selected Assistant Manager does not exist.');
        }
        $amEmailNorm = vendor_am_service_normalize_email((string) ($amRow['email'] ?? ''));
        if ($businessManagerEmailNorm !== null && $amEmailNorm !== '' && $businessManagerEmailNorm === $amEmailNorm) {
            throw new RuntimeException('Business Manager email cannot be the same as the Assistant Manager email.');
        }
    }

    if ($id > 0) {
        $duplicateStmt = $pdo->prepare(
            'SELECT id
             FROM vendor_am_mapping
             WHERE vendor_email = :vendor_email
             AND id <> :id
             LIMIT 1'
        );
        $duplicateStmt->execute([':vendor_email' => $vendorEmail, ':id' => $id]);
        if ($duplicateStmt->fetchColumn()) {
            throw new RuntimeException('Another mapping already uses this party email.');
        }

        $stmt = $pdo->prepare(
            'UPDATE vendor_am_mapping
             SET party_id = :party_id,
                 vendor_name = :vendor_name,
                 vendor_email = :vendor_email,
                 assistant_manager_id = :assistant_manager_id,
                 business_manager_email = :business_manager_email,
                 is_active = :is_active
             WHERE id = :id'
        );
        $stmt->execute([
            ':party_id' => $partyIdForRow,
            ':vendor_name' => $vendorName !== '' ? $vendorName : null,
            ':vendor_email' => $vendorEmail,
            ':assistant_manager_id' => $assistantManagerId,
            ':business_manager_email' => $businessManagerEmailNorm,
            ':is_active' => $isActive ? 1 : 0,
            ':id' => $id,
        ]);
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO vendor_am_mapping (party_id, vendor_name, vendor_email, assistant_manager_id, business_manager_email, is_active, created_at)
         VALUES (:party_id, :vendor_name, :vendor_email, :assistant_manager_id, :business_manager_email, :is_active, NOW())
         ON DUPLICATE KEY UPDATE
            party_id = VALUES(party_id),
            vendor_name = VALUES(vendor_name),
            assistant_manager_id = VALUES(assistant_manager_id),
            business_manager_email = VALUES(business_manager_email),
            is_active = VALUES(is_active),
            updated_at = NOW()'
    );
    $stmt->execute([
        ':party_id' => $partyIdForRow,
        ':vendor_name' => $vendorName !== '' ? $vendorName : null,
        ':vendor_email' => $vendorEmail,
        ':assistant_manager_id' => $assistantManagerId,
        ':business_manager_email' => $businessManagerEmailNorm,
        ':is_active' => $isActive ? 1 : 0,
    ]);
}

function vendor_am_service_mapping_by_id(PDO $pdo, int $id): ?array
{
    vendor_am_service_ensure_schema($pdo);

    if ($id <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT
            vam.id,
            vam.party_id,
            vam.vendor_name,
            vam.vendor_email,
            vam.assistant_manager_id,
            vam.business_manager_email,
            vam.is_active AS mapping_active,
            vam.updated_at,
            vam.created_at,
            am.name AS am_name,
            am.email AS am_email,
            am.is_active AS am_active
         FROM vendor_am_mapping vam
         LEFT JOIN assistant_managers am ON am.id = vam.assistant_manager_id
         WHERE vam.id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function vendor_am_service_delete_mapping(PDO $pdo, int $id): void
{
    vendor_am_service_ensure_schema($pdo);

    if ($id <= 0) {
        throw new RuntimeException('Party mapping not found.');
    }

    $stmt = $pdo->prepare('DELETE FROM vendor_am_mapping WHERE id = :id');
    $stmt->execute([':id' => $id]);
}

function vendor_am_service_mappings(PDO $pdo, string $search = '', int $limit = 100, string $status = 'all'): array
{
    vendor_am_service_ensure_schema($pdo);

    $where = ' WHERE 1=1';
    $params = [];

    $search = trim($search);
    $status = strtolower(trim($status));
    if ($status === 'active') {
        $where .= ' AND vam.is_active = 1';
    } elseif ($status === 'inactive') {
        $where .= ' AND vam.is_active = 0';
    } elseif ($status === 'no_am') {
        $where .= ' AND vam.assistant_manager_id IS NULL';
    }

    if ($search !== '') {
        $where .= ' AND (
            vam.vendor_name LIKE :search_vendor_name
            OR vam.vendor_email LIKE :search_vendor_email
            OR vam.business_manager_email LIKE :search_bm_email
            OR am.name LIKE :search_am_name
            OR am.email LIKE :search_am_email
        )';
        $params[':search_vendor_name'] = '%' . $search . '%';
        $params[':search_vendor_email'] = '%' . $search . '%';
        $params[':search_bm_email'] = '%' . $search . '%';
        $params[':search_am_name'] = '%' . $search . '%';
        $params[':search_am_email'] = '%' . $search . '%';
    }

    $stmt = $pdo->prepare(
        'SELECT
            vam.id,
            vam.party_id,
            vam.vendor_name,
            vam.vendor_email,
            vam.assistant_manager_id,
            vam.business_manager_email,
            vam.is_active AS mapping_active,
            vam.updated_at,
            vam.created_at,
            am.name AS am_name,
            am.email AS am_email,
            am.is_active AS am_active
         FROM vendor_am_mapping vam
         LEFT JOIN assistant_managers am ON am.id = vam.assistant_manager_id' . $where . '
         ORDER BY vam.vendor_name ASC, vam.vendor_email ASC
         LIMIT ' . max(1, min(250, $limit))
    );
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function vendor_am_service_recent_vendor_candidates(PDO $pdo, int $limit = 50): array
{
    vendor_am_service_ensure_schema($pdo);

    $candidates = [];
    $add = static function (string $email, string $name = '') use (&$candidates): void {
        $email = vendor_am_service_normalize_email($email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        if (!isset($candidates[$email])) {
            $candidates[$email] = [
                'vendor_email' => $email,
                'vendor_name' => trim($name),
            ];
        }
    };

    try {
        $stmt = $pdo->query(
            'SELECT to_email
             FROM email_outbox_log
             WHERE to_email IS NOT NULL
             AND to_email <> \'\'
             ORDER BY id DESC
             LIMIT 150'
        );
        foreach ($stmt->fetchAll() as $row) {
            $add((string) ($row['to_email'] ?? ''));
        }
    } catch (Throwable $throwable) {
        // Candidate hints are optional; mapping management should still load.
    }

    try {
        $stmt = $pdo->query(
            'SELECT from_email
             FROM email_inbox_log
             WHERE from_email IS NOT NULL
             AND from_email <> \'\'
             ORDER BY id DESC
             LIMIT 150'
        );
        foreach ($stmt->fetchAll() as $row) {
            $add((string) ($row['from_email'] ?? ''));
        }
    } catch (Throwable $throwable) {
        // Candidate hints are optional; mapping management should still load.
    }

    return array_slice(array_values($candidates), 0, max(1, $limit));
}

function vendor_am_service_lookup_for_vendor_email(PDO $pdo, string $vendorEmail): ?array
{
    vendor_am_service_ensure_schema($pdo);

    $vendorEmail = vendor_am_service_normalize_email($vendorEmail);
    if (!filter_var($vendorEmail, FILTER_VALIDATE_EMAIL)) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT
            vam.id AS mapping_id,
            vam.vendor_name,
            vam.vendor_email,
            vam.business_manager_email,
            am.id AS assistant_manager_id,
            am.name AS am_name,
            am.email AS am_email,
            am.is_active AS am_active
         FROM vendor_am_mapping vam
         LEFT JOIN assistant_managers am ON am.id = vam.assistant_manager_id
         WHERE LOWER(vam.vendor_email) = LOWER(:vendor_email)
         AND vam.is_active = 1
         LIMIT 1'
    );
    $stmt->execute([':vendor_email' => $vendorEmail]);
    $mapping = $stmt->fetch();

    if (!$mapping || empty($mapping['assistant_manager_id']) || (int) ($mapping['am_active'] ?? 0) !== 1) {
        return null;
    }

    $amEmail = trim((string) ($mapping['am_email'] ?? ''));
    if (!filter_var($amEmail, FILTER_VALIDATE_EMAIL)) {
        return null;
    }

    return $mapping;
}

function vendor_am_service_apply_cc(PDO $pdo, string $vendorEmail, array $ccEmails, ?int $ticketId = null): array
{
    $mapping = vendor_am_service_lookup_for_vendor_email($pdo, $vendorEmail);
    if (!$mapping) {
        error_log('Party AM mapping warning: no active AM mapping for party email ' . vendor_am_service_normalize_email($vendorEmail));

        if (($ticketId ?? 0) > 0) {
            ticket_log_service_add(
                $pdo,
                (int) $ticketId,
                'vendor_am_warning',
                'No active Assistant Manager mapping found for party email ' . vendor_am_service_normalize_email($vendorEmail) . '. Email will be sent without AM CC.'
            );
        }

        return [
            'cc_emails' => $ccEmails,
            'mapping' => null,
            'warning' => 'No active Assistant Manager mapping found for party email.',
        ];
    }

    $amEmail = (string) $mapping['am_email'];
    $vendorNorm = vendor_am_service_normalize_email($vendorEmail);
    $keyed = [];
    foreach ($ccEmails as $email) {
        $email = trim((string) $email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $keyed[strtolower($email)] = $email;
        }
    }
    $keyed[strtolower($amEmail)] = $amEmail;

    $bmRaw = trim((string) ($mapping['business_manager_email'] ?? ''));
    $bmNorm = vendor_am_service_normalize_email($bmRaw);
    if ($bmRaw !== '' && filter_var($bmNorm, FILTER_VALIDATE_EMAIL) && $bmNorm !== $vendorNorm) {
        $keyed[strtolower($bmNorm)] = $bmNorm;
    }

    return [
        'cc_emails' => array_values($keyed),
        'mapping' => $mapping,
        'warning' => null,
    ];
}

/**
 * Resolve AM/BM CC targets for compose when a CRM party is selected (uses first active mapping on party emails, primary first).
 *
 * @return array{ok: bool, error?: string, party_id?: int, party_name?: string, to_email?: string, am_email?: string|null, bm_email?: string|null, cc?: list<string>}
 */
function vendor_am_service_resolve_party_mapping_for_compose(PDO $pdo, int $partyId): array
{
    vendor_am_service_ensure_schema($pdo);
    require_once __DIR__ . '/party_service.php';

    $party = party_service_get_active_party($pdo, $partyId);
    if (!$party) {
        return [
            'ok' => false,
            'error' => 'Party not found or inactive.',
        ];
    }

    $emailRows = party_service_party_emails_ordered($pdo, $partyId);
    $partyName = trim((string) ($party['name'] ?? ''));

    $toEmail = '';
    $amEmail = null;
    $bmEmail = null;
    $ccKeyed = [];

    foreach ($emailRows as $row) {
        $e = vendor_am_service_normalize_email((string) ($row['email'] ?? ''));
        if ($e === '' || !filter_var($e, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        $mapping = vendor_am_service_lookup_for_vendor_email($pdo, $e);
        if ($mapping) {
            $toEmail = $e;
            $am = trim((string) ($mapping['am_email'] ?? ''));
            $bm = trim((string) ($mapping['business_manager_email'] ?? ''));
            if (filter_var($am, FILTER_VALIDATE_EMAIL)) {
                $amEmail = vendor_am_service_normalize_email($am);
                $ccKeyed[strtolower($amEmail)] = $amEmail;
            }
            if ($bm !== '') {
                $bmNorm = vendor_am_service_normalize_email($bm);
                if (filter_var($bmNorm, FILTER_VALIDATE_EMAIL) && $bmNorm !== $e) {
                    $bmEmail = $bmNorm;
                    $ccKeyed[strtolower($bmNorm)] = $bmNorm;
                }
            }
            break;
        }
    }

    $partyCcKeyed = [];
    foreach ($emailRows as $row) {
        $e = vendor_am_service_normalize_email((string) ($row['email'] ?? ''));
        $isPrimary = !empty($row['is_primary']);
        if ($e === '' || !filter_var($e, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        if ($isPrimary) {
            continue;
        }
        $partyCcKeyed[strtolower($e)] = $e;
    }
    unset($partyCcKeyed[strtolower($toEmail)]);

    if ($toEmail === '' && $emailRows) {
        foreach ($emailRows as $row) {
            $e = vendor_am_service_normalize_email((string) ($row['email'] ?? ''));
            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) {
                $toEmail = $e;
                break;
            }
        }
    }

    return [
        'ok' => true,
        'party_id' => $partyId,
        'party_name' => $partyName,
        'to_email' => $toEmail,
        'am_email' => $amEmail,
        'bm_email' => $bmEmail,
        'cc' => array_values($ccKeyed),
        'party_cc' => array_values($partyCcKeyed),
    ];
}

/**
 * Merge compose CC list with AM/BM from the first matching active party email mapping.
 *
 * @param list<string> $ccEmails
 * @return list<string>
 */
function vendor_am_service_merge_cc_with_party_mapping(PDO $pdo, array $ccEmails, ?int $partyId): array
{
    if (($partyId ?? 0) <= 0) {
        return $ccEmails;
    }

    $resolved = vendor_am_service_resolve_party_mapping_for_compose($pdo, (int) $partyId);
    if (!($resolved['ok'] ?? false)) {
        return $ccEmails;
    }

    $keyed = [];
    foreach ($ccEmails as $email) {
        $email = trim((string) $email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $keyed[strtolower($email)] = $email;
        }
    }
    foreach ($resolved['cc'] ?? [] as $email) {
        $email = trim((string) $email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $keyed[strtolower($email)] = $email;
        }
    }

    return array_values($keyed);
}
