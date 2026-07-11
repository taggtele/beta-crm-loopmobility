<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../services/email_log_service.php';
require_once __DIR__ . '/../services/email_parser_service.php';
require_once __DIR__ . '/../services/vendor_am_service.php';
require_once __DIR__ . '/../services/party_service.php';
require_once __DIR__ . '/../modules/email/email_processor.php';
require_once __DIR__ . '/../modules/email/smtp_service.php';
require_once __DIR__ . '/../modules/email/imap_service.php';
require_once __DIR__ . '/../services/email_attachments_service.php';
require_once __DIR__ . '/../services/user_email_signature_service.php';
require_once __DIR__ . '/../services/email_log_flag_service.php';
require_once __DIR__ . '/../services/ticket_quick_reply_service.php';
require_once __DIR__ . '/../services/ticket_query_service.php';
require_once __DIR__ . '/../services/email_outbox_assets_service.php';
require_once __DIR__ . '/../services/email_inbox_assets_service.php';
require_once __DIR__ . '/../services/email_minio_storage_service.php';

$currentUser = require_login($pdo);
require_once __DIR__ . '/../includes/rbac.php';
rbac_require_email_logs_read($currentUser);
$canManageEmailLogs = rbac_can_manage_email_logs($currentUser);

function email_logs_request_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443');
}

function email_logs_public_base_url(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }

    $scheme = email_logs_request_is_https() ? 'https' : 'http';
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $basePath = app_base_path();
    $base = $scheme . '://' . $host . ($basePath !== '' ? $basePath : '');

    return $base;
}

/** Absolute URL using the current request host + app base path (keeps session cookies valid). */
function email_logs_absolute_url(string $pathAndQuery): string
{
    $relative = url($pathAndQuery);
    if (preg_match('#^https?://#i', $relative)) {
        return $relative;
    }

    $scheme = email_logs_request_is_https() ? 'https' : 'http';
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));

    return $scheme . '://' . $host . $relative;
}

function email_logs_verify_csrf_token(): void
{
    app_session_start();

    $sessionToken = $_SESSION['csrf_token'] ?? '';
    $postedToken = $_POST['csrf_token'] ?? '';

    if ($sessionToken === '' || $postedToken === '' || !hash_equals((string) $sessionToken, (string) $postedToken)) {
        throw new RuntimeException('Invalid request. Please refresh the page and try again.');
    }
}

function email_logs_upload_error_message(int $errorCode, string $fileName): string
{
    $label = $fileName !== '' ? '"' . $fileName . '"' : 'Attachment';

    return match ($errorCode) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => $label . ' exceeds the allowed upload size.',
        UPLOAD_ERR_PARTIAL => $label . ' was only partially uploaded. Please attach it again.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server upload folder is missing.',
        UPLOAD_ERR_CANT_WRITE => 'Server could not write the uploaded attachment.',
        UPLOAD_ERR_EXTENSION => 'A PHP extension blocked the attachment upload.',
        default => $label . ' could not be uploaded. Error code: ' . $errorCode . '.',
    };
}

function email_logs_collect_attachments(): array
{
    if (empty($_FILES['attachments']) || !isset($_FILES['attachments']['name'])) {
        return [];
    }

    $files = $_FILES['attachments'];
    $names = is_array($files['name']) ? $files['name'] : [$files['name']];
    $tmpNames = is_array($files['tmp_name'] ?? null) ? $files['tmp_name'] : [($files['tmp_name'] ?? '')];
    $errors = is_array($files['error'] ?? null) ? $files['error'] : [($files['error'] ?? UPLOAD_ERR_NO_FILE)];
    $sizes = is_array($files['size'] ?? null) ? $files['size'] : [($files['size'] ?? 0)];

    $attachments = [];
    $uploadDir = rtrim(str_replace('\\', '/', (string) STORAGE_PATH), '/') . '/attachments/' . date('Y/m/d');
    $uploadUrlBase = 'storage/attachments/' . date('Y/m/d');

    foreach ($names as $idx => $originalName) {
        $originalName = trim((string) $originalName);
        $error = (int) ($errors[$idx] ?? UPLOAD_ERR_NO_FILE);

        if ($error === UPLOAD_ERR_NO_FILE || $originalName === '') {
            continue;
        }

        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException(email_logs_upload_error_message($error, $originalName));
        }

        $tmpName = (string) ($tmpNames[$idx] ?? '');
        $size = (int) ($sizes[$idx] ?? 0);

        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new RuntimeException('Attachment "' . $originalName . '" is not available for upload.');
        }

        if ($size > 5 * 1024 * 1024) {
            throw new RuntimeException('Attachment "' . $originalName . '" exceeds the 5 MB limit.');
        }

        if (!is_dir($uploadDir)) {
            $parentDir = dirname($uploadDir);
            if (!is_dir($parentDir)) {
                if (!mkdir($parentDir, 0755, true) && !is_dir($parentDir)) {
                    throw new RuntimeException('Unable to create attachment storage parent directory.');
                }
            }
            if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                throw new RuntimeException('Unable to create attachment storage folder.');
            }
        }

        $ext = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        $safeExt = $ext !== '' ? preg_replace('/[^a-z0-9]/', '', $ext) : '';
        $safeName = uniqid('att_', true) . ($safeExt !== '' ? '.' . $safeExt : '');
        $targetPath = $uploadDir . '/' . $safeName;

        if (!move_uploaded_file($tmpName, $targetPath)) {
            throw new RuntimeException('Unable to save attachment "' . $originalName . '".');
        }

        $mime = function_exists('mime_content_type') ? (@mime_content_type($targetPath) ?: '') : '';
        $attachments[] = [
            'path' => $targetPath,
            'name' => $originalName,
            'size' => $size,
            'mime' => $mime !== '' ? $mime : 'application/octet-stream',
            'url' => url($uploadUrlBase . '/' . $safeName),
        ];
    }

    return $attachments;
}

/**
 * Email Logs workspace helpers (used only on this page; ticket view keeps legacy cards).
 */
function email_logs_rfc_unfold_block(string $headerBlock): string
{
    return (string) (preg_replace("/\r?\n[ \t]+/", ' ', $headerBlock) ?? $headerBlock);
}

function email_logs_parse_incoming_headers(?string $rawMessage): array
{
    $out = ['reply_to' => '', 'to' => '', 'cc' => ''];
    if ($rawMessage === null || trim($rawMessage) === '') {
        return $out;
    }

    $headerPart = preg_split("/\r?\n\r?\n/", $rawMessage, 2)[0] ?? $rawMessage;
    $headerPart = email_logs_rfc_unfold_block((string) $headerPart);

    foreach (preg_split("/\r?\n/", $headerPart) ?: [] as $line) {
        if (!preg_match('/^([A-Za-z-]+):\s*(.*)$/', trim((string) $line), $m)) {
            continue;
        }

        $name = strtolower($m[1]);
        $value = trim((string) $m[2]);
        if ($value === '') {
            continue;
        }

        if ($name === 'reply-to') {
            $out['reply_to'] = $value;
        } elseif ($name === 'to') {
            $out['to'] = $value;
        } elseif ($name === 'cc') {
            $out['cc'] = $value;
        }
    }

    return $out;
}

/**
 * @return array{0:string,1:array<string, mixed>}
 */
function email_logs_inbox_scope_sql(array $currentUser): array
{
    if (rbac_email_logs_full_visibility($currentUser)) {
        return ['1=1', []];
    }

    [$scopeSql, $scopeParams] = ticket_scope($currentUser, 't', true, true);
    $scopeCondition = preg_replace('/^\s*AND\s+/', '', $scopeSql) ?? '1=0';

    return ['(ei.ticket_id IS NULL OR t.ticket_id IS NULL OR (' . $scopeCondition . '))', $scopeParams];
}

/**
 * @return array<string, mixed>|null
 */
function email_logs_fetch_inbox_row(PDO $pdo, array $currentUser, int $logId, bool $withRaw = true): ?array
{
    static $cache = [];
    if ($logId <= 0) {
        return null;
    }

    $cacheKey = $logId . ':' . ($withRaw ? 'raw' : 'lite');
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    email_inbox_assets_ensure_schema($pdo);

    $columns = ['ei.id', 'ei.body', 'ei.ticket_id'];
    if ($withRaw) {
        $columns[] = 'ei.raw_message';
    }
    if (email_inbox_assets_has_column($pdo, 'body_preview_html')) {
        $columns[] = 'ei.body_preview_html';
    }

    [$scopeSql, $scopeParams] = email_logs_inbox_scope_sql($currentUser);
    $stmt = $pdo->prepare(
        'SELECT ' . implode(', ', $columns) . '
         FROM email_inbox_log ei
         LEFT JOIN tickets t ON t.ticket_id = ei.ticket_id
         WHERE ei.id = :id AND ' . $scopeSql . '
         LIMIT 1'
    );
    $stmt->execute(array_merge([':id' => $logId], $scopeParams));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $cache[$cacheKey] = null;

        return null;
    }

    $cache[$cacheKey] = $row;
    if ($withRaw) {
        $cache[$logId . ':lite'] = $row;
    }

    return $row;
}

function email_logs_inbox_raw_message_cached(PDO $pdo, array $currentUser, int $logId): string
{
    $row = email_logs_fetch_inbox_row($pdo, $currentUser, $logId, true);

    return trim((string) ($row['raw_message'] ?? ''));
}

/**
 * @return array{mime:string,data:string}|null
 */
function email_logs_cid_map_lookup(array $cidMap, string $cidToken): ?array
{
    $token = strtolower(trim($cidToken, " <>\t\r\n"));
    if ($token === '') {
        return null;
    }

    if (isset($cidMap[$token])) {
        return $cidMap[$token];
    }

    foreach ($cidMap as $mapToken => $val) {
        if (str_contains($mapToken, $token) || str_contains($token, $mapToken)) {
            return $val;
        }
    }

    return null;
}

function email_logs_rewrite_cid_to_storage_urls(string $direction, int $logId, string $html, string $rawMessage): string
{
    if ($logId <= 0 || $html === '' || !preg_match('/\bcid:/i', $html)) {
        return $html;
    }

    $cidMap = email_logs_mime_cid_lookup_map($rawMessage);
    if ($cidMap === []) {
        return $html;
    }

    $writeAsset = static function (string $cidToken, ?array $found) use ($direction, $logId): ?string {
        if ($found === null) {
            return null;
        }

        $binary = (string) ($found['data'] ?? '');
        if ($binary === '') {
            return null;
        }

        $mime = (string) ($found['mime'] ?? 'image/png');

        if ($direction === 'incoming') {
            return email_inbox_assets_write_cid_file($logId, $cidToken, $binary, $mime);
        }

        return null;
    };

    $html = preg_replace_callback(
        '/\bsrc=(["\'])cid:([^"\']+)\1/i',
        static function (array $m) use ($cidMap, $writeAsset): string {
            $found = email_logs_cid_map_lookup($cidMap, (string) $m[2]);
            $url = $writeAsset((string) $m[2], $found);
            if ($url === null) {
                return $m[0];
            }

            return 'src=' . $m[1] . $url . $m[1];
        },
        $html
    ) ?? $html;

    return preg_replace_callback(
        '/url\s*\(\s*(["\']?)cid:([^"\')\s]+)\1\s*\)/i',
        static function (array $m) use ($cidMap, $writeAsset): string {
            $found = email_logs_cid_map_lookup($cidMap, (string) $m[2]);
            $url = $writeAsset((string) $m[2], $found);
            if ($url === null) {
                return $m[0];
            }

            $quote = $m[1] !== '' ? $m[1] : '"';

            return 'url(' . $quote . $url . $quote . ')';
        },
        $html
    ) ?? $html;
}

function email_logs_inbox_rewrite_cid_to_storage_urls(int $inboxId, string $html, string $rawMessage): string
{
    return email_logs_rewrite_cid_to_storage_urls('incoming', $inboxId, $html, $rawMessage);
}

function email_logs_enrich_incoming_preview_html(string $html, string $rawMessage, int $logId): string
{
    if ($logId <= 0 || trim($rawMessage) === '') {
        return $html;
    }

    $html = email_logs_inbox_rewrite_cid_to_storage_urls($logId, $html, $rawMessage);
    $cidsInHtml = email_logs_extract_cid_tokens_from_html($html);
    $cidMap = email_logs_mime_cid_lookup_map($rawMessage);
    $append = '';

    foreach ($cidMap as $cid => $val) {
        $token = strtolower(trim((string) $cid, " <>\t\r\n"));
        if ($token === '') {
            continue;
        }

        $referenced = isset($cidsInHtml[$token]);
        if (!$referenced) {
            foreach ($cidsInHtml as $htmlToken => $_unused) {
                if (str_contains($htmlToken, $token) || str_contains($token, $htmlToken)) {
                    $referenced = true;
                    break;
                }
            }
        }

        if ($referenced) {
            continue;
        }

        $url = email_inbox_assets_write_cid_file(
            $logId,
            (string) $cid,
            (string) ($val['data'] ?? ''),
            (string) ($val['mime'] ?? 'image/png')
        );
        if ($url === null) {
            continue;
        }

        $append .= '<figure class="elw-inline-asset"><img src="'
            . htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
            . '" alt="Inline image" loading="eager" decoding="sync"></figure>';
    }

    try {
        $attachments = email_logs_mime_attachment_manifest($rawMessage, $logId);
        $htmlLower = strtolower($html);
        foreach ($attachments as $att) {
            $mime = (string) ($att['mime'] ?? '');
            if (!preg_match('#^image/#i', $mime)) {
                continue;
            }

            $name = strtolower((string) ($att['name'] ?? ''));
            $url = (string) ($att['url'] ?? '');
            if ($url === '') {
                continue;
            }

            if ($name !== '' && str_contains($htmlLower, $name)) {
                continue;
            }

            $append .= '<figure class="elw-inline-asset"><img src="'
                . htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
                . '" alt="'
                . htmlspecialchars((string) ($att['name'] ?? 'Image'), ENT_QUOTES, 'UTF-8')
                . '" loading="eager" decoding="sync"></figure>';
        }
    } catch (Throwable $ignored) {
        // Attachment manifest is best-effort.
    }

    if ($append === '') {
        return $html;
    }

    if ($html === '') {
        return email_logs_sanitize_email_html('<div class="elw-email-body">' . $append . '</div>');
    }

    if (str_contains($html, 'elw-email-body')) {
        return preg_replace(
            '#(</div>\s*</div>\s*)$#i',
            $append . '$1',
            $html,
            1
        ) ?? ($html . $append);
    }

    return $html . $append;
}

function email_logs_inbox_materialize_preview(PDO $pdo, array $currentUser, int $logId, string $body, string $rawMessage): string
{
    if ($logId <= 0) {
        return '';
    }

    $stored = email_inbox_assets_load_preview_html($pdo, $logId);
    if ($stored !== '' && preg_match('/\bcid:/i', $stored) && email_minio_enabled()) {
        $stored = resolveInlineImages($stored, email_logs_minio_cid_map_from_preview_meta($pdo, $logId));
    }
    if ($stored !== '' && (
        preg_match('/action=inline_img.*direction=incoming/i', $stored)
        || preg_match('/\bcid:/i', $stored)
        || !str_contains($stored, 'elw-email-doc')
    )) {
        $stored = '';
    }
    if ($stored !== '') {
        $html = email_logs_finalize_preview_html_images(
            email_logs_dedupe_preview_img_src(email_logs_sanitize_email_html($stored)),
            'incoming',
            $logId
        );

        return email_logs_rewrite_minio_inline_urls_for_logs($pdo, 'incoming', $logId, $html);
    }

    if ($rawMessage === '') {
        $rawMessage = email_logs_inbox_raw_message_cached($pdo, $currentUser, $logId);
    }
    if ($rawMessage === '') {
        return '';
    }

    $html = email_logs_workspace_preview_html($body, $rawMessage, 'incoming', true);
    $html = email_logs_enrich_incoming_preview_html($html, $rawMessage, $logId);
    if ($html === '') {
        return '';
    }

    $assetResult = email_inbox_assets_replace_data_uris_with_storage_urls($logId, $html);
    $html = (string) ($assetResult['html'] ?? $html);
    $html = email_logs_finalize_preview_html_images($html, 'incoming', $logId);
    $html = email_logs_dedupe_preview_img_src($html);

    email_inbox_assets_save_preview_html($pdo, $logId, $html, (array) ($assetResult['meta'] ?? []));

    return email_logs_rewrite_minio_inline_urls_for_logs($pdo, 'incoming', $logId, $html);
}

function email_logs_workspace_attachment_hint(array $email, string $direction): bool
{
    if (!empty($email['has_attachment_hint'])) {
        return true;
    }

    $raw = (string) ($email['raw_message'] ?? $email['raw_header_chunk'] ?? '');
    if ($raw !== '' && preg_match('/Content-Disposition:\s*attachment/im', $raw)) {
        return true;
    }

    $body = (string) ($email['body'] ?? '');
    if ($body !== '' && preg_match('/^---+\s*Attachments\s*---+/im', $body)) {
        return true;
    }

    if ($direction === 'outgoing' && $body !== '' && stripos($body, '--- Attachments ---') !== false) {
        return true;
    }

    return false;
}

function email_logs_workspace_snippet(string $text, int $maxLen = 140): string
{
    $text = trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $text = preg_replace('/\s+/', ' ', $text) ?? '';
    $text = trim((string) $text);
    if (mb_strlen($text) <= $maxLen) {
        return $text;
    }

    return rtrim(mb_substr($text, 0, $maxLen - 1)) . '…';
}

/**
 * MIME tree node:
 *   [
 *     'headers'      => array<string,string>,
 *     'content_type' => string (lowercased main, e.g. "text/html"),
 *     'charset'      => string,
 *     'encoding'     => string (Content-Transfer-Encoding raw),
 *     'content_id'   => string (trimmed of <>),
 *     'disposition'  => string (lowercased; "attachment" | "inline" | ""),
 *     'filename'     => string,
 *     'body'         => string (raw, undecoded, only when leaf),
 *     'parts'        => array<int, MIME node> (only when multipart),
 *   ]
 */

// Parses a raw RFC822 message into a recursive MIME tree.
function email_logs_mime_parse_message(string $rawMessage): array
{
    $rawMessage = (string) $rawMessage;
    if (trim($rawMessage) === '') {
        return [
            'headers' => [],
            'content_type' => 'text/plain',
            'charset' => '',
            'encoding' => '',
            'content_id' => '',
            'disposition' => '',
            'filename' => '',
            'body' => '',
            'parts' => [],
        ];
    }

    [$headerBlock, $bodyBlock] = array_pad(preg_split("/\r\n\r\n|\n\n|\r\r/", $rawMessage, 2), 2, '');
    $headers = email_imap_parse_headers((string) $headerBlock);

    return email_logs_mime_build_node($headers, (string) $bodyBlock);
}

// Builds one MIME node from already-parsed headers and its raw body block.
function email_logs_mime_build_node(array $headers, string $bodyBlock): array
{
    $contentTypeHeader = (string) ($headers['content-type'] ?? 'text/plain');
    $contentType = email_imap_header_main_value($contentTypeHeader);
    $charset = email_imap_header_param($contentTypeHeader, 'charset');
    $encoding = (string) ($headers['content-transfer-encoding'] ?? '');
    $contentId = trim((string) ($headers['content-id'] ?? ''), " <>\t\r\n");
    $dispositionHeader = (string) ($headers['content-disposition'] ?? '');
    $disposition = strtolower(trim(explode(';', $dispositionHeader, 2)[0] ?? ''));
    $filename = email_imap_header_param($dispositionHeader, 'filename');
    if ($filename === '') {
        $filename = email_imap_header_param($contentTypeHeader, 'name');
    }

    $node = [
        'headers' => $headers,
        'content_type' => $contentType,
        'charset' => $charset,
        'encoding' => $encoding,
        'content_id' => $contentId,
        'disposition' => $disposition,
        'filename' => $filename,
        'body' => '',
        'parts' => [],
    ];

    if (strpos($contentType, 'multipart/') === 0) {
        $boundary = email_imap_header_param($contentTypeHeader, 'boundary');
        if ($boundary === '') {
            $node['body'] = $bodyBlock;
            return $node;
        }

        $segments = explode('--' . $boundary, $bodyBlock);
        // Drop preamble; explode keeps everything between boundaries plus epilogue
        array_shift($segments);

        foreach ($segments as $segment) {
            $segment = ltrim($segment, "\r\n");
            // End-boundary marker is "--<boundary>--" → segment starts with "--"
            if ($segment === '' || str_starts_with($segment, '--')) {
                continue;
            }

            [$segHeaderBlock, $segBody] = array_pad(preg_split("/\r\n\r\n|\n\n|\r\r/", $segment, 2), 2, '');
            $segHeaders = email_imap_parse_headers((string) $segHeaderBlock);
            $node['parts'][] = email_logs_mime_build_node($segHeaders, (string) $segBody);
        }

        return $node;
    }

    $node['body'] = $bodyBlock;
    return $node;
}

// Recursively finds the best-fit text/html body part. Skips attached .html files.
function email_logs_mime_find_html_body(array $node): ?array
{
    $disposition = (string) ($node['disposition'] ?? '');

    if ($node['content_type'] === 'text/html') {
        if (strpos($disposition, 'attachment') !== false) {
            return null;
        }
        return $node;
    }

    if (strpos((string) $node['content_type'], 'multipart/') === 0) {
        // multipart/alternative: prefer the LAST suitable part (typically text/html sits after text/plain)
        if ($node['content_type'] === 'multipart/alternative') {
            $parts = $node['parts'] ?? [];
            for ($i = count($parts) - 1; $i >= 0; $i--) {
                $found = email_logs_mime_find_html_body($parts[$i]);
                if ($found !== null) {
                    return $found;
                }
            }
            return null;
        }

        foreach ($node['parts'] ?? [] as $part) {
            $found = email_logs_mime_find_html_body($part);
            if ($found !== null) {
                return $found;
            }
        }
    }

    return null;
}

