<?php
/**
 * Email Attachments Service
 * Manages file attachments for outgoing emails (preview + SMTP).
 */

require_once __DIR__ . '/email_minio_storage_service.php';

// email_attachments schema is managed at runtime.
function email_attachments_ensure_table(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS email_attachments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            outbox_id BIGINT UNSIGNED NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_size INT UNSIGNED NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_outbox_id (outbox_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
    );

    $ready = true;
}

function email_attachments_has_column(PDO $pdo, string $column): bool
{
    static $columns = null;

    email_attachments_ensure_table($pdo);
    if ($columns === null) {
        $columns = [];
        foreach ($pdo->query('SHOW COLUMNS FROM email_attachments')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $field = (string) ($row['Field'] ?? '');
            if ($field !== '') {
                $columns[$field] = true;
            }
        }
    }

    return isset($columns[$column]);
}

function email_attachments_file_path_to_public_url(string $filePath): string
{
    $filePath = trim($filePath);
    if ($filePath === '') {
        return '';
    }

    $normalized = str_replace('\\', '/', $filePath);
    if (preg_match('#^https?://#i', $normalized)) {
        return $normalized;
    }

    $storageRoot = str_replace('\\', '/', (string) STORAGE_PATH);
    if ($storageRoot !== '' && stripos($normalized, $storageRoot) === 0) {
        $relative = 'storage/' . ltrim(substr($normalized, strlen($storageRoot)), '/');

        return url($relative);
    }

    if (str_starts_with($normalized, 'storage/')) {
        return url($normalized);
    }

    return '';
}

/**
 * @return array<int, array<string, mixed>>
 */
function email_attachments_load_by_outbox_ids(PDO $pdo, array $outboxIds): array
{
    $outboxIds = array_values(array_unique(array_filter(array_map('intval', $outboxIds), static fn (int $id): bool => $id > 0)));
    if ($outboxIds === []) {
        return [];
    }

    email_attachments_ensure_table($pdo);
    $placeholders = implode(',', array_fill(0, count($outboxIds), '?'));
    $dispositionSelect = email_attachments_has_column($pdo, 'disposition')
        ? 'disposition'
        : '\'attachment\' AS disposition';
    $stmt = $pdo->prepare(
        'SELECT outbox_id, file_path, file_name, file_size, mime_type, ' . $dispositionSelect . '
         FROM email_attachments
         WHERE outbox_id IN (' . $placeholders . ')
         ORDER BY id ASC'
    );
    $stmt->execute($outboxIds);

    $grouped = [];
    $indexByOutbox = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $outboxId = (int) ($row['outbox_id'] ?? 0);
        if ($outboxId <= 0) {
            continue;
        }
        $idx = $indexByOutbox[$outboxId] ?? 0;
        $indexByOutbox[$outboxId] = $idx + 1;
        $grouped[$outboxId][] = email_attachments_row_to_preview_item($row, $idx);
    }

    return $grouped;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function email_attachments_row_to_preview_item(array $row, int $index = 0): array
{
    $path = (string) ($row['file_path'] ?? '');
    $name = (string) ($row['file_name'] ?? 'attachment');
    $mime = (string) ($row['mime_type'] ?? 'application/octet-stream');
    $disposition = strtolower(trim((string) ($row['disposition'] ?? 'attachment')));
    if ($disposition === '') {
        $disposition = 'attachment';
    }

    $outboxId = (int) ($row['outbox_id'] ?? 0);
    $url = email_attachments_file_path_to_public_url($path);
    if ($url !== '' && $outboxId > 0 && email_minio_enabled() && preg_match('#^https?://#i', $path)) {
        $url = url(
            'emails/logs.php?' . http_build_query([
                'action' => 'email_attachment',
                'direction' => 'outgoing',
                'log_id' => $outboxId,
                'idx' => $index,
            ])
        );
    }
    if ($url === '' && $outboxId > 0) {
        $url = url(
            'emails/logs.php?' . http_build_query([
                'action' => 'email_attachment',
                'direction' => 'outgoing',
                'log_id' => $outboxId,
                'idx' => $index,
            ])
        );
    }

    return [
        'name' => $name,
        'mime' => $mime,
        'size' => (int) ($row['file_size'] ?? 0),
        'disposition' => $disposition,
        'url' => $url,
        'path' => $path,
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function email_attachments_list_for_outbox(PDO $pdo, int $outboxId): array
{
    if ($outboxId <= 0) {
        return [];
    }

    $grouped = email_attachments_load_by_outbox_ids($pdo, [$outboxId]);

    return $grouped[$outboxId] ?? [];
}

/**
 * Exclude inline assets and files already embedded in preview HTML.
 *
 * @param array<int, array<string, mixed>> $attachments
 * @return array<int, array<string, mixed>>
 */
function email_attachments_filter_for_preview(array $attachments, string $previewHtml): array
{
    if ($attachments === []) {
        return [];
    }

    $previewHtml = (string) $previewHtml;
    $previewLower = strtolower($previewHtml);
    $filtered = [];

    foreach ($attachments as $att) {
        if (!is_array($att)) {
            continue;
        }

        $disposition = strtolower(trim((string) ($att['disposition'] ?? 'attachment')));
        if ($disposition === 'inline') {
            continue;
        }

        $name = (string) ($att['name'] ?? '');
        $url = (string) ($att['url'] ?? '');
        $path = (string) ($att['path'] ?? '');

        if ($name !== '') {
            $baseName = strtolower(basename($name));
            if ($baseName !== '' && str_contains($previewLower, $baseName)) {
                continue;
            }
        }

        if ($url !== '') {
            $urlPath = (string) (parse_url($url, PHP_URL_PATH) ?: $url);
            $slug = strtolower(basename($urlPath));
            if ($slug !== '' && str_contains($previewLower, $slug)) {
                continue;
            }
        }

        if ($path !== '') {
            $pathSlug = strtolower(basename(str_replace('\\', '/', $path)));
            if ($pathSlug !== '' && str_contains($previewLower, $pathSlug)) {
                continue;
            }
        }

        if (preg_match('#^image/#i', (string) ($att['mime'] ?? '')) && preg_match('/<img\b/i', $previewHtml)) {
            $stem = strtolower(pathinfo($name, PATHINFO_FILENAME) ?: '');
            if ($stem !== '' && preg_match('/email_outbox_assets\/\d+\/[^"\'>\s]*/i', $previewHtml)) {
                if (preg_match('/email_outbox_assets\/\d+\/[^"\'>\s]*' . preg_quote($stem, '/') . '/i', $previewHtml)) {
                    continue;
                }
            }
        }

        unset($att['path']);
        $filtered[] = $att;
    }

    return $filtered;
}
