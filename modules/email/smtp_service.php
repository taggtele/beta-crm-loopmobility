<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/email_account_service.php';
require_once __DIR__ . '/../../services/ticket_log_service.php';
require_once __DIR__ . '/../../services/email_attachments_service.php';
require_once __DIR__ . '/../../services/email_minio_storage_service.php';

// Normalizes newlines for raw SMTP transport.
function email_smtp_normalize_text(string $text): string
{
    return preg_replace("/\r\n|\r|\n/", "\r\n", trim($text)) ?: '';
}

// True when compose body has visible text and/or embedded images (HTML paste/screenshots).
function email_smtp_body_has_substance(string $body, bool $bodyIsHtml): bool
{
    $body = trim($body);
    if ($body === '') {
        return false;
    }
    if ($bodyIsHtml) {
        if (preg_match('/<img\b/i', $body)) {
            return true;
        }
        $stripped = trim(strip_tags($body));

        return $stripped !== '';
    }

    return true;
}

// Strips dangerous markup from user HTML compose while keeping tables, links, and inline images.
function email_smtp_sanitize_compose_html(string $html): string
{
    $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html) ?? '';
    $html = preg_replace('#<style\b[^>]*>.*?</style>#is', '', $html) ?? '';
    $html = preg_replace('#<noscript\b[^>]*>.*?</noscript>#is', '', $html) ?? '';
    $html = preg_replace('#<(?:iframe|object|embed|meta|base|link)\b[^>]*>.*?</(?:iframe|object|embed|meta|base|link)>#is', '', $html) ?? '';
    $html = preg_replace('#<(?:iframe|object|embed|meta|base|link)\b[^>]*/>#is', '', $html) ?? '';
    $html = preg_replace('/\s+on[a-z][a-z0-9_-]*\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
    $html = preg_replace('/\s+on[a-z][a-z0-9_-]*\s*=\s*[^\s>]+/i', '', $html) ?? '';

    $allowed = '<div><span><p><br><br/><table><tbody><thead><tfoot><tr><th><td><caption><col><colgroup>'
        . '<a><img><ul><ol><li><strong><b><em><i><u><s><strike><h1><h2><h3><h4><h5><h6>'
        . '<blockquote><pre><code><font><center><hr><section><article><header><footer><main><small><sub><sup><del><ins>'
        . '<figure><figcaption>';

    $html = strip_tags($html, $allowed);
    $html = preg_replace('#\s(href|src)\s*=\s*(\'|")\s*javascript:[^"\']*#i', ' $1=$2#', $html) ?? '';
    $html = preg_replace('#\s(href|src)\s*=\s*javascript:[^\s>]+#i', ' $1="#"', $html) ?? '';
    $html = preg_replace('#\sstyle\s*=\s*(\'|")[^"\']*(expression\s*\(|@import|javascript\s*:)[^"\']*\1#i', '', $html) ?? '';

    return trim($html);
}

function email_smtp_max_inline_image_bytes(): int
{
    return 10 * 1024 * 1024;
}

function email_smtp_max_compose_body_bytes(): int
{
    return 3 * 1024 * 1024;
}

/**
 * Keeps compose image validation soft so large Outlook-style paste does not block send.
 */
function email_smtp_validate_inline_data_uri_images(string $html): void
{
    $max = email_smtp_max_inline_image_bytes();
    if (!preg_match_all(
        '/\bsrc\s*=\s*(["\'])data:image\/([a-zA-Z0-9.+-]+);base64,([\s\S]*?)\1/i',
        $html,
        $matches,
        PREG_SET_ORDER
    )) {
        return;
    }

    foreach ($matches as $m) {
        $b64 = preg_replace('/\s+/', '', (string) $m[3]) ?? '';
        $decoded = base64_decode($b64, true);
        if ($decoded === false || $decoded === '') {
            continue;
        }
        if (strlen($decoded) > $max) {
            error_log('[SMTP email] Inline image exceeds soft compose limit; continuing with MinIO/SMTP fallback.');
        }
    }
}

function email_smtp_body_for_db(string $body, bool $bodyIsHtml): string
{
    if (!$bodyIsHtml || stripos($body, 'data:image/') === false) {
        return $body;
    }

    $body = preg_replace(
        '/\bsrc\s*=\s*(["\'])data:image\/[a-zA-Z0-9.+-]+;base64,[\s\S]*?\1/i',
        'src="" data-inline-image="pending-minio"',
        $body
    ) ?? $body;

    return preg_replace(
        '/url\s*\(\s*(["\']?)data:image\/[a-zA-Z0-9.+-]+;base64,[\s\S]*?\1\s*\)/i',
        'url("")',
        $body
    ) ?? $body;
}