// Recursively finds the first text/plain body part (used as fallback when no HTML).
function email_logs_mime_find_plain_body(array $node): ?array
{
    $disposition = (string) ($node['disposition'] ?? '');

    if ($node['content_type'] === 'text/plain' && strpos($disposition, 'attachment') === false) {
        return $node;
    }

    if (strpos((string) $node['content_type'], 'multipart/') === 0) {
        foreach ($node['parts'] ?? [] as $part) {
            $found = email_logs_mime_find_plain_body($part);
            if ($found !== null) {
                return $found;
            }
        }
    }

    return null;
}

// Walks the MIME tree and indexes all image parts by Content-ID.
// Returns: [cid => ['mime' => string, 'data' => binary string]]
function email_logs_mime_collect_inline_images(array $node, array &$out = []): array
{
    $contentType = (string) ($node['content_type'] ?? '');
    if (preg_match('#^image/#i', $contentType)) {
        $cid = (string) ($node['content_id'] ?? '');
        if ($cid !== '' && (string) ($node['body'] ?? '') !== '') {
            $raw = email_logs_mime_decode_part_body($node);
            if ($raw !== '' && !isset($out[$cid])) {
                $out[$cid] = [
                    'mime' => $contentType,
                    'data' => $raw,
                ];
            }
        }
    }

    foreach ($node['parts'] ?? [] as $part) {
        email_logs_mime_collect_inline_images($part, $out);
    }

    return $out;
}

/**
 * @return array<string, array{mime:string,data:string}>
 */
function email_logs_mime_cid_lookup_map(string $rawMessage): array
{
    $rawMessage = trim($rawMessage);
    if ($rawMessage === '') {
        return [];
    }

    try {
        $tree = email_logs_mime_parse_message($rawMessage);
        $map = email_logs_mime_collect_inline_images($tree);
    } catch (Throwable $ignored) {
        return [];
    }

    $normalized = [];
    foreach ($map as $key => $val) {
        $token = strtolower(trim((string) $key, " <>\t\r\n"));
        if ($token !== '' && !isset($normalized[$token])) {
            $normalized[$token] = $val;
        }
    }

    return $normalized;
}

/**
 * @return array{mime:string,data:string}|null
 */
function email_logs_find_inline_image_by_cid(string $rawMessage, string $cid): ?array
{
    $cid = strtolower(trim($cid, " <>\t\r\n"));
    if ($cid === '') {
        return null;
    }

    $map = email_logs_mime_cid_lookup_map($rawMessage);
    if (isset($map[$cid])) {
        return $map[$cid];
    }

    foreach ($map as $token => $val) {
        if (str_contains($token, $cid) || str_contains($cid, $token)) {
            return $val;
        }
    }

    return null;
}

/**
 * @return array<string, true>
 */
function email_logs_extract_cid_tokens_from_html(string $html): array
{
    $cids = [];
    if ($html === '' || !preg_match('/\bcid:/i', $html)) {
        return $cids;
    }

    if (preg_match_all('/\bcid:([^"\'\s>]+)/i', $html, $matches)) {
        foreach ($matches[1] as $cid) {
            $token = strtolower(trim((string) $cid, '<> '));
            if ($token !== '') {
                $cids[$token] = true;
            }
        }
    }

    return $cids;
}

function email_logs_mime_is_attachment_part(array $node, array $cidsInHtml): bool
{
    $contentType = strtolower((string) ($node['content_type'] ?? ''));
    if (str_starts_with($contentType, 'multipart/')) {
        return false;
    }

    $disposition = strtolower((string) ($node['disposition'] ?? ''));
    $cid = strtolower(trim((string) ($node['content_id'] ?? ''), '<> '));
    $filename = trim((string) ($node['filename'] ?? ''));

    if (strpos($disposition, 'attachment') !== false) {
        return $filename !== '' || !in_array($contentType, ['text/plain', 'text/html'], true);
    }

    if ($cid !== '' && isset($cidsInHtml[$cid])) {
        return false;
    }

    if (strpos($disposition, 'inline') !== false && preg_match('#^image/#i', $contentType)) {
        return false;
    }

    if ($filename === '') {
        return false;
    }

    return !in_array($contentType, ['text/plain', 'text/html'], true);
}

/**
 * @return array<int, array<string, mixed>>
 */
function email_logs_mime_attachment_manifest(string $rawMessage, int $logId): array
{
    if ($logId <= 0 || trim($rawMessage) === '') {
        return [];
    }

    try {
        $tree = email_logs_mime_parse_message($rawMessage);
        $html = email_logs_extract_html_from_raw($rawMessage);
        $cidsInHtml = email_logs_extract_cid_tokens_from_html($html);
        $items = [];
        $idx = 0;
        email_logs_mime_collect_attachment_parts($tree, $cidsInHtml, $logId, $items, $idx);

        return $items;
    } catch (Throwable $ignored) {
        return [];
    }
}

/**
 * @param array<int, array<string, mixed>> $out
 */
function email_logs_mime_collect_attachment_parts(
    array $node,
    array $cidsInHtml,
    int $logId,
    array &$out,
    int &$idx
): void {
    if (email_logs_mime_is_attachment_part($node, $cidsInHtml)) {
        $filename = trim((string) ($node['filename'] ?? ''));
        if ($filename === '') {
            $filename = 'attachment-' . ($idx + 1);
        }

        $contentType = (string) ($node['content_type'] ?? 'application/octet-stream');
        $decodedBody = email_logs_mime_decode_part_body($node);
        $out[] = [
            'index' => $idx,
            'name' => $filename,
            'mime' => $contentType !== '' ? $contentType : 'application/octet-stream',
            'size' => strlen($decodedBody),
            'disposition' => 'attachment',
            'url' => url(
                'emails/logs.php?' . http_build_query([
                    'action' => 'email_attachment',
                    'direction' => 'incoming',
                    'log_id' => $logId,
                    'idx' => $idx,
                ])
            ),
        ];
        $idx++;
    }

    foreach ($node['parts'] ?? [] as $part) {
        email_logs_mime_collect_attachment_parts($part, $cidsInHtml, $logId, $out, $idx);
    }
}

/**
 * @return array<int, array<string, mixed>>
 */
function email_logs_mime_attachment_part_by_index(string $rawMessage, int $index): ?array
{
    if (trim($rawMessage) === '' || $index < 0) {
        return null;
    }

    $tree = email_logs_mime_parse_message($rawMessage);
    $html = email_logs_extract_html_from_raw($rawMessage);
    $cidsInHtml = email_logs_extract_cid_tokens_from_html($html);
    $cursor = 0;
    $found = null;
    email_logs_mime_find_attachment_part_by_index($tree, $cidsInHtml, $index, $cursor, $found);

    return $found;
}

function email_logs_mime_find_attachment_part_by_index(
    array $node,
    array $cidsInHtml,
    int $targetIndex,
    int &$cursor,
    ?array &$found
): void {
    if ($found !== null) {
        return;
    }

    if (email_logs_mime_is_attachment_part($node, $cidsInHtml)) {
        if ($cursor === $targetIndex) {
            $found = $node;

            return;
        }
        $cursor++;
    }

    foreach ($node['parts'] ?? [] as $part) {
        email_logs_mime_find_attachment_part_by_index($part, $cidsInHtml, $targetIndex, $cursor, $found);
        if ($found !== null) {
            return;
        }
    }
}

function email_logs_mime_decode_part_body(array $node): string
{
    $body = (string) ($node['body'] ?? '');
    $encoding = strtolower((string) ($node['encoding'] ?? ''));
    if ($encoding === 'base64') {
        $compact = preg_replace('/\s+/', '', $body) ?? $body;
        $decoded = base64_decode($compact, true);

        return $decoded !== false ? $decoded : '';
    }
    if ($encoding === 'quoted-printable') {
        return (string) quoted_printable_decode($body);
    }

    return $body;
}

function email_logs_dedupe_preview_img_src(string $html): string
{
    if ($html === '' || stripos($html, '<img') === false) {
        return $html;
    }

    $seen = [];

    $result = preg_replace_callback(
        '/<img\b[^>]*>/i',
        static function (array $m) use (&$seen): string {
            $tag = $m[0];
            if (!preg_match('/\bsrc=(["\'])([^"\']+)\1/i', $tag, $srcMatch)) {
                return $tag;
            }
            $srcKey = strtolower(trim((string) $srcMatch[2]));
            if ($srcKey === '') {
                return $tag;
            }
            if (isset($seen[$srcKey])) {
                return '';
            }
            $seen[$srcKey] = true;

            return $tag;
        },
        $html
    );

    return is_string($result) ? $result : $html;
}

/**
 * @return array<int, array<string, mixed>>
 */
function email_logs_workspace_attachments_for_row(
    PDO $pdo,
    array $email,
    string $direction,
    string $previewHtml,
    array $outboxAttachmentMap = []
): array {
    $logId = (int) ($email['log_id'] ?? 0);
    if ($logId <= 0) {
        return [];
    }

    if ($direction === 'outgoing') {
        $list = $outboxAttachmentMap[$logId] ?? email_attachments_list_for_outbox($pdo, $logId);

        return email_attachments_filter_for_preview($list, $previewHtml);
    }

    $minioItems = email_logs_minio_attachment_items($pdo, 'incoming', $logId);
    if ($minioItems !== []) {
        return email_attachments_filter_for_preview($minioItems, $previewHtml);
    }

    $raw = (string) ($email['raw_message'] ?? '');
    if (trim($raw) === '') {
        return [];
    }

    if (!email_logs_workspace_attachment_hint($email, 'incoming')
        && stripos($raw, 'Content-Disposition:') === false) {
        return [];
    }

    $manifest = email_logs_mime_attachment_manifest($raw, $logId);

    return email_attachments_filter_for_preview($manifest, $previewHtml);
}

function email_logs_minio_attachment_items(PDO $pdo, string $direction, int $logId): array
{
    if (!email_minio_enabled() || $logId <= 0) {
        return [];
    }

    $items = email_minio_attachment_preview_items($pdo, $direction, $logId);
    foreach ($items as $idx => &$item) {
        $item['index'] = $idx;
        $item['url'] = url(
            'emails/logs.php?' . http_build_query([
                'action' => 'email_attachment',
                'direction' => $direction,
                'log_id' => $logId,
                'idx' => $idx,
            ])
        );
    }
    unset($item);

    return $items;
}

function email_logs_minio_inline_proxy_url(string $direction, int $logId, int $idx): string
{
    return url(
        'emails/logs.php?' . http_build_query([
            'action' => 'email_inline_file',
            'direction' => $direction,
            'log_id' => $logId,
            'idx' => $idx,
        ])
    );
}

function email_logs_rewrite_minio_inline_urls_for_logs(PDO $pdo, string $direction, int $logId, string $html): string
{
    if ($html === '' || !email_minio_enabled() || $logId <= 0) {
        return $html;
    }

    $rows = email_minio_file_mappings_for_email($pdo, $direction, $logId, 'inline');
    foreach ($rows as $idx => $row) {
        $minioUrl = trim((string) ($row['minio_url'] ?? ''));
        if ($minioUrl === '') {
            continue;
        }

        $proxyUrl = email_logs_minio_inline_proxy_url($direction, $logId, (int) $idx);
        $html = str_replace($minioUrl, $proxyUrl, $html);
        $html = str_replace(htmlspecialchars($minioUrl, ENT_QUOTES, 'UTF-8'), htmlspecialchars($proxyUrl, ENT_QUOTES, 'UTF-8'), $html);
    }

    return $html;
}

function email_logs_minio_cid_map_from_preview_meta(PDO $pdo, int $inboxId): array
{
    if (!email_minio_enabled() || $inboxId <= 0) {
        return [];
    }

    try {
        $stmt = $pdo->prepare('SELECT body_assets_meta FROM email_inbox_log WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $inboxId]);
        $metaJson = trim((string) ($stmt->fetchColumn() ?: ''));
        if ($metaJson === '') {
            return [];
        }

        $meta = json_decode($metaJson, true);
        if (!is_array($meta) || empty($meta['assets']) || !is_array($meta['assets'])) {
            return [];
        }

        $map = [];
        foreach ($meta['assets'] as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $cid = email_minio_normalize_cid_token((string) ($asset['cid'] ?? ''));
            $url = trim((string) ($asset['url'] ?? ''));
            if ($cid !== '' && $url !== '') {
                $map[$cid] = $url;
            }
        }

        return $map;
    } catch (Throwable $ignored) {
        return [];
    }
}

// Replaces <img src="cid:..."> with data: URIs. Caps per-image and total budget so the JSON payload stays bounded.
// Missing/oversized CIDs are replaced with a 1x1 transparent placeholder so layout doesn't shift.
function email_logs_inline_cid_images(string $html, array $cidMap, int $maxBytesPerImage = 65536, int $maxTotalBudget = 90000): string
{
    if ($html === '' || empty($cidMap)) {
        return $html;
    }

    $placeholderSrc = 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==';
    // Index CID map with normalized (no-angle-bracket, lowercased) keys for tolerant lookup
    $normalized = [];
    foreach ($cidMap as $key => $val) {
        $k = strtolower(trim((string) $key, " <>\t\r\n"));
        if ($k !== '' && !isset($normalized[$k])) {
            $normalized[$k] = $val;
        }
    }

    $usedBudget = 0;
    $result = preg_replace_callback(
        '/(<img\b[^>]*\bsrc\s*=\s*["\'])cid:([^"\']+)(["\'])/i',
        static function (array $m) use ($normalized, $maxBytesPerImage, $maxTotalBudget, $placeholderSrc, &$usedBudget): string {
            $cid = strtolower(trim($m[2], " <>\t\r\n"));
            $found = $normalized[$cid] ?? null;

            if ($found === null) {
                return $m[1] . $placeholderSrc . $m[3];
            }
            $rawSize = strlen((string) $found['data']);
            if ($rawSize <= 0 || $rawSize > $maxBytesPerImage) {
                return $m[1] . $placeholderSrc . $m[3];
            }
            $b64 = base64_encode((string) $found['data']);
            $b64Size = strlen($b64);
            if ($usedBudget + $b64Size > $maxTotalBudget) {
                return $m[1] . $placeholderSrc . $m[3];
            }
            $usedBudget += $b64Size;
            return $m[1] . 'data:' . (string) $found['mime'] . ';base64,' . $b64 . $m[3];
        },
        $html
    );

    return is_string($result) ? $result : $html;
}

// Extracts the best-fit HTML body from a raw RFC822 message.
function email_logs_extract_html_from_raw(?string $rawMessage, bool $deferCidInlining = false): string
{
    if ($rawMessage === null || trim((string) $rawMessage) === '') {
        return '';
    }

    try {
        $tree = email_logs_mime_parse_message((string) $rawMessage);
    } catch (Throwable $ignored) {
        return '';
    }

    $htmlNode = email_logs_mime_find_html_body($tree);
    $decoded = '';

    if ($htmlNode !== null) {
        try {
            $decoded = email_imap_decode_part_body(
                (string) ($htmlNode['body'] ?? ''),
                (string) ($htmlNode['encoding'] ?? ''),
                (string) ($htmlNode['charset'] ?? '')
            );
        } catch (Throwable $ignored) {
            $decoded = (string) ($htmlNode['body'] ?? '');
        }
    } else {
        $plainNode = email_logs_mime_find_plain_body($tree);
        if ($plainNode !== null) {
            try {
                $plain = email_imap_decode_part_body(
                    (string) ($plainNode['body'] ?? ''),
                    (string) ($plainNode['encoding'] ?? ''),
                    (string) ($plainNode['charset'] ?? '')
                );
            } catch (Throwable $ignored) {
                $plain = (string) ($plainNode['body'] ?? '');
            }
            $decoded = email_logs_plain_text_to_preview_html((string) $plain);
        }
    }

    $decoded = trim((string) $decoded);
    if ($decoded === '') {
        return '';
    }

    if (!$deferCidInlining) {
        try {
            $cidMap = email_logs_mime_collect_inline_images($tree);
            if (!empty($cidMap)) {
                $decoded = email_logs_inline_cid_images($decoded, $cidMap, 524288, 1048576);
            }
        } catch (Throwable $ignored) {
            // Keep HTML; CID rewrite may still run later.
        }
    }

    return $decoded;
}

function email_logs_sanitize_style_block(string $css): string
{
    $css = (string) $css;
    $css = preg_replace('/@import\b[^;]*;?/i', '', $css) ?? '';
    $css = preg_replace('/expression\s*\(/i', '', $css) ?? '';
    $css = preg_replace('/javascript\s*:/i', '', $css) ?? '';
    $css = preg_replace('/behavior\s*:/i', '', $css) ?? '';
    $css = preg_replace('/-moz-binding\s*:/i', '', $css) ?? '';
    $css = preg_replace('/binding\s*:/i', '', $css) ?? '';

    return trim($css);
}

function email_logs_collect_document_styles(string $html): string
{
    $blocks = '';
    if (!preg_match_all('#<style\b[^>]*>(.*?)</style>#is', $html, $matches)) {
        return '';
    }

    foreach ($matches[1] as $block) {
        $clean = email_logs_sanitize_style_block((string) $block);
        if ($clean !== '') {
            $blocks .= '<style type="text/css">' . $clean . '</style>';
        }
    }

    return $blocks;
}

function email_logs_extract_body_inner_html(string $html): string
{
    if (preg_match('/<body\b[^>]*>(.*)<\/body>/is', $html, $bodyMatch)) {
        return trim((string) $bodyMatch[1]);
    }

    $html = preg_replace('#<!DOCTYPE\b[^>]*>#i', '', $html) ?? $html;
    $html = preg_replace('#<head\b[^>]*>.*?</head>#is', '', $html) ?? $html;
    $html = preg_replace('#</?(?:html|body)\b[^>]*>#i', '', $html) ?? $html;

    return trim($html);
}

function email_logs_strip_inline_style_tags(string $html): string
{
    $html = preg_replace('#<style\b[^>]*>.*?</style>#is', '', $html) ?? $html;

    return preg_replace('#<!--\[if[^\]]*\]>.*?<!\[endif\]-->#is', '', $html) ?? $html;
}

function email_logs_strip_orphan_css_text(string $inner): string
{
    if ($inner === '' || !preg_match('/<[a-z][^>]*>/i', $inner, $match, PREG_OFFSET_CAPTURE)) {
        return $inner;
    }

    $firstTagPos = (int) ($match[0][1] ?? 0);
    if ($firstTagPos < 12) {
        return $inner;
    }

    $prefix = trim(substr($inner, 0, $firstTagPos));
    if ($prefix === '') {
        return $inner;
    }

    $looksLikeCss = preg_match('/@media|^\s*[\.\#\[{]|!\s*important/i', $prefix) === 1
        && preg_match('/\{[^}]*\}/s', $prefix) === 1;
    $looksLikeReadable = preg_match('/\b(dear|hi|hello|thanks|regards|subject|invoice|order|payment)\b/i', $prefix) === 1;

    if ($looksLikeCss && !$looksLikeReadable) {
        return ltrim(substr($inner, $firstTagPos));
    }

    return $inner;
}

function email_logs_plain_text_to_preview_html(string $text): string
{
    $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
    if ($text === '') {
        return '';
    }

    $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $escaped = preg_replace_callback(
        '/\bhttps?:\/\/[^\s<]+/i',
        static function (array $m): string {
            $url = rtrim((string) $m[0], '.,;:!?)>]');
            $trail = substr((string) $m[0], strlen($url));
            $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            return '<a href="' . $safeUrl . '" target="_blank" rel="noopener noreferrer">' . $safeUrl . '</a>' . $trail;
        },
        $escaped
    ) ?? $escaped;

    return '<div class="elw-plain-body">' . nl2br($escaped, false) . '</div>';
}

function email_logs_linkify_plain_html(string $html): string
{
    if ($html === '' || preg_match('/<[a-z][^>]*>/i', $html)) {
        return $html;
    }

    return email_logs_plain_text_to_preview_html($html);
}

function email_logs_sanitize_email_html(string $html): string
{
    $html = (string) $html;
    if ($html === '') {
        return '';
    }

    $styleBlocks = email_logs_collect_document_styles($html);
    $inner = email_logs_extract_body_inner_html($html);
    $inner = email_logs_strip_inline_style_tags($inner);
    $inner = email_logs_strip_orphan_css_text($inner);

    $inner = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $inner) ?? '';
    $inner = preg_replace('#<noscript\b[^>]*>.*?</noscript>#is', '', $inner) ?? '';
    $inner = preg_replace('#<(?:iframe|object|embed|meta|base|link|form)\b[^>]*>.*?</(?:iframe|object|embed|meta|base|link|form)>#is', '', $inner) ?? '';
    $inner = preg_replace('#<(?:iframe|object|embed|meta|base|link|form)\b[^>]*/>#is', '', $inner) ?? '';
    $inner = preg_replace('/\s+on[a-z][a-z0-9_-]*\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $inner) ?? '';
    $inner = preg_replace('/\s+on[a-z][a-z0-9_-]*\s*=\s*[^\s>]+/i', '', $inner) ?? '';

    $allowed = '<div><span><p><br><br/><table><tbody><thead><tfoot><tr><th><td><caption><col><colgroup>'
        . '<a><img><ul><ol><li><strong><b><em><i><u><s><strike><h1><h2><h3><h4><h5><h6>'
        . '<blockquote><pre><code><font><center><hr><section><article><header><footer><main><small><sub><sup><del><ins>'
        . '<figure><figcaption><label><abbr><cite><q><mark><ruby><rt><rp><wbr><nobr><address><dl><dt><dd><time><var><kbd><samp>';

    $inner = strip_tags($inner, $allowed);
    $inner = preg_replace('#\s(href|src)\s*=\s*(\'|")\s*javascript:[^"\']*#i', ' $1=$2#', $inner) ?? '';
    $inner = preg_replace('#\s(href|src)\s*=\s*javascript:[^\s>]+#i', ' $1="#"', $inner) ?? '';
    $inner = preg_replace('#\sstyle\s*=\s*(\'|")[^"\']*(expression\s*\(|@import|javascript\s*:)[^"\']*\1#i', '', $inner) ?? '';

    $inner = trim($inner);
    if ($inner === '' && $styleBlocks === '') {
        return '';
    }

    return '<div class="elw-email-doc">' . $styleBlocks . '<div class="elw-email-body">' . $inner . '</div></div>';
}

/**
 * Replaces data:image src values with preview URLs without scanning megabytes of base64 (PCRE-safe).
 */
function email_logs_rewrite_data_uri_src_for_preview(string $direction, int $logId, string $html): string
{
    if ($logId <= 0 || $html === '' || stripos($html, 'data:image/') === false) {
        return $html;
    }

    $idx = 0;
    $searchFrom = 0;
    $needle = 'data:image/';

    while (($dataPos = stripos($html, $needle, $searchFrom)) !== false) {
        $srcPos = strripos(substr($html, 0, $dataPos), 'src');
        if ($srcPos === false) {
            $searchFrom = $dataPos + strlen($needle);
            continue;
        }

        $quotePos = $dataPos - 1;
        while ($quotePos > $srcPos && ($html[$quotePos] === ' ' || $html[$quotePos] === "\t")) {
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

        $url = email_logs_inline_img_preview_url($direction, $logId, null, $idx);
        $idx++;
        $html = substr($html, 0, $quotePos + 1) . $url . substr($html, $endQuote);
        $searchFrom = $quotePos + 1 + strlen($url);
    }

    return $html;
}

/**
 * Extracts img src from attribute string without regex on megabyte data: URIs (PCRE-safe).
 *
 * @return array{0:string,1:string,2:int,3:int}|null [quote, src, valueStart, valueEnd]
 */
function email_logs_parse_img_src_from_attrs(string $attrs): ?array
{
    if (!preg_match('/\bsrc\s*=\s*(["\'])/i', $attrs, $qm, PREG_OFFSET_CAPTURE)) {
        return null;
    }

    $quote = (string) $qm[1][0];
    $valueStart = (int) $qm[1][1] + 1;
    $valueEnd = strpos($attrs, $quote, $valueStart);
    if ($valueEnd === false) {
        return null;
    }

    $src = trim(substr($attrs, $valueStart, $valueEnd - $valueStart));
    if ($src === '') {
        return null;
    }

    return [$quote, $src, $valueStart, $valueEnd];
}

function email_logs_replace_img_src_in_attrs(string $attrs, string $quote, int $valueStart, int $valueEnd, string $newSrc): string
{
    return substr($attrs, 0, $valueStart) . $newSrc . substr($attrs, $valueEnd);
}

/**
 * Production preview pass: strip lazy-loading, force eager decode, absolutize relative img src.
 * Preserves data:, cid:, blob:, and existing absolute URLs.
 */
function email_logs_finalize_preview_html_images(string $html, string $direction = '', int $logId = 0): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }

    if (stripos($html, 'data:image/') !== false && $logId > 0 && $direction !== '') {
        $html = email_logs_rewrite_data_uri_src_for_preview($direction, $logId, $html);
    }

    $html = preg_replace('/\sloading\s*=\s*["\']?\s*lazy\s*["\']?/i', '', $html) ?? $html;
    $html = preg_replace('/\sfetchpriority\s*=\s*["\']?\s*low\s*["\']?/i', '', $html) ?? $html;

    $base = email_logs_public_base_url();
    $httpsDefault = str_starts_with(strtolower($base), 'https://');
    $urlParts = parse_url($base);
    if (!is_array($urlParts)) {
        $urlParts = [];
    }

    $searchFrom = 0;
    while (($imgPos = stripos($html, '<img', $searchFrom)) !== false) {
        $tagEnd = strpos($html, '>', $imgPos);
        if ($tagEnd === false) {
            break;
        }

        $attrs = substr($html, $imgPos + 4, $tagEnd - $imgPos - 4);
        $parsed = email_logs_parse_img_src_from_attrs($attrs);
        if ($parsed === null) {
            $searchFrom = $tagEnd + 1;
            continue;
        }

        [$quote, $src, $valueStart, $valueEnd] = $parsed;
        $newSrc = $src;

        if (!preg_match('#^(https?:|data:|cid:|blob:)#i', $src)) {
            if (str_starts_with($src, '//')) {
                $newSrc = ($httpsDefault ? 'https:' : 'http:') . $src;
            } elseif (str_starts_with($src, '/')) {
                $scheme = (string) ($urlParts['scheme'] ?? 'https');
                $host = (string) ($urlParts['host'] ?? '');
                $port = isset($urlParts['port']) ? ':' . $urlParts['port'] : '';
                $newSrc = $scheme . '://' . $host . $port . $src;
            } else {
                $newSrc = $base . '/' . ltrim($src, '/');
            }
        }

        $newAttrs = $attrs;
        if ($newSrc !== $src) {
            $newAttrs = email_logs_replace_img_src_in_attrs($attrs, $quote, $valueStart, $valueEnd, $newSrc);
        }

        $newAttrs = preg_replace('/\sloading\s*=\s*["\']?\s*lazy\s*["\']?/i', '', $newAttrs) ?? $newAttrs;
        $newAttrs = preg_replace('/\sdecoding\s*=\s*["\']?\s*async\s*["\']?/i', '', $newAttrs) ?? $newAttrs;

        if (!preg_match('/\bloading\s*=/i', $newAttrs)) {
            $newAttrs .= ' loading="eager"';
        }
        if (!preg_match('/\bdecoding\s*=/i', $newAttrs)) {
            $newAttrs .= ' decoding="sync"';
        }
        if (!preg_match('/\bstyle\s*=/i', $newAttrs)) {
            $newAttrs .= ' style="max-width:100%;height:auto;display:block;"';
        }

        $newTag = '<img' . $newAttrs . '>';
        $html = substr($html, 0, $imgPos) . $newTag . substr($html, $tagEnd + 1);
        $searchFrom = $imgPos + strlen($newTag);
    }

    return $html;
}

