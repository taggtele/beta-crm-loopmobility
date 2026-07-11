<?php
require_once __DIR__ . '/../../includes/mail.php';
require_once __DIR__ . '/../notifications/notification_service.php';
require_once __DIR__ . '/../tickets/ticket_service.php';
require_once __DIR__ . '/../../services/email_inbox_service.php';
require_once __DIR__ . '/../../services/email_parser_service.php';
require_once __DIR__ . '/../../services/external_ticket_history_service.php';
require_once __DIR__ . '/../../services/party_service.php';
require_once __DIR__ . '/../../services/unknown_email_service.php';
require_once __DIR__ . '/../../services/ticket_log_service.php';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../services/notification_ui_service.php';
require_once __DIR__ . '/../../services/email_minio_storage_service.php';

function email_processor_notification_context(
    PDO $pdo,
    array $message,
    string $messageId,
    ?int $ticketId = null,
    string $mailStatus = '',
    ?int $inboxLogId = null
): array {
    $inboxLogId = max(0, (int) ($inboxLogId ?? 0));
    if ($inboxLogId <= 0 && $messageId !== '') {
        $stmt = $pdo->prepare('SELECT id FROM email_inbox_log WHERE message_id = :message_id LIMIT 1');
        $stmt->execute([':message_id' => $messageId]);
        $inboxLogId = (int) ($stmt->fetchColumn() ?: 0);
    }

    $fromEmail = trim((string) ($message['from_email'] ?? ''));
    $subject = trim((string) ($message['subject'] ?? ''));
    $plainBody = email_processor_clean_body((string) ($message['body'] ?? ''));
    $raw = (string) ($message['raw_message'] ?? '');
    $hasAttachment = $raw !== '' && preg_match('/Content-Disposition:\s*attachment/im', $raw) === 1;

    $snippet = preg_replace('/\s+/', ' ', $plainBody) ?? $plainBody;
    if (mb_strlen($snippet) > 160) {
        $snippet = rtrim(mb_substr($snippet, 0, 157)) . '…';
    }

    $context = [
        'inbox_log_id' => $inboxLogId,
        'ticket_id' => $ticketId !== null && $ticketId > 0 ? $ticketId : null,
        'sender_name' => email_processor_customer_name($message),
        'sender_email' => $fromEmail,
        'subject' => $subject !== '' ? $subject : 'Incoming email',
        'snippet' => $snippet,
        'has_attachment' => $hasAttachment,
        'mail_status' => $mailStatus,
    ];

    $context['link_url'] = notification_ui_service_email_logs_link($context);

    return $context;
}

function email_processor_notify_admins(
    PDO $pdo,
    string $title,
    string $message,
    string $type,
    array $messageRow,
    string $messageId,
    ?int $ticketId = null,
    string $mailStatus = '',
    ?int $inboxLogId = null
): void {
    $context = email_processor_notification_context($pdo, $messageRow, $messageId, $ticketId, $mailStatus, $inboxLogId);
    notifications_create_for_users(
        $pdo,
        notifications_active_admin_user_ids($pdo),
        $title,
        $message,
        $type,
        $context
    );
}

// Builds a readable customer label from the incoming sender information.
function email_processor_customer_name(array $message): string
{
    $fromName = trim((string) ($message['from_name'] ?? ''));
    $fromEmail = trim((string) ($message['from_email'] ?? ''));

    return $fromName !== '' ? $fromName : ($fromEmail !== '' ? $fromEmail : 'Email Customer');
}

// Resolves the mailbox owner into the public users.user_id required by tickets.created_by.
function email_processor_created_by_user_id(PDO $pdo, array $account): string
{
    $ownerId = (int) ($account['user_id'] ?? 0);

    if ($ownerId > 0) {
        $stmt = $pdo->prepare(
            'SELECT user_id
             FROM users
             WHERE id = :id
             AND deleted = 0
             LIMIT 1'
        );
        $stmt->execute([':id' => $ownerId]);
        $userId = $stmt->fetchColumn();

        if (is_string($userId) && trim($userId) !== '') {
            return trim($userId);
        }
    }

    $fallbackStmt = $pdo->prepare(
        'SELECT user_id
         FROM users
         WHERE deleted = 0
         AND status = :status
         ORDER BY id ASC
         LIMIT 1'
    );
    $fallbackStmt->execute([':status' => 'Active']);
    $fallbackUserId = $fallbackStmt->fetchColumn();

    if (is_string($fallbackUserId) && trim($fallbackUserId) !== '') {
        return trim($fallbackUserId);
    }

    throw new RuntimeException('Unable to resolve a valid created_by user for the email account.');
}