function email_smtp_compact_body_for_storage(string $body, bool $bodyIsHtml): string
{
    $body = trim($body);
    if ($body === '' || !$bodyIsHtml) {
        return $body;
    }

    $body = email_smtp_sanitize_compose_html($body);
    $body = preg_replace('/\s+(?:class|id|cellpadding|cellspacing|border|bgcolor|align|valign)\s*=\s*(["\']).*?\1/i', '', $body) ?? $body;
    $body = preg_replace('/\s+style\s*=\s*(["\']).*?\1/i', '', $body) ?? $body;
    $body = preg_replace('/\s+data-[a-z0-9_-]+\s*=\s*(["\']).*?\1/i', '', $body) ?? $body;
    $body = preg_replace('/\s+(?:width|height)\s*=\s*(["\'])\s*[^"\']*\1/i', '', $body) ?? $body;
    $body = preg_replace('/\s+src\s*=\s*(["\'])data:image\/[^"\']*\1/i', ' src=""', $body) ?? $body;

    if (strlen($body) > email_smtp_max_compose_body_bytes()) {
        $plain = trim(html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $plain = mb_substr($plain, 0, email_smtp_max_compose_body_bytes());
        $body = '<div>' . nl2br(e($plain)) . '</div>';
    }

    return trim($body);
}

function email_smtp_is_max_packet_exception(Throwable $throwable): bool
{
    $message = strtolower($throwable->getMessage());
    if (strpos($message, 'max_allowed_packet') !== false) {
        return true;
    }

    $info = null;
    if ($throwable instanceof PDOException && isset($throwable->errorInfo) && is_array($throwable->errorInfo)) {
        $info = $throwable->errorInfo;
    }

    return $info && (($info[0] ?? '') === '08S01' || (int) ($info[1] ?? 0) === 1153);
}

/**
 * Converts pasted data:image/...;base64,... img sources into cid: references and returns inline MIME parts.
 *
 * @return array{0:string,1:array<int,array{name:string,mime:string,data:string,cid:string,disposition:string}>}
 */
function email_smtp_convert_data_uri_images_to_cid(string $html): array
{
    $inline = [];
    $maxBytes = email_smtp_max_inline_image_bytes();
    $result = preg_replace_callback(
        '/\bsrc\s*=\s*(["\'])data:image\/([a-zA-Z0-9.+-]+);base64,([\s\S]*?)\1/i',
        static function (array $m) use (&$inline, $maxBytes): string {
            $q = $m[1];
            $subtypeRaw = strtolower((string) $m[2]);
            $subtypeRaw = preg_replace('/[^a-z0-9.+-]/', '', $subtypeRaw) ?: 'png';
            $b64 = preg_replace('/\s+/', '', (string) $m[3]) ?? '';
            $decoded = base64_decode($b64, true);
            if ($decoded === false || $decoded === '') {
                return 'src=' . $q . $q;
            }
            if (strlen($decoded) > $maxBytes) {
                return 'src=' . $q . $q;
            }
            try {
                $suffix = bin2hex(random_bytes(4));
            } catch (Throwable $ignored) {
                $suffix = str_replace('.', '', uniqid('', true));
            }
            $cid = 'compose-inline-' . $suffix . '@loopmobility.local';
            $mime = 'image/' . $subtypeRaw;
            if (!preg_match('#^image/[a-z0-9.+-]+$#i', $mime)) {
                $mime = 'image/png';
            }
            $ext = $subtypeRaw === 'jpeg' ? 'jpg' : (strpos($subtypeRaw, 'svg') !== false ? 'svg' : 'png');
            $inline[] = [
                'name' => 'inline-' . $suffix . '.' . $ext,
                'mime' => $mime,
                'data' => $decoded,
                'cid' => $cid,
                'disposition' => 'inline',
            ];

            return 'src=' . $q . 'cid:' . $cid . $q;
        },
        $html
    );

    return [(string) $result, $inline];
}

// Loads queued file attachments from disk for SMTP retry / cron delivery.
function email_smtp_load_outbox_attachments(PDO $pdo, int $outboxId): array
{
    $pdo = get_pdo();
    if ($outboxId <= 0) {
        return [];
    }
    try {
        email_attachments_ensure_table($pdo);
        $stmt = $pdo->prepare(
            'SELECT file_path, file_name, mime_type FROM email_attachments WHERE outbox_id = :id ORDER BY id ASC'
        );
        $stmt->execute([':id' => $outboxId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $ignored) {
        return [];
    }
    $out = [];
    foreach ($rows as $row) {
        $path = trim((string) ($row['file_path'] ?? ''));
        if ($path === '') {
            continue;
        }

        if (preg_match('#^https?://#i', $path)) {
            $data = email_minio_enabled()
                ? email_minio_read_mapped_file(
                    $pdo,
                    'outgoing',
                    $outboxId,
                    (string) ($row['file_name'] ?? ''),
                    $path
                )
                : '';
            if ($data === '') {
                $data = email_minio_download_url($path);
            }
        } elseif (is_readable($path)) {
            $data = @file_get_contents($path);
        } else {
            continue;
        }

        if ($data === false || $data === '') {
            continue;
        }
        $out[] = [
            'name' => (string) ($row['file_name'] ?? 'attachment.bin'),
            'mime' => (string) ($row['mime_type'] ?? 'application/octet-stream'),
            'data' => $data,
        ];
    }

    return $out;
}

function email_smtp_prepare_minio_body_for_outbox(PDO $pdo, int $outboxId, string $body): string
{
    $pdo = get_pdo();
    if ($outboxId <= 0 || trim($body) === '' || !email_minio_enabled() || stripos($body, 'data:image/') === false) {
        return $body;
    }

    // This MinIO + compression pipeline is currently for local testing. Production enable/disable controlled via config flag USE_MINIO.
    $result = email_minio_replace_data_uris_with_urls($pdo, 'outgoing', $outboxId, $body);
    $updatedBody = (string) ($result['html'] ?? $body);
    if ($updatedBody !== $body) {
        $pdo = get_pdo();
        $stmt = $pdo->prepare('UPDATE email_outbox_log SET body = :body WHERE id = :id');
        $stmt->execute([
            ':body' => $updatedBody,
            ':id' => $outboxId,
        ]);
    }

    return $updatedBody;
}

function email_smtp_parse_recipient_list(string $value): array
{
    $valid = [];
    $invalid = [];

    foreach (preg_split('/[,;\r\n]+/', $value) ?: [] as $part) {
        $part = trim((string) $part);
        if ($part === '') {
            continue;
        }

        $email = $part;
        if (preg_match('/<([^<>]+)>/', $part, $matches)) {
            $email = trim((string) $matches[1]);
        }
        $email = trim($email, " \t\n\r\0\x0B\"'");

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $invalid[] = $part;
            continue;
        }

        $key = strtolower($email);
        if (!isset($valid[$key])) {
            $valid[$key] = $email;
        }
    }

    return [
        'valid' => array_values($valid),
        'invalid' => $invalid,
    ];
}

function email_smtp_normalize_recipient_array(array $emails): array
{
    $normalized = [];

    foreach ($emails as $email) {
        $email = trim((string) $email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }

        $key = strtolower($email);
        if (!isset($normalized[$key])) {
            $normalized[$key] = $email;
        }
    }

    return array_values($normalized);
}

function email_smtp_header_value(string $value): string
{
    return trim(str_replace(["\r", "\n"], ' ', $value));
}

function email_smtp_format_from_header(array $account): string
{
    $email = email_smtp_header_value((string) ($account['email'] ?? ''));
    $fromName = email_smtp_header_value((string) ($account['from_name'] ?? ''));

    if ($fromName === '') {
        return $email;
    }

    return '"' . addcslashes($fromName, '"\\') . '" <' . $email . '>';
}

function email_smtp_address_header(array $emails): string
{
    return implode(', ', array_map('email_smtp_header_value', email_smtp_normalize_recipient_array($emails)));
}

function email_smtp_generate_message_id(array $account = []): string
{
    $email = trim((string) ($account['email'] ?? ''));
    $domain = strtolower(trim((string) (explode('@', $email)[1] ?? 'local')));
    $domain = preg_replace('/[^a-z0-9.-]/', '', $domain) ?: 'local';

    try {
        $random = bin2hex(random_bytes(8));
    } catch (Throwable $throwable) {
        $random = sha1(uniqid('', true));
    }

    return '<noc-' . date('YmdHis') . '-' . $random . '@' . $domain . '>';
}

function email_smtp_data_response_timeout(int $payloadBytes = 0): int
{
    if ($payloadBytes <= 0) {
        return 180;
    }

    $payloadMb = (int) ceil($payloadBytes / (1024 * 1024));
    return max(180, min(600, 120 + ($payloadMb * 30)));
}

function email_smtp_is_google_host(string $host): bool
{
    $host = strtolower(trim($host));

    return $host !== '' && (str_contains($host, 'gmail.com') || str_contains($host, 'googlemail.com'));
}

function email_smtp_auth_username(array $account): string
{
    $email = trim((string) ($account['email'] ?? ''));
    $username = trim((string) ($account['username'] ?? ''));
    if ($username === '') {
        $username = $email;
    }

    if ($email !== '' && email_smtp_is_google_host((string) ($account['smtp_host'] ?? ''))) {
        $username = $email;
    }

    return $username;
}

function email_smtp_auth_password(array $account): string
{
    $password = (string) ($account['password'] ?? '');

    if (email_smtp_is_google_host((string) ($account['smtp_host'] ?? ''))) {
        $normalized = preg_replace('/\s+/', '', $password);
        if (is_string($normalized) && $normalized !== '') {
            return $normalized;
        }
    }

    return $password;
}

function email_smtp_outbox_columns(PDO $pdo): array
{
    $pdo = get_pdo();
    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM email_outbox_log')->fetchAll() as $column) {
        $field = (string) ($column['Field'] ?? '');
        if ($field !== '') {
            $columns[$field] = true;
        }
    }

    return $columns;
}

function email_smtp_ensure_outbox_columns(PDO $pdo): void
{
    $pdo = get_pdo();
    static $ready = false;

    if ($ready) {
        return;
    }

    $columns = email_smtp_outbox_columns($pdo);
    if (!isset($columns['email_account_id'])) {
        $pdo->exec('ALTER TABLE email_outbox_log ADD COLUMN email_account_id INT NULL AFTER ticket_id');
    }

    if (!isset($columns['from_email'])) {
        $pdo->exec('ALTER TABLE email_outbox_log ADD COLUMN from_email VARCHAR(150) NULL AFTER email_account_id');
    }

    if (!isset($columns['cc_email'])) {
        $pdo->exec('ALTER TABLE email_outbox_log ADD COLUMN cc_email TEXT NULL AFTER to_email');
    }

    if (!isset($columns['message_id'])) {
        $pdo->exec('ALTER TABLE email_outbox_log ADD COLUMN message_id VARCHAR(255) NULL AFTER cc_email');
    }

    if (!isset($columns['in_reply_to'])) {
        $pdo->exec('ALTER TABLE email_outbox_log ADD COLUMN in_reply_to TEXT NULL AFTER message_id');
    }

    if (!isset($columns['references_header'])) {
        $pdo->exec('ALTER TABLE email_outbox_log ADD COLUMN references_header TEXT NULL AFTER in_reply_to');
    }

    if (!isset($columns['party_id'])) {
        $pdo->exec('ALTER TABLE email_outbox_log ADD COLUMN party_id INT UNSIGNED NULL AFTER ticket_id');
    }

    if (!isset($columns['body_is_html'])) {
        $pdo->exec('ALTER TABLE email_outbox_log ADD COLUMN body_is_html TINYINT(1) NOT NULL DEFAULT 0 AFTER body');
    }

    $bodyColumn = null;
    foreach ($pdo->query("SHOW COLUMNS FROM email_outbox_log LIKE 'body'")->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $bodyColumn = $column;
        break;
    }
    if ($bodyColumn && stripos((string) ($bodyColumn['Type'] ?? ''), 'longtext') === false) {
        $pdo->exec('ALTER TABLE email_outbox_log MODIFY COLUMN body LONGTEXT NULL');
    }

    $ready = true;
}

function email_smtp_outbox_has_column(PDO $pdo, string $column): bool
{
    email_smtp_ensure_outbox_columns($pdo);
    $columns = email_smtp_outbox_columns($pdo);

    return isset($columns[$column]);
}

function email_smtp_outbox_headers(PDO $pdo, int $outboxId, array $account = []): array
{
    $pdo = get_pdo();
    email_smtp_ensure_outbox_columns($pdo);

    $stmt = $pdo->prepare(
        'SELECT message_id, in_reply_to, references_header
         FROM email_outbox_log
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $outboxId]);
    $row = $stmt->fetch() ?: [];

    $messageId = trim((string) ($row['message_id'] ?? ''));
    if ($messageId === '') {
        $messageId = email_smtp_generate_message_id($account);
        $updateStmt = $pdo->prepare('UPDATE email_outbox_log SET message_id = :message_id WHERE id = :id');
        $updateStmt->execute([':message_id' => $messageId, ':id' => $outboxId]);
    }

    return [
        'message_id' => $messageId,
        'in_reply_to' => trim((string) ($row['in_reply_to'] ?? '')),
        'references_header' => trim((string) ($row['references_header'] ?? '')),
    ];
}