/**
 * Sanitized HTML → inline snapshot URLs (outgoing) → image finalize for read preview.
 */
function email_logs_prepare_email_body_for_preview(
    PDO $pdo,
    string $html,
    string $direction,
    int $logId,
    string $rawBody = '',
    string $rawMessage = ''
): string {
    $html = trim($html);
    $rawBody = trim($rawBody);
    $rawMessage = trim($rawMessage);

    if ($html === '' && $logId > 0 && $direction === 'outgoing' && stripos($rawBody, 'data:image/') !== false) {
        $html = '<p><img src="' . htmlspecialchars(
            email_logs_inline_img_preview_url($direction, $logId, null, 0),
            ENT_QUOTES,
            'UTF-8'
        ) . '" alt="Inline image"></p>';
    }

    if ($html !== '' && $logId > 0 && $direction === 'outgoing') {
        $html = email_logs_rewrite_preview_inline_sources(
            $pdo,
            $direction,
            $logId,
            $html,
            $rawBody !== '' ? $rawBody : $html
        );
        if (stripos($html, 'data:image/') !== false) {
            $html = email_logs_rewrite_data_uri_src_for_preview($direction, $logId, $html);
        }
    }

    if ($html !== '' && $logId > 0 && $direction === 'incoming') {
        $rawMime = $rawMessage !== '' ? $rawMessage : $rawBody;
        if ($rawMime !== '' && (preg_match('/\bcid:/i', $html) || stripos($html, 'data:image/') !== false)) {
            $html = email_logs_rewrite_preview_inline_sources($pdo, $direction, $logId, $html, $rawMime);
        }
    }

    if (stripos($html, 'data:image/') !== false && $logId > 0 && $direction === 'outgoing') {
        $html = email_logs_rewrite_data_uri_src_for_preview($direction, $logId, $html);
    }

    return email_logs_finalize_preview_html_images($html, $direction, $logId);
}

function email_logs_workspace_preview_html(string $body, ?string $rawMessage, string $direction, bool $deferCidInlining = false): string
{
    $body = (string) $body;
    $fromRaw = $rawMessage !== null && trim($rawMessage) !== ''
        ? email_logs_extract_html_from_raw($rawMessage, $deferCidInlining)
        : '';
    $candidate = $fromRaw !== '' ? $fromRaw : $body;
    $candidate = email_logs_linkify_plain_html($candidate);

    if ($candidate !== '' && preg_match('/<[a-z][^>]*>/i', $candidate)) {
        return email_logs_sanitize_email_html($candidate);
    }

    return '';
}

function email_logs_outgoing_workspace_preview_html(string $body, bool $bodyIsHtml): string
{
    $body = trim((string) $body);
    if ($body === '') {
        return '';
    }

    $looksHtml = $bodyIsHtml
        || preg_match('/<(?:img|p|div|br|table|span|blockquote)\b/i', $body);

    if ($looksHtml && preg_match('/<[a-z][^>]*>/i', $body)) {
        return email_logs_sanitize_email_html($body);
    }

    return email_logs_linkify_plain_html($body);
}

function email_logs_outgoing_preview_html_from_row(array $email): string
{
    $stored = trim((string) ($email['body_preview_html'] ?? ''));
    if ($stored !== '') {
        $html = email_logs_finalize_preview_html_images(email_logs_sanitize_email_html($stored));

        return email_logs_dedupe_preview_img_src($html);
    }

    $body = (string) ($email['body'] ?? '');

    return email_logs_outgoing_workspace_preview_html($body, !empty($email['body_is_html']));
}

function email_logs_resolve_body_html_for_preview(string $html): string
{
    return email_logs_sanitize_email_html($html);
}

function email_logs_inline_images_ensure_table(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $ready = true;
}

function email_logs_persist_outbox_inline_parts(PDO $pdo, int $outboxId, array $inlineParts): void
{
    if ($outboxId <= 0 || $inlineParts === []) {
        return;
    }

    email_logs_inline_images_ensure_table($pdo);
    $pdo->prepare('DELETE FROM email_outbox_inline_images WHERE outbox_id = :id')->execute([':id' => $outboxId]);

    $dir = rtrim(str_replace('\\', '/', (string) STORAGE_PATH), '/') . '/email_inline/' . $outboxId;
    if (!is_dir($dir)) {
        $parentDir = dirname($dir);
        if (!is_dir($parentDir) && !mkdir($parentDir, 0755, true) && !is_dir($parentDir)) {
            return;
        }
        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
    }

    $stmt = $pdo->prepare(
        'INSERT INTO email_outbox_inline_images (outbox_id, cid_token, mime_type, file_path)
         VALUES (:outbox_id, :cid_token, :mime_type, :file_path)'
    );

    foreach ($inlineParts as $part) {
        $data = (string) ($part['data'] ?? '');
        if ($data === '') {
            continue;
        }

        $cid = trim((string) ($part['cid'] ?? ''));
        if ($cid === '') {
            continue;
        }

        $name = (string) ($part['name'] ?? 'inline.png');
        $path = $dir . '/' . preg_replace('/[^a-zA-Z0-9._-]+/', '_', $name);
        if (file_put_contents($path, $data, LOCK_EX) === false) {
            continue;
        }

        $stmt->execute([
            ':outbox_id' => $outboxId,
            ':cid_token' => $cid,
            ':mime_type' => (string) ($part['mime'] ?? 'image/png'),
            ':file_path' => $path,
        ]);
    }
}

function email_logs_snapshot_outbox_inline_images(PDO $pdo, int $outboxId, string $html): void
{
    if ($outboxId <= 0 || !preg_match('/data:image\//i', $html)) {
        return;
    }

    [, $inlineParts] = email_smtp_convert_data_uri_images_to_cid($html);
    email_logs_persist_outbox_inline_parts($pdo, $outboxId, $inlineParts);
}

function email_logs_inline_img_preview_url(string $direction, int $logId, ?string $cid = null, ?int $idx = null): string
{
    $q = [
        'action' => 'inline_img',
        'direction' => $direction,
        'log_id' => $logId,
    ];
    if ($cid !== null && $cid !== '') {
        $q['cid'] = $cid;
    } elseif ($idx !== null) {
        $q['idx'] = $idx;
    }

    return url('emails/logs.php?' . http_build_query($q));
}

function email_logs_outbox_data_uri_image_at(string $html, int $index): ?array
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

            return [
                'mime' => 'image/' . $subtype,
                'data' => $decoded,
            ];
        }

        $current++;
        $searchFrom = $endQuote + 1;
    }

    return null;
}

function email_logs_rewrite_preview_inline_sources(PDO $pdo, string $direction, int $logId, string $html, string $rawBody): string
{
    if ($logId <= 0 || $html === '') {
        return $html;
    }

    $html = preg_replace_callback(
        '/\bsrc=(["\'])cid:([^"\']+)\1/i',
        static function (array $m) use ($direction, $logId): string {
            $cid = trim((string) $m[2], '<> ');
            if ($cid === '') {
                return $m[0];
            }

            return 'src=' . $m[1] . email_logs_inline_img_preview_url($direction, $logId, $cid) . $m[1];
        },
        $html
    ) ?? $html;

    return email_logs_rewrite_data_uri_src_for_preview($direction, $logId, $html);
}

function email_logs_serve_inline_img(PDO $pdo, array $currentUser): void
{
    $direction = strtolower(trim((string) ($_GET['direction'] ?? '')));
    $logId = max(0, (int) ($_GET['log_id'] ?? 0));
    $cid = trim((string) ($_GET['cid'] ?? ''), '<> ');
    $idx = isset($_GET['idx']) ? max(0, (int) $_GET['idx']) : -1;

    if (!in_array($direction, ['incoming', 'outgoing'], true) || $logId <= 0 || ($cid === '' && $idx < 0)) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Invalid image request.';

        return;
    }

    $mime = 'image/png';
    $binary = null;

    if ($direction === 'outgoing') {
        $stmt = $pdo->prepare('SELECT eo.body, eo.ticket_id FROM email_outbox_log eo WHERE eo.id = :id LIMIT 1');
        $stmt->execute([':id' => $logId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Message not found.';

            return;
        }
        if (!rbac_email_logs_full_visibility($currentUser)) {
            [$scopeSql, $scopeParams] = ticket_scope($currentUser, 't', true, true);
            $scopeStmt = $pdo->prepare(
                'SELECT 1 FROM email_outbox_log eo
                 LEFT JOIN tickets t ON t.ticket_id = eo.ticket_id
                 WHERE eo.id = :id AND ' . $scopeSql . ' LIMIT 1'
            );
            $scopeStmt->execute(array_merge([':id' => $logId], $scopeParams));
            if (!$scopeStmt->fetchColumn()) {
                http_response_code(403);
                header('Content-Type: text/plain; charset=UTF-8');
                echo 'Access denied.';

                return;
            }
        }

        $body = (string) ($row['body'] ?? '');

        if ($cid !== '') {
            email_logs_inline_images_ensure_table($pdo);
            $imgStmt = $pdo->prepare(
                'SELECT mime_type, file_path FROM email_outbox_inline_images
                 WHERE outbox_id = :id AND LOWER(cid_token) = LOWER(:cid)
                 LIMIT 1'
            );
            $imgStmt->execute([':id' => $logId, ':cid' => $cid]);
            $imgRow = $imgStmt->fetch(PDO::FETCH_ASSOC);
            if ($imgRow) {
                $path = (string) ($imgRow['file_path'] ?? '');
                if ($path !== '' && is_readable($path)) {
                    $binary = file_get_contents($path);
                    $mime = (string) ($imgRow['mime_type'] ?? 'image/png');
                }
            }
        }

        if ($binary === null && $idx >= 0) {
            $extracted = email_logs_outbox_data_uri_image_at($body, $idx);
            if ($extracted !== null) {
                $binary = $extracted['data'];
                $mime = (string) $extracted['mime'];
            }
        }
    } else {
        $row = email_logs_fetch_inbox_row($pdo, $currentUser, $logId, true);
        if ($row === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Message not found.';

            return;
        }

        if ($cid !== '') {
            $fromDisk = email_inbox_assets_read_cid_file($logId, $cid);
            if ($fromDisk !== null) {
                $binary = (string) ($fromDisk['data'] ?? '');
                $mime = (string) ($fromDisk['mime'] ?? 'image/png');
            }
        }

        $rawMessage = (string) ($row['raw_message'] ?? '');
        if ($binary === null && $cid !== '' && $rawMessage !== '') {
            $found = email_logs_find_inline_image_by_cid($rawMessage, $cid);
            if ($found !== null) {
                $binary = (string) ($found['data'] ?? '');
                $mime = (string) ($found['mime'] ?? 'image/png');
                if ($binary !== '') {
                    email_inbox_assets_write_cid_file($logId, $cid, $binary, $mime);
                }
            }
        }

        if ($binary === null && $idx >= 0 && $rawMessage !== '') {
            $previewHtml = email_logs_workspace_preview_html(
                (string) ($row['body'] ?? ''),
                $rawMessage,
                'incoming'
            );
            $extracted = email_logs_outbox_data_uri_image_at($previewHtml, $idx);
            if ($extracted !== null) {
                $binary = $extracted['data'];
                $mime = (string) $extracted['mime'];
            }
        }
    }

    if ($binary === null || $binary === '') {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Image not found.';

        return;
    }

    if (!preg_match('#^image/[a-z0-9.+-]+$#i', $mime)) {
        $mime = 'image/png';
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) strlen($binary));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=3600');
    echo $binary;
}

function email_logs_serve_attachment_list(PDO $pdo, array $currentUser): void
{
    header('Content-Type: application/json; charset=UTF-8');

    $direction = strtolower(trim((string) ($_GET['direction'] ?? '')));
    $logId = max(0, (int) ($_GET['log_id'] ?? 0));

    if (!in_array($direction, ['incoming', 'outgoing'], true) || $logId <= 0) {
        http_response_code(400);
        echo json_encode(['attachments' => [], 'error' => 'Invalid request'], JSON_UNESCAPED_SLASHES);

        return;
    }

    $attachments = [];
    try {
        if ($direction === 'outgoing') {
            if (!rbac_email_logs_full_visibility($currentUser)) {
                [$scopeSql, $scopeParams] = ticket_scope($currentUser, 't', true, true);
                $scopeCondition = preg_replace('/^\s*AND\s+/', '', $scopeSql) ?? '';
                $scopeStmt = $pdo->prepare(
                    'SELECT 1 FROM email_outbox_log eo
                     LEFT JOIN tickets t ON t.ticket_id = eo.ticket_id
                     WHERE eo.id = :id AND (eo.ticket_id IS NULL OR t.ticket_id IS NULL OR (' . $scopeCondition . '))
                     LIMIT 1'
                );
                $scopeStmt->execute(array_merge([':id' => $logId], $scopeParams));
                if (!$scopeStmt->fetchColumn()) {
                    http_response_code(403);
                    echo json_encode(['attachments' => [], 'error' => 'Access denied'], JSON_UNESCAPED_SLASHES);

                    return;
                }
            }
            $attachments = email_attachments_list_for_outbox($pdo, $logId);
        } else {
            $row = email_logs_fetch_inbox_row($pdo, $currentUser, $logId, true);
            if ($row === null) {
                http_response_code(404);
                echo json_encode(['attachments' => [], 'error' => 'Not found'], JSON_UNESCAPED_SLASHES);

                return;
            }
            $minioItems = email_logs_minio_attachment_items($pdo, 'incoming', $logId);
            if ($minioItems !== []) {
                echo json_encode(['attachments' => $minioItems], JSON_UNESCAPED_SLASHES);

                return;
            }
            $raw = (string) ($row['raw_message'] ?? '');
            $body = (string) ($row['body'] ?? '');
            $previewHtml = email_inbox_assets_load_preview_html($pdo, $logId);
            if ($previewHtml === '') {
                $previewHtml = email_logs_workspace_preview_html($body, $raw !== '' ? $raw : null, 'incoming');
            }
            $attachments = email_logs_mime_attachment_manifest($raw, $logId);
            $attachments = email_attachments_filter_for_preview($attachments, $previewHtml);
        }
    } catch (Throwable $listErr) {
        error_log('[Email logs] attachment_list failed: ' . $listErr->getMessage());
    }

    echo json_encode(['attachments' => $attachments], JSON_UNESCAPED_SLASHES);
}

