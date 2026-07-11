<?php

declare(strict_types=1);

/**
 * Additional emails for party_accounts (primary remains party_accounts.party_email).
 */

/** @return list<string> */
function party_account_normalize_additional_emails(mixed $raw): array
{
    if (!is_array($raw)) {
        return [];
    }

    $out = [];
    $seen = [];

    foreach ($raw as $item) {
        $email = trim((string) $item);
        if ($email === '') {
            continue;
        }
        $key = strtolower($email);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $email;
    }

    return $out;
}

/** @param array<string, mixed> $normalized */
function party_account_collect_email_addresses(array $normalized): array
{
    $primary = trim((string) ($normalized['party_email'] ?? ''));
    $additional = $normalized['additional_emails'] ?? [];
    if (!is_array($additional)) {
        $additional = [];
    }

    $out = [];
    $seen = [];

    if ($primary !== '') {
        $seen[strtolower($primary)] = true;
        $out[] = $primary;
    }

    foreach ($additional as $email) {
        $email = trim((string) $email);
        if ($email === '') {
            continue;
        }
        $key = strtolower($email);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $email;
    }

    return $out;
}

function party_account_ensure_emails_table(PDO $pdo): void
{
    static $ready = false;

    if ($ready || !party_account_table_exists($pdo, 'party_accounts')) {
        $ready = true;

        return;
    }

    if (!party_account_table_exists($pdo, 'party_account_emails')) {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `party_account_emails` (
              `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
              `party_account_id` bigint UNSIGNED NOT NULL,
              `email` varchar(255) NOT NULL,
              `is_primary` tinyint(1) NOT NULL DEFAULT 0,
              `sort_order` smallint UNSIGNED NOT NULL DEFAULT 0,
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_party_account_emails_email` (`email`(191)),
              UNIQUE KEY `uq_party_account_emails_account_email` (`party_account_id`, `email`(191)),
              KEY `idx_party_account_emails_account` (`party_account_id`),
              KEY `idx_party_account_emails_primary` (`party_account_id`, `is_primary`),
              CONSTRAINT `fk_party_account_emails_account`
                FOREIGN KEY (`party_account_id`) REFERENCES `party_accounts` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    party_account_backfill_emails_from_primary($pdo);

    $ready = true;
}

function party_account_backfill_emails_from_primary(PDO $pdo): void
{
    if (!party_account_table_exists($pdo, 'party_account_emails')) {
        return;
    }

    $sql = '
        INSERT INTO party_account_emails (party_account_id, email, is_primary, sort_order)
        SELECT pa.id, pa.party_email, 1, 0
        FROM party_accounts pa
        WHERE pa.party_email IS NOT NULL
          AND TRIM(pa.party_email) != \'\'
          AND NOT EXISTS (
              SELECT 1 FROM party_account_emails pae WHERE pae.party_account_id = pa.id
          )';

    try {
        $pdo->exec($sql);
    } catch (Throwable $ignored) {
    }
}

/** @return list<array{email: string, is_primary: int, sort_order: int}> */
function party_account_emails_list(PDO $pdo, int $partyAccountId): array
{
    if ($partyAccountId <= 0 || !party_account_table_exists($pdo, 'party_account_emails')) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT email, is_primary, sort_order
         FROM party_account_emails
         WHERE party_account_id = :id
         ORDER BY is_primary DESC, sort_order ASC, id ASC'
    );
    $stmt->execute([':id' => $partyAccountId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return list<string> Non-primary emails only (for edit form). */
function party_account_emails_additional(PDO $pdo, int $partyAccountId): array
{
    $rows = party_account_emails_list($pdo, $partyAccountId);
    $out = [];

    foreach ($rows as $row) {
        if (!empty($row['is_primary'])) {
            continue;
        }
        $email = trim((string) ($row['email'] ?? ''));
        if ($email !== '') {
            $out[] = $email;
        }
    }

    return $out;
}

/** @return list<string> */
function party_account_emails_all_addresses(PDO $pdo, int $partyAccountId): array
{
    $rows = party_account_emails_list($pdo, $partyAccountId);
    if ($rows !== []) {
        $out = [];
        foreach ($rows as $row) {
            $email = trim((string) ($row['email'] ?? ''));
            if ($email !== '') {
                $out[] = $email;
            }
        }

        return $out;
    }

    $stmt = $pdo->prepare('SELECT party_email FROM party_accounts WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $partyAccountId]);
    $primary = trim((string) ($stmt->fetchColumn() ?: ''));

    return $primary !== '' ? [$primary] : [];
}

/**
 * Replace email rows for an account; keeps party_accounts.party_email as primary source in CRUD layer.
 *
 * @param list<string> $additionalEmails
 */
function party_account_emails_sync(PDO $pdo, int $partyAccountId, string $primaryEmail, array $additionalEmails): void
{
    if ($partyAccountId <= 0 || !party_account_table_exists($pdo, 'party_account_emails')) {
        return;
    }

    $primaryEmail = trim($primaryEmail);
    $additionalEmails = party_account_normalize_additional_emails($additionalEmails);

    $primaryKey = $primaryEmail !== '' ? strtolower($primaryEmail) : '';
    $toInsert = [];

    if ($primaryEmail !== '') {
        $toInsert[] = ['email' => $primaryEmail, 'is_primary' => 1, 'sort_order' => 0];
    }

    $sort = 1;
    foreach ($additionalEmails as $email) {
        if ($primaryKey !== '' && strtolower($email) === $primaryKey) {
            continue;
        }
        $toInsert[] = ['email' => $email, 'is_primary' => 0, 'sort_order' => $sort++];
    }

    $pdo->prepare('DELETE FROM party_account_emails WHERE party_account_id = :id')
        ->execute([':id' => $partyAccountId]);

    if ($toInsert === []) {
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO party_account_emails (party_account_id, email, is_primary, sort_order)
         VALUES (:aid, :email, :is_primary, :sort_order)'
    );

    foreach ($toInsert as $row) {
        $stmt->execute([
            ':aid' => $partyAccountId,
            ':email' => $row['email'],
            ':is_primary' => $row['is_primary'],
            ':sort_order' => $row['sort_order'],
        ]);
    }
}