function email_smtp_account_select_columns(PDO $pdo): string
{
    $encryptionSelect = email_smtp_account_has_column($pdo, 'smtp_encryption')
        ? "COALESCE(NULLIF(smtp_encryption, ''), encryption) AS encryption"
        : 'encryption';
    $usernameSelect = email_smtp_account_has_column($pdo, 'username')
        ? "COALESCE(NULLIF(username, ''), email) AS username"
        : 'email AS username';
    $fromNameSelect = email_smtp_account_has_column($pdo, 'from_name')
        ? 'from_name'
        : 'NULL AS from_name';
    $autoReplySelect = email_smtp_account_has_column($pdo, 'is_auto_reply_account')
        ? 'is_auto_reply_account'
        : '0 AS is_auto_reply_account';

    return 'id, email, ' . $usernameSelect . ', ' . $fromNameSelect . ', password, smtp_host, smtp_port, ' . $encryptionSelect . ', is_active, ' . $autoReplySelect;
}

function email_smtp_available_accounts(PDO $pdo, bool $activeOnly = true): array
{
    $pdo = get_pdo();
    email_account_service_ensure_schema($pdo);

    $sql = 'SELECT ' . email_smtp_account_select_columns($pdo) . '
            FROM email_accounts
            WHERE smtp_host IS NOT NULL
            AND smtp_host <> \'\'';

    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }

    $sql .= ' ORDER BY is_active DESC, id ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute();

    return $stmt->fetchAll();
}