function email_logs_serve_email_attachment(PDO $pdo, array $currentUser): void
{
    $direction = strtolower(trim((string) ($_GET['direction'] ?? '')));
    $logId = max(0, (int) ($_GET['log_id'] ?? 0));
    $idx = max(0, (int) ($_GET['idx'] ?? -1));

    if (!in_array($direction, ['incoming', 'outgoing'], true) || $logId <= 0 || $idx < 0) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Invalid attachment request.';

        return;
    }

    if ($direction === 'outgoing') {
        email_attachments_ensure_table($pdo);
        $stmt = $pdo->prepare(
            'SELECT ea.file_path, ea.file_name, ea.mime_type, eo.ticket_id
             FROM email_attachments ea
             INNER JOIN email_outbox_log eo ON eo.id = ea.outbox_id
             WHERE ea.outbox_id = :id
             ORDER BY ea.id ASC'
        );
        $stmt->execute([':id' => $logId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $row = $rows[$idx] ?? null;
        if (!$row) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Attachment not found.';

            return;
        }
        if (!rbac_email_logs_full_visibility($currentUser)) {
            [$scopeSql, $scopeParams] = ticket_scope($currentUser, 't', true, true);
            $scopeStmt = $pdo->prepare(
                'SELECT 1 FROM email_outbox_log eo
                 LEFT JOIN tickets t ON t.ticket_id = eo.ticket_id
                 WHERE eo.id = :id AND ' . $scopeSql . ' LIMIT 1'
            );
            $scopeStmt->execute(array_merge([':id' => $logId], $scopeParams));
            if (!$scopeStmt->fetchColumn()) {
                http_response_code(403);
                header('Content-Type: text/plain; charset=UTF-8');
                echo 'Access denied.';

                return;
            }
        }

        $mime = (string) ($row['mime_type'] ?? 'application/octet-stream');
        $name = (string) ($row['file_name'] ?? 'attachment');
        $path = (string) ($row['file_path'] ?? '');
        if (preg_match('#^https?://#i', $path)) {
            $binary = email_minio_enabled()
                ? email_minio_read_mapped_file($pdo, 'outgoing', $logId, $name, $path)
                : '';
            if ($binary === '') {
                $binary = email_minio_download_url($path);
            }
        } elseif ($path !== '' && is_readable($path)) {
            $binary = file_get_contents($path);
        } else {
            $binary = false;
        }

        if ($binary === false || $binary === '') {
            http_response_code(404);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Attachment file missing.';

            return;
        }

        header('Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream'));
        header('Content-Length: ' . (string) strlen($binary));
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=3600');
        echo $binary;

        return;
    }

    $row = email_logs_fetch_inbox_row($pdo, $currentUser, $logId, true);
    if ($row === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Message not found.';

        return;
    }

    $minioItems = email_minio_enabled() ? email_minio_file_mappings_for_email($pdo, 'incoming', $logId, 'attachment') : [];
    if (isset($minioItems[$idx])) {
        $mapped = $minioItems[$idx];
        $binary = email_minio_read_mapped_file(
            $pdo,
            'incoming',
            $logId,
            (string) ($mapped['file_name'] ?? ''),
            (string) ($mapped['minio_url'] ?? '')
        );
        if ($binary === '') {
            http_response_code(404);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Attachment file missing.';

            return;
        }

        $mime = (string) ($mapped['mime_type'] ?? 'application/octet-stream');
        $name = (string) ($mapped['file_name'] ?? ('attachment-' . ($idx + 1)));
        header('Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream'));
        header('Content-Length: ' . (string) strlen($binary));
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=3600');
        echo $binary;

        return;
    }

    $part = email_logs_mime_attachment_part_by_index((string) ($row['raw_message'] ?? ''), $idx);
    if ($part === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Attachment not found.';

        return;
    }

    $binary = email_logs_mime_decode_part_body($part);
    if ($binary === '') {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Attachment is empty.';

        return;
    }

    $mime = (string) ($part['content_type'] ?? 'application/octet-stream');
    $name = trim((string) ($part['filename'] ?? ''));
    if ($name === '') {
        $name = 'attachment-' . ($idx + 1);
    }

    header('Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream'));
    header('Content-Length: ' . (string) strlen($binary));
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=3600');
    echo $binary;
}

function email_logs_serve_inline_file(PDO $pdo, array $currentUser): void
{
    $direction = strtolower(trim((string) ($_GET['direction'] ?? '')));
    $logId = max(0, (int) ($_GET['log_id'] ?? 0));
    $idx = max(0, (int) ($_GET['idx'] ?? -1));

    if (!email_minio_enabled() || !in_array($direction, ['incoming', 'outgoing'], true) || $logId <= 0 || $idx < 0) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Invalid inline image request.';

        return;
    }

    if ($direction === 'outgoing' && !rbac_email_logs_full_visibility($currentUser)) {
        [$scopeSql, $scopeParams] = ticket_scope($currentUser, 't', true, true);
        $scopeStmt = $pdo->prepare(
            'SELECT 1 FROM email_outbox_log eo
             LEFT JOIN tickets t ON t.ticket_id = eo.ticket_id
             WHERE eo.id = :id AND ' . $scopeSql . ' LIMIT 1'
        );
        $scopeStmt->execute(array_merge([':id' => $logId], $scopeParams));
        if (!$scopeStmt->fetchColumn()) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Access denied.';

            return;
        }
    }

    if ($direction === 'incoming' && email_logs_fetch_inbox_row($pdo, $currentUser, $logId, false) === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Message not found.';

        return;
    }

    $rows = email_minio_file_mappings_for_email($pdo, $direction, $logId, 'inline');
    $row = $rows[$idx] ?? null;
    if (!$row) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Inline image not found.';

        return;
    }

    $binary = email_minio_read_mapped_file(
        $pdo,
        $direction,
        $logId,
        (string) ($row['file_name'] ?? ''),
        (string) ($row['minio_url'] ?? '')
    );
    if ($binary === '') {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Inline image file missing.';

        return;
    }

    $mime = (string) ($row['mime_type'] ?? 'image/jpeg');
    header('Content-Type: ' . ($mime !== '' ? $mime : 'image/jpeg'));
    header('Content-Length: ' . (string) strlen($binary));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=3600');
    echo $binary;
}

function email_logs_apply_outbox_inline_images_to_html(PDO $pdo, int $outboxId, string $html): string
{
    if ($outboxId <= 0 || !preg_match('/\bcid:/i', $html)) {
        return $html;
    }

    email_logs_inline_images_ensure_table($pdo);
    $stmt = $pdo->prepare(
        'SELECT cid_token, mime_type, file_path FROM email_outbox_inline_images WHERE outbox_id = :id'
    );
    $stmt->execute([':id' => $outboxId]);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $token = strtolower(trim((string) ($row['cid_token'] ?? ''), '<> '));
        if ($token !== '') {
            $map[$token] = $row;
        }
    }

    if ($map === []) {
        return $html;
    }

    return preg_replace_callback(
        '/\bsrc=(["\'])cid:([^"\']+)\1/i',
        static function (array $m) use ($map): string {
            $q = $m[1];
            $cid = strtolower(trim((string) $m[2], '<> '));
            if ($cid === '' || !isset($map[$cid])) {
                return $m[0];
            }
            $path = (string) ($map[$cid]['file_path'] ?? '');
            if ($path === '' || !is_readable($path)) {
                return $m[0];
            }
            $data = file_get_contents($path);
            if ($data === false || $data === '') {
                return $m[0];
            }
            $mime = (string) ($map[$cid]['mime_type'] ?? 'image/png');
            $b64 = base64_encode($data);

            return 'src=' . $q . 'data:' . $mime . ';base64,' . $b64 . $q;
        },
        $html
    ) ?? $html;
}

function email_logs_preview_body_should_lazy_load(string $previewHtml, string $rawBody = ''): bool
{
    if ($previewHtml !== '' && preg_match('#/storage/email_outbox_assets/\d+/#i', $previewHtml)) {
        if (preg_match('/data:image\//i', $previewHtml) !== 1 && mb_strlen($previewHtml) <= 120000) {
            return false;
        }
    }

    if ($previewHtml === '' && $rawBody !== '' && preg_match('/data:image\//i', $rawBody) === 1) {
        return true;
    }

    if ($previewHtml === '') {
        return false;
    }

    return preg_match('/data:image\//i', $previewHtml) === 1
        || preg_match('/\bsrc=(["\'])cid:/i', $previewHtml) === 1
        || mb_strlen($previewHtml) > 120000;
}

/**
 * @return array{preview_html:string,preview_lazy:int,preview_body_url:string}
 */
function email_logs_workspace_preview_fields(string $direction, int $logId, string $previewHtml, string $rawBody = ''): array
{
    if ($logId <= 0 || !email_logs_preview_body_should_lazy_load($previewHtml, $rawBody)) {
        return [
            'preview_html' => $previewHtml,
            'preview_lazy' => 0,
            'preview_body_url' => '',
        ];
    }

    return [
        'preview_html' => '',
        'preview_lazy' => 1,
        'preview_body_url' => url(
            'emails/logs.php?action=preview_body&direction=' . rawurlencode($direction) . '&log_id=' . $logId
        ),
    ];
}

function email_logs_serve_preview_body(PDO $pdo, array $currentUser): void
{
    try {
        email_logs_serve_preview_body_inner($pdo, $currentUser);
    } catch (Throwable $previewError) {
        error_log('[Email logs] preview_body failed: ' . $previewError->getMessage());
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Preview could not be generated.';
    }
}

function email_logs_serve_preview_body_inner(PDO $pdo, array $currentUser): void
{
    $direction = strtolower(trim((string) ($_GET['direction'] ?? '')));
    $logId = max(0, (int) ($_GET['log_id'] ?? 0));

    if (!in_array($direction, ['incoming', 'outgoing'], true) || $logId <= 0) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Invalid preview request.';

        return;
    }

    $html = '';
    $row = null;
    if ($direction === 'outgoing') {
        $bodyIsHtmlCol = email_log_service_outbox_has_column($pdo, 'body_is_html') ? 'eo.body_is_html' : '0 AS body_is_html';
        $bodyPreviewCol = email_log_service_outbox_has_column($pdo, 'body_preview_html')
            ? 'eo.body_preview_html'
            : 'NULL AS body_preview_html';
        $stmt = $pdo->prepare(
            'SELECT eo.body, ' . $bodyPreviewCol . ', ' . $bodyIsHtmlCol . ', eo.ticket_id
             FROM email_outbox_log eo
             LEFT JOIN tickets t ON t.ticket_id = eo.ticket_id
             WHERE eo.id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $logId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Message not found.';

            return;
        }
        if (!rbac_email_logs_full_visibility($currentUser)) {
            [$scopeSql, $scopeParams] = ticket_scope($currentUser, 't', true, true);
            $scopeStmt = $pdo->prepare(
                'SELECT 1 FROM email_outbox_log eo
                 LEFT JOIN tickets t ON t.ticket_id = eo.ticket_id
                 WHERE eo.id = :id AND ' . $scopeSql . ' LIMIT 1'
            );
            $scopeStmt->execute(array_merge([':id' => $logId], $scopeParams));
            if (!$scopeStmt->fetchColumn()) {
                http_response_code(403);
                header('Content-Type: text/plain; charset=UTF-8');
                echo 'Access denied.';

                return;
            }
        }

        $body = (string) ($row['body'] ?? '');
        $html = email_logs_outgoing_preview_html_from_row($row);
        if (trim((string) ($row['body_preview_html'] ?? '')) === '') {
            $html = email_logs_prepare_email_body_for_preview($pdo, $html, 'outgoing', $logId, $body);
            $html = email_logs_dedupe_preview_img_src($html);
        }
        $html = email_logs_rewrite_minio_inline_urls_for_logs($pdo, 'outgoing', $logId, $html);
    } else {
        $row = email_logs_fetch_inbox_row($pdo, $currentUser, $logId, true);
        if ($row === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Message not found.';

            return;
        }

        $body = (string) ($row['body'] ?? '');
        $rawMessage = (string) ($row['raw_message'] ?? '');
        $html = email_logs_inbox_materialize_preview($pdo, $currentUser, $logId, $body, $rawMessage);
    }

    if ($html === '') {
        $html = email_logs_workspace_plain_wrap(trim(strip_tags((string) ($row['body'] ?? ''))));
    }

    header('Content-Type: text/html; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=300');
    echo $html;
}

function email_logs_email_is_thread_reply(array $row): bool
{
    if (email_logs_normalize_message_id_for_thread($row['in_reply_to'] ?? '') !== '') {
        return true;
    }

    return email_logs_extract_reference_ids_for_thread($row['references_header'] ?? '') !== [];
}

function email_logs_should_use_ticket_thread_bucket(array $row): bool
{
    if ((int) ($row['ticket_id'] ?? 0) <= 0) {
        return false;
    }

    if (!empty($row['is_system_auto'])) {
        return true;
    }

    return email_logs_email_is_thread_reply($row);
}

function email_logs_workspace_plain_wrap(string $plain): string
{
    $plain = trim($plain);
    if ($plain === '') {
        return '<p class="email-logs-plain-empty">No message body available.</p>';
    }

    return '<pre class="email-logs-plain-body">' . htmlspecialchars($plain, ENT_QUOTES, 'UTF-8') . '</pre>';
}

function email_logs_workspace_time_short(?string $at): string
{
    $ts = strtotime((string) $at) ?: 0;
    if ($ts <= 0) {
        return '';
    }

    $startToday = strtotime(date('Y-m-d') . ' 00:00:00');
    $endToday = $startToday + 86400;
    if ($ts >= $startToday && $ts < $endToday) {
        return date('H:i', $ts);
    }

    if (date('Y', $ts) === date('Y')) {
        return date('j M', $ts) . ', ' . date('H:i', $ts);
    }

    return date('j M Y', $ts) . ', ' . date('H:i', $ts);
}

function email_logs_normalize_message_id_for_thread(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    return strtolower(trim($value, " \t\n\r\0\x0B<>"));
}

function email_logs_extract_reference_ids_for_thread(?string $references): array
{
    $ids = [];
    $references = trim((string) $references);
    if ($references === '') {
        return $ids;
    }

    if (preg_match_all('/<([^<>]+)>/', $references, $matches)) {
        foreach ($matches[1] as $match) {
            $normalized = email_logs_normalize_message_id_for_thread((string) $match);
            if ($normalized !== '') {
                $ids[] = $normalized;
            }
        }
    }

    return array_values(array_unique($ids));
}

function email_logs_is_system_auto_email(string $subject, string $direction, string $body = ''): bool
{
    if ($direction !== 'outgoing') {
        return false;
    }

    if (preg_match('/^\[(Auto Acknowledgement|Ticket Resolved|Ticket Update)\]/i', trim($subject))) {
        return true;
    }

    $bodyLower = strtolower(trim(strip_tags($body)));
    if (strpos($bodyLower, 'system-generated acknowledgement email') !== false) {
        return true;
    }
    if (strpos($bodyLower, 'automated acknowledgement confirming') !== false) {
        return true;
    }
    if (strpos($bodyLower, 'greetings from loop mobility ltd') !== false
        && preg_match('/\bLM-\d{8}-\d{2}\b/i', $subject)) {
        return true;
    }

    return false;
}

function email_logs_thread_subject_key(string $subject): string
{
    $subject = trim($subject);
    while (preg_match('/^\s*(re|fw|fwd)\s*:\s*/i', $subject)) {
        $subject = preg_replace('/^\s*(re|fw|fwd)\s*:\s*/i', '', $subject) ?? $subject;
    }

    return strtolower(trim($subject));
}

// Merges ticket companion rows (same log_id) for full-thread preview when direction filter is narrow.
function email_logs_merge_unique_incoming(array $base, array $extra): array
{
    $seen = [];
    foreach ($base as $row) {
        $seen[(int) ($row['log_id'] ?? 0)] = true;
    }
    foreach ($extra as $row) {
        $id = (int) ($row['log_id'] ?? 0);
        if ($id > 0 && isset($seen[$id])) {
            continue;
        }
        $base[] = $row;
        if ($id > 0) {
            $seen[$id] = true;
        }
    }

    return $base;
}

function email_logs_merge_unique_outgoing(array $base, array $extra): array
{
    $seen = [];
    foreach ($base as $row) {
        $seen[(int) ($row['log_id'] ?? 0)] = true;
    }
    foreach ($extra as $row) {
        $id = (int) ($row['log_id'] ?? 0);
        if ($id > 0 && isset($seen[$id])) {
            continue;
        }
        $base[] = $row;
        if ($id > 0) {
            $seen[$id] = true;
        }
    }

    return $base;
}

function email_logs_unique_by_direction_log_id(array $rows): array
{
    $seen = [];
    $out = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $direction = strtolower(trim((string) ($row['direction'] ?? '')));
        $logId = (int) ($row['log_id'] ?? 0);
        $key = $direction . ':' . $logId;

        if ($direction !== '' && $logId > 0) {
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
        }

        $out[] = $row;
    }

    return $out;
}

function email_logs_unique_email_service_rows(array $rows): array
{
    $seen = [];
    $out = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $logId = (int) ($row['log_id'] ?? 0);
        if ($logId > 0) {
            if (isset($seen[$logId])) {
                continue;
            }
            $seen[$logId] = true;
        }

        $out[] = $row;
    }

    return $out;
}

/**
 * When the UI filters incoming/outgoing only, still load the other side for mapped tickets
 * so auto-replies and party replies appear in one conversation iframe.
 *
 * @return array{0: array, 1: array}
 */
function email_logs_ensure_open_log_in_lists(PDO $pdo, array $incoming, array $outgoing, ?array $currentUser): array
{
    $openLogId = max(0, (int) ($_GET['open_log_id'] ?? 0));
    $openDirection = strtolower(trim((string) ($_GET['open_direction'] ?? 'incoming')));
    if ($openLogId <= 0 || !in_array($openDirection, ['incoming', 'outgoing'], true)) {
        return [$incoming, $outgoing];
    }

    $targetList = $openDirection === 'outgoing' ? $outgoing : $incoming;
    foreach ($targetList as $row) {
        if ((int) ($row['log_id'] ?? 0) === $openLogId) {
            return [$incoming, $outgoing];
        }
    }

    if ($openDirection === 'incoming') {
        $row = email_log_service_fetch_incoming_by_id($pdo, $openLogId, $currentUser);
        if ($row) {
            array_unshift($incoming, $row);
        }
    } else {
        $row = email_log_service_fetch_outgoing_by_id($pdo, $openLogId, $currentUser);
        if ($row) {
            array_unshift($outgoing, $row);
        }
    }

    return [$incoming, $outgoing];
}

function email_logs_supplement_ticket_thread_emails(PDO $pdo, array $filters, array $incoming, array $outgoing, ?array $currentUser): array
{
    $direction = (string) ($filters['direction'] ?? 'all');
    $filterTicketId = (int) ($filters['ticket_id'] ?? 0);

    if ($filterTicketId > 0) {
        if ($direction === 'incoming') {
            $outFilters = $filters;
            $outFilters['direction'] = 'outgoing';
            $outgoing = email_log_service_outgoing($pdo, $outFilters, $currentUser);
        } elseif ($direction === 'outgoing') {
            $inFilters = $filters;
            $inFilters['direction'] = 'incoming';
            $incoming = email_log_service_incoming($pdo, $inFilters, $currentUser);
        }

        return [$incoming, $outgoing];
    }

    if ($direction === 'incoming') {
        $ticketIds = [];
        foreach ($incoming as $email) {
            $tid = (int) ($email['ticket_id'] ?? 0);
            if ($tid > 0) {
                $ticketIds[$tid] = true;
            }
        }
        if ($ticketIds !== []) {
            $extraOutgoing = [];
            foreach (array_keys($ticketIds) as $tid) {
                $outFilters = $filters;
                $outFilters['direction'] = 'outgoing';
                $outFilters['ticket_id'] = $tid;
                $extraOutgoing = array_merge(
                    $extraOutgoing,
                    email_log_service_outgoing($pdo, $outFilters, $currentUser)
                );
            }
            $outgoing = email_logs_merge_unique_outgoing($outgoing, $extraOutgoing);
        }
    } elseif ($direction === 'outgoing') {
        $ticketIds = [];
        foreach ($outgoing as $email) {
            $tid = (int) ($email['ticket_id'] ?? 0);
            if ($tid > 0) {
                $ticketIds[$tid] = true;
            }
        }
        if ($ticketIds !== []) {
            $extraIncoming = [];
            foreach (array_keys($ticketIds) as $tid) {
                $inFilters = $filters;
                $inFilters['direction'] = 'incoming';
                $inFilters['ticket_id'] = $tid;
                $extraIncoming = array_merge(
                    $extraIncoming,
                    email_log_service_incoming($pdo, $inFilters, $currentUser)
                );
            }
            $incoming = email_logs_merge_unique_incoming($incoming, $extraIncoming);
        }
    }

    return [$incoming, $outgoing];
}

