<?php

/**
 * Per-user important/flag markers for Email Logs (incoming + outgoing rows).
 */

function email_log_flag_ensure_table(PDO $pdo): void
{
    static $ready = false;

    if ($ready) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS email_log_flags (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            mail_direction ENUM(\'incoming\', \'outgoing\') NOT NULL,
            log_id INT UNSIGNED NOT NULL,
            flagged_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_email_log_flags_user_mail (user_id, mail_direction, log_id),
            KEY idx_email_log_flags_user_flagged (user_id, flagged_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $ready = true;
}

function email_log_flag_user_id(?array $currentUser): int
{
    return max(0, (int) ($currentUser['id'] ?? 0));
}

function email_log_flag_row_key(string $direction, int $logId): string
{
    $direction = $direction === 'outgoing' ? 'outgoing' : 'incoming';

    return ($direction === 'incoming' ? 'in-' : 'out-') . $logId;
}

/**
 * @return array<string, true> Map of workspace row id (in-123 / out-456) => true
 */
function email_log_flag_map_for_user(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }

    email_log_flag_ensure_table($pdo);

    $stmt = $pdo->prepare(
        'SELECT mail_direction, log_id
         FROM email_log_flags
         WHERE user_id = :user_id'
    );
    $stmt->execute([':user_id' => $userId]);

    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $direction = (string) ($row['mail_direction'] ?? '');
        $logId = (int) ($row['log_id'] ?? 0);
        if ($logId <= 0) {
            continue;
        }
        $map[email_log_flag_row_key($direction, $logId)] = true;
    }

    return $map;
}

function email_log_flag_count_for_user(PDO $pdo, int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }

    email_log_flag_ensure_table($pdo);

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM email_log_flags WHERE user_id = :user_id');
    $stmt->execute([':user_id' => $userId]);

    return (int) $stmt->fetchColumn();
}

/**
 * @return array{0: string, 1: array<string, mixed>} SQL fragment + params for EXISTS filter
 */
function email_log_flag_exists_sql(int $userId, string $direction, string $logIdColumn): array
{
    if ($userId <= 0) {
        return ['', []];
    }

    $direction = $direction === 'outgoing' ? 'outgoing' : 'incoming';

    return [
        ' AND EXISTS (
            SELECT 1 FROM email_log_flags elf
            WHERE elf.user_id = :elf_user_id
              AND elf.mail_direction = :elf_direction
              AND elf.log_id = ' . $logIdColumn . '
        )',
        [
            ':elf_user_id' => $userId,
            ':elf_direction' => $direction,
        ],
    ];
}

/**
 * @return array{flagged: bool, flagged_at: ?string}
 */
function email_log_flag_toggle(PDO $pdo, int $userId, string $direction, int $logId): array
{
    if ($userId <= 0 || $logId <= 0) {
        throw new InvalidArgumentException('Invalid flag request.');
    }

    $direction = $direction === 'outgoing' ? 'outgoing' : 'incoming';
    email_log_flag_ensure_table($pdo);

    $check = $pdo->prepare(
        'SELECT id FROM email_log_flags
         WHERE user_id = :user_id AND mail_direction = :mail_direction AND log_id = :log_id
         LIMIT 1'
    );
    $check->execute([
        ':user_id' => $userId,
        ':mail_direction' => $direction,
        ':log_id' => $logId,
    ]);
    $existingId = (int) ($check->fetchColumn() ?: 0);

    if ($existingId > 0) {
        $delete = $pdo->prepare('DELETE FROM email_log_flags WHERE id = :id AND user_id = :user_id LIMIT 1');
        $delete->execute([':id' => $existingId, ':user_id' => $userId]);

        return ['flagged' => false, 'flagged_at' => null];
    }

    $insert = $pdo->prepare(
        'INSERT INTO email_log_flags (user_id, mail_direction, log_id, flagged_at)
         VALUES (:user_id, :mail_direction, :log_id, NOW())'
    );
    $insert->execute([
        ':user_id' => $userId,
        ':mail_direction' => $direction,
        ':log_id' => $logId,
    ]);

    return ['flagged' => true, 'flagged_at' => date('Y-m-d H:i:s')];
}

/**
 * @param array<int, array<string, mixed>> $rows
 */
function email_log_flag_apply_to_workspace_rows(PDO $pdo, int $userId, array &$rows): void
{
    if ($rows === [] || $userId <= 0) {
        foreach ($rows as $index => $row) {
            $rows[$index]['is_flagged'] = false;
        }

        return;
    }

    $map = email_log_flag_map_for_user($pdo, $userId);

    foreach ($rows as $index => $row) {
        $rowId = (string) ($row['id'] ?? '');
        $rows[$index]['is_flagged'] = $rowId !== '' && !empty($map[$rowId]);
    }
}

function email_log_flag_sort_rows_flagged_first(array $rows): array
{
    usort($rows, static function (array $a, array $b): int {
        $fa = !empty($a['is_flagged']);
        $fb = !empty($b['is_flagged']);
        if ($fa !== $fb) {
            return $fb <=> $fa;
        }

        $ta = strtotime((string) ($a['sort_at'] ?? '')) ?: 0;
        $tb = strtotime((string) ($b['sort_at'] ?? '')) ?: 0;
        if ($ta !== $tb) {
            return $tb <=> $ta;
        }

        return strcmp((string) ($b['id'] ?? ''), (string) ($a['id'] ?? ''));
    });

    return $rows;
}