function email_smtp_account_has_column(PDO $pdo, string $column): bool
{
    static $columns = null;

    if ($columns === null) {
        email_account_service_ensure_schema($pdo);

        $columns = [];
        foreach ($pdo->query('SHOW COLUMNS FROM email_accounts')->fetchAll() as $row) {
            $field = (string) ($row['Field'] ?? '');
            if ($field !== '') {
                $columns[$field] = true;
            }
        }
    }

    return isset($columns[$column]);
}

// Returns the configured active email account used for SMTP delivery.
function email_smtp_active_account(PDO $pdo, ?int $accountId = null): ?array
{
    $pdo = get_pdo();
    email_account_service_ensure_schema($pdo);

    $sql = 'SELECT ' . email_smtp_account_select_columns($pdo) . '
         FROM email_accounts
         WHERE smtp_host IS NOT NULL
         AND smtp_host <> \'\'';

    $params = [];
    if (($accountId ?? 0) > 0) {
        $sql .= ' AND id = :id';
        $params[':id'] = $accountId;
    }

    $sql .= '
         ORDER BY is_active DESC, id ASC
         LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $account = $stmt->fetch();

    return $account ?: null;
}

// Returns the account marked for auto-replies; falls back to default active account if none marked.
function email_smtp_get_auto_reply_account(PDO $pdo): ?array
{
    $pdo = get_pdo();
    email_account_service_ensure_schema($pdo);

    // Check if column exists
    $hasColumn = email_smtp_account_has_column($pdo, 'is_auto_reply_account');
    if (!$hasColumn) {
        return email_smtp_active_account($pdo);
    }

    $stmt = $pdo->prepare(
        'SELECT ' . email_smtp_account_select_columns($pdo) . '
         FROM email_accounts
         WHERE is_auto_reply_account = 1
         AND is_active = 1
         AND smtp_host IS NOT NULL
         AND smtp_host <> \'\'
         LIMIT 1'
    );
    $stmt->execute();
    $account = $stmt->fetch();

    return $account ?: email_smtp_active_account($pdo);
}

