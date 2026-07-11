<?php

/**
 * Persists incoming Email Logs preview HTML with on-disk assets (mirrors outgoing outbox assets).
 */

require_once __DIR__ . '/email_inbox_service.php';
require_once __DIR__ . '/email_minio_storage_service.php';

function email_inbox_assets_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    email_inbox_service_ensure_table($pdo);

    $ready = true;
}

function email_inbox_assets_has_column(PDO $pdo, string $column): bool
{
    static $columns = null;

    email_inbox_assets_ensure_schema($pdo);
    if ($columns === null) {
        $columns = [];
        foreach ($pdo->query('SHOW COLUMNS FROM email_inbox_log')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $field = (string) ($row['Field'] ?? '');
            if ($field !== '') {
                $columns[$field] = true;
            }
        }
    }

    return isset($columns[$column]);
}

function email_inbox_assets_public_url(int $inboxId, string $filename): string
{
    return url('storage/email_inbox_assets/' . $inboxId . '/' . rawurlencode($filename));
}

function email_inbox_assets_storage_dir(int $inboxId): string
{
    return rtrim(str_replace('\\', '/', (string) STORAGE_PATH), '/') . '/email_inbox_assets/' . $inboxId;
}

function email_inbox_assets_cid_filename(string $cid, string $mime): string
{
    $token = strtolower(trim($cid, " <>\t\r\n"));
    $hash = substr(hash('sha1', $token), 0, 16);
    $ext = 'png';
    if (preg_match('#^image/([a-z0-9.+-]+)$#i', $mime, $m)) {
        $sub = strtolower(preg_replace('/[^a-z0-9.+-]/', '', (string) $m[1]) ?: 'png');
        $ext = $sub === 'jpeg' ? 'jpg' : $sub;
    }

    return 'cid-' . $hash . '.' . $ext;
}

/**
 * @return array{mime:string,data:string,path:string}|null
 */
function email_inbox_assets_read_cid_file(int $inboxId, string $cid): ?array
{
    if ($inboxId <= 0 || trim($cid) === '') {
        return null;
    }

    if (email_minio_enabled()) {
        return null;
    }

    $dir = email_inbox_assets_storage_dir($inboxId);
    if (!is_dir($dir)) {
        return null;
    }

    $token = strtolower(trim($cid, " <>\t\r\n"));
    $hash = substr(hash('sha1', $token), 0, 16);
    $pattern = $dir . '/cid-' . $hash . '.*';
    $matches = glob($pattern) ?: [];
    $path = $matches[0] ?? '';
    if ($path === '' || !is_readable($path)) {
        return null;
    }

    $binary = file_get_contents($path);
    if ($binary === false || $binary === '') {
        return null;
    }

    $mime = 'image/png';
    $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === 'jpg' || $ext === 'jpeg') {
        $mime = 'image/jpeg';
    } elseif ($ext === 'gif') {
        $mime = 'image/gif';
    } elseif ($ext === 'webp') {
        $mime = 'image/webp';
    }

    return [
        'mime' => $mime,
        'data' => $binary,
        'path' => $path,
    ];
}

function email_inbox_assets_write_cid_file(int $inboxId, string $cid, string $binary, string $mime): ?string
{
if ($inboxId <= 0 || $binary === '') {
        return null;
    }

    if (email_minio_enabled() && function_exists('get_pdo')) {
        // This MinIO + compression pipeline is currently for local testing. Production enable/disable controlled via config flag USE_MINIO.
        try {
            $name = email_inbox_assets_cid_filename($cid, $mime);
            $stored = email_minio_store_binary(get_pdo(), 'incoming', $inboxId, $binary, $name, $mime, 'inline');

            return !empty($stored['url']) ? (string) $stored['url'] : null;
        } catch (Throwable $error) {
            error_log('[MinIO email] incoming CID upload failed: ' . $error->getMessage());
        }

        return null;
    }

    if (email_minio_enabled()) {
        return null;
    }

    $dir = email_inbox_assets_storage_dir($inboxId);
    if (!is_dir($dir)) {
        $parentDir = dirname($dir);
        if (!is_dir($parentDir) && !mkdir($parentDir, 0755, true) && !is_dir($parentDir)) {
            return null;
        }
        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }
    }

    $filename = email_inbox_assets_cid_filename($cid, $mime);
    $path = $dir . '/' . $filename;
    if (file_put_contents($path, $binary, LOCK_EX) === false) {
        return null;
    }

    return email_inbox_assets_public_url($inboxId, $filename);
}