// Skips common email greetings to find actual issue content.
function email_processor_skip_greeting(string $line): bool
{
    $lineTrimmed = trim($line);
    if ($lineTrimmed === '') return false;
    
    $lineClean = preg_replace('/[\s,;:!.*]/', '', strtolower($lineTrimmed));
    
    if (strlen($lineClean) < 4) return true;
    
    $greetings = ['hi', 'hello', 'hey', 'dear', 'respected', 'namaste', 'thanks', 'thank', 'regards', 'kind', 'best', 'warm', 'sincerely'];
    foreach ($greetings as $greeting) {
        if ($lineClean === $greeting) return true;
    }
    
    if (preg_match('/^(hi|hello|hey|dear|respected|namaste|thanks|good)/i', $lineTrimmed) && strlen($lineTrimmed) < 40) {
        return true;
    }
    
    return false;
}

// Extracts the issue text that will be used for ticket creation.
function email_processor_extract_issue(array $message): string
{
    $subject = trim((string) ($message['subject'] ?? ''));
    $subjectLower = strtolower($subject);
    
    $genericSubjects = ['incoming email', 'no subject', 're:', 'fw:', 'fwd:', ''];
    $isGeneric = in_array($subjectLower, array_filter($genericSubjects, fn($s) => $s !== ''));

    $body = trim((string) ($message['body'] ?? ''));
    $bodyLines = array_filter(preg_split("/\r\n|\n|\r/", $body));
    
    $foundRealContent = false;
    $issueLines = [];
    
    foreach ($bodyLines as $line) {
        $line = trim((string) $line);
        if ($line === '') continue;
        
        if (!$foundRealContent) {
            if (email_processor_skip_greeting($line)) {
                continue;
            }
            $foundRealContent = true;
        }
        
        if ($foundRealContent && strlen(implode(' ', $issueLines)) < 200) {
            $issueLines[] = $line;
        }
    }
    
    $bodyIssue = trim(implode(' ', $issueLines));
    
    if (!$isGeneric && $subject !== '') {
        return mb_substr($subject, 0, 255);
    }
    
    if ($bodyIssue !== '') {
        return mb_substr($bodyIssue, 0, 255);
    }
    
    return '';
}

function email_processor_find_ticket_by_internal_reference(PDO $pdo, string $reference): ?array
{
    $reference = email_parser_normalize_ticket_reference($reference);
    if ($reference === '') {
        return null;
    }

    if (ctype_digit($reference)) {
        $stmt = $pdo->prepare('SELECT ticket_id, status, external_ticket_id, created_at FROM tickets WHERE ticket_id = :ticket_id LIMIT 1');
        $stmt->execute([':ticket_id' => (int) $reference]);
        $ticket = $stmt->fetch();

        return $ticket ?: null;
    }

    if (!email_parser_is_internal_ticket_reference($reference)) {
        return null;
    }

    if (!preg_match('/^LM-(\d{4})(\d{2})(\d{2})-(\d{1,6})$/', $reference, $matches)) {
        return null;
    }

    $date = $matches[1] . '-' . $matches[2] . '-' . $matches[3];
    $offset = max(0, ((int) $matches[4]) - 1);
    $stmt = $pdo->prepare(
        'SELECT ticket_id, status, external_ticket_id, created_at
         FROM tickets
         WHERE DATE(created_at) = :ticket_date
         ORDER BY ticket_id ASC
         LIMIT 1 OFFSET ' . $offset
    );
    $stmt->execute([':ticket_date' => $date]);
    $ticket = $stmt->fetch();

    return $ticket ?: null;
}

function email_processor_first_internal_ticket(PDO $pdo, array $references): ?array
{
    foreach ($references as $reference) {
        $ticket = email_processor_find_ticket_by_internal_reference($pdo, (string) $reference);
        if ($ticket) {
            $ticket['matched_internal_reference'] = email_parser_normalize_ticket_reference((string) $reference);
            return $ticket;
        }
    }

    return null;
}

function email_processor_clean_body(string $body): string
{
    $body = trim($body);
    if ($body === '') {
        return '';
    }

    if (preg_match('/<[a-z][\s\S]*>/i', $body)) {
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

function email_processor_normalize_message_id(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $value = trim($value, " \t\n\r\0\x0B<>");
    return $value !== '' ? '<' . $value . '>' : '';
}

function email_processor_extract_message_ids(string $value): array
{
    $ids = [];
    if (preg_match_all('/<([^<>]+)>/', $value, $matches)) {
        foreach ($matches[1] as $match) {
            $normalized = email_processor_normalize_message_id((string) $match);
            if ($normalized !== '') {
                $ids[] = $normalized;
            }
        }
    } else {
        $normalized = email_processor_normalize_message_id($value);
        if ($normalized !== '') {
            $ids[] = $normalized;
        }
    }

    return array_values(array_unique($ids));
}

function email_processor_table_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $stmt = $pdo->prepare('SHOW COLUMNS FROM ' . $table . ' LIKE :column_name');
        $stmt->execute([':column_name' => $column]);
        $cache[$key] = (bool) $stmt->fetch();
    } catch (Throwable $throwable) {
        $cache[$key] = false;
    }

    return $cache[$key];
}