// Queues an outgoing message. Optional $bodyIsHtml is stored when the outbox column exists (compose HTML).
function email_smtp_queue(
    PDO $pdo,
    string $toEmail,
    string $subject,
    string $body,
    ?int $ticketId = null,
    array $ccEmails = [],
    ?int $emailAccountId = null,
    ?int $partyId = null,
    bool $bodyIsHtml = false,
    ?string $inReplyTo = null,
    ?string $referencesHeader = null
): ?int {
    $pdo = get_pdo();
    $toEmail = trim($toEmail);
    $subject = trim($subject);
    $body = trim($body);
    $ccEmails = email_smtp_normalize_recipient_array($ccEmails);

    if ($toEmail === '' || $subject === '' || $body === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return null;
    }

    email_smtp_ensure_outbox_columns($pdo);
    $account = ($emailAccountId ?? 0) > 0 ? email_smtp_active_account($pdo, $emailAccountId) : null;
    $fromEmail = $account ? (string) ($account['email'] ?? '') : null;

    $columns = ['ticket_id'];
    $values = [':ticket_id'];
    $params = [
        ':ticket_id' => $ticketId,
    ];

    if (email_smtp_outbox_has_column($pdo, 'email_account_id')) {
        $columns[] = 'email_account_id';
        $values[] = ':email_account_id';
        $params[':email_account_id'] = $account ? (int) $account['id'] : null;
    }

    if (email_smtp_outbox_has_column($pdo, 'from_email')) {
        $columns[] = 'from_email';
        $values[] = ':from_email';
        $params[':from_email'] = $fromEmail;
    }

    if (email_smtp_outbox_has_column($pdo, 'party_id')) {
        $columns[] = 'party_id';
        $values[] = ':party_id';
        $params[':party_id'] = ($partyId ?? 0) > 0 ? (int) $partyId : null;
    }

    $messageId = email_smtp_generate_message_id($account ?: []);
    if (email_smtp_outbox_has_column($pdo, 'message_id')) {
        $columns[] = 'message_id';
        $values[] = ':message_id';
        $params[':message_id'] = $messageId;
    }

    $inReplyTo = trim((string) $inReplyTo);
    if ($inReplyTo !== '' && email_smtp_outbox_has_column($pdo, 'in_reply_to')) {
        $columns[] = 'in_reply_to';
        $values[] = ':in_reply_to';
        $params[':in_reply_to'] = $inReplyTo;
    }

    $referencesHeader = trim((string) $referencesHeader);
    if ($referencesHeader !== '' && email_smtp_outbox_has_column($pdo, 'references_header')) {
        $columns[] = 'references_header';
        $values[] = ':references_header';
        $params[':references_header'] = $referencesHeader;
    }

    $columns = array_merge($columns, ['to_email']);
    $values = array_merge($values, [':to_email']);

    if (email_smtp_outbox_has_column($pdo, 'cc_email')) {
        $columns[] = 'cc_email';
        $values[] = ':cc_email';
    }

    $columns = array_merge($columns, ['subject', 'body']);
    $values = array_merge($values, [':subject', ':body']);

    if (email_smtp_outbox_has_column($pdo, 'body_is_html')) {
        $columns[] = 'body_is_html';
        $values[] = ':body_is_html';
        $params[':body_is_html'] = $bodyIsHtml ? 1 : 0;
    }

    $columns = array_merge($columns, ['status', 'error_message', 'sent_at', 'created_at']);
    $values = array_merge($values, [':status', 'NULL', 'NULL', 'NOW()']);
    $params += [
        ':to_email' => $toEmail,
        ':subject' => $subject,
        ':body' => email_smtp_body_for_db($body, $bodyIsHtml),
        ':status' => 'pending',
    ];

    if (email_smtp_outbox_has_column($pdo, 'cc_email')) {
        $params[':cc_email'] = $ccEmails !== [] ? implode(', ', $ccEmails) : null;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO email_outbox_log (' . implode(', ', $columns) . ')
         VALUES (' . implode(', ', $values) . ')'
    );

    try {
        $stmt->execute($params);
    } catch (Throwable $throwable) {
        if (!email_smtp_is_max_packet_exception($throwable)) {
            throw $throwable;
        }

        $params[':body'] = $bodyIsHtml
            ? email_smtp_compact_body_for_storage($body, true)
            : mb_substr($body, 0, 60000);
        try {
            $stmt->execute($params);
        } catch (Throwable $retryThrowable) {
            if (!email_smtp_is_max_packet_exception($retryThrowable)) {
                throw $retryThrowable;
            }

            $params[':body'] = mb_substr(
                $bodyIsHtml ? email_smtp_compact_body_for_storage($body, true) : $body,
                0,
                60000
            );
            $stmt->execute($params);
        }
    }

    $outboxId = (int) $pdo->lastInsertId();
    if ($outboxId > 0 && $bodyIsHtml && trim($body) !== '') {
        $body = email_smtp_prepare_minio_body_for_outbox($pdo, $outboxId, $body);
    }
    if ($outboxId > 0 && $bodyIsHtml && trim($body) !== '') {
        require_once dirname(__DIR__, 2) . '/services/email_outbox_assets_service.php';
        email_outbox_assets_persist_preview_html($pdo, $outboxId, $body);
    }

    return $outboxId;
}

// Reads one SMTP response, including multiline replies.
function email_smtp_read_response($stream): array
{
    $response = '';
    $code = 0;

    while (($line = @fgets($stream, 515)) !== false) {
        $response .= $line;
        if (preg_match('/^(\d{3})([\s-])/', $line, $matches)) {
            $code = (int) $matches[1];
            if ($matches[2] === ' ') {
                break;
            }
        }
    }

    if ($response === '') {
        $meta = stream_get_meta_data($stream);
        if (!empty($meta['timed_out'])) {
            throw new RuntimeException('SMTP read timed out.');
        }

        throw new RuntimeException('SMTP connection closed by the remote host.');
    }

    return ['code' => $code, 'message' => trim($response)];
}

// Sends one raw command and validates the SMTP response code.
function email_smtp_command($stream, string $command, array $allowedCodes): array
{
    fwrite($stream, $command . "\r\n");
    $response = email_smtp_read_response($stream);

    if (!in_array($response['code'], $allowedCodes, true)) {
        throw new RuntimeException($response['message'] !== '' ? $response['message'] : ('SMTP command failed: ' . $command));
    }

    return $response;
}

function email_smtp_write_all($stream, string $data): void
{
    $offset = 0;
    $length = strlen($data);
    while ($offset < $length) {
        $written = fwrite($stream, substr($data, $offset, 65536));
        if ($written === false || $written <= 0) {
            throw new RuntimeException('SMTP DATA write failed.');
        }
        $offset += $written;
    }
}

// Opens an SMTP socket using the configured encryption mode.
function email_smtp_open_connection(array $account)
{
    $host = (string) $account['smtp_host'];
    $port = (int) $account['smtp_port'];
    $encryption = strtolower((string) ($account['encryption'] ?? 'ssl'));
    $transportHost = $encryption === 'ssl' ? 'ssl://' . $host : $host;

    $stream = @stream_socket_client($transportHost . ':' . $port, $errorNumber, $errorMessage, 20);
    if (!is_resource($stream)) {
        throw new RuntimeException('Unable to connect to SMTP server: ' . $errorMessage);
    }

    stream_set_timeout($stream, 20);
    $greeting = email_smtp_read_response($stream);
    if ($greeting['code'] !== 220) {
        throw new RuntimeException($greeting['message'] !== '' ? $greeting['message'] : 'SMTP greeting failed.');
    }

    email_smtp_command($stream, 'EHLO localhost', [250]);

    if ($encryption === 'tls') {
        email_smtp_command($stream, 'STARTTLS', [220]);
        $cryptoEnabled = stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if ($cryptoEnabled !== true) {
            throw new RuntimeException('Unable to enable TLS encryption for SMTP.');
        }
        email_smtp_command($stream, 'EHLO localhost', [250]);
    }

    return $stream;
}

function email_smtp_mime_sanitize_filename(string $name): string
{
    $name = basename(str_replace(["\0", "\r", "\n"], '', $name));

    return $name !== '' ? $name : 'attachment.bin';
}

