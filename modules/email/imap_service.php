<?php
require_once __DIR__ . '/email_processor.php';
require_once __DIR__ . '/../../services/email_account_service.php';
require_once __DIR__ . '/../../config/db.php';

// Returns all active email accounts that are IMAP-enabled.
function email_imap_active_accounts(PDO $pdo): array
{
    email_imap_ensure_account_columns($pdo);
    $usernameSelect = email_account_service_has_column($pdo, 'username')
        ? "COALESCE(NULLIF(username, ''), email) AS username"
        : 'email AS username';

    $stmt = $pdo->prepare(
        'SELECT id, user_id, email, ' . $usernameSelect . ', password, imap_host, imap_port, encryption, last_checked_at, import_cutoff_at, last_seen_uid, cron_enabled
         FROM email_accounts
         WHERE is_active = 1
         AND imap_host IS NOT NULL
         AND imap_host <> \'\'
         ORDER BY id ASC'
    );
    $stmt->execute();

    return $stmt->fetchAll();
}

// Returns one IMAP account by database ID, optionally including inactive rows.
function email_imap_account_by_id(PDO $pdo, int $accountId, bool $includeInactive = false): ?array
{
    email_imap_ensure_account_columns($pdo);
    $usernameSelect = email_account_service_has_column($pdo, 'username')
        ? "COALESCE(NULLIF(username, ''), email) AS username"
        : 'email AS username';

    if ($accountId <= 0) {
        return null;
    }

    $sql = 'SELECT id, user_id, email, ' . $usernameSelect . ', password, imap_host, imap_port, encryption, is_active, last_checked_at, import_cutoff_at, last_seen_uid
            FROM email_accounts
            WHERE id = :id
            AND imap_host IS NOT NULL
            AND imap_host <> \'\'';

    if (!$includeInactive) {
        $sql .= ' AND is_active = 1';
    }

    $sql .= ' LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $accountId]);
    $account = $stmt->fetch();

    return $account ?: null;
}

// email_accounts schema is managed by migrations.
function email_imap_ensure_account_columns(PDO $pdo): void
{
    static $ready = false;

    if ($ready) {
        return;
    }

    $ready = true;
}