// Groups rows: mapped tickets share one thread (party + auto-replies); unmapped uses Message-ID / subject.
function email_logs_assign_thread_keys(array $rows): array
{
    $count = count($rows);
    if ($count === 0) {
        return $rows;
    }

    $parent = range(0, $count - 1);
    $find = static function (int $index) use (&$parent, &$find): int {
        if ($parent[$index] !== $index) {
            $parent[$index] = $find($parent[$index]);
        }

        return $parent[$index];
    };
    $union = static function (int $a, int $b) use (&$parent, $find): void {
        $rootA = $find($a);
        $rootB = $find($b);
        if ($rootA !== $rootB) {
            $parent[$rootB] = $rootA;
        }
    };

    $messageIndex = [];
    foreach ($rows as $index => $row) {
        $messageId = email_logs_normalize_message_id_for_thread($row['message_id'] ?? '');
        if ($messageId !== '') {
            $messageIndex[$messageId] = $index;
        }
    }

    foreach ($rows as $index => $row) {
        $inReplyTo = email_logs_normalize_message_id_for_thread($row['in_reply_to'] ?? '');
        if ($inReplyTo !== '' && isset($messageIndex[$inReplyTo])) {
            $union($index, $messageIndex[$inReplyTo]);
        }
        foreach (email_logs_extract_reference_ids_for_thread($row['references_header'] ?? '') as $referenceId) {
            if (isset($messageIndex[$referenceId])) {
                $union($index, $messageIndex[$referenceId]);
            }
        }
        $selfId = email_logs_normalize_message_id_for_thread($row['message_id'] ?? '');
        if ($selfId !== '' && isset($messageIndex[$selfId])) {
            $union($index, $messageIndex[$selfId]);
        }
    }

    $subjectBuckets = [];
    foreach ($rows as $index => $row) {
        $root = $find($index);
        if ($root !== $index) {
            continue;
        }
        if ((int) ($row['ticket_id'] ?? 0) > 0 && !email_logs_should_use_ticket_thread_bucket($row)) {
            continue;
        }
        $subjectKey = email_logs_thread_subject_key((string) ($row['subject'] ?? ''));
        if ($subjectKey === '') {
            continue;
        }
        $party = strtolower(trim((string) ($row['from_email'] ?? $row['to_email'] ?? '')));
        $ticketPart = (int) ($row['ticket_id'] ?? 0) > 0 ? 't' . (int) $row['ticket_id'] : 'x0';
        $bucket = $subjectKey . '|' . $party . '|' . $ticketPart;
        if (!isset($subjectBuckets[$bucket])) {
            $subjectBuckets[$bucket] = [];
        }
        $subjectBuckets[$bucket][] = $index;
    }

    foreach ($subjectBuckets as $indices) {
        if (count($indices) < 2) {
            continue;
        }
        $first = $indices[0];
        for ($i = 1, $len = count($indices); $i < $len; $i++) {
            $union($first, $indices[$i]);
        }
    }

    foreach ($rows as $index => $row) {
        if (!empty($row['thread_key'])) {
            continue;
        }
        $root = $find($index);
        $rows[$index]['thread_key'] = 'hdr-' . $root;
    }

    foreach ($rows as $index => $row) {
        if (email_logs_should_use_ticket_thread_bucket($row)) {
            $rows[$index]['thread_key'] = 'ticket-' . (int) $row['ticket_id'];
        }
    }

    $counts = [];
    foreach ($rows as $row) {
        $key = (string) ($row['thread_key'] ?? '');
        if ($key !== '') {
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
    }

    foreach ($rows as $index => $row) {
        $key = (string) ($row['thread_key'] ?? '');
        $rows[$index]['thread_count'] = $key !== '' ? (int) ($counts[$key] ?? 1) : 1;
    }

    return $rows;
}

function email_logs_build_workspace_rows(PDO $pdo, array $incomingEmails, array $outgoingEmails, string $direction): array
{
    $incomingEmails = email_logs_unique_email_service_rows($incomingEmails);
    $outgoingEmails = email_logs_unique_email_service_rows($outgoingEmails);
    $rows = [];
    $outboxAttachmentMap = [];
    if ($direction !== 'incoming') {
        $outboxIds = [];
        foreach ($outgoingEmails as $outEmail) {
            $oid = (int) ($outEmail['log_id'] ?? 0);
            if ($oid > 0) {
                $outboxIds[] = $oid;
            }
        }
        $outboxAttachmentMap = email_attachments_load_by_outbox_ids($pdo, $outboxIds);
    }

    if ($direction !== 'outgoing') {
        foreach ($incomingEmails as $email) {
            $isIgnored = ($email['mail_status'] ?? '') === 'ignored';
            $isUnmapped = ($email['mail_status'] ?? '') === 'unmapped';
            $isUnknown = ($email['mail_status'] ?? '') === 'unknown';
            $statusLabel = $isIgnored ? 'Ignored' : ($isUnmapped ? 'Unmapped' : ($isUnknown ? 'Unknown' : 'Incoming'));
            $statusClass = $isIgnored || $isUnmapped || $isUnknown ? 'badge-medium' : 'badge-open';

            $ticketSerial = '-';
            if (!empty($email['ticket_id'])) {
                $ticketSerial = !empty($email['ticket_created_at'])
                    ? format_ticket_serial($pdo, ['ticket_id' => $email['ticket_id'], 'created_at' => $email['ticket_created_at']])
                    : (string) ($email['ticket_id'] ?? '-');
            }

            $subject = trim((string) ($email['subject'] ?? '')) !== '' ? (string) $email['subject'] : (string) ($email['issue'] ?? 'Incoming Email');
            $from = trim((string) ($email['from_email'] ?? '')) !== '' ? (string) $email['from_email'] : (string) ($email['customer_email'] ?? '');
            $body = (string) ($email['body'] ?? '');
            $occurred = (string) (($email['received_at'] ?: $email['created_at']) ?? '');
            $headers = email_logs_parse_incoming_headers($email['raw_header_chunk'] ?? null);
            $inLogId = (int) ($email['log_id'] ?? 0);
            // List view: lazy-load body/attachments on open (avoids N× full MIME parse / large payloads).
            if ($inLogId > 0 && !empty($email['has_stored_preview'])) {
                $previewFields = [
                    'preview_html' => '',
                    'preview_lazy' => 1,
                    'preview_body_url' => url('emails/logs.php?action=preview_body&direction=incoming&log_id=' . $inLogId),
                ];
            } else {
                $previewFields = email_logs_workspace_preview_fields('incoming', $inLogId, '', $body);
            }
            $rowAttachments = email_logs_minio_attachment_items($pdo, 'incoming', $inLogId);
            $hasAttachHint = email_logs_workspace_attachment_hint($email, 'incoming') || $rowAttachments !== [];
            $snippetSource = $body !== '' ? $body : (string) ($email['subject'] ?? 'Incoming Email');
            $timeFull = format_date($occurred ?: null);
            $custLine = trim((string) ($email['customer'] ?? ''));
            $custEmail = trim((string) ($email['customer_email'] ?? ''));
            $listSecondary = $custLine !== '' ? $custLine : $custEmail;
            if ($listSecondary !== '' && mb_strlen($listSecondary) > 52) {
                $listSecondary = mb_substr($listSecondary, 0, 49) . '…';
            }
            $rowTitleParts = array_filter([
                $subject !== '' ? $subject : 'Incoming Email',
                $statusLabel,
                $timeFull,
                $from !== '' ? $from : null,
            ]);
            $rowTitle = implode(' | ', $rowTitleParts);
            if (mb_strlen($rowTitle) > 420) {
                $rowTitle = mb_substr($rowTitle, 0, 417) . '…';
            }

            $rows[] = array_merge([
                'id' => 'in-' . (int) ($email['log_id'] ?? 0),
                'direction' => 'incoming',
                'log_id' => (int) ($email['log_id'] ?? 0),
                'sort_at' => $occurred,
                'list_sender' => $from !== '' ? $from : 'Unknown sender',
                'list_secondary' => $listSecondary,
                'subject' => $subject !== '' ? $subject : 'Incoming Email',
                'snippet' => email_logs_workspace_snippet($snippetSource),
                'time_short' => email_logs_workspace_time_short($occurred),
                'time_tooltip' => $timeFull,
                'row_title' => $rowTitle,
                'time_full' => $timeFull,
                'status_class' => $statusClass,
                'status_label' => $statusLabel,
                'message_id' => trim((string) ($email['message_id'] ?? '')),
                'in_reply_to' => trim((string) ($email['in_reply_to'] ?? '')),
                'references_header' => trim((string) ($email['references_header'] ?? '')),
                'ticket_id' => (int) ($email['ticket_id'] ?? 0),
                'ticket_serial' => $ticketSerial,
                'external_ticket_id' => (string) ($email['external_ticket_id'] ?? ''),
                'from_email' => $from,
                'to_email' => '',
                'cc_email' => '',
                'parsed_reply_to' => (string) ($headers['reply_to'] ?? ''),
                'parsed_to' => (string) ($headers['to'] ?? ''),
                'parsed_cc' => (string) ($headers['cc'] ?? ''),
                'customer_email' => (string) ($email['customer_email'] ?? ''),
                'has_attachment' => $hasAttachHint ? 1 : 0,
                'attachments' => $rowAttachments,
                'attachments_lazy' => ($hasAttachHint && $inLogId > 0 && $rowAttachments === []) ? 1 : 0,
                'attachments_url' => ($hasAttachHint && $inLogId > 0)
                    ? url('emails/logs.php?action=attachment_list&direction=incoming&log_id=' . $inLogId)
                    : '',
                'body_plain' => $body,
                'raw_message_bytes' => (int) ($email['raw_message_bytes'] ?? 0),
                'has_inline_images' => !empty($previewFields['preview_lazy']) ? 1 : 0,
                'ignored_reason' => (string) ($email['ignored_reason'] ?? ''),
                'is_unmapped' => $isUnmapped && empty($email['ticket_id']),
                'needs_attention' => $isIgnored || $isUnmapped || $isUnknown,
                'meta_customer' => (string) ($email['customer'] ?? ''),
                'meta_ticket_status' => (string) ($email['ticket_status'] ?? ''),
                'meta_source' => ucfirst((string) ($email['source'] ?? 'email')),
                'meta_assignee' => (string) ($email['assignee_name'] ?? ''),
            ], $previewFields);
        }
    }

    if ($direction !== 'incoming') {
        foreach ($outgoingEmails as $email) {
            $statusClass = 'badge-medium';
            if (($email['mail_status'] ?? '') === 'sent') {
                $statusClass = 'badge-closed';
            } elseif (($email['mail_status'] ?? '') === 'failed') {
                $statusClass = 'badge-high';
            }

            $statusLabel = strtoupper((string) ($email['mail_status'] ?? 'pending'));
            $ticketSerial = '-';
            if (!empty($email['ticket_id'])) {
                $ticketSerial = !empty($email['ticket_created_at'])
                    ? format_ticket_serial($pdo, ['ticket_id' => $email['ticket_id'], 'created_at' => $email['ticket_created_at']])
                    : (string) ($email['ticket_id'] ?? '-');
            }

            $subject = trim((string) ($email['subject'] ?? '')) !== '' ? (string) $email['subject'] : (string) ($email['issue'] ?? 'Outgoing Email');
            $body = (string) ($email['body'] ?? '');
            $bodyIsHtml = !empty($email['body_is_html']);
            $occurred = (string) (($email['sent_at'] ?: $email['created_at']) ?? '');
            $outLogId = (int) ($email['log_id'] ?? 0);
            $hasStoredPreview = !empty($email['has_stored_preview']);
            $needsLazyPreview = $outLogId > 0 && (
                $bodyIsHtml
                || $hasStoredPreview
                || preg_match('/<img\b|data:image\//i', $body) === 1
            );
            if ($needsLazyPreview) {
                $previewFields = [
                    'preview_html' => '',
                    'preview_lazy' => 1,
                    'preview_body_url' => url(
                        'emails/logs.php?action=preview_body&direction=outgoing&log_id=' . $outLogId
                    ),
                ];
            } else {
                $previewHtml = email_logs_outgoing_workspace_preview_html($body, $bodyIsHtml);
                $previewFields = email_logs_workspace_preview_fields('outgoing', $outLogId, $previewHtml, $body);
            }
            $rowAttachments = $outLogId > 0
                ? ($outboxAttachmentMap[$outLogId] ?? email_attachments_list_for_outbox($pdo, $outLogId))
                : [];
            $timeFull = format_date($occurred ?: null);
            $fromAddr = trim((string) ($email['from_email'] ?? ''));
            $listSecondary = $fromAddr !== '' ? ('From: ' . $fromAddr) : '';
            if ($listSecondary !== '' && mb_strlen($listSecondary) > 56) {
                $listSecondary = mb_substr($listSecondary, 0, 53) . '…';
            }
            $toAddr = trim((string) ($email['to_email'] ?? ''));
            $rowTitleParts = array_filter([
                $subject !== '' ? $subject : 'Outgoing Email',
                $statusLabel,
                $timeFull,
                $toAddr !== '' ? ('To: ' . $toAddr) : null,
            ]);
            $rowTitle = implode(' | ', $rowTitleParts);
            if (mb_strlen($rowTitle) > 420) {
                $rowTitle = mb_substr($rowTitle, 0, 417) . '…';
            }
            $mailStatus = strtolower((string) ($email['mail_status'] ?? 'pending'));

            $rows[] = array_merge([
                'id' => 'out-' . (int) ($email['log_id'] ?? 0),
                'direction' => 'outgoing',
                'log_id' => (int) ($email['log_id'] ?? 0),
                'sort_at' => $occurred,
                'list_sender' => 'To: ' . trim((string) ($email['to_email'] ?: '-')),
                'list_secondary' => $listSecondary,
                'subject' => $subject !== '' ? $subject : 'Outgoing Email',
                'snippet' => email_logs_workspace_snippet($body),
                'time_short' => email_logs_workspace_time_short($occurred),
                'time_tooltip' => $timeFull,
                'row_title' => $rowTitle,
                'time_full' => $timeFull,
                'status_label' => $statusLabel,
                'status_class' => $statusClass,
                'message_id' => trim((string) ($email['message_id'] ?? '')),
                'in_reply_to' => trim((string) ($email['in_reply_to'] ?? '')),
                'references_header' => trim((string) ($email['references_header'] ?? '')),
                'is_failed' => $mailStatus === 'failed',
                'is_pending' => $mailStatus === 'pending',
                'needs_attention' => $mailStatus === 'failed',
                'ticket_id' => (int) ($email['ticket_id'] ?? 0),
                'ticket_serial' => $ticketSerial,
                'external_ticket_id' => (string) ($email['external_ticket_id'] ?? ''),
                'from_email' => (string) ($email['from_email'] ?? ''),
                'to_email' => (string) ($email['to_email'] ?? ''),
                'cc_email' => (string) ($email['cc_email'] ?? ''),
                'parsed_reply_to' => '',
                'parsed_to' => '',
                'parsed_cc' => '',
                'customer_email' => (string) ($email['customer_email'] ?? ''),
                'has_attachment' => $rowAttachments !== [] ? 1 : 0,
                'attachments' => $rowAttachments,
                'body_plain' => $body,
                'error_message' => (string) ($email['error_message'] ?? ''),
                'meta_customer' => (string) ($email['customer'] ?? ''),
                'meta_ticket_status' => (string) ($email['ticket_status'] ?? ''),
                'meta_source' => ucfirst((string) ($email['source'] ?? 'manual')),
                'meta_creator' => (string) ($email['creator_name'] ?? ''),
                'is_system_auto' => email_logs_is_system_auto_email($subject, 'outgoing', $body),
                'body_is_html' => $bodyIsHtml ? 1 : 0,
                'has_inline_images' => (
                    !empty($previewFields['preview_lazy'])
                    || preg_match('/<img\b|data:image\//i', $body) === 1
                ) ? 1 : 0,
                'is_thread_reply' => email_logs_email_is_thread_reply([
                    'in_reply_to' => trim((string) ($email['in_reply_to'] ?? '')),
                    'references_header' => trim((string) ($email['references_header'] ?? '')),
                ]) ? 1 : 0,
            ], $previewFields);
        }
    }

    usort($rows, static function (array $a, array $b): int {
        $ta = strtotime((string) ($a['sort_at'] ?? '')) ?: 0;
        $tb = strtotime((string) ($b['sort_at'] ?? '')) ?: 0;
        if ($ta === $tb) {
            return strcmp((string) ($b['id'] ?? ''), (string) ($a['id'] ?? ''));
        }

        return $tb <=> $ta;
    });

    $maxPayload = 120000;
    foreach ($rows as &$row) {
        $bodyPlain = (string) ($row['body_plain'] ?? '');
        $hasInlineInBody = preg_match('/data:image\//i', $bodyPlain) === 1 || preg_match('/<img\b/i', $bodyPlain) === 1;

        $hasEmbeddedPreview = trim((string) ($row['preview_html'] ?? '')) !== ''
            && empty($row['preview_lazy']);
        if ((!empty($row['preview_lazy']) || $hasInlineInBody) && !$hasEmbeddedPreview) {
            $row['body_plain'] = '(HTML message with images — open to load preview)';
        } elseif (mb_strlen($bodyPlain) > $maxPayload) {
            $row['body_plain'] = mb_substr($bodyPlain, 0, $maxPayload) . "\n…";
        }

        if (preg_match('/data:image\//i', (string) ($row['preview_html'] ?? '')) === 1) {
            $row['preview_html'] = '';
            if (empty($row['preview_lazy']) && !empty($row['log_id']) && !empty($row['direction'])) {
                $lazyFields = email_logs_workspace_preview_fields(
                    (string) $row['direction'],
                    (int) $row['log_id'],
                    '<img src="data:image/png;base64,AA==" alt="">'
                );
                $row['preview_lazy'] = $lazyFields['preview_lazy'];
                $row['preview_body_url'] = $lazyFields['preview_body_url'];
            }
        }

        if (empty($row['preview_lazy'])
            && mb_strlen((string) ($row['preview_html'] ?? '')) > $maxPayload
            && !preg_match('/<img\b/i', (string) ($row['preview_html'] ?? ''))) {
            $row['preview_html'] = mb_substr((string) $row['preview_html'], 0, $maxPayload);
        }
    }

    unset($row);

    return email_logs_assign_thread_keys(email_logs_unique_by_direction_log_id($rows));
}

function email_logs_workspace_json_encode($data): string
{
    $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }

    $json = json_encode($data, $flags);

    if ($json === false) {
        return is_array($data) ? '[]' : '{}';
    }

    return $json;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_email_flag') {
    rbac_require_email_logs_read($currentUser);
    verify_csrf();

    $isAjax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    $response = ['success' => false, 'message' => 'Unable to update flag.', 'flagged' => false, 'flagged_at' => null];

    try {
        $direction = trim((string) ($_POST['mail_direction'] ?? ''));
        $logId = max(0, (int) ($_POST['log_id'] ?? 0));
        $userId = email_log_flag_user_id($currentUser);
        $result = email_log_flag_toggle($pdo, $userId, $direction, $logId);
        $response = [
            'success' => true,
            'message' => $result['flagged'] ? 'Marked as important.' : 'Flag removed.',
            'flagged' => (bool) $result['flagged'],
            'flagged_at' => $result['flagged_at'],
            'row_id' => email_log_flag_row_key($direction, $logId),
        ];
    } catch (Throwable $flagErr) {
        $response['message'] = $flagErr->getMessage();
    }

    if ($isAjax) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($response, JSON_UNESCAPED_SLASHES);
        exit;
    }

    set_flash($response['success'] ? 'success' : 'error', $response['message']);
    $q = $_GET;
    $url = 'emails/logs.php';
    if ($q) {
        $url .= '?' . http_build_query($q);
    }
    redirect($url);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'map_unmapped_email') {
    rbac_require_email_logs_manage($currentUser);
    verify_csrf();

    try {
        email_processor_map_unmapped_email(
            $pdo,
            (int) ($_POST['inbox_log_id'] ?? 0),
            (int) ($_POST['map_ticket_id'] ?? 0),
            $currentUser
        );
        set_flash('success', 'Unmapped email linked to ticket successfully.');
    } catch (Throwable $throwable) {
        set_flash('error', 'Mapping failed: ' . $throwable->getMessage());
    }

    $q = $_GET;
    $url = 'emails/logs.php';
    if ($q) {
        $url .= '?' . http_build_query($q);
    }
    redirect($url);
}