/**
 * @return array{html:string,meta:array<string,mixed>}
 */
function email_inbox_assets_replace_data_uris_with_storage_urls(int $inboxId, string $html): array
{
    $meta = ['version' => 1, 'assets' => []];
    if ($inboxId <= 0 || stripos($html, 'data:image/') === false) {
        return ['html' => $html, 'meta' => $meta];
    }

    if (email_minio_enabled() && function_exists('get_pdo')) {
        // This MinIO + compression pipeline is currently for local testing. Production enable/disable controlled via config flag USE_MINIO.
        return email_minio_replace_data_uris_with_urls(get_pdo(), 'incoming', $inboxId, $html);
    }

    if (email_minio_enabled()) {
        return ['html' => $html, 'meta' => $meta];
    }

    if (!function_exists('email_outbox_assets_decode_data_uri_at')) {
        require_once __DIR__ . '/email_outbox_assets_service.php';
    }

    $dir = email_inbox_assets_storage_dir($inboxId);
    if (!is_dir($dir)) {
        $parentDir = dirname($dir);
        if (!is_dir($parentDir) && !mkdir($parentDir, 0755, true) && !is_dir($parentDir)) {
            return ['html' => $html, 'meta' => $meta];
        }
        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['html' => $html, 'meta' => $meta];
        }
    }

    $idx = 0;
    $searchFrom = 0;
    $needle = 'data:image/';

    while (($dataPos = stripos($html, $needle, $searchFrom)) !== false) {
        $decoded = email_outbox_assets_decode_data_uri_at($html, $idx);
        if ($decoded === null) {
            $searchFrom = $dataPos + strlen($needle);
            $idx++;
            continue;
        }

        $path = $dir . '/inline-' . $idx . '-' . $decoded['filename'];
        if (file_put_contents($path, $decoded['data'], LOCK_EX) === false) {
            $searchFrom = $dataPos + strlen($needle);
            $idx++;
            continue;
        }

        $publicUrl = email_inbox_assets_public_url($inboxId, basename($path));
        $meta['assets'][] = [
            'index' => $idx,
            'path' => $path,
            'url' => $publicUrl,
            'mime' => $decoded['mime'],
        ];

        $quotePos = $dataPos - 1;
        while ($quotePos > 0 && ($html[$quotePos] === ' ' || $html[$quotePos] === "\t" || $html[$quotePos] === '=')) {
            $quotePos--;
        }

        $quote = $html[$quotePos] ?? '';
        if ($quote !== '"' && $quote !== "'") {
            $searchFrom = $dataPos + strlen($needle);
            $idx++;
            continue;
        }

        $endQuote = strpos($html, $quote, $dataPos);
        if ($endQuote === false) {
            break;
        }

        $html = substr($html, 0, $quotePos + 1) . $publicUrl . substr($html, $endQuote);
        $searchFrom = $quotePos + 1 + strlen($publicUrl);
        $idx++;
    }

    return ['html' => $html, 'meta' => $meta];
}

function email_inbox_assets_save_preview_html(PDO $pdo, int $inboxId, string $html, array $meta = []): bool
{
    if ($inboxId <= 0) {
        return false;
    }

    if (
        !email_inbox_assets_has_column($pdo, 'body_preview_html')
        || !email_inbox_assets_has_column($pdo, 'body_assets_meta')
    ) {
        return false;
    }

    $html = trim($html);
    if ($html === '') {
        return false;
    }

    $metaJson = json_encode($meta, JSON_UNESCAPED_SLASHES);
    if ($metaJson === false) {
        $metaJson = '{}';
    }

    $stmt = $pdo->prepare(
        'UPDATE email_inbox_log
         SET body_preview_html = :body_preview_html, body_assets_meta = :body_assets_meta
         WHERE id = :id'
    );

    return $stmt->execute([
        ':body_preview_html' => $html,
        ':body_assets_meta' => $metaJson,
        ':id' => $inboxId,
    ]);
}

function email_inbox_assets_load_preview_html(PDO $pdo, int $inboxId): string
{
    if ($inboxId <= 0 || !email_inbox_assets_has_column($pdo, 'body_preview_html')) {
        return '';
    }

    $stmt = $pdo->prepare('SELECT body_preview_html FROM email_inbox_log WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $inboxId]);

    return trim((string) ($stmt->fetchColumn() ?: ''));
}