function email_processor_find_ticket_by_message_id_candidates(PDO $pdo, array $messageIds, string $matchedBy): ?array
{
    $messageIds = array_values(array_unique(array_filter(array_map('email_processor_normalize_message_id', $messageIds))));
    if (!$messageIds) {
        return null;
    }

    $find = static function (PDO $pdo, string $sql, array $ids): ?array {
        $params = [];
        $placeholders = [];
        foreach ($ids as $index => $messageId) {
            $placeholder = ':message_id_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $messageId;
        }

        $stmt = $pdo->prepare(str_replace('__MESSAGE_IDS__', implode(', ', $placeholders), $sql));
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row ?: null;
    };

    $queries = [
        'SELECT t.ticket_id, t.status, t.external_ticket_id, t.created_at
         FROM tickets t
         WHERE t.mail_message_id IN (__MESSAGE_IDS__)
         ORDER BY t.ticket_id DESC
         LIMIT 1',
        'SELECT t.ticket_id, t.status, t.external_ticket_id, t.created_at
         FROM tickets t
         WHERE t.mail_thread_id IN (__MESSAGE_IDS__)
         ORDER BY t.ticket_id DESC
         LIMIT 1',
        'SELECT t.ticket_id, t.status, t.external_ticket_id, t.created_at
         FROM email_inbox_log ei
         JOIN tickets t ON t.ticket_id = ei.ticket_id
         WHERE ei.message_id IN (__MESSAGE_IDS__)
         AND ei.ticket_id IS NOT NULL
         ORDER BY t.ticket_id DESC
         LIMIT 1',
    ];

    if (email_processor_table_has_column($pdo, 'email_outbox_log', 'message_id')) {
        $queries[] = 'SELECT t.ticket_id, t.status, t.external_ticket_id, t.created_at
             FROM email_outbox_log eo
             JOIN tickets t ON t.ticket_id = eo.ticket_id
             WHERE eo.message_id IN (__MESSAGE_IDS__)
             AND eo.ticket_id IS NOT NULL
             ORDER BY t.ticket_id DESC
             LIMIT 1';
    }

    foreach ($queries as $query) {
        $ticket = $find($pdo, $query, $messageIds);
        if ($ticket) {
            $ticket['matched_by'] = $matchedBy;
            return $ticket;
        }
    }

    return null;
}

function email_processor_find_ticket_by_message_headers(PDO $pdo, string $messageId, string $inReplyTo, string $references): ?array
{
    $replyIds = email_processor_extract_message_ids($inReplyTo);
    $ticket = email_processor_find_ticket_by_message_id_candidates($pdo, $replyIds, 'In-Reply-To header');
    if ($ticket) {
        return $ticket;
    }

    $referenceIds = array_reverse(email_processor_extract_message_ids($references));
    $ticket = email_processor_find_ticket_by_message_id_candidates($pdo, $referenceIds, 'References header');
    if ($ticket) {
        return $ticket;
    }

    return email_processor_find_ticket_by_message_id_candidates($pdo, email_processor_extract_message_ids($messageId), 'Message-ID header');
}

function email_processor_update_inbox_result(PDO $pdo, string $messageId, ?int $ticketId, string $result, ?string $reason, ?string $externalTicketId): void
{
    $stmt = $pdo->prepare(
        'UPDATE email_inbox_log
         SET ticket_id = :ticket_id,
             processed = 1,
             processed_at = NOW(),
             processing_result = :processing_result,
             ignored_reason = :ignored_reason,
             external_ticket_id = :external_ticket_id
         WHERE message_id = :message_id'
    );
    $stmt->execute([
        ':ticket_id' => $ticketId,
        ':processing_result' => $result,
        ':ignored_reason' => $reason,
        ':external_ticket_id' => $externalTicketId,
        ':message_id' => $messageId,
    ]);
}