// Updates the account heartbeat after an IMAP poll attempt finishes.
function email_imap_touch_account(PDO $pdo, int $accountId): void
{
    if ($accountId <= 0) {
        return;
    }

    $pdo = get_pdo();

    try {
        $stmt = $pdo->prepare(
            'UPDATE email_accounts
             SET last_checked_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([':id' => $accountId]);
    } catch (PDOException $e) {
        if (!pdo_connection_is_lost($e)) {
            throw $e;
        }

        $pdo = get_pdo();
        $stmt = $pdo->prepare(
            'UPDATE email_accounts
             SET last_checked_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([':id' => $accountId]);
    }
}

// Updates whether an email account can be imported by cron jobs.
function email_imap_set_account_active(PDO $pdo, int $accountId, bool $isActive): void
{
    if ($accountId <= 0) {
        return;
    }

    $stmt = $pdo->prepare(
        'UPDATE email_accounts
         SET is_active = :is_active
         WHERE id = :id'
    );
    $stmt->execute([
        ':is_active' => $isActive ? 1 : 0,
        ':id' => $accountId,
    ]);
}

// Sets the starting point for future-only imports and skips legacy inbox history.
function email_imap_initialize_cutoff(PDO $pdo, int $accountId, string $cutoffAt): void
{
    if ($accountId <= 0 || trim($cutoffAt) === '') {
        return;
    }

    $stmt = $pdo->prepare(
        'UPDATE email_accounts
         SET import_cutoff_at = :import_cutoff_at,
             last_checked_at = :last_checked_at
         WHERE id = :id
         AND import_cutoff_at IS NULL'
    );
    $stmt->execute([
        ':import_cutoff_at' => $cutoffAt,
        ':last_checked_at' => $cutoffAt,
        ':id' => $accountId,
    ]);
}

// Stores the highest mailbox UID as the future-only import baseline.
function email_imap_store_last_seen_uid(PDO $pdo, int $accountId, int $lastSeenUid, string $checkedAt): void
{
    if ($accountId <= 0 || $lastSeenUid < 0) {
        return;
    }

    $stmt = $pdo->prepare(
        'UPDATE email_accounts
         SET last_seen_uid = :last_seen_uid,
             import_cutoff_at = COALESCE(import_cutoff_at, :import_cutoff_at),
             last_checked_at = :last_checked_at
         WHERE id = :id'
    );
    $stmt->execute([
        ':last_seen_uid' => $lastSeenUid,
        ':import_cutoff_at' => $checkedAt,
        ':last_checked_at' => $checkedAt,
        ':id' => $accountId,
    ]);
}

// Arms one mailbox so that only future emails after the current highest UID are imported.
function email_imap_arm_future_only_baseline(PDO $pdo, int $accountId): array
{
    $account = email_imap_account_by_id($pdo, $accountId, true);
    if (!$account) {
        throw new RuntimeException('IMAP account not found.');
    }

    $armedAt = date('Y-m-d H:i:s');
    $connection = email_imap_open_connection($account);
    $stream = $connection['stream'];
    $sequence = $connection['sequence'];

    try {
        $status = email_imap_mailbox_uid_status($stream, $sequence);
        $highestUid = max(0, $status['uidnext'] - 1);

        email_imap_store_last_seen_uid($pdo, $accountId, $highestUid, $armedAt);

        return [
            'account_id' => $accountId,
            'email' => (string) ($account['email'] ?? ''),
            'highest_uid' => $highestUid,
            'message_count' => (int) ($status['messages'] ?? 0),
            'armed_at' => $armedAt,
        ];
    } finally {
        email_imap_close_connection($stream, $sequence);
    }
}

// Parses UID values from an IMAP SEARCH response.
function email_imap_parse_search_uids(array $lines): array
{
    $uids = [];

    foreach ($lines as $line) {
        if (stripos($line, '* SEARCH') === 0) {
            $parts = preg_split('/\s+/', trim($line));
            $uids = array_map('intval', array_values(array_filter(array_slice($parts, 2), 'strlen')));
            break;
        }
    }

    return $uids;
}

// Reads one IMAP line from the active socket.
function email_imap_read_line($stream): string
{
    $line = fgets($stream);
    $meta = stream_get_meta_data($stream);
    if ($line === false) {
        if (!empty($meta['timed_out'])) {
            throw new RuntimeException('IMAP read timed out.');
        }

        throw new RuntimeException('Unexpected end of IMAP stream.');
    }

    if (!empty($meta['timed_out'])) {
        throw new RuntimeException('IMAP read timed out.');
    }

    return $line;
}

// Reads a tagged IMAP command response until completion.
function email_imap_read_tagged_response($stream, string $tag): array
{
    $lines = [];
    $maxLines = 8000;

    for ($i = 0; $i < $maxLines; $i++) {
        $line = email_imap_read_line($stream);
        $lines[] = $line;

        if (strpos($line, $tag . ' ') === 0) {
            if (stripos($line, $tag . ' OK') === 0) {
                return $lines;
            }

            throw new RuntimeException(trim($line));
        }
    }

    throw new RuntimeException('IMAP response exceeded safe line limit.');
}

// Sends one IMAP command and returns the raw response lines.
function email_imap_command($stream, int $sequence, string $command): array
{
    $tag = 'A' . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    fwrite($stream, $tag . ' ' . $command . "\r\n");

    return email_imap_read_tagged_response($stream, $tag);
}

// Opens and authenticates an IMAP socket using the configured account.
function email_imap_open_connection(array $account)
{
    $encryption = strtolower((string) ($account['encryption'] ?? 'ssl'));
    $host = (string) $account['imap_host'];
    $port = (int) $account['imap_port'];
    $transportHost = $encryption === 'ssl' ? 'ssl://' . $host : $host;

    $stream = @stream_socket_client($transportHost . ':' . $port, $errorNumber, $errorMessage, 25);
    if (!is_resource($stream)) {
        throw new RuntimeException('Unable to connect to IMAP server: ' . $errorMessage);
    }

    stream_set_timeout($stream, 45);
    $greeting = email_imap_read_line($stream);
    if (stripos($greeting, '* OK') !== 0) {
        throw new RuntimeException(trim($greeting));
    }

    $sequence = 1;
    if ($encryption === 'tls') {
        email_imap_command($stream, $sequence++, 'STARTTLS');
        $cryptoEnabled = stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if ($cryptoEnabled !== true) {
            throw new RuntimeException('Unable to enable IMAP TLS encryption.');
        }
    }

    email_imap_command(
        $stream,
        $sequence++,
        'LOGIN "' . addcslashes((string) ($account['username'] ?? $account['email']), '\\"') . '" "' . addcslashes((string) $account['password'], '\\"') . '"'
    );
    email_imap_command($stream, $sequence++, 'SELECT INBOX');

    return ['stream' => $stream, 'sequence' => $sequence];
}

// Closes an IMAP socket without allowing logout errors to mask import results.
function email_imap_close_connection($stream, int &$sequence): void
{
    if (!is_resource($stream)) {
        return;
    }

    try {
        if ($sequence > 0) {
            email_imap_command($stream, $sequence++, 'LOGOUT');
        }
    } catch (Throwable $ignored) {
        // The socket is being closed anyway.
    }

    fclose($stream);
}

// Logs import errors and only prints them during CLI cron/manual runs.
function email_imap_report_error(string $message): void
{
    error_log($message);
    if (PHP_SAPI === 'cli') {
        echo $message . PHP_EOL;
    }
}

// Returns all message UIDs from the current mailbox (avoid on large mailboxes).
function email_imap_fetch_all_uids($stream, int &$sequence): array
{
    return email_imap_parse_search_uids(email_imap_command($stream, $sequence++, 'UID SEARCH ALL'));
}

/**
 * @return array{uidnext:int,messages:int}
 */
function email_imap_mailbox_uid_status($stream, int &$sequence): array
{
    $uidNext = 0;
    $messages = 0;

    foreach (email_imap_command($stream, $sequence++, 'STATUS INBOX (UIDNEXT MESSAGES)') as $line) {
        if (preg_match('/UIDNEXT\s+(\d+)/i', $line, $m)) {
            $uidNext = max(0, (int) $m[1]);
        }
        if (preg_match('/MESSAGES\s+(\d+)/i', $line, $m)) {
            $messages = max(0, (int) $m[1]);
        }
    }

    return ['uidnext' => $uidNext, 'messages' => $messages];
}

function email_imap_search_uid_range($stream, int &$sequence, int $minUid, int $maxUid = 0): array
{
    if ($minUid <= 0) {
        return [];
    }

    $criteria = $maxUid > 0 && $maxUid >= $minUid
        ? 'UID ' . $minUid . ':' . $maxUid
        : 'UID ' . $minUid . ':*';

    return email_imap_parse_search_uids(email_imap_command($stream, $sequence++, 'UID SEARCH ' . $criteria));
}

/**
 * Newest N UIDs without UID SEARCH ALL (scans only a recent UID window).
 *
 * @return array{uids:array<int,int>,mailbox_highest:int}
 */
function email_imap_fetch_newest_uids($stream, int &$sequence, int $limit, int $windowPadding = 120): array
{
    if ($limit <= 0) {
        return ['uids' => [], 'mailbox_highest' => 0];
    }

    $status = email_imap_mailbox_uid_status($stream, $sequence);
    $mailboxHighest = max(0, $status['uidnext'] - 1);
    if ($mailboxHighest <= 0) {
        return ['uids' => [], 'mailbox_highest' => 0];
    }

    $window = max($limit * 8, $windowPadding);
    $minUid = max(1, $mailboxHighest - $window + 1);
    $uids = email_imap_search_uid_range($stream, $sequence, $minUid, $mailboxHighest);
    if ($uids === []) {
        return ['uids' => [], 'mailbox_highest' => $mailboxHighest];
    }

    rsort($uids, SORT_NUMERIC);
    $uids = array_slice($uids, 0, $limit);
    sort($uids, SORT_NUMERIC);

    return ['uids' => $uids, 'mailbox_highest' => $mailboxHighest];
}

// Returns message UIDs newer than the stored mailbox UID baseline.
function email_imap_fetch_uids_since_uid($stream, int &$sequence, int $lastSeenUid, int $limit = 0): array
{
    if ($lastSeenUid <= 0) {
        return [];
    }

    $uids = email_imap_search_uid_range($stream, $sequence, $lastSeenUid + 1);
    sort($uids, SORT_NUMERIC);

    if (!$uids) {
        return [];
    }

    if ($limit <= 0) {
        return $uids;
    }

    return array_slice($uids, 0, max(1, $limit));
}

/**
 * Picks UIDs to import for one mailbox poll.
 * Manual UI import (limit + not cron): newest N messages in mailbox (for Email Logs).
 * Cron/future-only: only UIDs above last_seen_uid; first run may baseline-only.
 *
 * @return array{uids:array<int,int>,mailbox_highest:int,baseline_only:bool}
 */
function email_imap_select_uids_for_import(
    $stream,
    int &$sequence,
    int $lastSeenUid,
    int $limitPerAccount,
    bool $manualNewestImport
): array {
    if ($manualNewestImport && $limitPerAccount > 0) {
        $recent = email_imap_fetch_newest_uids($stream, $sequence, $limitPerAccount);

        return [
            'uids' => $recent['uids'],
            'mailbox_highest' => (int) $recent['mailbox_highest'],
            'baseline_only' => false,
        ];
    }

    $status = email_imap_mailbox_uid_status($stream, $sequence);
    $mailboxHighest = max(0, $status['uidnext'] - 1);

    if ($lastSeenUid <= 0) {
        return ['uids' => [], 'mailbox_highest' => $mailboxHighest, 'baseline_only' => true];
    }

    $uids = email_imap_fetch_uids_since_uid($stream, $sequence, $lastSeenUid, $limitPerAccount);

    return ['uids' => $uids, 'mailbox_highest' => $mailboxHighest, 'baseline_only' => false];
}

// Reads a fixed-size IMAP literal payload.
function email_imap_read_literal($stream, int $length): string
{
    $data = '';

    while (strlen($data) < $length) {
        $chunk = fread($stream, $length - strlen($data));
        if ($chunk === false || $chunk === '') {
            throw new RuntimeException('Unable to read IMAP message body.');
        }
        $data .= $chunk;
    }

    return $data;
}

// Fetches the raw RFC822 message for one UID.
function email_imap_fetch_raw_message($stream, int &$sequence, string $uid): string
{
    $tag = 'A' . str_pad((string) $sequence++, 4, '0', STR_PAD_LEFT);
    fwrite($stream, $tag . ' UID FETCH ' . $uid . ' (RFC822)' . "\r\n");

    $raw = '';

    while (true) {
        $line = email_imap_read_line($stream);

        if (preg_match('/\{(\d+)\}\s*$/', trim($line), $matches)) {
            $raw = email_imap_read_literal($stream, (int) $matches[1]);
            continue;
        }

        if (strpos($line, $tag . ' ') === 0) {
            if (stripos($line, $tag . ' OK') !== 0) {
                throw new RuntimeException(trim($line));
            }
            break;
        }
    }

    return $raw;
}

// Decodes MIME-encoded header values into UTF-8 text.
function email_imap_decode_header_value(string $value): string
{
    $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
    if ($decoded === false && function_exists('mb_decode_mimeheader')) {
        $decoded = @mb_decode_mimeheader($value);
    }

    return $decoded !== false ? trim(email_imap_to_utf8($decoded)) : trim(email_imap_to_utf8($value));
}

// Parses raw RFC822 headers into a normalized associative array.
function email_imap_parse_headers(string $headerBlock): array
{
    $headers = [];
    $current = null;

    foreach (preg_split("/\r\n|\n|\r/", $headerBlock) as $line) {
        if ($line === '') {
            continue;
        }

        if (($line[0] === ' ' || $line[0] === "\t") && $current !== null) {
            $headers[$current] .= ' ' . trim($line);
            continue;
        }

        [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
        $current = strtolower(trim($name));
        $headers[$current] = trim($value);
    }

    return $headers;
}

// Returns the main header value before semicolon parameters.
function email_imap_header_main_value(string $value): string
{
    return strtolower(trim(explode(';', $value, 2)[0]));
}

// Reads a quoted/unquoted MIME header parameter without changing case-sensitive values.
function email_imap_header_param(string $value, string $name): string
{
    if (!preg_match('/;\s*' . preg_quote($name, '/') . '\s*=\s*(?:"((?:\\\\.|[^"])*)"|([^;\s]+))/i', $value, $matches)) {
        return '';
    }

    $paramValue = isset($matches[1]) && $matches[1] !== ''
        ? stripcslashes($matches[1])
        : (string) ($matches[2] ?? '');

    return trim($paramValue, "\"' \t\r\n");
}

// Converts decoded mail text into UTF-8 where the message declares another charset.
function email_imap_to_utf8(string $value, string $charset = ''): string
{
    $value = str_replace("\0", '', $value);
    $charset = trim($charset, "\"' \t\r\n");

    if ($value === '') {
        return '';
    }

    if (function_exists('mb_check_encoding') && mb_check_encoding($value, 'UTF-8')) {
        return $value;
    }

    $sources = [];
    if ($charset !== '') {
        $sources[] = $charset;
    }
    $sources = array_values(array_unique(array_merge($sources, ['UTF-8', 'Windows-1252', 'ISO-8859-1'])));

    foreach ($sources as $source) {
        if (function_exists('mb_convert_encoding')) {
            // PHP 8+ throws ValueError for unknown encodings (e.g. windows-1250)
            // which @ does not suppress. Catch it so iconv fallback can handle it.
            $converted = false;
            try {
                $converted = @mb_convert_encoding($value, 'UTF-8', $source);
            } catch (\ValueError $unknownEncoding) {
                $converted = false;
            }
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }

        $converted = @iconv($source, 'UTF-8//IGNORE', $value);
        if (is_string($converted) && $converted !== '') {
            return $converted;
        }
    }

    return $value;
}

// Best-effort guard for emails whose transfer encoding header is missing or wrong.
function email_imap_maybe_decode_unlabelled_body(string $body): string
{
    $trimmed = trim($body);
    if ($trimmed === '') {
        return $body;
    }

    if (preg_match('/=[0-9A-F]{2}|=\r?\n/i', $trimmed)) {
        $decoded = quoted_printable_decode($trimmed);
        if (email_imap_text_looks_readable($decoded)) {
            return $decoded;
        }
    }

    $compact = preg_replace('/\s+/', '', $trimmed) ?? $trimmed;
    if (strlen($compact) >= 4
        && strlen($compact) % 4 === 0
        && (strlen($compact) >= 8 || str_contains($compact, '='))
        && preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $compact)
    ) {
        $decoded = base64_decode($compact, true);
        if ($decoded !== false && email_imap_text_looks_readable($decoded)) {
            return $decoded;
        }
    }

    return $body;
}

// Keeps accidental base64/binary decoding from turning normal text into junk.
function email_imap_text_looks_readable(string $value): bool
{
    $value = email_imap_to_utf8($value);
    $trimmed = trim($value);
    if ($trimmed === '') {
        return false;
    }

    $length = strlen($trimmed);
    $controls = preg_match_all('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $trimmed);
    if ($controls !== false && $length > 0 && ($controls / $length) > 0.05) {
        return false;
    }

    return (bool) preg_match('/[\p{L}\p{N}<]/u', $trimmed);
}

// Decodes transfer-encoded body text into readable plain text.
function email_imap_decode_part_body(string $body, string $encoding, string $charset = ''): string
{
    $encoding = strtolower(trim($encoding));
    $decoded = $body;

    if ($encoding === 'base64') {
        $compact = preg_replace('/\s+/', '', $body) ?? $body;
        $base64 = base64_decode($compact, true);
        $decoded = $base64 !== false ? $base64 : $body;
    } elseif ($encoding === 'quoted-printable') {
        $decoded = quoted_printable_decode($body);
    } elseif ($encoding === '') {
        $decoded = email_imap_maybe_decode_unlabelled_body($body);
    }

    return email_imap_to_utf8($decoded, $charset);
}

// Converts message dates to IST for consistent ticket/mail display.
function email_imap_datetime_ist(?string $value = null): string
{
    try {
        $date = trim((string) $value) !== ''
            ? new DateTimeImmutable((string) $value)
            : new DateTimeImmutable('now');
    } catch (Throwable $throwable) {
        $date = new DateTimeImmutable('now');
    }

    return $date->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('Y-m-d H:i:s');
}

// Cleans imported email bodies while preserving readable paragraph breaks.
function email_imap_clean_body(string $body): string
{
    $body = trim($body);
    if ($body === '') {
        return '';
    }

    $looksHtml = (bool) preg_match('/<[a-z][\s\S]*>/i', $body);
    if ($looksHtml) {
        $body = preg_replace('#<script[^>]*>.*?</script>#is', ' ', $body) ?? $body;
        $body = preg_replace('#<style[^>]*>.*?</style>#is', ' ', $body) ?? $body;
        $body = preg_replace('#<!--.*?-->#s', ' ', $body) ?? $body;
        $body = preg_replace('#<(br|/p|/div|/tr|/li|/h[1-6])[^>]*>#i', "\n", $body) ?? $body;
        $body = strip_tags($body);
        $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    $body = str_replace("\0", '', $body);
    $body = preg_replace("/\r\n|\r/", "\n", $body) ?? $body;
    $lines = [];
    $blank = false;

    foreach (explode("\n", $body) as $line) {
        $line = trim(preg_replace('/[ \t]+/', ' ', $line) ?? $line);
        if ($line === '') {
            if (!$blank && $lines) {
                $lines[] = '';
            }
            $blank = true;
            continue;
        }

        $blank = false;
        $lines[] = $line;
    }

    while (end($lines) === '') {
        array_pop($lines);
    }

    return trim(implode("\n", $lines));
}

// Skips files and binary inline parts while still allowing real text/html bodies.
function email_imap_is_attachment_part(array $headers): bool
{
    $contentType = email_imap_header_main_value((string) ($headers['content-type'] ?? ''));
    $contentDisposition = strtolower((string) ($headers['content-disposition'] ?? ''));

    if (strpos($contentDisposition, 'attachment') !== false) {
        return true;
    }

    if (preg_match('#^(image|audio|video|application)/#i', $contentType)) {
        return true;
    }

    return false;
}

// Extracts a best-effort plain-text body from a raw RFC822 email.
function email_imap_extract_body(string $bodyBlock, array $headers): string
{
    $contentTypeHeader = (string) ($headers['content-type'] ?? 'text/plain');
    $contentType = email_imap_header_main_value($contentTypeHeader);
    $encoding = (string) ($headers['content-transfer-encoding'] ?? '');
    $charset = email_imap_header_param($contentTypeHeader, 'charset');

    if (strpos($contentType, 'multipart/') === false) {
        return email_imap_clean_body(email_imap_decode_part_body($bodyBlock, $encoding, $charset));
    }

    $boundary = email_imap_header_param($contentTypeHeader, 'boundary');
    if ($boundary === '') {
        return email_imap_clean_body(email_imap_decode_part_body($bodyBlock, $encoding, $charset));
    }

    $parts = explode('--' . $boundary, $bodyBlock);
    array_shift($parts);
    $textBody = '';
    $htmlBody = '';

    foreach ($parts as $part) {
        $part = ltrim($part, "\r\n");
        if ($part === '' || str_starts_with($part, '--')) {
            continue;
        }

        [$partHeadersBlock, $partBody] = array_pad(preg_split("/\r\n\r\n|\n\n|\r\r/", $part, 2), 2, '');
        $partHeaders = email_imap_parse_headers($partHeadersBlock);
        $partTypeHeader = (string) ($partHeaders['content-type'] ?? 'text/plain');
        $partType = email_imap_header_main_value($partTypeHeader);

        // Recursively handle nested multipart sections (e.g., multipart/alternative)
        if (strpos($partType, 'multipart/') !== false) {
            $nested = email_imap_extract_body($partBody, $partHeaders);
            if ($nested !== '' && $textBody === '') {
                $textBody = $nested;
            }
            continue;
        }

        if (email_imap_is_attachment_part($partHeaders)) {
            continue;
        }

        $decodedPart = trim(email_imap_decode_part_body(
            $partBody,
            (string) ($partHeaders['content-transfer-encoding'] ?? ''),
            email_imap_header_param($partTypeHeader, 'charset') ?: $charset
        ));

        if ($partType === 'text/plain') {
            if ($decodedPart !== '' && $textBody === '') {
                $textBody = $decodedPart;
            }
        } elseif ($partType === 'text/html') {
            if ($decodedPart !== '' && $htmlBody === '') {
                $htmlBody = $decodedPart;
            }
        }
    }

    if ($textBody !== '') {
        return email_imap_clean_body($textBody);
    }

    if ($htmlBody !== '') {
        return email_imap_clean_body($htmlBody);
    }

    return email_imap_clean_body($bodyBlock);
}

// Parses one raw RFC822 email into the fields needed by the ticket importer.
function email_imap_parse_message(string $rawMessage): array
{
    [$headerBlock, $bodyBlock] = array_pad(preg_split("/\r\n\r\n|\n\n|\r\r/", $rawMessage, 2), 2, '');
    $headers = email_imap_parse_headers($headerBlock);

    $fromHeader = email_imap_decode_header_value((string) ($headers['from'] ?? ''));
    $fromEmail = $fromHeader;
    $fromName = '';

    if (preg_match('/^(.*)<([^>]+)>$/', $fromHeader, $matches)) {
        $fromName = trim(trim($matches[1]), '" ');
        $fromEmail = trim($matches[2]);
    }
// Exchange/Microsoft sometimes sends extremely long Message-IDs.
// Store a fixed-length hash to preserve uniqueness and avoid DB errors.
    $messageId = trim((string) ($headers['message-id'] ?? ''));

if ($messageId === '') {
    $messageId = '<generated-' . sha1($rawMessage) . '@local>';
}

$messageId = email_processor_normalize_message_id($messageId);

if (strlen($messageId) > 250) {
    $messageId = '<sha256-' . hash('sha256', $messageId) . '@exchange>';
}

    $inReplyTo = trim((string) ($headers['in-reply-to'] ?? ''));
    $references = trim((string) ($headers['references'] ?? ''));
    // thread_id kept for backward compatibility; prefers in-reply-to else references
    $threadId = $inReplyTo !== '' ? $inReplyTo : $references;

    return [
        'message_id' => $messageId,
        'from_name' => $fromName,
        'from_email' => trim($fromEmail, '<> '),
        'subject' => email_imap_decode_header_value((string) ($headers['subject'] ?? 'Incoming Email')),
        'received_at' => email_imap_datetime_ist((string) ($headers['date'] ?? '')),
        'in_reply_to' => $inReplyTo,
        'references' => $references,
        'thread_id' => $threadId,
        'body' => email_imap_extract_body($bodyBlock, $headers),
        'raw_message' => $rawMessage,
    ];
}

// Imports email messages from every active account and converts them into tickets.
// Safe: $limitPerAccount caps emails read per mailbox (0 = unlimited).
function email_imap_import_messages(PDO $pdo, int $limitPerAccount = 0, int $accountId = 0, bool $cronFilter = false): array
{
    if (PHP_SAPI !== 'cli') {
        @set_time_limit(180);
    }

    // Always use the freshest connection (auto-reconnect if closed)
    $pdo = get_pdo();
    $requestedAccountId = max(0, $accountId);
    
    $summary = [
        'accounts' => 0,
        'messages' => 0,
        'created' => 0,
        'replied' => 0,
        'unmapped' => 0,
        'unknown' => 0,
        'ignored' => 0,
        'duplicates' => 0,
        'baseline_initialized' => 0,
        'failed' => 0,
    ];

    $accounts = email_imap_active_accounts($pdo);
    
    // Filter by cron_enabled if requested
    if ($cronFilter) {
        $accounts = array_filter($accounts, function($acc) {
            return !empty($acc['cron_enabled']);
        });
    }
    
    foreach ($accounts as $account) {
        // If specific account requested, skip others
        $currentAccountId = (int) ($account['id'] ?? 0);
        if ($requestedAccountId > 0 && $currentAccountId !== $requestedAccountId) {
            continue;
        }
        
        $summary['accounts']++;
        $pollStartedAt = date('Y-m-d H:i:s');
        $stream = null;
        $sequence = 0;
        $currentUid = null;

        try {
            $connection = email_imap_open_connection($account);
            $stream = $connection['stream'];
            $sequence = $connection['sequence'];
            $lastSeenUid = (int) ($account['last_seen_uid'] ?? 0);
            $manualNewestImport = $limitPerAccount > 0 && !$cronFilter;

            $uidPlan = email_imap_select_uids_for_import(
                $stream,
                $sequence,
                $lastSeenUid,
                $limitPerAccount,
                $manualNewestImport
            );
            $uids = $uidPlan['uids'];
            $mailboxHighest = (int) ($uidPlan['mailbox_highest'] ?? 0);

            if (!empty($uidPlan['baseline_only'])) {
                $pdo = get_pdo();
                email_imap_initialize_cutoff($pdo, $currentAccountId, $pollStartedAt);
                email_imap_store_last_seen_uid($pdo, $currentAccountId, $mailboxHighest, $pollStartedAt);
                $summary['baseline_initialized']++;
                continue;
            }

            if ($manualNewestImport && $lastSeenUid <= 0 && $mailboxHighest > 0) {
                $pdo = get_pdo();
                email_imap_initialize_cutoff($pdo, $currentAccountId, $pollStartedAt);
                $summary['baseline_initialized']++;
            }

            $highestSeenUid = max($lastSeenUid, $mailboxHighest);

            foreach ($uids as $uid) {
                $currentUid = (int) $uid;
                try {
                    $rawMessage = email_imap_fetch_raw_message($stream, $sequence, (string) $uid);
                    $parsedMessage = email_imap_parse_message($rawMessage);

                    $pdo = get_pdo();
                    $summary['messages']++;
                    $result = email_processor_process_message($pdo, $account, $parsedMessage);
                    $processedOk = true;

                    if (!empty($result['duplicate'])) {
                        $summary['duplicates']++;
                    } elseif (!empty($result['unmapped'])) {
                        $summary['unmapped']++;
                    } elseif (!empty($result['unknown'])) {
                        $summary['unknown']++;
                    } elseif (!empty($result['ignored'])) {
                        $summary['ignored']++;
                    } elseif (!empty($result['is_reply'])) {
                        $summary['replied']++;
                    } elseif (!empty($result['success'])) {
                        $summary['created']++;
                    } else {
                        $summary['failed']++;
                        $processedOk = false;
                    }

                    // Advance the checkpoint only for processed/stored messages.
                    if (!$processedOk) {
                        email_imap_report_error(
                            'IMAP Account #' . $currentAccountId . ' Message UID ' . $uid . ' was not checkpointed: ' . (string) ($result['message'] ?? 'Processing failed.')
                        );
                        if (!$manualNewestImport) {
                            break;
                        }
                        continue;
                    }

                    $highestSeenUid = max($highestSeenUid, (int) $uid);
                    $pdo = get_pdo();
                    email_imap_store_last_seen_uid($pdo, $currentAccountId, $highestSeenUid, $pollStartedAt);
                } catch (Throwable $throwable) {
                    $summary['failed']++;
                    email_imap_report_error('IMAP Account #' . $currentAccountId . ' Message UID ' . $uid . ' Error: ' . $throwable->getMessage());
                    if (!$manualNewestImport) {
                        break;
                    }
                }
            }

            if ($manualNewestImport && $mailboxHighest > 0) {
                $highestSeenUid = max($highestSeenUid, $mailboxHighest);
                $pdo = get_pdo();
                email_imap_store_last_seen_uid($pdo, $currentAccountId, $highestSeenUid, $pollStartedAt);
            }
        } catch (Throwable $throwable) {
            $summary['failed']++;
            $msg = 'ERROR (Account #' . $currentAccountId . ', UID ' . ($currentUid ?? 'N/A') . '): ' . $throwable->getMessage() . ' in ' . $throwable->getFile() . ':' . $throwable->getLine();
            email_imap_report_error($msg);
        } finally {
            email_imap_close_connection($stream, $sequence);
            try {
                email_imap_touch_account(get_pdo(), $currentAccountId);
            } catch (Throwable $touchError) {
                email_imap_report_error(
                    'IMAP Account #' . $currentAccountId . ' heartbeat update failed: ' . $touchError->getMessage()
                );
            }
        }
    } // close foreach $accounts

    return $summary;
}
