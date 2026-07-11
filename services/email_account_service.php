<?php

function email_account_service_ensure_schema(PDO $pdo): void
{
    static $ready = false;

    if ($ready) {
        return;
    }

    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM email_accounts')->fetchAll() as $column) {
        $field = (string) ($column['Field'] ?? '');
        if ($field !== '') {
            $columns[$field] = $column;
        }
    }

    if (!isset($columns['username'])) {
        $pdo->exec('ALTER TABLE email_accounts ADD COLUMN username VARCHAR(255) NULL AFTER email');
        $pdo->exec('UPDATE email_accounts SET username = email WHERE username IS NULL OR username = \'\'');
        $columns['username'] = ['Type' => 'varchar(255)'];
    }

    if (!isset($columns['from_name'])) {
        $pdo->exec('ALTER TABLE email_accounts ADD COLUMN from_name VARCHAR(150) NULL AFTER username');
        $columns['from_name'] = ['Type' => 'varchar(150)'];
    }

    if (!isset($columns['smtp_encryption'])) {
        $pdo->exec('ALTER TABLE email_accounts ADD COLUMN smtp_encryption ENUM(\'ssl\', \'tls\', \'none\') NULL DEFAULT \'tls\' AFTER smtp_port');
        $pdo->exec('UPDATE email_accounts SET smtp_encryption = COALESCE(NULLIF(encryption, \'\'), \'tls\') WHERE smtp_encryption IS NULL OR smtp_encryption = \'\'');
        $columns['smtp_encryption'] = ['Type' => 'enum(\'ssl\',\'tls\',\'none\')'];
    }

    foreach (['encryption' => 'ssl', 'smtp_encryption' => 'tls'] as $column => $default) {
        $type = strtolower((string) ($columns[$column]['Type'] ?? ''));
        if (str_starts_with($type, 'enum(')
            && (!str_contains($type, "'ssl'") || !str_contains($type, "'tls'") || !str_contains($type, "'none'"))
        ) {
            $pdo->exec(
                'ALTER TABLE email_accounts MODIFY COLUMN ' . $column . ' ENUM(\'ssl\', \'tls\', \'none\') DEFAULT \'' . $default . '\''
            );
        }
    }

    if (!isset($columns['cron_enabled'])) {
        $pdo->exec('ALTER TABLE email_accounts ADD COLUMN cron_enabled TINYINT(1) DEFAULT 1 NOT NULL AFTER smtp_encryption');
    }

    if (!isset($columns['is_auto_reply_account'])) {
        $pdo->exec('ALTER TABLE email_accounts ADD COLUMN is_auto_reply_account TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active');
    }

    $ready = true;
}

function email_account_service_columns(PDO $pdo): array
{
    static $columns = null;

    if ($columns !== null) {
        return $columns;
    }

    email_account_service_ensure_schema($pdo);

    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM email_accounts')->fetchAll() as $column) {
        $field = (string) ($column['Field'] ?? '');
        if ($field !== '') {
            $columns[$field] = $column;
        }
    }

    return $columns;
}

function email_account_service_has_column(PDO $pdo, string $column): bool
{
    $columns = email_account_service_columns($pdo);

    return isset($columns[$column]);
}

function email_account_service_enum_values(PDO $pdo, string $column, array $fallback): array
{
    $columns = email_account_service_columns($pdo);
    $type = (string) ($columns[$column]['Type'] ?? '');

    if (preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $type, $matches)) {
        $values = array_map('stripcslashes', $matches[1]);
        return array_values(array_unique(array_filter($values, 'strlen')));
    }

    return $fallback;
}

function email_account_service_encryption_options(PDO $pdo, string $column = 'encryption'): array
{
    $values = email_account_service_enum_values($pdo, $column, ['ssl', 'tls', 'none']);
    $labels = [
        'ssl' => 'SSL',
        'tls' => 'TLS',
        'none' => 'None',
    ];

    $options = [];
    foreach ($values as $value) {
        if (isset($labels[$value])) {
            $options[$value] = $labels[$value];
        }
    }

    return $options ?: ['ssl' => 'SSL', 'tls' => 'TLS'];
}