function email_processor_attach_message_to_ticket(PDO $pdo, int $ticketId, array $message, string $messageId, string $subject, string $plainBody, string $fromEmail, string $receivedAt, ?string $externalTicketId, string $mappedBy, ?int $inboxLogId = null): void
{
    $inReplyTo = trim((string) ($message['in_reply_to'] ?? ''));
    $references = trim((string) ($message['references'] ?? ''));
    $meta = [
        'sender_email' => $fromEmail,
        'subject' => $subject,
        'message_id' => $messageId,
        'in_reply_to' => $inReplyTo,
        'references_header' => $references,
    ];

    external_ticket_history_apply_to_ticket($pdo, $ticketId, $externalTicketId, $fromEmail, $messageId);

    ticket_log_service_add(
        $pdo,
        $ticketId,
        'ticket_mapped',
        'Incoming email mapped by ' . $mappedBy . '. Internal ticket ID was used only for lookup and was not modified.'
    );
    ticket_log_service_add(
        $pdo,
        $ticketId,
        'incoming_reply',
        "Reply from: {$fromEmail}\nSubject: {$subject}\nReceived At: {$receivedAt}\nMessage-ID: {$messageId}\nIn-Reply-To: " . ($inReplyTo ?: 'N/A') . "\nReferences: " . ($references ?: 'N/A') . "\nExternal Ticket ID: " . ($externalTicketId ?: 'N/A') . "\n\n{$plainBody}",
        $receivedAt,
        $meta
    );

    email_processor_update_inbox_result($pdo, $messageId, $ticketId, 'replied', null, $externalTicketId);

    $ticketDetail = ticket_service_get_ticket_with_user_context($pdo, $ticketId);
    $ticketSerial = $ticketDetail ? format_ticket_serial($pdo, $ticketDetail) : '#' . $ticketId;
    email_processor_notify_admins(
        $pdo,
        'Ticket reply received',
        'Reply from ' . email_processor_customer_name($message) . ' mapped to ticket ' . $ticketSerial . '.',
        'email_reply',
        $message,
        $messageId,
        $ticketId,
        'received',
        $inboxLogId
    );
}

function email_processor_map_unmapped_email(PDO $pdo, int $inboxLogId, int $ticketId, array $currentUser): void
{
    if ($inboxLogId <= 0 || $ticketId <= 0) {
        throw new RuntimeException('Valid email log and ticket are required.');
    }

    if (function_exists('rbac_email_logs_full_visibility') && rbac_email_logs_full_visibility($currentUser)) {
        [$scopeSql, $scopeParams] = ['1=1', []];
    } else {
        [$scopeSql, $scopeParams] = ticket_scope($currentUser, 't', true);
    }
    $ticketStmt = $pdo->prepare(
        'SELECT t.ticket_id, t.status, t.external_ticket_id, t.created_at
         FROM tickets t
         WHERE t.ticket_id = :ticket_id
         AND ' . $scopeSql . '
         LIMIT 1'
    );
    $ticketStmt->execute(array_merge([':ticket_id' => $ticketId], $scopeParams));
    $ticket = $ticketStmt->fetch();
    if (!$ticket) {
        throw new RuntimeException('Ticket not found or access denied.');
    }

    $emailStmt = $pdo->prepare(
        'SELECT id, message_id, in_reply_to, references_header, from_email, subject, body, raw_message, received_at, processing_result, external_ticket_id
         FROM email_inbox_log
         WHERE id = :id
         AND processing_result = :processing_result
         LIMIT 1'
    );
    $emailStmt->execute([
        ':id' => $inboxLogId,
        ':processing_result' => 'unmapped',
    ]);
    $email = $emailStmt->fetch();
    if (!$email) {
        throw new RuntimeException('Unmapped email not found.');
    }

    $messageId = trim((string) ($email['message_id'] ?? ''));
    $subject = trim((string) ($email['subject'] ?? 'Incoming Email'));
    $plainBody = email_processor_clean_body(trim((string) ($email['body'] ?? '')) ?: trim((string) ($email['raw_message'] ?? '')));
    $fromEmail = trim((string) ($email['from_email'] ?? ''));
    $receivedAt = trim((string) ($email['received_at'] ?? '')) ?: date('Y-m-d H:i:s');
    $externalTicketId = external_ticket_history_normalize_id((string) ($email['external_ticket_id'] ?? ''));
    $meta = [
        'sender_email' => $fromEmail,
        'subject' => $subject,
        'message_id' => $messageId,
        'in_reply_to' => trim((string) ($email['in_reply_to'] ?? '')),
        'references_header' => trim((string) ($email['references_header'] ?? '')),
    ];

    $pdo->beginTransaction();
    try {
        external_ticket_history_apply_to_ticket($pdo, $ticketId, $externalTicketId, $fromEmail, $messageId);

        ticket_log_service_add(
            $pdo,
            $ticketId,
            'manual_email_mapped',
            'Unmapped email #' . $inboxLogId . ' manually mapped by ' . ($currentUser['user_id'] ?? 'unknown') . '.'
        );
        ticket_log_service_add(
            $pdo,
            $ticketId,
            'incoming_reply',
            "Reply from: {$fromEmail}\nSubject: {$subject}\nReceived At: {$receivedAt}\nMessage-ID: {$messageId}\nIn-Reply-To: " . ($meta['in_reply_to'] ?: 'N/A') . "\nReferences: " . ($meta['references_header'] ?: 'N/A') . "\nExternal Ticket ID: " . ($externalTicketId ?: 'N/A') . "\n\n{$plainBody}",
            $receivedAt,
            $meta
        );

        $updateStmt = $pdo->prepare(
            'UPDATE email_inbox_log
             SET ticket_id = :ticket_id,
                 processed = 1,
                 processed_at = NOW(),
                 processing_result = :processing_result,
                 ignored_reason = NULL
             WHERE id = :id'
        );
        $updateStmt->execute([
            ':ticket_id' => $ticketId,
            ':processing_result' => 'replied',
            ':id' => $inboxLogId,
        ]);

        $pdo->commit();
    } catch (Throwable $throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $throwable;
    }
}