// Appends one MIME subpart (--boundary …) for attachment or inline image.
function email_smtp_append_mime_file(string $boundary, array $file, bool $isInline): string
{
    $data = $file['data'] ?? '';
    if ($data === '') {
        return '';
    }
    $name = email_smtp_mime_sanitize_filename((string) ($file['name'] ?? 'file.bin'));
    $safeName = addcslashes($name, "\"\\");
    $mime = trim((string) ($file['mime'] ?? 'application/octet-stream'));
    if ($mime === '') {
        $mime = 'application/octet-stream';
    }
    $out = '--' . $boundary . "\r\n";
    $out .= 'Content-Type: ' . $mime . '; name="' . $safeName . "\"\r\n";
    $out .= "Content-Transfer-Encoding: base64\r\n";
    if ($isInline && !empty($file['cid'])) {
        $cid = trim((string) $file['cid'], '<> ');
        $out .= 'Content-ID: <' . $cid . ">\r\n";
        $out .= 'Content-Disposition: inline; filename="' . $safeName . "\"\r\n\r\n";
    } else {
        $out .= 'Content-Disposition: attachment; filename="' . $safeName . "\"\r\n\r\n";
    }
    $out .= chunk_split(base64_encode((string) $data), 76, "\r\n") . "\r\n";

    return $out;
}

/**
 * Builds full RFC 822 payload for SMTP DATA (headers + blank line + MIME body).
 *
 * @param array<int, array{name?:string,mime?:string,data?:string}> $attachments
 */
function email_smtp_build_message_rfc822(
    array $account,
    string $toEmail,
    string $subject,
    string $body,
    array $attachments,
    array $ccEmails,
    string $messageId,
    string $inReplyTo,
    string $references,
    bool $bodyIsHtml
): string {
    $common = [
        'Date: ' . date('r'),
        'Message-ID: ' . email_smtp_header_value($messageId),
        'From: ' . email_smtp_format_from_header($account),
        'To: ' . email_smtp_address_header([$toEmail]),
    ];
    if ($inReplyTo !== '') {
        $common[] = 'In-Reply-To: ' . email_smtp_header_value($inReplyTo);
    }
    if ($references !== '') {
        $common[] = 'References: ' . email_smtp_header_value($references);
    }
    if ($ccEmails) {
        $common[] = 'Cc: ' . email_smtp_address_header($ccEmails);
    }
    $common[] = 'Subject: ' . email_smtp_header_value($subject);
    $common[] = 'MIME-Version: 1.0';

    $htmlBody = $body;
    $inlineParts = [];
    if ($bodyIsHtml) {
        [$htmlBody, $inlineParts] = email_smtp_convert_data_uri_images_to_cid($body);
    }

    $fileParts = [];
    foreach ($attachments as $file) {
        $d = $file['data'] ?? '';
        if ($d === '') {
            continue;
        }
        $fileParts[] = [
            'name' => (string) ($file['name'] ?? 'attachment.bin'),
            'mime' => (string) ($file['mime'] ?? 'application/octet-stream'),
            'data' => $d,
        ];
    }

    if (!$bodyIsHtml && !$fileParts) {
        $headers = array_merge($common, [
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ]);

        return implode("\r\n", $headers) . "\r\n\r\n" . email_smtp_normalize_text($body);
    }

    if (!$bodyIsHtml && $fileParts) {
        $boundary = '===Mixed_' . md5((string) microtime(true)) . '===';
        $headers = array_merge($common, [
            'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
        ]);
        $out = implode("\r\n", $headers) . "\r\n\r\n";
        $out .= '--' . $boundary . "\r\n";
        $out .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $out .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $out .= email_smtp_normalize_text($body) . "\r\n";
        foreach ($fileParts as $fp) {
            $out .= email_smtp_append_mime_file($boundary, $fp, false);
        }
        $out .= '--' . $boundary . "--\r\n";

        return $out;
    }

    // HTML compose (with optional pasted inline images and optional file attachments)
    if ($bodyIsHtml && !$fileParts) {
        if (!$inlineParts) {
            $headers = array_merge($common, [
                'Content-Type: text/html; charset=UTF-8',
                'Content-Transfer-Encoding: base64',
            ]);

            return implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($htmlBody), 76, "\r\n");
        }
        $relB = '===Rel_' . md5((string) microtime(true) . 'r') . '===';
        $headers = array_merge($common, [
            'Content-Type: multipart/related; type="text/html"; boundary="' . $relB . '"',
        ]);
        $out = implode("\r\n", $headers) . "\r\n\r\n";
        $out .= '--' . $relB . "\r\n";
        $out .= "Content-Type: text/html; charset=UTF-8\r\n";
        $out .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $out .= chunk_split(base64_encode($htmlBody), 76, "\r\n") . "\r\n";
        foreach ($inlineParts as $ip) {
            $out .= email_smtp_append_mime_file($relB, $ip, true);
        }
        $out .= '--' . $relB . "--\r\n";

        return $out;
    }

    // HTML + file attachments
    $mixB = '===Mixed_' . md5((string) microtime(true) . 'm') . '===';
    $headers = array_merge($common, [
        'Content-Type: multipart/mixed; boundary="' . $mixB . '"',
    ]);
    $out = implode("\r\n", $headers) . "\r\n\r\n";
    $out .= '--' . $mixB . "\r\n";
    if (!$inlineParts) {
        $out .= "Content-Type: text/html; charset=UTF-8\r\n";
        $out .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $out .= chunk_split(base64_encode($htmlBody), 76, "\r\n") . "\r\n";
    } else {
        $relB = '===Rel_' . md5((string) microtime(true) . 'n') . '===';
        $out .= 'Content-Type: multipart/related; type="text/html"; boundary="' . $relB . "\"\r\n\r\n";
        $out .= '--' . $relB . "\r\n";
        $out .= "Content-Type: text/html; charset=UTF-8\r\n";
        $out .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $out .= chunk_split(base64_encode($htmlBody), 76, "\r\n") . "\r\n";
        foreach ($inlineParts as $ip) {
            $out .= email_smtp_append_mime_file($relB, $ip, true);
        }
        $out .= '--' . $relB . "--\r\n";
    }
    foreach ($fileParts as $fp) {
        $out .= email_smtp_append_mime_file($mixB, $fp, false);
    }
    $out .= '--' . $mixB . "--\r\n";

    return $out;
}

