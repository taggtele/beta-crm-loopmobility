<?php

/**
 * Persists outgoing HTML preview with permanent storage URLs (not runtime preview endpoints).
 */

require_once __DIR__ . '/email_minio_storage_service.php';

function email_outbox_assets_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    if (!function_exists('email_smtp_ensure_outbox_columns')) {
        require_once __DIR__ . '/../modules/email/smtp_service.php';
    }

    email_smtp_ensure_outbox_columns($pdo);
    $columns = email_smtp_outbox_columns($pdo);

    $ready = true;
}

function email_outbox_assets_has_column(PDO $pdo, string $column): bool
{
    email_outbox_assets_ensure_schema($pdo);

    return isset(email_smtp_outbox_columns($pdo)[$column]);
}

function email_outbox_assets_public_url(int $outboxId, string $filename): string
{
    return url('storage/email_outbox_assets/' . $outboxId . '/' . rawurlencode($filename));
}

/**
 * @return array{mime:string,data:string,filename:string}|null
 */
function email_outbox_assets_decode_data_uri_at(string $html, int $index): ?array
{
    if ($index < 0 || stripos($html, 'data:image/') === false) {
        return null;
    }

    $current = 0;
    $searchFrom = 0;
    $needle = 'data:image/';

    while (($dataPos = stripos($html, $needle, $searchFrom)) !== false) {
        $quotePos = $dataPos - 1;
        while ($quotePos >= 0 && ($html[$quotePos] === ' ' || $html[$quotePos] === "\t" || $html[$quotePos] === '=')) {
            $quotePos--;
        }

        $quote = $html[$quotePos] ?? '';
        if ($quote !== '"' && $quote !== "'") {
            $searchFrom = $dataPos + strlen($needle);
            continue;
        }

        $endQuote = strpos($html, $quote, $dataPos);
        if ($endQuote === false) {
            break;
        }

        if ($current === $index) {
            $payload = substr($html, $dataPos, $endQuote - $dataPos);
            if (!preg_match('#^data:image/([a-zA-Z0-9.+-]+);base64,(.+)$#is', $payload, $m)) {
                return null;
            }

            $subtype = strtolower(preg_replace('/[^a-z0-9.+-]/', '', (string) $m[1]) ?: 'png');
            $b64 = preg_replace('/\s+/', '', (string) $m[2]) ?? '';
            $decoded = base64_decode($b64, true);
            if ($decoded === false || $decoded === '') {
                return null;
            }

            $ext = $subtype === 'jpeg' ? 'jpg' : ($subtype === 'webp' ? 'webp' : 'png');

            return [
                'mime' => 'image/' . $subtype,
                'data' => $decoded,
                'filename' => 'inline-' . $index . '.' . $ext,
            ];
        }

        $current++;
        $searchFrom = $endQuote + 1;
    }

    return null;
}

function email_outbox_assets_replace_data_uris_with_storage_urls(int $outboxId, string $html): array
{
    $meta = ['version' => 1, 'assets' => []];
    if ($outboxId <= 0 || stripos($html, 'data:image/') === false) {
        return ['html' => $html, 'meta' => $meta];
    }

    if (email_minio_enabled() && function_exists('get_pdo')) {
        return email_minio_replace_data_uris_with_urls(get_pdo(), 'outgoing', $outboxId, $html);
    }

    if (email_minio_enabled()) {
        return ['html' => $html, 'meta' => $meta];
    }

    $dir = rtrim(str_replace('\\', '/', (string) STORAGE_PATH), '/') . '/email_outbox_assets/' . $outboxId;
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

        $path = $dir . '/' . $decoded['filename'];
        if (file_put_contents($path, $decoded['data'], LOCK_EX) === false) {
            $searchFrom = $dataPos + strlen($needle);
            $idx++;
            continue;
        }

        $publicUrl = email_outbox_assets_public_url($outboxId, $decoded['filename']);
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

/**
 * Stores final preview HTML (permanent asset URLs) for Email Logs rendering.
 * Does not modify the queued SMTP body column.
 */
function email_outbox_assets_persist_preview_html(PDO $pdo, int $outboxId, string $html): bool
{
    $pdo = get_pdo();
    if ($outboxId <= 0) {
        return false;
    }

    if (
        !email_outbox_assets_has_column($pdo, 'body_preview_html')
        || !email_outbox_assets_has_column($pdo, 'body_assets_meta')
    ) {
        return false;
    }

    $html = trim($html);
    if ($html === '') {
        return false;
    }

    $result = email_outbox_assets_replace_data_uris_with_storage_urls($outboxId, $html);
    $previewHtml = (string) $result['html'];
    if (function_exists('email_smtp_compact_body_for_storage')) {
        $previewHtml = email_smtp_compact_body_for_storage($previewHtml, true);
    }
    $metaJson = json_encode($result['meta'], JSON_UNESCAPED_SLASHES);
    if ($metaJson === false) {
        $metaJson = '{}';
    }

    $pdo = get_pdo();
    $stmt = $pdo->prepare(
        'UPDATE email_outbox_log
         SET body_preview_html = :body_preview_html, body_assets_meta = :body_assets_meta
         WHERE id = :id'
    );

    try {
        return $stmt->execute([
            ':body_preview_html' => $previewHtml,
            ':body_assets_meta' => $metaJson,
            ':id' => $outboxId,
        ]);
    } catch (Throwable $throwable) {
        if (!function_exists('email_smtp_is_max_packet_exception') || !email_smtp_is_max_packet_exception($throwable)) {
            throw $throwable;
        }

        $fallbackHtml = function_exists('email_smtp_compact_body_for_storage')
            ? mb_substr(email_smtp_compact_body_for_storage($html, true), 0, 60000)
            : mb_substr(strip_tags($html), 0, 60000);

        return $stmt->execute([
            ':body_preview_html' => $fallbackHtml,
            ':body_assets_meta' => $metaJson,
            ':id' => $outboxId,
        ]);
    }
}

function email_outbox_assets_load_preview_html(PDO $pdo, int $outboxId): string
{
    if ($outboxId <= 0 || !email_outbox_assets_has_column($pdo, 'body_preview_html')) {
        return '';
    }

    $stmt = $pdo->prepare('SELECT body_preview_html FROM email_outbox_log WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $outboxId]);

    return trim((string) ($stmt->fetchColumn() ?: ''));
}