function email_processor_mark_failed_raw(PDO $pdo, array $message, string $messageId, string $reason): void
{
    try {
        email_inbox_service_ensure_table($pdo);
        $rawForStorage = trim((string) ($message['raw_message'] ?? ''));
        if ($rawForStorage !== '' && email_minio_enabled() && email_minio_raw_message_needs_external_storage($rawForStorage)) {
            // This MinIO + compression pipeline is currently for local testing. Production enable/disable controlled via config flag USE_MINIO.
            $rawForStorage = email_minio_strip_binary_parts_from_raw_message($rawForStorage);
        }
        $stmt = $pdo->prepare(
            'INSERT INTO email_inbox_log (
                message_id,
                in_reply_to,
                references_header,
                from_email,
                subject,
                body,
                raw_message,
                received_at,
                processed,
                processed_at,
                processing_result,
                ignored_reason,
                ticket_id,
                external_ticket_id,
                created_at
            ) VALUES (
                :message_id,
                :in_reply_to,
                :references_header,
                :from_email,
                :subject,
                :body,
                :raw_message,
                :received_at,
                1,
                NOW(),
                :processing_result,
                :ignored_reason,
                NULL,
                NULL,
                NOW()
            )
            ON DUPLICATE KEY UPDATE
                processed = 1,
                processed_at = NOW(),
                processing_result = VALUES(processing_result),
                ignored_reason = VALUES(ignored_reason)'
        );
        $stmt->execute([
            ':message_id' => $messageId,
            ':in_reply_to' => trim((string) ($message['in_reply_to'] ?? '')) ?: null,
            ':references_header' => trim((string) ($message['references'] ?? '')) ?: null,
            ':from_email' => trim((string) ($message['from_email'] ?? '')) ?: null,
            ':subject' => trim((string) ($message['subject'] ?? 'Incoming Email')) ?: null,
            ':body' => email_processor_clean_body((string) ($message['body'] ?? '')) ?: null,
            ':raw_message' => $rawForStorage !== '' ? $rawForStorage : null,
            ':received_at' => trim((string) ($message['received_at'] ?? '')) ?: date('Y-m-d H:i:s'),
            ':processing_result' => 'unmapped',
            ':ignored_reason' => mb_substr($reason, 0, 2000),
        ]);
    } catch (Throwable $ignored) {
        // Preserve the original processing error.
    }
}