// Delivers one email using plain SMTP auth and DATA transport.
// Supports HTML bodies, pasted inline images (data: → CID), and file attachments.
function email_smtp_send_message(
    array $account,
    string $toEmail,
    string $subject,
    string $body,
    array $attachments = [],
    array $ccEmails = [],
    ?string $messageId = null,
    ?string $inReplyTo = null,
    string $references = '',
    bool $bodyIsHtml = false
): void {
    $toEmail = trim($toEmail);
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('A valid recipient email is required.');
    }

    $ccEmails = email_smtp_normalize_recipient_array($ccEmails);
    $toKey = strtolower($toEmail);
    $ccEmails = array_values(array_filter(
        $ccEmails,
        static fn(string $email): bool => strtolower($email) !== $toKey
    ));
    $messageId = trim((string) $messageId) !== '' ? trim((string) $messageId) : email_smtp_generate_message_id($account);
    $inReplyTo = trim((string) $inReplyTo);
    $references = trim($references);

    $stream = email_smtp_open_connection($account);

    try {
        $smtpUsername = email_smtp_auth_username($account);
        $smtpPassword = email_smtp_auth_password($account);

        email_smtp_command($stream, 'AUTH LOGIN', [334]);
        email_smtp_command($stream, base64_encode($smtpUsername), [334]);
        email_smtp_command($stream, base64_encode($smtpPassword), [235]);
        email_smtp_command($stream, 'MAIL FROM:<' . $account['email'] . '>', [250]);
        email_smtp_command($stream, 'RCPT TO:<' . $toEmail . '>', [250, 251]);
        foreach ($ccEmails as $ccEmail) {
            email_smtp_command($stream, 'RCPT TO:<' . $ccEmail . '>', [250, 251]);
        }
        email_smtp_command($stream, 'DATA', [354]);

        $payload = email_smtp_build_message_rfc822(
            $account,
            $toEmail,
            $subject,
            $body,
            $attachments,
            $ccEmails,
            $messageId,
            $inReplyTo,
            $references,
            $bodyIsHtml
        );

        $payload = str_replace("\r\n.", "\r\n..", $payload);
        $payloadData = rtrim($payload, "\r\n") . "\r\n.\r\n";
        stream_set_timeout($stream, email_smtp_data_response_timeout(strlen($payloadData)));
        email_smtp_write_all($stream, $payloadData);
        $response = email_smtp_read_response($stream);
        stream_set_timeout($stream, 20);
        if ($response['code'] !== 250) {
            throw new RuntimeException($response['message'] !== '' ? $response['message'] : 'SMTP DATA send failed.');
        }

        email_smtp_command($stream, 'QUIT', [221]);
    } finally {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }
}

// Marks an outbox item as sent after successful SMTP delivery.
function email_smtp_mark_sent(PDO $pdo, int $outboxId): void
{
    $pdo = get_pdo();
    $stmt = $pdo->prepare(
        'UPDATE email_outbox_log
         SET status = :status, sent_at = NOW(), error_message = NULL
         WHERE id = :id'
    );
    $stmt->execute([
        ':status' => 'sent',
        ':id' => $outboxId,
    ]);

    $ccSelect = email_smtp_outbox_has_column($pdo, 'cc_email') ? 'cc_email' : 'NULL AS cc_email';
    $fromSelect = email_smtp_outbox_has_column($pdo, 'from_email') ? 'from_email' : 'NULL AS from_email';
    $ticketStmt = $pdo->prepare('SELECT ticket_id, ' . $fromSelect . ', to_email, ' . $ccSelect . ', subject FROM email_outbox_log WHERE id = :id LIMIT 1');
    $ticketStmt->execute([':id' => $outboxId]);
    $row = $ticketStmt->fetch();
    if ($row && !empty($row['ticket_id'])) {
        $ccText = !empty($row['cc_email']) ? ' with CC ' . $row['cc_email'] : '';
        $fromText = !empty($row['from_email']) ? ' from ' . $row['from_email'] : '';
        ticket_log_service_add(
            $pdo,
            (int) $row['ticket_id'],
            'email_sent',
            'Email "' . $row['subject'] . '" sent' . $fromText . ' to ' . $row['to_email'] . $ccText . '.'
        );
    }
}

// Marks an outbox item as failed while preserving the failure reason.
function email_smtp_mark_failed(PDO $pdo, int $outboxId, string $errorMessage): void
{
    $pdo = get_pdo();
    $stmt = $pdo->prepare(
        'UPDATE email_outbox_log
         SET status = :status, error_message = :error_message
         WHERE id = :id'
    );
    $stmt->execute([
        ':status' => 'failed',
        ':error_message' => mb_substr($errorMessage, 0, 65535),
        ':id' => $outboxId,
    ]);

    $ccSelect = email_smtp_outbox_has_column($pdo, 'cc_email') ? 'cc_email' : 'NULL AS cc_email';
    $fromSelect = email_smtp_outbox_has_column($pdo, 'from_email') ? 'from_email' : 'NULL AS from_email';
    $ticketStmt = $pdo->prepare('SELECT ticket_id, ' . $fromSelect . ', to_email, ' . $ccSelect . ', subject FROM email_outbox_log WHERE id = :id LIMIT 1');
    $ticketStmt->execute([':id' => $outboxId]);
    $row = $ticketStmt->fetch();
    if ($row && !empty($row['ticket_id'])) {
        $ccText = !empty($row['cc_email']) ? ' with CC ' . $row['cc_email'] : '';
        $fromText = !empty($row['from_email']) ? ' from ' . $row['from_email'] : '';
        ticket_log_service_add(
            $pdo,
            (int) $row['ticket_id'],
            'email_failed',
            'Email "' . $row['subject'] . '"' . $fromText . ' to ' . $row['to_email'] . $ccText . ' failed: ' . mb_substr($errorMessage, 0, 500) . '.'
        );
    }
}