// Handle compose/send mail (supports both AJAX and regular POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_email') {
    rbac_require_email_logs_manage($currentUser);
    verify_csrf();

    $fromAccountId = max(0, (int) ($_POST['email_account_id'] ?? 0));
    $toEmail = trim((string) ($_POST['to_email'] ?? ''));
    $ccRaw = trim((string) ($_POST['cc_email'] ?? ''));
    $ccParsed = email_smtp_parse_recipient_list($ccRaw);
    $ccEmails = $ccParsed['valid'];
    $subject = trim((string) ($_POST['subject'] ?? ''));
    $body = trim((string) ($_POST['body'] ?? ''));
    $bodyIsHtml = isset($_POST['body_is_html']) && (string) $_POST['body_is_html'] === '1';
    $ticketId = (int) ($_POST['ticket_id'] ?? 0);
    $partyId = (int) ($_POST['party_id'] ?? 0);

    $isAjax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

    $response = ['success' => false, 'message' => ''];
    $account = $fromAccountId > 0 ? email_smtp_active_account($pdo, $fromAccountId) : null;
    $relatedTicket = null;
    $relatedTicketSerial = '';
    $vendorAmResult = null;

    if ($ticketId > 0) {
        if (rbac_email_logs_full_visibility($currentUser)) {
            [$scopeSql, $scopeParams] = ['1=1', []];
        } else {
            [$scopeSql, $scopeParams] = ticket_scope($currentUser, 't', true);
        }
        $ticketStmt = $pdo->prepare(
            'SELECT t.ticket_id, t.created_at, t.issue, t.customer, t.customer_email,
                    t.initiator_party_id, t.assigned_vendor_id
             FROM tickets t
             WHERE t.ticket_id = :ticket_id
             AND ' . $scopeSql . '
             LIMIT 1'
        );
        $ticketStmt->execute(array_merge([':ticket_id' => $ticketId], $scopeParams));
        $relatedTicket = $ticketStmt->fetch() ?: null;
        if ($relatedTicket) {
            $relatedTicketSerial = format_ticket_serial($pdo, $relatedTicket);
        }
    }

    if (!$account) {
        $response['message'] = 'Choose a valid active From email account.';
    } elseif ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        $response['message'] = 'Valid recipient email is required.';
    } elseif ($ccParsed['invalid']) {
        $response['message'] = 'Invalid CC email address: ' . implode(', ', $ccParsed['invalid']);
    } elseif ($subject === '') {
        $response['message'] = 'Subject is required.';
    } elseif (!email_smtp_body_has_substance($body, $bodyIsHtml)) {
        $response['message'] = 'Message body is required.';
    } elseif ($ticketId > 0 && !$relatedTicket) {
        $response['message'] = 'Related ticket not found or access denied.';
    } elseif ($relatedTicket && !email_parser_message_has_internal_ticket_id($subject, $body, (int) $relatedTicket['ticket_id'], $relatedTicketSerial)) {
        $response['message'] = 'Include internal ticket ID ' . $relatedTicketSerial . ' in the subject or body before sending.';
    } else {
        $quickReplyFlow = trim((string) ($_POST['quick_reply_flow'] ?? ''));
        if ($quickReplyFlow !== '' && $relatedTicket) {
            $quickReplyCheck = ticket_quick_reply_validate_outgoing(
                $pdo,
                $ticketId,
                $quickReplyFlow,
                $toEmail,
                $ccEmails,
                $partyId,
                $relatedTicket
            );
            if (!$quickReplyCheck['ok']) {
                $response['message'] = $quickReplyCheck['message'];
                if ($isAjax) {
                    header('Content-Type: application/json; charset=UTF-8');
                    echo json_encode($response, JSON_UNESCAPED_SLASHES);
                    exit;
                }
                set_flash('error', $response['message']);
                redirect('emails/logs.php?ticket_id=' . $ticketId);
            }
            $partyId = (int) $quickReplyCheck['party_id'];
            $ccEmails = $quickReplyCheck['cc_emails'];
        }

        // Party validation and auto-mapping
        if ($quickReplyFlow === '' && $partyId > 0) {
            // Verify party exists and is active
            $partyStmt = $pdo->prepare('SELECT id, name FROM parties WHERE id = ? AND status = ? LIMIT 1');
            $partyStmt->execute([$partyId, 'active']);
            $validParty = $partyStmt->fetch();
            if (!$validParty) {
                $response['message'] = 'Selected party not found or inactive.';
                if ($isAjax) {
                    header('Content-Type: application/json; charset=UTF-8');
                    echo json_encode($response, JSON_UNESCAPED_SLASHES);
                    exit;
                }
                set_flash('error', $response['message']);
                // Preserve simple fields in query; omit body due to size
                $q = $_GET;
                $q['compose_to'] = $toEmail;
                $q['compose_subject'] = $subject;
                $q['open_compose'] = '1';
                if ($ticketId > 0) {
                    $q['compose_ticket_id'] = $ticketId;
                }
                if ($partyId > 0) {
                    $q['compose_party_id'] = $partyId;
                }
                $url = 'emails/logs.php?' . http_build_query($q);
                redirect($url);
            }
        } elseif ($quickReplyFlow === '') {
            // Auto-map: look up party by toEmail
            $autoParty = party_service_find_by_email($pdo, $toEmail);
            $partyId = $autoParty ? (int)$autoParty['id'] : null;
        }

        if (($partyId ?? 0) > 0) {
            $ccEmails = vendor_am_service_merge_cc_with_party_mapping($pdo, $ccEmails, (int) $partyId);
        }

        try {
        $vendorAmResult = vendor_am_service_apply_cc($pdo, $toEmail, $ccEmails, $ticketId > 0 ? $ticketId : null);
        $ccEmails = $vendorAmResult['cc_emails'];

        email_attachments_ensure_table($pdo);
        $attachmentPaths = email_logs_collect_attachments();

        $finalBody = $body;
        if ($bodyIsHtml) {
            $finalBody = email_smtp_sanitize_compose_html($finalBody);
            email_smtp_validate_inline_data_uri_images($finalBody);
            $finalBody = user_email_signature_apply_to_outgoing_body($pdo, (int) $currentUser['id'], $finalBody, true);
        }

        $outboxId = null;
        $minioCleanupPaths = [];
        if ($account) {
            try {
                $composeInReplyTo = trim((string) ($_POST['compose_in_reply_to'] ?? ''));
                $composeReferences = trim((string) ($_POST['compose_references_header'] ?? ''));
                $outboxId = email_smtp_queue(
                    $pdo,
                    $toEmail,
                    $subject,
                    $finalBody,
                    $ticketId > 0 ? $ticketId : null,
                    $ccEmails,
                    (int) $account['id'],
                    $partyId > 0 ? $partyId : null,
                    $bodyIsHtml,
                    $composeInReplyTo !== '' ? $composeInReplyTo : null,
                    $composeReferences !== '' ? $composeReferences : null
                );
                if ($outboxId) {
                    $smtpBody = $finalBody;
                    if ($bodyIsHtml) {
                        $storedBody = email_smtp_prepare_minio_body_for_outbox($pdo, (int) $outboxId, $finalBody);
                        if (strlen($finalBody) > email_smtp_max_compose_body_bytes() && trim($storedBody) !== '') {
                            $smtpBody = $storedBody;
                        }
                    }

                    $attachmentsForSmtp = [];

                    // Save attachment references to DB. MinIO is optional and guarded by USE_MINIO.
                    if ($attachmentPaths) {
                        $pdo = get_pdo();
                        $attachmentHasDisposition = email_attachments_has_column($pdo, 'disposition');
                        $attachSql = $attachmentHasDisposition
                            ? 'INSERT INTO email_attachments (outbox_id, file_path, file_name, file_size, mime_type, disposition, created_at)
                               VALUES (:outbox, :path, :name, :size, :mime, :disposition, NOW())'
                            : 'INSERT INTO email_attachments (outbox_id, file_path, file_name, file_size, mime_type, created_at)
                               VALUES (:outbox, :path, :name, :size, :mime, NOW())';
                        foreach ($attachmentPaths as $att) {
                            $dbPath = (string) ($att['path'] ?? '');
                            $dbName = (string) ($att['name'] ?? 'attachment.bin');
                            $dbSize = (int) ($att['size'] ?? 0);
                            $dbMime = (string) ($att['mime'] ?? 'application/octet-stream');
                            $sendPath = $dbPath;

                            if (email_minio_enabled() && $dbPath !== '') {
                                // This MinIO + compression pipeline is currently for local testing. Production enable/disable controlled via config flag USE_MINIO.
                                $minioCleanupPaths[] = $dbPath;
                                $stored = email_minio_store_file_from_path(
                                    $pdo,
                                    'outgoing',
                                    (int) $outboxId,
                                    $dbPath,
                                    $dbName,
                                    $dbMime,
                                    'attachment'
                                );
                                if (!empty($stored['url'])) {
                                    $dbPath = (string) $stored['url'];
                                    $dbName = (string) ($stored['name'] ?? $dbName);
                                    $dbSize = (int) ($stored['size'] ?? $dbSize);
                                    $dbMime = (string) ($stored['mime'] ?? $dbMime);
                                    $sendPath = (string) ($stored['local_path'] ?? $sendPath);
                                    foreach ((array) ($stored['cleanup_paths'] ?? []) as $cleanupPath) {
                                        $minioCleanupPaths[] = (string) $cleanupPath;
                                    }
                                } else {
                                    throw new RuntimeException('Unable to store attachment "' . $dbName . '" in MinIO.');
                                }
                            }

                            $attachParams = [
                                ':outbox' => $outboxId,
                                ':path' => $dbPath,
                                ':name' => $dbName,
                                ':size' => $dbSize,
                                ':mime' => $dbMime,
                            ];
                            if ($attachmentHasDisposition) {
                                $attachParams[':disposition'] = 'attachment';
                            }
                            $pdo = get_pdo();
                            $attachStmt = $pdo->prepare($attachSql);
                            $attachStmt->execute($attachParams);

                            $data = $sendPath !== '' && is_readable($sendPath) ? @file_get_contents($sendPath) : false;
                            if ($data !== false && $data !== '') {
                                $attachmentsForSmtp[] = [
                                    'name' => $dbName,
                                    'mime' => $dbMime,
                                    'data' => $data,
                                ];
                            }
                        }
                    }

                    // Send email with final body (includes attachment listing)
                    $outboxHeaders = email_smtp_outbox_headers($pdo, (int) $outboxId, $account);
                    email_smtp_send_message(
                        $account,
                        $toEmail,
                        $subject,
                        $smtpBody,
                        $attachmentsForSmtp,
                        $ccEmails,
                        $outboxHeaders['message_id'],
                        $outboxHeaders['in_reply_to'],
                        $outboxHeaders['references_header'],
                        $bodyIsHtml
                    );
                    email_smtp_mark_sent($pdo, $outboxId);

                    if ($ticketId > 0 && $outboxId) {
                        try {
                            if ($quickReplyFlow === 'vendor') {
                                $pdo->prepare('UPDATE tickets SET vendor_email_initiated = 1 WHERE ticket_id = :ticket_id AND vendor_email_initiated = 0')
                                    ->execute([':ticket_id' => $ticketId]);
                            } elseif ($relatedTicket && (int) ($relatedTicket['assigned_vendor_id'] ?? 0) > 0) {
                                $normalizedTo = ticket_quick_reply_normalize_email((string) $toEmail);
                                if ($normalizedTo !== '') {
                                    $vendorEmailStmt = $pdo->prepare(
                                        'SELECT LOWER(email) FROM party_emails WHERE party_id = :party_id'
                                    );
                                    $vendorEmailStmt->execute([':party_id' => (int) $relatedTicket['assigned_vendor_id']]);
                                    $vendorEmails = array_flip($vendorEmailStmt->fetchAll(PDO::FETCH_COLUMN));
                                    if (isset($vendorEmails[$normalizedTo])) {
                                        $pdo->prepare('UPDATE tickets SET vendor_email_initiated = 1 WHERE ticket_id = :ticket_id AND vendor_email_initiated = 0')
                                            ->execute([':ticket_id' => $ticketId]);
                                    }
                                }
                            }
                        } catch (Throwable $ignored) {
                        }
                    }

                    $sendDetails = [];
                    if ($ccEmails) {
                        $sendDetails[] = count($ccEmails) . ' CC recipient(s)';
                    }
                    if (!empty($vendorAmResult['mapping']['am_email'])) {
                        $sendDetails[] = 'AM ' . $vendorAmResult['mapping']['am_email'] . ' copied';
                    }
                    $bmCc = trim((string) ($vendorAmResult['mapping']['business_manager_email'] ?? ''));
                    if ($bmCc !== '' && filter_var($bmCc, FILTER_VALIDATE_EMAIL)) {
                        $sendDetails[] = 'BM ' . $bmCc . ' copied';
                    }
                    if ($attachmentPaths) {
                        $sendDetails[] = count($attachmentPaths) . ' attachment(s)';
                    }

                    $response['success'] = true;
                    $response['message'] = 'Email sent successfully' . ($sendDetails ? ' with ' . implode(' and ', $sendDetails) : '') . '.';
                } else {
                    $response['message'] = 'Failed to queue email.';
                }
            } catch (Throwable $e) {
                if (($outboxId ?? 0) > 0) {
                    try {
                        email_smtp_mark_failed($pdo, (int) $outboxId, $e->getMessage());
                    } catch (Throwable $ignored) {
                        // Preserve the original send error for the user.
                    }
                }
                $response['message'] = 'Send failed: ' . $e->getMessage();
            } finally {
                if (email_minio_enabled()) {
                    foreach (array_unique(array_filter($minioCleanupPaths)) as $cleanupPath) {
                        if (is_string($cleanupPath) && is_file($cleanupPath)) {
                            @unlink($cleanupPath);
                        }
                    }
                }
            }
        } else {
            $response['message'] = 'No active SMTP account configured.';
        }
        } catch (Throwable $e) {
            if (($outboxId ?? 0) > 0) {
                try {
                    email_smtp_mark_failed($pdo, (int) $outboxId, $e->getMessage());
                } catch (Throwable $ignored) {
                    // Preserve the original send error for the user.
                }
            }
            $response['message'] = 'Send failed: ' . $e->getMessage();
        }
    }

    if ($isAjax) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($response, JSON_UNESCAPED_SLASHES);
        exit;
    }

    set_flash($response['success'] ? 'success' : 'error', $response['message']);
    $q = $_GET;
    $url = 'emails/logs.php';
    if ($q) { $url .= '?' . http_build_query($q); }
    redirect($url);
}

// Get system From address for modal
$smtpAccounts = email_smtp_available_accounts($pdo, true);
$systemAccount = $smtpAccounts[0] ?? email_smtp_active_account($pdo);
$systemFromEmail = $systemAccount['email'] ?? '';

// Fetch recent tickets for autocomplete
$recentTicketsStmt = $pdo->prepare('SELECT ticket_id, issue, customer, status, created_at FROM tickets ORDER BY ticket_id DESC LIMIT 25');
$recentTicketsStmt->execute();
$recentTickets = $recentTicketsStmt->fetchAll();

$filters = email_log_service_filters($_GET);
$incomingEmails = email_log_service_incoming($pdo, $filters, $currentUser);
$outgoingEmails = email_log_service_outgoing($pdo, $filters, $currentUser);
[$incomingEmails, $outgoingEmails] = email_logs_supplement_ticket_thread_emails(
    $pdo,
    $filters,
    $incomingEmails,
    $outgoingEmails,
    $currentUser
);
[$incomingEmails, $outgoingEmails] = email_logs_ensure_open_log_in_lists(
    $pdo,
    $incomingEmails,
    $outgoingEmails,
    $currentUser
);
$emailLogsOpenLogId = max(0, (int) ($_GET['open_log_id'] ?? 0));
$emailLogsOpenDirection = strtolower(trim((string) ($_GET['open_direction'] ?? '')));
if ($emailLogsOpenDirection === '' && $emailLogsOpenLogId > 0) {
    $emailLogsOpenDirection = 'incoming';
}
$emailLogsOpenNotificationId = max(0, (int) ($_GET['open_notification_id'] ?? 0));
if ($emailLogsOpenNotificationId > 0) {
    notifications_mark_read($pdo, (string) ($currentUser['user_id'] ?? ''), $emailLogsOpenNotificationId);
}
$emailSuggestions = email_log_service_recent_addresses($pdo, $currentUser);
$shouldOpenCompose = isset($_GET['open_compose']) && (string) $_GET['open_compose'] === '1';
$composePrefill = [
    'to' => trim((string) ($_GET['compose_to'] ?? '')),
    'subject' => trim((string) ($_GET['compose_subject'] ?? '')),
    'ticket_id' => max(0, (int) ($_GET['compose_ticket_id'] ?? 0) ?: (int) ($_GET['ticket_id'] ?? 0)),
    'party_id' => max(0, (int) ($_GET['compose_party_id'] ?? 0)),
    'cc' => trim((string) ($_GET['compose_cc'] ?? '')),
    'open_compose' => $shouldOpenCompose,
    'quoted_plain' => '',
    'quote_kind' => 'reply',
    'quick_reply_flow' => '',
];
$quickReplyFlowGet = trim((string) ($_GET['quick_reply'] ?? ''));
if ($shouldOpenCompose && $quickReplyFlowGet !== '' && $composePrefill['ticket_id'] > 0) {
    $quickReplyTicket = ticket_query_service_detail($pdo, (int) $composePrefill['ticket_id']);
    if ($quickReplyTicket) {
        $quickReplyBuilt = ticket_quick_reply_build_compose_prefill(
            $pdo,
            (int) $composePrefill['ticket_id'],
            $currentUser,
            $quickReplyFlowGet,
            $quickReplyTicket
        );
        if ($quickReplyBuilt['ok']) {
            $composePrefill = array_merge($composePrefill, $quickReplyBuilt['prefill']);
        } else {
            set_flash('error', (string) ($quickReplyBuilt['message'] ?: 'Unable to prepare quick reply.'));
            $composePrefill['open_compose'] = false;
        }
    } else {
        set_flash('error', 'Ticket not found for quick reply.');
        $composePrefill['open_compose'] = false;
    }
}
if ($composePrefill['to'] !== '' && !filter_var($composePrefill['to'], FILTER_VALIDATE_EMAIL)) {
    $composePrefill['to'] = '';
}

$operatorEmail = '';
try {
    $operatorStmt = $pdo->prepare('SELECT email FROM users WHERE user_id = :uid LIMIT 1');
    $operatorStmt->execute([':uid' => (string) ($currentUser['user_id'] ?? '')]);
    $operatorEmail = trim((string) ($operatorStmt->fetchColumn() ?: ''));
    if ($operatorEmail !== '' && !filter_var($operatorEmail, FILTER_VALIDATE_EMAIL)) {
        $operatorEmail = '';
    }
} catch (Throwable $ignored) {
    $operatorEmail = '';
}

$composeUserSignature = null;
if ($canManageEmailLogs) {
    $composeUserSignature = user_email_signature_compose_payload(
        getUserSignature($pdo, (int) ($currentUser['id'] ?? 0))
    );
}

if (isset($_GET['action']) && (string) $_GET['action'] === 'inline_img') {
    email_logs_serve_inline_img($pdo, $currentUser);
    exit;
}

if (isset($_GET['action']) && (string) $_GET['action'] === 'preview_body') {
    email_logs_serve_preview_body($pdo, $currentUser);
    exit;
}

if (isset($_GET['action']) && (string) $_GET['action'] === 'email_attachment') {
    email_logs_serve_email_attachment($pdo, $currentUser);
    exit;
}

if (isset($_GET['action']) && (string) $_GET['action'] === 'email_inline_file') {
    email_logs_serve_inline_file($pdo, $currentUser);
    exit;
}

if (isset($_GET['action']) && (string) $_GET['action'] === 'attachment_list') {
    email_logs_serve_attachment_list($pdo, $currentUser);
    exit;
}

$emailLogsThreadItems = email_logs_build_workspace_rows($pdo, $incomingEmails, $outgoingEmails, 'all');
$emailLogsFlagUserId = email_log_flag_user_id($currentUser);
email_log_flag_apply_to_workspace_rows($pdo, $emailLogsFlagUserId, $emailLogsThreadItems);
if (empty($filters['flagged'])) {
    $emailLogsThreadItems = email_log_flag_sort_rows_flagged_first($emailLogsThreadItems);
}
$emailLogsFlaggedCount = email_log_flag_count_for_user($pdo, $emailLogsFlagUserId);
$emailLogsListIndices = [];
foreach ($emailLogsThreadItems as $poolIdx => $row) {
    if ($filters['direction'] === 'all' || ($row['direction'] ?? '') === $filters['direction']) {
        $emailLogsListIndices[] = $poolIdx;
    }
}

/** Visible rows only — keys are pool indices (avoids JSON/DOM count mismatch). */
$emailLogsClientItems = [];
foreach ($emailLogsListIndices as $poolIdx) {
    if (isset($emailLogsThreadItems[$poolIdx])) {
        $emailLogsClientItems[(int) $poolIdx] = $emailLogsThreadItems[$poolIdx];
    }
}

$emailLogsOpenPoolIndex = -1;
if ($emailLogsOpenLogId > 0 && in_array($emailLogsOpenDirection, ['incoming', 'outgoing'], true)) {
    foreach ($emailLogsListIndices as $poolIdx) {
        $openRow = $emailLogsThreadItems[$poolIdx] ?? null;
        if (
            $openRow
            && (int) ($openRow['log_id'] ?? 0) === $emailLogsOpenLogId
            && strtolower((string) ($openRow['direction'] ?? '')) === $emailLogsOpenDirection
        ) {
            $emailLogsOpenPoolIndex = (int) $poolIdx;
            break;
        }
    }
}

$emailLogsFiltersExpanded = $filters['search'] !== ''
    || $filters['ticket_id'] > 0
    || $filters['email'] !== ''
    || $filters['status'] !== ''
    || $filters['from_date'] !== ''
    || $filters['to_date'] !== ''
    || $filters['direction'] !== 'all'
    || $filters['limit'] !== 50
    || !empty($filters['flagged']);

$pageTitle = 'Email Logs';
$pageHeading = 'Email Activity';
$pageDescription = $canManageEmailLogs
    ? 'Track incoming customer mail and outgoing SMTP delivery from one place.'
    : 'View incoming and outgoing mail (read-only).';

include __DIR__ . '/../includes/header.php';
?>

<div class="email-logs-top-controls" id="email-logs-top-controls">
<div class="page-actions email-logs-page-actions">
    <div class="email-logs-page-actions__intro">
        <h2 class="section-title email-logs-page-actions__title">Mail Tracking</h2>
    </div>
    <div class="toolbar email-logs-page-actions__toolbar">
        <?php if ($canManageEmailLogs): ?>
        <button type="button" class="btn btn-primary" id="compose-btn" aria-haspopup="dialog" aria-controls="compose-modal">Send Mail</button>
        <?php endif; ?>
        <a href="<?php echo e(url('tickets/list.php')); ?>" class="btn btn-outline">Back to Tickets</a>
    </div>
</div>

<?php if (!$canManageEmailLogs): ?>
    <div class="flash flash-info email-logs-sticky-flash" role="status">Read-only access — you can browse and filter mail but cannot send, reply, or map emails.</div>
<?php endif; ?>