// Inserts/maps one inbound email while preserving unmapped vendor replies.
function email_processor_process_message(PDO $pdo, array $account, array $message): array
{
    email_inbox_service_ensure_table($pdo);
    party_service_ensure_schema($pdo);
    unknown_email_service_ensure_table($pdo);

    $messageId = trim((string) ($message['message_id'] ?? ''));
    if ($messageId === '') {
        return ['success' => false, 'message' => 'Message ID missing.'];
    }

    $rawMessageForMinioRetry = trim((string) ($message['raw_message'] ?? ''));
    $duplicateStmt = $pdo->prepare(
        'SELECT id, ticket_id, processing_result, ignored_reason FROM email_inbox_log WHERE message_id = :message_id LIMIT 1'
    );
    $duplicateStmt->execute([':message_id' => $messageId]);
    $existing = $duplicateStmt->fetch();

    if ($existing) {
        $existingId = (int) ($existing['id'] ?? 0);
        $existingReason = (string) ($existing['ignored_reason'] ?? '');
        if ($existingId > 0
            && email_minio_enabled()
            && $rawMessageForMinioRetry !== ''
            && stripos($existingReason, 'MinIO upload failed') !== false
            && email_minio_raw_message_needs_external_storage($rawMessageForMinioRetry)
        ) {
            email_minio_store_raw_message($pdo, 'incoming', $existingId, $rawMessageForMinioRetry);
            $minioSummary = email_minio_process_incoming_raw_message($pdo, $existingId, $rawMessageForMinioRetry);
            if (!empty($minioSummary['failed'])) {
                return ['success' => false, 'message' => 'MinIO upload failed for incoming email files.'];
            }

            $clearStmt = $pdo->prepare(
                'UPDATE email_inbox_log
                 SET ignored_reason = NULL
                 WHERE id = :id AND ignored_reason LIKE :reason'
            );
            $clearStmt->execute([
                ':id' => $existingId,
                ':reason' => 'Processing failed: MinIO upload failed%',
            ]);

            return [
                'success' => true,
                'duplicate' => true,
                'assets_refreshed' => true,
                'ticket_id' => (int) ($existing['ticket_id'] ?? 0),
                'processing_result' => (string) ($existing['processing_result'] ?? ''),
            ];
        }

        return [
            'success' => true,
            'duplicate' => true,
            'ticket_id' => (int) ($existing['ticket_id'] ?? 0),
            'processing_result' => (string) ($existing['processing_result'] ?? ''),
        ];
    }

    $receivedAt = trim((string) ($message['received_at'] ?? '')) ?: date('Y-m-d H:i:s');
    $subject = trim((string) ($message['subject'] ?? 'Incoming Email'));
    $plainBody = email_processor_clean_body((string) ($message['body'] ?? ''));
    $fromEmail = trim((string) ($message['from_email'] ?? ''));
    $inReplyTo = trim((string) ($message['in_reply_to'] ?? ''));
    $references = trim((string) ($message['references'] ?? ''));
    $threadId = $inReplyTo !== '' ? $inReplyTo : (trim((string) ($message['thread_id'] ?? '')) ?: $references);
    $rawMessage = $rawMessageForMinioRetry;
    $storedRawMessage = $rawMessage;
    $minioEnabled = email_minio_enabled();
    $minioShouldExternalize = $minioEnabled
        && $storedRawMessage !== ''
        && email_minio_raw_message_needs_external_storage($storedRawMessage);
    if ($minioShouldExternalize) {
        // This MinIO + compression pipeline is currently for local testing. Production enable/disable controlled via config flag USE_MINIO.
        $storedRawMessage = email_minio_strip_binary_parts_from_raw_message($storedRawMessage);
        email_minio_ensure_mapping_table($pdo);
    }
    $issue = email_processor_extract_issue($message);
    if ($issue === '') {
        $issue = $subject !== '' && strcasecmp($subject, 'Incoming Email') !== 0 ? mb_substr($subject, 0, 255) : 'Incoming Email';
    }

    $matchedTicket = email_processor_find_ticket_by_message_headers($pdo, $messageId, $inReplyTo, $references);
    $internalReferences = email_parser_extract_internal_ticket_references($subject, $plainBody);
    if (!$matchedTicket) {
        $matchedTicket = email_processor_first_internal_ticket($pdo, $internalReferences);
        if ($matchedTicket) {
            $matchedTicket['matched_by'] = 'internal ticket ID lookup';
        }
    }
    $externalTicketId = email_parser_detect_external_ticket_id($subject, $plainBody, $fromEmail);
    $externalTicketId = $externalTicketId !== null && $externalTicketId !== '' ? $externalTicketId : null;
    $looksLikeReply = $inReplyTo !== ''
        || $references !== ''
        || (bool) preg_match('/^\s*(re|fw|fwd)\s*:/i', $subject);

    $pdo->beginTransaction();

    try {
        $inboxStmt = $pdo->prepare(
            'INSERT INTO email_inbox_log (
                message_id,
                in_reply_to,
                references_header,
                from_email,
                subject,
                body,
                raw_message,
                received_at,
                processed,
                processed_at,
                processing_result,
                ignored_reason,
                ticket_id,
                external_ticket_id,
                created_at
            ) VALUES (
                :message_id,
                :in_reply_to,
                :references_header,
                :from_email,
                :subject,
                :body,
                :raw_message,
                :received_at,
                0,
                NULL,
                :processing_result,
                NULL,
                NULL,
                :external_ticket_id,
                NOW()
            )'
        );
        $inboxStmt->execute([
            ':message_id' => $messageId,
            ':in_reply_to' => $inReplyTo !== '' ? $inReplyTo : null,
            ':references_header' => $references !== '' ? $references : null,
            ':from_email' => $fromEmail,
            ':subject' => $subject,
            ':body' => $plainBody !== '' ? $plainBody : null,
            ':raw_message' => $storedRawMessage !== '' ? $storedRawMessage : null,
            ':received_at' => $receivedAt,
            ':processing_result' => 'pending',
            ':external_ticket_id' => $externalTicketId,
        ]);
        $inboxLogId = (int) $pdo->lastInsertId();

        if ($minioShouldExternalize && $rawMessage !== '' && $inboxLogId > 0) {
            email_minio_store_raw_message($pdo, 'incoming', $inboxLogId, $rawMessage);
            $minioSummary = email_minio_process_incoming_raw_message($pdo, $inboxLogId, $rawMessage);
            if (!empty($minioSummary['failed'])) {
                throw new RuntimeException('MinIO upload failed for incoming email files.');
            }
        }

        if ($matchedTicket) {
            $ticketId = (int) $matchedTicket['ticket_id'];
            email_processor_attach_message_to_ticket(
                $pdo,
                $ticketId,
                $message,
                $messageId,
                $subject,
                $plainBody,
                $fromEmail,
                $receivedAt,
                $externalTicketId,
                (string) ($matchedTicket['matched_by'] ?? 'internal ticket ID lookup'),
                $inboxLogId > 0 ? $inboxLogId : null
            );

            if ($pdo->inTransaction()) {
                $pdo->commit();
            }

            return [
                'success' => true,
                'ticket_id' => $ticketId,
                'external_ticket_id' => $externalTicketId,
                'is_reply' => true,
            ];
        }

        if ($internalReferences) {
            $reason = 'Unmapped: internal ticket ID was present but did not match an existing ticket.';
            email_processor_update_inbox_result($pdo, $messageId, null, 'unmapped', $reason, $externalTicketId);

            email_processor_notify_admins(
                $pdo,
                'Email unmapped - ticket not found',
                'Incoming email from ' . email_processor_customer_name($message) . ' was stored as unmapped because the internal ticket ID did not match an existing ticket.',
                'email_unmapped',
                $message,
                $messageId,
                null,
                'unmapped',
                $inboxLogId > 0 ? $inboxLogId : null
            );

            if ($pdo->inTransaction()) {
                $pdo->commit();
            }

            return [
                'success' => true,
                'unmapped' => true,
                'external_ticket_id' => $externalTicketId,
                'message' => $reason,
            ];
        }

        if ($looksLikeReply) {
            $reason = 'Unmapped reply: In-Reply-To/References or reply subject was present, but no ticket match was found.';
            email_processor_update_inbox_result($pdo, $messageId, null, 'unmapped', $reason, $externalTicketId);

            email_processor_notify_admins(
                $pdo,
                'Email reply unmapped',
                'Reply from ' . email_processor_customer_name($message) . ' was stored as unmapped because no ticket could be matched.',
                'email_unmapped',
                $message,
                $messageId,
                null,
                'unmapped',
                $inboxLogId > 0 ? $inboxLogId : null
            );

            if ($pdo->inTransaction()) {
                $pdo->commit();
            }

            return [
                'success' => true,
                'unmapped' => true,
                'external_ticket_id' => $externalTicketId,
                'message' => $reason,
            ];
        }

        $initiatorParty = party_service_find_by_email($pdo, $fromEmail);
        if (!$initiatorParty) {
            $reason = 'Unknown sender: email is not registered in party_emails, so no ticket was created.';
            unknown_email_service_store($pdo, $message + [
                'message_id' => $messageId,
                'from_email' => $fromEmail,
                'subject' => $subject,
                'body' => $plainBody,
                'raw_message' => $storedRawMessage,
                'received_at' => $receivedAt,
            ]);
            email_processor_update_inbox_result($pdo, $messageId, null, 'unknown', $reason, $externalTicketId);

            email_processor_notify_admins(
                $pdo,
                'Unknown email received',
                'Incoming email from ' . ($fromEmail ?: email_processor_customer_name($message)) . ' was stored for review because the sender is not registered.',
                'email_unknown',
                $message,
                $messageId,
                null,
                'unknown',
                $inboxLogId > 0 ? $inboxLogId : null
            );

            if ($pdo->inTransaction()) {
                $pdo->commit();
            }

            return [
                'success' => true,
                'unknown' => true,
                'external_ticket_id' => $externalTicketId,
                'message' => $reason,
            ];
        }

        // Apply guardrails: verify email meets creation criteria before making ticket
        $shouldCreate = email_parser_should_create_ticket($subject, $plainBody, $fromEmail, $externalTicketId, $issue);
        if (!$shouldCreate) {
            $reason = 'Email did not meet creation criteria (issue/body too short or subject blacklisted).';
            email_processor_update_inbox_result($pdo, $messageId, null, 'unmapped', $reason, $externalTicketId);
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
            return [
                'success' => true,
                'unmapped' => true,
                'external_ticket_id' => $externalTicketId,
                'message' => $reason,
            ];
        }
//added
        $ticketStmt = $pdo->prepare(
            'INSERT INTO tickets (
                issue,
                description,
                status,
                priority,
                customer,
                customer_email,
                country,
                source,
                initiator_party_id,
                assigned_vendor_id,
                internal_ticket_id,
                assign_to,
                created_by,
                mail_message_id,
                mail_thread_id,
                external_ticket_id,
                reference,
                send_auto_acknowledgement,
                created_at,
                closed_at
            ) VALUES (
                :issue,
                :description,
                :status,
                :priority,
                :customer,
                :customer_email,
                :country,
                :source,
                :initiator_party_id,
                NULL,
                NULL,
                :assign_to,
                :created_by,
                :mail_message_id,
                :mail_thread_id,
                NULL,
                :reference,
                1,
                NOW(),
                NULL
            )'
        );
        $ticketStmt->execute([
            ':issue' => $issue,
            ':description' => $plainBody !== '' ? $plainBody : 'Imported from email without a plain-text body.',
            ':status' => 'Open',
            ':priority' => 'Medium',
            ':customer' => email_processor_customer_name($message),
            ':customer_email' => $fromEmail !== '' ? $fromEmail : null,
            ':country' => '',
            ':source' => 'email',
            ':initiator_party_id' => (int) $initiatorParty['id'],
            ':assign_to' => null,
            ':created_by' => email_processor_created_by_user_id($pdo, $account),
            ':mail_message_id' => $messageId,
            ':mail_thread_id' => $threadId !== '' ? $threadId : null,
            ':reference' => $subject,
        ]);

        $ticketId = (int) $pdo->lastInsertId();
        $internalTicketId = format_ticket_serial($pdo, ['ticket_id' => $ticketId, 'created_at' => date('Y-m-d H:i:s')]);
        party_service_set_ticket_internal_id($pdo, $ticketId, $internalTicketId);

        ticket_log_service_add(
            $pdo,
            $ticketId,
            'email_received',
            "Subject: {$subject}\nIssue: {$issue}\nFrom: {$fromEmail}\nReceived At: {$receivedAt}\nMessage-ID: {$messageId}\nIn-Reply-To: " . ($inReplyTo ?: 'N/A') . "\nReferences: " . ($references ?: 'N/A') . "\nInitiator Party: " . ($initiatorParty['name'] ?? ('#' . (int) $initiatorParty['id'])) . "\nExternal Ticket ID: N/A\n\n{$plainBody}",
            $receivedAt,
            [
                'sender_email' => $fromEmail,
                'subject' => $subject,
                'message_id' => $messageId,
                'in_reply_to' => $inReplyTo,
                'references_header' => $references,
            ]
        );

        email_processor_update_inbox_result($pdo, $messageId, $ticketId, 'created', null, null);

        if ($pdo->inTransaction()) {
            $pdo->commit();
        }

        $ticket = ticket_service_get_ticket_with_user_context($pdo, $ticketId);
        if ($ticket) {
            $ticketSerial = format_ticket_serial($pdo, $ticket);
            ticket_service_handle_ticket_created($pdo, $ticket, true);

            email_processor_notify_admins(
                $pdo,
                'Ticket created from email',
                'Incoming email from ' . email_processor_customer_name($message)
                    . ' created ticket ' . $ticketSerial . '.',
                'email_received',
                $message,
                $messageId,
                $ticketId,
                'received',
                $inboxLogId > 0 ? $inboxLogId : null
            );
        } else {
            email_processor_notify_admins(
                $pdo,
                'Ticket created from email',
                'Incoming email from ' . email_processor_customer_name($message)
                    . ' created ticket #' . $ticketId . '.',
                'email_received',
                $message,
                $messageId,
                $ticketId,
                'received',
                $inboxLogId > 0 ? $inboxLogId : null
            );
        }
        ticket_log_service_add(
            $pdo,
            $ticketId,
            'notification_sent',
            'Email-received notification sent to active admin users.'
        );

        return [
            'success' => true,
            'ticket_id' => $ticketId,
            'external_ticket_id' => null,
        ];
    } catch (Throwable $throwable) {
        // Log detailed error for debugging
        $debug = [
            'msg' => $throwable->getMessage(),
            'file' => $throwable->getFile(),
            'line' => $throwable->getLine(),
            'trace' => $throwable->getTraceAsString(),
            'ticketId' => $ticketId ?? null,
            'messageId' => $messageId ?? null,
        ];
        $logDir = defined('LOGS_PATH') ? LOGS_PATH : __DIR__ . '/../../storage/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        file_put_contents($logDir . '/email_process_errors.log', date('[Y-m-d H:i:s]') . ' ' . json_encode($debug) . PHP_EOL, FILE_APPEND);
        error_log('Email Process Error: ' . json_encode($debug));
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        email_processor_mark_failed_raw($pdo, $message, $messageId, 'Processing failed: ' . $throwable->getMessage());

        return ['success' => false, 'message' => $throwable->getMessage()];
    }
}