// Processes pending outbox entries in FIFO order.
function email_smtp_process_outbox(PDO $pdo, int $limit = 25): array
{
    $pdo = get_pdo();
    $ccSelect = email_smtp_outbox_has_column($pdo, 'cc_email') ? 'cc_email' : 'NULL AS cc_email';
    $accountSelect = email_smtp_outbox_has_column($pdo, 'email_account_id') ? 'email_account_id' : 'NULL AS email_account_id';
    $messageIdSelect = email_smtp_outbox_has_column($pdo, 'message_id') ? 'message_id' : 'NULL AS message_id';
    $inReplyToSelect = email_smtp_outbox_has_column($pdo, 'in_reply_to') ? 'in_reply_to' : 'NULL AS in_reply_to';
    $referencesSelect = email_smtp_outbox_has_column($pdo, 'references_header') ? 'references_header' : 'NULL AS references_header';
    $bodyIsHtmlSelect = email_smtp_outbox_has_column($pdo, 'body_is_html') ? 'body_is_html' : '0 AS body_is_html';
    $stmt = $pdo->prepare(
        'SELECT id, ticket_id, ' . $accountSelect . ', to_email, ' . $ccSelect . ', ' . $messageIdSelect . ', ' . $inReplyToSelect . ', ' . $referencesSelect . ', subject, body, ' . $bodyIsHtmlSelect . '
         FROM email_outbox_log
         WHERE status = :status
         ORDER BY id ASC
         LIMIT ' . (int) max(1, $limit)
    );
    $stmt->execute([':status' => 'pending']);
    $rows = $stmt->fetchAll();

    $summary = [
        'processed' => 0,
        'sent' => 0,
        'failed' => 0,
    ];

    if (!$rows) {
        return $summary;
    }

    $defaultAccount = email_smtp_active_account($pdo);
    if (!$defaultAccount) {
        foreach ($rows as $row) {
            email_smtp_mark_failed($pdo, (int) $row['id'], 'No active email account is configured.');
            $summary['processed']++;
            $summary['failed']++;
        }

        return $summary;
    }

    foreach ($rows as $row) {
        try {
            $account = !empty($row['email_account_id'])
                ? email_smtp_active_account($pdo, (int) $row['email_account_id'])
                : $defaultAccount;

            if (!$account) {
                throw new RuntimeException('The selected From email account is not active or no longer exists.');
            }

            $ccEmails = email_smtp_parse_recipient_list((string) ($row['cc_email'] ?? ''))['valid'];
            $headers = email_smtp_outbox_headers($pdo, (int) $row['id'], $account);
            $attachments = email_smtp_load_outbox_attachments($pdo, (int) $row['id']);
            email_smtp_send_message(
                $account,
                (string) $row['to_email'],
                (string) $row['subject'],
                (string) $row['body'],
                $attachments,
                $ccEmails,
                $headers['message_id'],
                $headers['in_reply_to'],
                $headers['references_header'],
                !empty($row['body_is_html'])
            );
            email_smtp_mark_sent($pdo, (int) $row['id']);
            $summary['sent']++;
        } catch (Throwable $throwable) {
            email_smtp_mark_failed($pdo, (int) $row['id'], $throwable->getMessage());
            $summary['failed']++;
        }

        $summary['processed']++;
    }

    return $summary;
}

// Attempts to send a single queued outbox row immediately.
function email_smtp_process_outbox_item(PDO $pdo, int $outboxId): bool
{
    $pdo = get_pdo();
    if ($outboxId <= 0) {
        return false;
    }

    $ccSelect = email_smtp_outbox_has_column($pdo, 'cc_email') ? 'cc_email' : 'NULL AS cc_email';
    $accountSelect = email_smtp_outbox_has_column($pdo, 'email_account_id') ? 'email_account_id' : 'NULL AS email_account_id';
    $messageIdSelect = email_smtp_outbox_has_column($pdo, 'message_id') ? 'message_id' : 'NULL AS message_id';
    $inReplyToSelect = email_smtp_outbox_has_column($pdo, 'in_reply_to') ? 'in_reply_to' : 'NULL AS in_reply_to';
    $referencesSelect = email_smtp_outbox_has_column($pdo, 'references_header') ? 'references_header' : 'NULL AS references_header';
    $bodyIsHtmlSelect = email_smtp_outbox_has_column($pdo, 'body_is_html') ? 'body_is_html' : '0 AS body_is_html';
    $stmt = $pdo->prepare(
        'SELECT id, ticket_id, ' . $accountSelect . ', to_email, ' . $ccSelect . ', ' . $messageIdSelect . ', ' . $inReplyToSelect . ', ' . $referencesSelect . ', subject, body, status, ' . $bodyIsHtmlSelect . '
         FROM email_outbox_log
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $outboxId]);
    $row = $stmt->fetch();

    if (!$row || (string) ($row['status'] ?? '') !== 'pending') {
        return false;
    }

    $account = !empty($row['email_account_id'])
        ? email_smtp_active_account($pdo, (int) $row['email_account_id'])
        : email_smtp_active_account($pdo);
    if (!$account) {
        email_smtp_mark_failed($pdo, $outboxId, 'The selected From email account is not active or no longer exists.');
        return false;
    }

    try {
        $ccEmails = email_smtp_parse_recipient_list((string) ($row['cc_email'] ?? ''))['valid'];
        $headers = email_smtp_outbox_headers($pdo, $outboxId, $account);
        $attachments = email_smtp_load_outbox_attachments($pdo, $outboxId);
        email_smtp_send_message(
            $account,
            (string) $row['to_email'],
            (string) $row['subject'],
            (string) $row['body'],
            $attachments,
            $ccEmails,
            $headers['message_id'],
            $headers['in_reply_to'],
            $headers['references_header'],
            !empty($row['body_is_html'])
        );
        email_smtp_mark_sent($pdo, $outboxId);
        return true;
    } catch (Throwable $throwable) {
        email_smtp_mark_failed($pdo, $outboxId, $throwable->getMessage());
        return false;
    }
}