<form method="GET" class="filter-card email-logs-filter-card" id="filter-section" aria-label="Email log filters">
    <div class="email-logs-filter-bar">
        <button
            type="button"
            class="btn btn-outline btn-sm email-logs-filter-toggle"
            id="email-logs-filter-toggle"
            aria-expanded="<?php echo $emailLogsFiltersExpanded ? 'true' : 'false'; ?>"
            aria-controls="email-logs-filter-fields"
        ><?php echo $emailLogsFiltersExpanded ? 'Hide filters' : 'Show filters'; ?></button>
        <div class="email-logs-filter-bar__chips">
            <span class="email-logs-chip"><?php echo e(ucfirst($filters['direction'])); ?></span>
            <?php if ($filters['search'] !== ''): ?>
                <span class="email-logs-chip" title="<?php echo e($filters['search']); ?>">Search: <?php echo e(mb_strlen($filters['search']) > 28 ? mb_substr($filters['search'], 0, 25) . '…' : $filters['search']); ?></span>
            <?php endif; ?>
            <?php if ($filters['ticket_id'] > 0): ?>
                <span class="email-logs-chip">Ticket #<?php echo e((string) $filters['ticket_id']); ?></span>
            <?php endif; ?>
            <?php if ($filters['email'] !== ''): ?>
                <span class="email-logs-chip"><?php echo e($filters['email']); ?></span>
            <?php endif; ?>
            <?php if ($filters['status'] !== ''): ?>
                <span class="email-logs-chip"><?php echo e($filters['status']); ?></span>
            <?php endif; ?>
            <?php if ($filters['from_date'] !== '' || $filters['to_date'] !== ''): ?>
                <span class="email-logs-chip"><?php
                    $from = $filters['from_date'] !== '' ? $filters['from_date'] : '…';
                    $to = $filters['to_date'] !== '' ? $filters['to_date'] : '…';
                    echo e($from . ' → ' . $to);
                ?></span>
            <?php endif; ?>
            <?php if (!empty($filters['flagged'])): ?>
                <span class="email-logs-chip email-logs-chip--flag">Flagged</span>
            <?php endif; ?>
            <span class="email-logs-chip"><?php echo e((string) $filters['limit']); ?> / page</span>
        </div>
        <a href="<?php echo e(url('emails/logs.php')); ?>" class="btn btn-outline btn-sm">Clear</a>
        <button type="submit" class="btn btn-primary btn-sm email-logs-filter-apply">Apply</button>
    </div>

    <div class="filter-grid ticket-filter-grid email-logs-filter-fields<?php echo $emailLogsFiltersExpanded ? '' : ' is-collapsed'; ?>" id="email-logs-filter-fields">
        <div class="input-group">
            <label for="search">Search</label>
            <input type="text" id="search" name="search" value="<?php echo e($filters['search']); ?>" placeholder="Subject, body, customer, ticket ID">
        </div>

        <div class="input-group">
            <label for="direction">Direction</label>
            <select id="direction" name="direction">
                <option value="all" <?php echo $filters['direction'] === 'all' ? 'selected' : ''; ?>>All</option>
                <option value="incoming" <?php echo $filters['direction'] === 'incoming' ? 'selected' : ''; ?>>Incoming</option>
                <option value="outgoing" <?php echo $filters['direction'] === 'outgoing' ? 'selected' : ''; ?>>Outgoing</option>
            </select>
        </div>

        <div class="input-group">
            <label for="ticket_id">Ticket ID</label>
            <input type="number" id="ticket_id" name="ticket_id" min="0" value="<?php echo e($filters['ticket_id'] > 0 ? (string) $filters['ticket_id'] : ''); ?>" placeholder="Internal ticket ID">
        </div>

        <div class="input-group">
            <label for="email">Email</label>
            <input type="text" id="email" name="email" value="<?php echo e($filters['email']); ?>" placeholder="Customer or recipient email">
        </div>

        <div class="input-group">
            <label for="from_date">From Date</label>
            <input type="date" id="from_date" name="from_date" value="<?php echo e($filters['from_date']); ?>">
        </div>

        <div class="input-group">
            <label for="to_date">To Date</label>
            <input type="date" id="to_date" name="to_date" value="<?php echo e($filters['to_date']); ?>">
        </div>

        <div class="input-group">
            <label for="status">Mail Status</label>
            <select id="status" name="status">
                <option value="" <?php echo $filters['status'] === '' ? 'selected' : ''; ?>>All</option>
                <option value="received" <?php echo $filters['status'] === 'received' ? 'selected' : ''; ?>>Received</option>
                <option value="unmapped" <?php echo $filters['status'] === 'unmapped' ? 'selected' : ''; ?>>Unmapped</option>
                <option value="unknown" <?php echo $filters['status'] === 'unknown' ? 'selected' : ''; ?>>Unknown</option>
                <option value="ignored" <?php echo $filters['status'] === 'ignored' ? 'selected' : ''; ?>>Ignored</option>
                <option value="pending" <?php echo $filters['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="sent" <?php echo $filters['status'] === 'sent' ? 'selected' : ''; ?>>Sent</option>
                <option value="failed" <?php echo $filters['status'] === 'failed' ? 'selected' : ''; ?>>Failed</option>
            </select>
        </div>

        <div class="input-group">
            <label for="limit">Rows</label>
            <select id="limit" name="limit">
                <?php foreach ([10, 25, 50, 100] as $limit): ?>
                    <option value="<?php echo e((string) $limit); ?>" <?php echo $filters['limit'] === $limit ? 'selected' : ''; ?>><?php echo e((string) $limit); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="input-group email-logs-filter-flagged">
            <label for="flagged">Important</label>
            <select id="flagged" name="flagged">
                <option value="" <?php echo empty($filters['flagged']) ? 'selected' : ''; ?>>All messages</option>
                <option value="1" <?php echo !empty($filters['flagged']) ? 'selected' : ''; ?>>Flagged only<?php echo $emailLogsFlaggedCount > 0 ? ' (' . (int) $emailLogsFlaggedCount . ')' : ''; ?></option>
            </select>
        </div>
    </div>
</form>
</div>

<section class="email-logs-shell table-card" aria-labelledby="email-logs-shell-heading">
    <div class="table-header email-logs-shell-header">
        <div>
            <h2 class="section-title" id="email-logs-shell-heading">Message log</h2>
            <p class="section-subtitle"><?php echo e((string) count($emailLogsListIndices)); ?> messages in this view (newest first).</p>
        </div>
    </div>
    <div class="email-logs-shell-body">
        <?php include __DIR__ . '/../views/emails/logs_workspace.php'; ?>
    </div>
</section>

<script type="application/json" id="email-logs-items-json"><?php echo email_logs_workspace_json_encode($emailLogsClientItems); ?></script>
<script type="application/json" id="email-logs-context-json"><?php echo email_logs_workspace_json_encode([
    'operatorEmail' => $operatorEmail,
    'systemFromEmail' => $systemFromEmail,
    'mapFormActionUrl' => url('emails/logs.php?direction=incoming&status=unmapped'),
    'canManageEmailLogs' => $canManageEmailLogs,
    'csrfToken' => csrf_token(),
    'csrfFieldName' => 'csrf_token',
    'flagToggleUrl' => url('emails/logs.php'),
    'logsPageUrl' => url('emails/logs.php'),
    'publicBaseUrl' => email_logs_public_base_url(),
    'openLogId' => $emailLogsOpenLogId,
    'openDirection' => $emailLogsOpenDirection,
    'openPoolIndex' => $emailLogsOpenPoolIndex,
]); ?></script>

<!-- Toast Notification Container -->
<div id="toast-container" class="email-logs-toast-host" aria-live="polite" aria-relevant="additions"></div>

<?php if ($canManageEmailLogs): ?>
<!-- Compose Email Modal -->
<style>
    .compose-party-typeahead { position: relative; }
    .compose-party-dropdown { position: absolute; left: 0; right: 0; top: 100%; z-index: 4000; max-height: 260px; overflow-y: auto; background: var(--panel, #fff); border: 1px solid var(--border, #ccc); border-radius: 8px; margin-top: 4px; box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12); }
    .compose-party-dropdown button { display: block; width: 100%; text-align: left; padding: 10px 12px; border: 0; background: transparent; cursor: pointer; font: inherit; color: inherit; }
    .compose-party-dropdown button:hover, .compose-party-dropdown button:focus { background: var(--panel-soft, #f4f4f4); outline: none; }
    .compose-party-dropdown__meta { display: block; font-size: 12px; opacity: 0.78; margin-top: 2px; }
    .compose-opt-hint { font-weight: 400; opacity: 0.78; font-size: 0.9em; }
    .compose-attach-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-top: 4px; }
    .compose-attach-input { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); border: 0; }
    .compose-attach-list { list-style: none; margin: 6px 0 0; padding: 0; max-height: 100px; overflow-y: auto; }
    .compose-attach-list li { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 5px 8px; font-size: 12px; border: 1px solid var(--border, #ddd); border-radius: 6px; margin-bottom: 4px; background: var(--panel-soft, #f8f9fa); }
    .compose-attach-list .compose-attach-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1; min-width: 0; }
    .compose-attach-hint { display: block; margin-top: 4px; opacity: 0.85; }
    .compose-cc-header { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
    .compose-party-cc-toggle { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; color: var(--muted, #666); cursor: pointer; user-select: none; }
    .compose-party-cc-toggle input { width: auto; margin: 0; }
</style>
<div class="modal-overlay compose-modal-overlay" id="compose-modal" role="dialog" aria-modal="true" aria-labelledby="compose-modal-title">
    <div class="modal compose-modal">
        <div class="modal-header compose-modal-header">
            <div>
                <h3 id="compose-modal-title">Compose Email</h3>
                <p>Send a ticket email from any configured SMTP account.</p>
            </div>
            <button type="button" id="close-modal" class="compose-close-btn" aria-label="Close">&times;</button>
        </div>
        <form method="POST" id="compose-form" enctype="multipart/form-data" class="compose-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="send_email">
            <input type="hidden" name="ticket_id" id="compose-ticket-id" value="">
            <input type="hidden" name="quick_reply_flow" id="compose-quick-reply-flow" value="">
            <input type="hidden" name="compose_in_reply_to" id="compose-in-reply-to" value="">
            <input type="hidden" name="compose_references_header" id="compose-references-header" value="">
            
            <div class="input-group">
                <label for="compose-from">From</label>
                <select id="compose-from" name="email_account_id" required <?php echo $smtpAccounts ? '' : 'disabled'; ?>>
                    <?php if ($smtpAccounts): ?>
                        <?php foreach ($smtpAccounts as $account): ?>
                            <?php
                            $fromLabel = trim((string) ($account['from_name'] ?? ''));
                            $fromLabel = $fromLabel !== '' ? $fromLabel . ' <' . $account['email'] . '>' : $account['email'];
                            ?>
                            <option value="<?php echo e((string) $account['id']); ?>" <?php echo (int) ($systemAccount['id'] ?? 0) === (int) $account['id'] ? 'selected' : ''; ?>>
                                <?php echo e($fromLabel . ' - ' . $account['smtp_host'] . ':' . $account['smtp_port']); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <option value="">No active SMTP account configured</option>
                    <?php endif; ?>
                </select>
                <small><?php echo $smtpAccounts ? 'Select which configured mailbox should send this email.' : 'Activate an SMTP account in Email Accounts before sending.'; ?></small>
            </div>

            <div class="input-group">
                <label for="compose-to">To <span style="color:red">*</span></label>
                <input type="email" id="compose-to" name="to_email" list="compose-email-suggestions" required placeholder="customer@example.com">
            </div>

            <div class="input-group compose-cc-group" id="compose-cc-group">
                <div class="compose-cc-header">
                    <label for="compose-cc">CC</label>
                    <button type="button" class="compose-cc-toggle" id="compose-cc-toggle" hidden aria-expanded="false" aria-controls="compose-cc-expanded compose-cc-collapsed">Show CC</button>
                    <label class="compose-party-cc-toggle" id="compose-party-cc-toggle-label">
                        <input type="checkbox" id="compose-party-cc-toggle" checked>
                        <span>Include party CC emails</span>
                    </label>
                </div>
                <div class="compose-cc-collapsed" id="compose-cc-collapsed" hidden>
                    <p class="compose-cc-summary" id="compose-cc-summary"></p>
                </div>
                <div class="compose-cc-expanded" id="compose-cc-expanded">
                    <input type="text" id="compose-cc" name="cc_email" list="compose-email-suggestions" placeholder="cc1@example.com, cc2@example.com" autocomplete="off">
                    <?php if ($emailSuggestions): ?>
                        <select id="compose-cc-picker" aria-label="Add CC recipient">
                            <option value="">Add saved/recent contact</option>
                            <?php foreach ($emailSuggestions as $email): ?>
                                <option value="<?php echo e($email); ?>"><?php echo e($email); ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <ul class="compose-cc-list" id="compose-cc-list" aria-label="CC recipients"></ul>
                    <small>Use comma-separated addresses for multiple CC recipients.</small>
                </div>
            </div>

            <div class="input-group compose-party-typeahead">
                <label for="compose-party-search">Party (Optional)</label>
                <input type="text" id="compose-party-search" autocomplete="off" placeholder="Search active parties by name, email, or ID…">
                <input type="hidden" name="party_id" id="compose-party-id" value="0">
                <div id="compose-party-dropdown" class="compose-party-dropdown" hidden></div>
                <small>Choosing a party fills <strong>To</strong> (mapped party email or primary) and adds <strong>AM/BM</strong> to CC when configured.</small>
            </div>

            <datalist id="compose-email-suggestions">
                <?php foreach ($emailSuggestions as $email): ?>
                    <option value="<?php echo e($email); ?>"></option>
                <?php endforeach; ?>
            </datalist>

            <div class="input-group">
                <label for="compose-ticket-id-select">Related Ticket (Optional)</label>
                <input type="text" id="compose-ticket-id-select" list="compose-ticket-suggestions" placeholder="Search ticket ID or issue..." autocomplete="off">
                <small>For vendor emails, select the ticket and manually include its internal ticket ID in the subject or body.</small>
                <datalist id="compose-ticket-suggestions">
                    <?php foreach ($recentTickets as $ticket): ?>
                        <?php $ticketSerial = format_ticket_serial($pdo, $ticket); ?>
                        <option value="<?php echo e($ticketSerial . ' - ' . $ticket['issue'] . ' (' . $ticket['customer'] . ')'); ?>"
                                data-id="<?php echo e($ticket['ticket_id']); ?>"
                                data-serial="<?php echo e($ticketSerial); ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>

            <div class="input-group">
                <label for="compose-subject">Subject <span style="color:red">*</span></label>
                <input type="text" id="compose-subject" name="subject" required placeholder="Email subject">
            </div>

            <div class="input-group compose-message-group">
                <label for="compose-body-html">Message <span style="color:red">*</span></label>
                <input type="hidden" name="body" id="compose-body" value="">
                <input type="hidden" name="body_is_html" id="compose-body-is-html" value="1">
                <div class="compose-editor-toolbar" id="compose-editor-toolbar" role="toolbar" aria-label="Message formatting">
                    <button type="button" class="compose-toolbar-btn" data-cmd="bold" title="Bold (Ctrl+B)"><strong>B</strong></button>
                    <button type="button" class="compose-toolbar-btn" data-cmd="italic" title="Italic (Ctrl+I)"><em>I</em></button>
                    <button type="button" class="compose-toolbar-btn" data-cmd="underline" title="Underline (Ctrl+U)"><span style="text-decoration:underline">U</span></button>
                    <span class="compose-toolbar-sep" aria-hidden="true"></span>
                    <button type="button" class="compose-toolbar-btn" data-cmd="insertUnorderedList" title="Bullet list">• List</button>
                    <button type="button" class="compose-toolbar-btn" data-cmd="insertOrderedList" title="Numbered list">1. List</button>
                    <button type="button" class="compose-toolbar-btn" data-cmd="indent" title="Indent">Indent</button>
                    <button type="button" class="compose-toolbar-btn" data-cmd="outdent" title="Outdent">Outdent</button>
                    <span class="compose-toolbar-sep" aria-hidden="true"></span>
                    <button type="button" class="compose-toolbar-btn" data-cmd="createLink" title="Insert link">Link</button>
                    <button type="button" class="compose-toolbar-btn" data-cmd="removeFormat" title="Clear formatting">Clear</button>
                    <div class="compose-template-picker" data-message-template-picker>
                        <button type="button" class="compose-toolbar-btn compose-template-toggle" data-message-template-toggle aria-haspopup="menu" aria-expanded="false">Insert Template</button>
                        <div class="compose-template-menu" data-message-template-menu hidden></div>
                    </div>
                </div>
                <div
                    id="compose-body-html"
                    class="compose-editor"
                    contenteditable="true"
                    role="textbox"
                    aria-multiline="true"
                    aria-label="Email message"
                    data-placeholder="Write your message here… Paste from Excel or Word, or drop images."
                ></div>
                <small>Rich text: tables and formatting from Excel/Word are preserved when pasted. Images: paste (Ctrl+V) or drag into the message area.</small>
            </div>

            <div class="input-group compose-attachments-block">
                <label for="compose-attachments">Attachments <span class="compose-opt-hint">(optional)</span></label>
                <div class="compose-attach-row">
                    <button type="button" class="btn btn-outline btn-sm" id="compose-attach-add">Add files</button>
                    <input type="file" id="compose-attachments" name="attachments[]" multiple class="compose-attach-input" tabindex="-1" aria-hidden="true">
                </div>
                <ul id="compose-attach-list" class="compose-attach-list" aria-label="Selected attachments"></ul>
                <small class="compose-attach-hint">Max 5 MB per file. Inline screenshots are auto-compressed on paste/drop before sending.</small>
            </div>

            <div class="compose-send-state" id="compose-send-state" aria-live="polite">Sending email. Please wait...</div>

            <div class="form-actions compose-actions">
                <button type="button" id="cancel-compose" class="btn btn-secondary">Cancel</button>
                <button type="submit" id="send-email-btn" class="btn btn-primary" style="position:relative;" <?php echo $smtpAccounts ? '' : 'disabled'; ?>>
                    <span class="btn-text">Send Email</span>
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($composeError)): ?>
    <div id="flash-toast" class="flash flash-error" style="position:fixed;top:20px;right:20px;z-index:10000;max-width:400px;"><?php echo e($composeError); ?></div>
<?php endif; ?>
<?php if (!empty($composeSuccess)): ?>
    <div id="flash-toast" class="flash flash-success" style="position:fixed;top:20px;right:20px;z-index:10000;max-width:400px;"><?php echo e($composeSuccess); ?></div>
<?php endif; ?>

<script src="<?php echo e(url('assets/js/email-logs-workspace.js')); ?>"></script>
<?php if ($canManageEmailLogs): ?>
<script src="<?php echo e(url('assets/js/compose-rich-editor.js')); ?>"></script>
<script src="<?php echo e(url('assets/js/compose-email-signature.js')); ?>"></script>
<script>window.composeUserSignature = <?php echo json_encode($composeUserSignature, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES); ?>;</script>
<script>window.messageTemplatesConfig = {
    apiUrl: <?php echo json_encode(url('profile/api_message_templates.php'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES); ?>,
    csrfToken: <?php echo json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES); ?>
};</script>
<script src="<?php echo e(url('assets/js/message-templates.js')); ?>"></script>
<?php endif; ?>
<script>
(function() {
    // ================= TOAST NOTIFICATIONS =================
    function showToast(message, type) {
        var container = document.getElementById('toast-container');
        var toast = document.createElement('div');
        toast.className = 'flash flash-' + (type === 'error' ? 'error' : 'success');
        toast.style.cssText = 'padding:12px 16px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.1);animation:slideIn 0.3s ease-out;';
        toast.textContent = message;
        container.appendChild(toast);

        setTimeout(function() {
            toast.style.animation = 'slideOut 0.3s ease-out forwards';
            setTimeout(function() { toast.remove(); }, 300);
        }, 3000);
    }

    // Inject CSS animations once
    if (!document.getElementById('toast-styles')) {
        var style = document.createElement('style');
        style.id = 'toast-styles';
        style.textContent = '@keyframes slideIn{from{transform:translateX(100%);opacity:0}to{transform:translateX(0);opacity:1}}@keyframes slideOut{from{transform:translateX(0);opacity:1}to{transform:translateX(100%);opacity:0}}';
        document.head.appendChild(style);
    }

    // ================= MODAL LOGIC =================
    var modal = document.getElementById('compose-modal');
    var composeBtn = document.getElementById('compose-btn');
    var closeBtn = document.getElementById('close-modal');
    var cancelBtn = document.getElementById('cancel-compose');
    var composeForm = document.getElementById('compose-form');
    var sendBtn = document.getElementById('send-email-btn');
    var ccInput = document.getElementById('compose-cc');
    var ccPicker = document.getElementById('compose-cc-picker');
    var ccToggle = document.getElementById('compose-cc-toggle');
    var ccCollapsed = document.getElementById('compose-cc-collapsed');
    var ccExpanded = document.getElementById('compose-cc-expanded');
    var ccSummary = document.getElementById('compose-cc-summary');
    var ccListEl = document.getElementById('compose-cc-list');
    var partyCcToggle = document.getElementById('compose-party-cc-toggle');
    var activePartyCcEmails = [];
    var ccPanelExpanded = true;

    function parseComposeCcList() {
        if (!ccInput) {
            return [];
        }
        var seen = {};
        return ccInput.value.split(/[,;\n]+/).map(function (item) {
            return item.trim();
        }).filter(function (item) {
            if (!item || item.indexOf('@') === -1) {
                return false;
            }
            var low = item.toLowerCase();
            if (seen[low]) {
                return false;
            }
            seen[low] = true;
            return true;
        });
    }

    function composeCcSetExpanded(open) {
        ccPanelExpanded = !!open;
        if (ccExpanded) {
            ccExpanded.classList.toggle('is-hidden', !ccPanelExpanded);
        }
        if (ccCollapsed) {
            ccCollapsed.hidden = ccPanelExpanded;
        }
        if (ccToggle) {
            ccToggle.setAttribute('aria-expanded', ccPanelExpanded ? 'true' : 'false');
        }
    }

    function composeCcSummaryText(list) {
        if (!list.length) {
            return '';
        }
        if (list.length <= 2) {
            return list.join(', ');
        }
        return list[0] + ', ' + list[1] + ', +' + (list.length - 2) + ' more';
    }

    function refreshComposeCcUi() {
        var list = parseComposeCcList();
        var count = list.length;

        if (ccListEl) {
            ccListEl.innerHTML = '';
            list.forEach(function (addr) {
                var li = document.createElement('li');
                li.textContent = addr;
                ccListEl.appendChild(li);
            });
            ccListEl.hidden = count === 0;
        }

        if (ccToggle) {
            if (count === 0) {
                ccToggle.hidden = true;
                composeCcSetExpanded(true);
            } else {
                ccToggle.hidden = false;
                ccToggle.textContent = ccPanelExpanded ? 'Hide CC' : ('Show CC (' + count + ')');
            }
        }

        if (ccSummary) {
            ccSummary.textContent = composeCcSummaryText(list);
        }
    }

    if (ccToggle) {
        ccToggle.addEventListener('click', function () {
            var list = parseComposeCcList();
            if (!list.length) {
                composeCcSetExpanded(true);
                refreshComposeCcUi();
                return;
            }
            composeCcSetExpanded(!ccPanelExpanded);
            refreshComposeCcUi();
        });
    }

    if (ccInput) {
        ccInput.addEventListener('input', function () {
            if (!parseComposeCcList().length) {
                composeCcSetExpanded(true);
            }
            refreshComposeCcUi();
        });
    }

    if (partyCcToggle) {
        partyCcToggle.addEventListener('change', function () {
            if (!partyCcToggle.checked && activePartyCcEmails.length) {
                var current = ccInput.value.split(/[,;\n]+/).map(function (s) { return s.trim(); }).filter(function (s) { return s; });
                var removeLower = activePartyCcEmails.map(function (e) { return e.toLowerCase(); });
                var next = current.filter(function (addr) {
                    return removeLower.indexOf(addr.toLowerCase()) === -1;
                });
                ccInput.value = next.join(', ');
                activePartyCcEmails = [];
                refreshComposeCcUi();
            } else if (partyCcToggle.checked && partyHiddenId && parseInt(partyHiddenId.value, 10) > 0) {
                applyPartyMapping(partyHiddenId.value, true);
            }
        });
    }

    refreshComposeCcUi();
    var ticketSelect = document.getElementById('compose-ticket-id-select');
    var ticketHidden = document.getElementById('compose-ticket-id');
    var toInput = document.getElementById('compose-to');
    var partySearchInput = document.getElementById('compose-party-search');
    var partyHiddenId = document.getElementById('compose-party-id');
    var partyDd = document.getElementById('compose-party-dropdown');
    var partyAutocompleteUrl = <?php echo json_encode(url('emails/party_autocomplete.php'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES); ?>;
    var partyMappingLookupUrl = <?php echo json_encode(url('emails/party_mapping_lookup.php'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES); ?>;
    var partyCcCache = {};
    var partySearchDebounce = null;
    var composePrefill = <?php echo json_encode($composePrefill, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES); ?>;
    var isSending = false;

    function hideComposePartyDd() {
        if (!partyDd) {
            return;
        }
        partyDd.hidden = true;
        partyDd.innerHTML = '';
    }

    function applyPartyMapping(pid, forceFetch) {
        if (!toInput || !partyHiddenId) {
            return;
        }
        var id = parseInt(pid, 10);
        if (!id) {
            partyHiddenId.value = '0';
            if (partySearchInput) {
                partySearchInput.value = '';
            }
            hideComposePartyDd();
            return;
        }
        partyHiddenId.value = String(id);
        var cacheKey = String(id);
        var useCache = !forceFetch && partyCcCache[cacheKey];
        var finish = function (data) {
            if (!data || !data.ok) {
                return;
            }
            if (partySearchInput) {
                partySearchInput.value = data.party_name || '';
            }
            if (data.to_email) {
                toInput.value = data.to_email;
            }
            activePartyCcEmails = [];
            (data.cc || []).forEach(function (addr) {
                appendCcAddress(addr);
            });
            if (partyCcToggle && partyCcToggle.checked && (data.party_cc || []).length) {
                (data.party_cc || []).forEach(function (addr) {
                    appendCcAddress(addr);
                    activePartyCcEmails.push(addr);
                });
            }
            if (window.composeEmailSignature && typeof window.composeEmailSignature.applyToComposeEditor === 'function') {
                window.composeEmailSignature.applyToComposeEditor();
            }
        };
        if (useCache) {
            finish(useCache);
            return;
        }
        fetch(partyMappingLookupUrl + '?party_id=' + encodeURIComponent(String(id)), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.ok) {
                    partyCcCache[cacheKey] = data;
                }
                finish(data || { ok: false });
            })
            .catch(function () {});
    }

    if (partySearchInput && partyDd && partyAutocompleteUrl) {
        partySearchInput.addEventListener('input', function () {
            clearTimeout(partySearchDebounce);
            var q = partySearchInput.value.trim();
            partySearchDebounce = setTimeout(function () {
                if (q.length < 1) {
                    hideComposePartyDd();
                    if (partyHiddenId) {
                        partyHiddenId.value = '0';
                    }
                    return;
                }
                fetch(partyAutocompleteUrl + '?action=search&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        partyDd.innerHTML = '';
                        if (!d || !d.results || !d.results.length) {
                            partyDd.hidden = true;
                            return;
                        }
                        d.results.forEach(function (row) {
                            var btn = document.createElement('button');
                            btn.type = 'button';
                            var strong = document.createElement('strong');
                            strong.textContent = row.name || '';
                            btn.appendChild(strong);
                            var meta = document.createElement('span');
                            meta.className = 'compose-party-dropdown__meta';
                            meta.textContent = 'ID ' + row.id + (row.primary_email ? ' · ' + row.primary_email : '');
                            btn.appendChild(meta);
                            btn.addEventListener('click', function () {
                                applyPartyMapping(row.id, true);
                                hideComposePartyDd();
                            });
                            partyDd.appendChild(btn);
                        });
                        partyDd.hidden = false;
                    })
                    .catch(function () {
                        hideComposePartyDd();
                    });
            }, 200);
        });

        document.addEventListener('click', function (ev) {
            if (!partyDd || partyDd.hidden) {
                return;
            }
            if (ev.target === partySearchInput || partyDd.contains(ev.target)) {
                return;
            }
            hideComposePartyDd();
        });
    }

    if (composeForm) {
    Array.from(composeForm.querySelectorAll('input, select, textarea, button')).forEach(function(control) {
        if (control.disabled) {
            control.setAttribute('data-disabled-original', '1');
        }
    });
    }

    function setComposeSending(sending) {
        if (!composeForm || !modal || !sendBtn) {
            return;
        }
        isSending = sending;
        modal.classList.toggle('is-sending', sending);
        sendBtn.classList.toggle('is-loading', sending);

        Array.from(composeForm.querySelectorAll('input, select, textarea, button')).forEach(function(control) {
            if (control.type === 'hidden') {
                return;
            }
            control.disabled = sending || control.hasAttribute('data-disabled-original');
        });
        var richEditor = document.getElementById('compose-body-html');
        if (richEditor) {
            richEditor.contentEditable = sending ? 'false' : 'true';
        }
        var tb = document.getElementById('compose-editor-toolbar');
        if (tb) {
            Array.from(tb.querySelectorAll('button')).forEach(function(b) {
                b.disabled = !!sending;
            });
        }
    }

    function openCompose(arg1, subject, body, ticketId, partyId) {
        if (isSending) return;
        composeClearAttachments();
        var opts = typeof arg1 === 'object' && arg1 !== null && !Array.isArray(arg1)
            ? arg1
            : { to: arg1, subject: subject, body: body, ticketId: ticketId, partyId: partyId, cc: '' };

        var toEmail = opts.to != null ? String(opts.to) : '';
        var ccVal = opts.cc != null ? String(opts.cc) : '';
        document.getElementById('compose-to').value = toEmail;
        if (ccInput) {
            ccInput.value = ccVal;
            var ccListOnOpen = ccVal.split(/[,;\n]+/).map(function (s) { return s.trim(); }).filter(function (s) {
                return s && s.indexOf('@') !== -1;
            });
            ccPanelExpanded = ccListOnOpen.length === 0;
            composeCcSetExpanded(ccPanelExpanded);
            refreshComposeCcUi();
        }
        document.getElementById('compose-subject').value = opts.subject != null ? String(opts.subject) : '';
        var bodyVal = opts.body != null ? String(opts.body) : '';
        var quotedVal = opts.quotedPlain != null ? String(opts.quotedPlain) : '';
        var quoteKind = opts.quoteKind != null ? String(opts.quoteKind) : 'reply';
        if (window.composeRichEditor) {
            if (quotedVal.trim() !== '') {
                window.composeRichEditor.setBodyWithQuote(bodyVal, quotedVal, quoteKind);
            } else {
                window.composeRichEditor.setBody(bodyVal);
            }
        } else {
            var combined = bodyVal;
            if (quotedVal.trim() !== '') {
                combined = combined + (combined ? '\n\n' : '') + quotedVal;
            }
            document.getElementById('compose-body').value = combined;
        }

        var tid = opts.ticketId != null ? String(opts.ticketId) : '';
        var pid = opts.partyId != null ? String(opts.partyId) : '';
        var quickFlow = opts.quickReplyFlow != null ? String(opts.quickReplyFlow) : '';
        var quickFlowEl = document.getElementById('compose-quick-reply-flow');
        if (quickFlowEl) {
            quickFlowEl.value = quickFlow;
        }
        var inReplyEl = document.getElementById('compose-in-reply-to');
        var refsEl = document.getElementById('compose-references-header');
        if (inReplyEl) {
            inReplyEl.value = opts.composeInReplyTo != null ? String(opts.composeInReplyTo) : '';
        }
        if (refsEl) {
            refsEl.value = opts.composeReferencesHeader != null ? String(opts.composeReferencesHeader) : '';
        }

        ticketHidden.value = tid;
        var visibleTicket = document.getElementById('compose-ticket-id-select');
        if (tid) {
            var datalist = document.getElementById('compose-ticket-suggestions');
            var foundText = '';
            if (datalist) {
                var options = datalist.querySelectorAll('option');
                for (var i = 0; i < options.length; i++) {
                    if (parseInt(options[i].dataset.id, 10) === parseInt(tid, 10)) {
                        foundText = options[i].value;
                        break;
                    }
                }
            }
            visibleTicket.value = foundText;
        } else {
            visibleTicket.value = '';
        }

        if (tid) {
            var datalist2 = document.getElementById('compose-ticket-suggestions');
            if (datalist2) {
                var options2 = datalist2.querySelectorAll('option');
                for (var j = 0; j < options2.length; j++) {
                    if (parseInt(options2[j].dataset.id, 10) === parseInt(tid, 10)) {
                        var serial = options2[j].dataset.serial || '';
                        if (serial) {
                            autoInsertTicketSerial(serial);
                        }
                        break;
                    }
                }
            }
        }

        if (partyHiddenId && partySearchInput) {
            if (pid) {
                applyPartyMapping(pid, false);
            } else {
                partyHiddenId.value = '0';
                partySearchInput.value = '';
                hideComposePartyDd();
            }
        }

        modal.style.display = 'flex';

        if (window.composeEmailSignature && typeof window.composeEmailSignature.applyToComposeEditor === 'function') {
            window.composeEmailSignature.applyToComposeEditor();
        }
    }

    function appendCcAddress(address) {
        if (!ccInput || !address) return;
        var values = ccInput.value.split(/[,;\n]+/).map(function(item) {
            return item.trim();
        }).filter(Boolean);
        var exists = values.some(function(item) {
            return item.toLowerCase() === address.toLowerCase();
        });
        if (!exists) values.push(address);
        ccInput.value = values.join(', ');
        if (values.length > 0 && ccPanelExpanded) {
            ccPanelExpanded = false;
            composeCcSetExpanded(false);
        }
        refreshComposeCcUi();
        ccInput.focus();
    }

     function selectedTicketSerial() {
         if (!ticketHidden || !ticketHidden.value) return '';
         var datalist = document.getElementById('compose-ticket-suggestions');
         if (!datalist) return '';
         var options = datalist.querySelectorAll('option');
         for (var i = 0; i < options.length; i++) {
             if (options[i].dataset.id === ticketHidden.value) {
                 return options[i].dataset.serial || '';
             }
         }
         return '';
     }

     function autoInsertTicketSerial(serial) {
         if (!serial) return;
         var subjectInput = document.getElementById('compose-subject');
         if (!subjectInput) return;
         var currentSubject = subjectInput.value || '';
         var currentBody = window.composeRichEditor
             ? window.composeRichEditor.getPlainTextForChecks()
             : (document.getElementById('compose-body') ? document.getElementById('compose-body').value || '' : '');
         var combined = (currentSubject + '\n' + currentBody).toUpperCase();
         if (combined.indexOf(serial.toUpperCase()) !== -1) {
             return;
         }
         if (currentSubject.trim() === '') {
             subjectInput.value = '[' + serial + ']';
         } else {
             subjectInput.value = '[' + serial + '] ' + currentSubject;
         }
     }

     function closeCompose() {
         if (isSending) return;
         composeClearAttachments();
         modal.style.display = 'none';
     }

    if (composeBtn) {
        composeBtn.addEventListener('click', function() {
            openCompose({
                to: '',
                subject: '',
                body: '',
                ticketId: '',
                partyId: '',
                cc: '',
                composeInReplyTo: '',
                composeReferencesHeader: ''
            });
        });
    }
    if (closeBtn) {
        closeBtn.addEventListener('click', closeCompose);
    }
    if (cancelBtn) {
        cancelBtn.addEventListener('click', closeCompose);
    }
    if (ccPicker) {
        ccPicker.addEventListener('change', function() {
            appendCcAddress(ccPicker.value);
            ccPicker.value = '';
        });
    }

    // Ticket selector: sync visible input to hidden field
    if (ticketSelect) {
        ticketSelect.addEventListener('input', function() {
            var val = ticketSelect.value.trim();
            // Clear hidden field if visible input is empty
            if (val === '') {
                ticketHidden.value = '';
                return;
            }
            var datalist = document.getElementById('compose-ticket-suggestions');
            var foundId = '';
            if (datalist) {
                var options = datalist.querySelectorAll('option');
                for (var i = 0; i < options.length; i++) {
                    var opt = options[i];
                    // Exact match on display value
                    if (opt.value === val) {
                        foundId = opt.dataset.id;
                        break;
                    }
                    if ((opt.dataset.serial || '').toLowerCase() === val.toLowerCase()) {
                        foundId = opt.dataset.id;
                        ticketSelect.value = opt.value;
                        break;
                    }
                    // If user typed just the ID number
                    if (opt.dataset.id === val) {
                        foundId = opt.dataset.id;
                        ticketSelect.value = opt.value; // complete with full text
                        break;
                    }
                }
            }
            // If still not found, try parsing leading digits from the original (untrimmed) value
            if (!foundId) {
                var rawVal = ticketSelect.value;
                var match = rawVal.match(/^(\d+)/);
                if (match) foundId = match[1];
            }
            ticketHidden.value = foundId;

            // Auto-insert ticket serial into subject if not already present
            if (foundId) {
                var datalist = document.getElementById('compose-ticket-suggestions');
                var matchedOption = null;
                if (datalist) {
                    var opts = datalist.querySelectorAll('option');
                    for (var j = 0; j < opts.length; j++) {
                        if (opts[j].dataset.id == foundId) {
                            matchedOption = opts[j];
                            break;
                        }
                    }
                }
                if (matchedOption) {
                    var serial = matchedOption.dataset.serial || '';
                    if (serial) {
                        autoInsertTicketSerial(serial);
                    }
                }
            }
        });
    }

    // Reply buttons (legacy class; ticket view still uses incoming_cards)
    document.addEventListener('click', function(e) {
        if (e.target && e.target.matches('.reply-email-btn')) {
            e.preventDefault();
            var to = e.target.dataset.to;
            var subject = e.target.dataset.subject;
            var ticketId = e.target.dataset.ticketId || '';
            openCompose({ to: to, subject: subject, body: '', ticketId: ticketId, partyId: '', cc: '' });
        }
    });

    // Compose modal closes only via Close/Cancel or successful send — not on backdrop click.

    // ================= Compose file attachments (DataTransfer + list/remove) =================
    var composeAttachBuffer = [];

    function composeSyncFilesToInput() {
        var fi = document.getElementById('compose-attachments');
        if (!fi) {
            composeRefreshAttachList();
            return;
        }
        if (typeof DataTransfer === 'undefined') {
            composeRefreshAttachList();
            return;
        }
        var dt = new DataTransfer();
        composeAttachBuffer.forEach(function (f) {
            try {
                dt.items.add(f);
            } catch (e) {
                /* ignore */
            }
        });
        fi.files = dt.files;
        composeRefreshAttachList();
    }

    function composeRefreshAttachList() {
        var list = document.getElementById('compose-attach-list');
        if (!list) {
            return;
        }
        list.innerHTML = '';
        composeAttachBuffer.forEach(function (file, idx) {
            var li = document.createElement('li');
            var name = document.createElement('span');
            name.className = 'compose-attach-name';
            name.textContent = file.name + ' (' + Math.round(file.size / 1024) + ' KB)';
            var rm = document.createElement('button');
            rm.type = 'button';
            rm.className = 'btn btn-outline btn-sm';
            rm.textContent = 'Remove';
            rm.addEventListener('click', function () {
                composeAttachBuffer.splice(idx, 1);
                composeSyncFilesToInput();
            });
            li.appendChild(name);
            li.appendChild(rm);
            list.appendChild(li);
        });
    }

    function composeClearAttachments() {
        composeAttachBuffer = [];
        var fi = document.getElementById('compose-attachments');
        if (fi && typeof DataTransfer !== 'undefined') {
            fi.files = new DataTransfer().files;
        } else if (fi) {
            fi.value = '';
        }
        composeRefreshAttachList();
    }

    var fileInput = document.getElementById('compose-attachments');
    var attachAddBtn = document.getElementById('compose-attach-add');
    if (attachAddBtn && fileInput) {
        attachAddBtn.addEventListener('click', function () {
            if (isSending) {
                return;
            }
            fileInput.click();
        });
    }
    if (fileInput) {
        fileInput.addEventListener('change', function () {
            var added = Array.from(fileInput.files || []);
            added.forEach(function (f) {
                composeAttachBuffer.push(f);
            });
            composeSyncFilesToInput();
        });
    }

    function responseErrorMessage(xhr) {
        var raw = (xhr.responseText || '').trim();
        if (!raw) return 'Request failed';

        try {
            var parsed = JSON.parse(raw);
            return parsed.message || parsed.error || 'Request failed';
        } catch (e) {
            return raw.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 500) || 'Invalid server response';
        }
    }

    // ================= AJAX FORM SUBMIT =================
    if (composeForm) {
    composeForm.addEventListener('submit', function(e) {
        e.preventDefault();
        if (isSending) return;

        if (window.composeRichEditor) {
            window.composeRichEditor.syncToHidden();
        }
        var formData = new FormData(composeForm);
        var selectedSerial = selectedTicketSerial();
        if (ticketHidden.value && selectedSerial) {
            var subjectValue = document.getElementById('compose-subject').value || '';
            var bodyPlainCheck = window.composeRichEditor
                ? window.composeRichEditor.getPlainTextForChecks()
                : (document.getElementById('compose-body').value || '');
            var sourceText = (subjectValue + '\n' + bodyPlainCheck).toUpperCase();
            var numericInternalPattern = new RegExp('\\bINTERNAL[_\\s-]*(?:TICKET[_\\s-]*)?ID\\s*[:#-]?\\s*' + ticketHidden.value + '\\b', 'i');
            if (sourceText.indexOf(selectedSerial.toUpperCase()) === -1 && !numericInternalPattern.test(subjectValue + '\n' + bodyPlainCheck)) {
                showToast('Include internal ticket ID ' + selectedSerial + ' in the subject or body before sending.', 'error');
                return;
            }
        }
        setComposeSending(true);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', window.location.href, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        xhr.onload = function() {
            setComposeSending(false);

            if (xhr.status === 200) {
                try {
                    var response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        showToast(response.message || 'Email sent successfully', 'success');
                        closeCompose();
                        setTimeout(function() { location.reload(); }, 1000);
                    } else {
                        showToast(response.message || 'Failed to send email', 'error');
                    }
                } catch (e) {
                    showToast(responseErrorMessage(xhr), 'error');
                }
            } else {
                showToast(responseErrorMessage(xhr), 'error');
            }
        };

        xhr.onerror = function() {
            setComposeSending(false);
            showToast('Network error', 'error');
        };

        xhr.send(formData);
    });
    }

    <?php if ($canManageEmailLogs): ?>
    function stripComposeOpenParamsFromUrl() {
        try {
            var nextUrl = new URL(window.location.href);
            ['open_compose', 'compose_to', 'compose_subject', 'compose_ticket_id', 'compose_party_id', 'compose_cc', 'quick_reply'].forEach(function (key) {
                nextUrl.searchParams.delete(key);
            });
            var qs = nextUrl.searchParams.toString();
            window.history.replaceState({}, document.title, nextUrl.pathname + (qs ? '?' + qs : '') + nextUrl.hash);
        } catch (urlErr) {
            /* ignore */
        }
    }

    function runComposePrefillOpen() {
        if (!composePrefill || !composePrefill.open_compose) {
            return;
        }
        if (!(composePrefill.to || composePrefill.ticket_id || composePrefill.party_id)) {
            return;
        }
        openCompose({
            to: composePrefill.to || '',
            subject: composePrefill.subject || '',
            body: '',
            ticketId: composePrefill.ticket_id ? String(composePrefill.ticket_id) : '',
            partyId: composePrefill.party_id ? String(composePrefill.party_id) : '',
            cc: composePrefill.cc || '',
            quotedPlain: '',
            quoteKind: 'reply',
            quickReplyFlow: composePrefill.quick_reply_flow || '',
            composeInReplyTo: composePrefill.compose_in_reply_to || '',
            composeReferencesHeader: composePrefill.compose_references_header || ''
        });
        stripComposeOpenParamsFromUrl();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', runComposePrefillOpen);
    } else {
        runComposePrefillOpen();
    }
    <?php endif; ?>

    if (window.initEmailLogsWorkspace) {
        var emailLogsComposeOpener = <?php echo $canManageEmailLogs ? 'openCompose' : 'function () {}'; ?>;
        window.initEmailLogsWorkspace(emailLogsComposeOpener);
    }
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