function email_account_service_select_columns(PDO $pdo): string
{
    $select = [
        'id',
        'email',
        'imap_host',
        'imap_port',
        'encryption',
        'smtp_host',
        'smtp_port',
        'is_active',
        'last_checked_at',
        'created_at',
    ];

    $select[] = email_account_service_has_column($pdo, 'username')
        ? 'username'
        : 'email AS username';

    $select[] = email_account_service_has_column($pdo, 'from_name')
        ? 'from_name'
        : 'NULL AS from_name';

    $select[] = email_account_service_has_column($pdo, 'smtp_encryption')
        ? 'smtp_encryption'
        : 'encryption AS smtp_encryption';

    $select[] = email_account_service_has_column($pdo, 'is_auto_reply_account')
        ? 'is_auto_reply_account'
        : '0 AS is_auto_reply_account';

    return implode(', ', $select);
}

function email_account_service_all(PDO $pdo): array
{
    $stmt = $pdo->prepare(
        'SELECT ' . email_account_service_select_columns($pdo) . '
         FROM email_accounts
         ORDER BY id DESC'
    );
    $stmt->execute();

    return $stmt->fetchAll();
}

function email_account_service_find(PDO $pdo, int $accountId): ?array
{
    if ($accountId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT ' . email_account_service_select_columns($pdo) . '
         FROM email_accounts
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $accountId]);
    $account = $stmt->fetch();

    return $account ?: null;
}

function email_account_service_email_exists(PDO $pdo, string $email, ?int $excludeId = null): bool
{
    $sql = 'SELECT id FROM email_accounts WHERE LOWER(email) = LOWER(:email)';
    $params = [':email' => $email];

    if ($excludeId !== null && $excludeId > 0) {
        $sql .= ' AND id <> :id';
        $params[':id'] = $excludeId;
    }

    $sql .= ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (bool) $stmt->fetch();
}

function email_account_service_payload(PDO $pdo, array $data, array $currentUser, bool $includePassword, bool $includeOwner): array
{
    $payload = [
        'email' => $data['email'],
        'imap_host' => $data['imap_host'],
        'imap_port' => $data['imap_port'],
        'encryption' => $data['imap_encryption'],
        'smtp_host' => $data['smtp_host'],
        'smtp_port' => $data['smtp_port'],
        'is_active' => $data['is_active'],
    ];

    if ($includePassword) {
        $payload['password'] = $data['password'];
    }

    if ($includeOwner && email_account_service_has_column($pdo, 'user_id')) {
        $payload['user_id'] = (int) ($currentUser['id'] ?? 0) > 0 ? (int) $currentUser['id'] : null;
    }

    if (email_account_service_has_column($pdo, 'username')) {
        $payload['username'] = $data['username'];
    }

    if (email_account_service_has_column($pdo, 'from_name')) {
        $payload['from_name'] = $data['from_name'] !== '' ? $data['from_name'] : null;
    }

    if (email_account_service_has_column($pdo, 'smtp_encryption')) {
        $payload['smtp_encryption'] = $data['smtp_encryption'];
    }

    return $payload;
}

function email_account_service_insert(PDO $pdo, array $data, array $currentUser): int
{
    $payload = email_account_service_payload($pdo, $data, $currentUser, true, true);
    $columns = array_keys($payload);
    $placeholders = array_map(static fn(string $column): string => ':' . $column, $columns);

    $stmt = $pdo->prepare(
        'INSERT INTO email_accounts (' . implode(', ', $columns) . ')
         VALUES (' . implode(', ', $placeholders) . ')'
    );

    foreach ($payload as $column => $value) {
        $stmt->bindValue(':' . $column, $value);
    }

    $stmt->execute();

    return (int) $pdo->lastInsertId();
}

function email_account_service_update(PDO $pdo, int $accountId, array $data, array $currentUser): void
{
    $payload = email_account_service_payload($pdo, $data, $currentUser, $data['password'] !== '', false);
    $sets = [];

    foreach (array_keys($payload) as $column) {
        $sets[] = $column . ' = :' . $column;
    }

    $stmt = $pdo->prepare(
        'UPDATE email_accounts
         SET ' . implode(', ', $sets) . '
         WHERE id = :id'
    );

    foreach ($payload as $column => $value) {
        $stmt->bindValue(':' . $column, $value);
    }
    $stmt->bindValue(':id', $accountId, PDO::PARAM_INT);
    $stmt->execute();
}

function email_account_service_delete(PDO $pdo, int $accountId): void
{
    $stmt = $pdo->prepare('DELETE FROM email_accounts WHERE id = :id');
    $stmt->execute([':id' => $accountId]);
}
