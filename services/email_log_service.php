<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/email_inbox_service.php';
require_once __DIR__ . '/email_log_flag_service.php';

// Returns validated filters for the email activity pages.
function email_log_service_filters(array $query): array
{
    $direction = trim((string) ($query['direction'] ?? 'all'));
    $status = trim((string) ($query['status'] ?? ''));

    $flagged = isset($query['flagged']) && (string) $query['flagged'] === '1';

    return [
        'direction' => in_array($direction, ['all', 'incoming', 'outgoing'], true) ? $direction : 'all',
        'ticket_id' => max(0, (int) ($query['ticket_id'] ?? 0)),
        'email' => trim((string) ($query['email'] ?? '')),
        'search' => trim((string) ($query['search'] ?? '')),
        'status' => in_array($status, ['', 'received', 'unmapped', 'unknown', 'ignored', 'pending', 'sent', 'failed'], true) ? $status : '',
        'from_date' => email_inbox_service_normalize_date($query['from_date'] ?? ''),
        'to_date' => email_inbox_service_normalize_date($query['to_date'] ?? ''),
        'limit' => max(1, min(100, (int) ($query['limit'] ?? 50))),
        'flagged' => $flagged,
    ];
}

// Applies ticket visibility rules to email activity queries.
// When filtering to a single ticket (e.g. ticket view), agents use the same browse-all rule as ticket_scope(..., true, true).
function email_log_service_scope_sql(?array $currentUser, string $alias = 't', bool $agentsBrowseAllTickets = false): array
{
    if ($currentUser === null || rbac_email_logs_full_visibility($currentUser)) {
        return ['', []];
    }

    [$scopeSql, $scopeParams] = ticket_scope($currentUser, $alias, true, $agentsBrowseAllTickets);
    return [' AND ' . $scopeSql, $scopeParams];
}

function email_log_service_outbox_has_column(PDO $pdo, string $column): bool
{
    static $columns = null;

    if ($columns === null) {
        $columns = [];
        foreach ($pdo->query('SHOW COLUMNS FROM email_outbox_log')->fetchAll() as $row) {
            $field = (string) ($row['Field'] ?? '');
            if ($field !== '') {
                $columns[$field] = true;
            }
        }
    }

    return isset($columns[$column]);
}

function email_log_service_inbox_has_column(PDO $pdo, string $column): bool
{
    static $columns = null;

    email_inbox_service_ensure_table($pdo);
    if ($columns === null) {
        $columns = [];
        foreach ($pdo->query('SHOW COLUMNS FROM email_inbox_log')->fetchAll() as $row) {
            $field = (string) ($row['Field'] ?? '');
            if ($field !== '') {
                $columns[$field] = true;
            }
        }
    }

    return isset($columns[$column]);
}

function email_log_service_split_addresses(string $value): array
{
    $addresses = [];

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

        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $addresses[strtolower($email)] = $email;
        }
    }

    return array_values($addresses);
}

// Returns formatted incoming email activity from the inbox log.
function email_log_service_incoming(PDO $pdo, array $filters, ?array $currentUser = null): array
{
    email_inbox_service_ensure_table($pdo);
    require_once __DIR__ . '/email_inbox_assets_service.php';
    email_inbox_assets_ensure_schema($pdo);

    $filters = email_log_service_filters($filters);
    if ($filters['direction'] === 'outgoing') {
        return [];
    }

    if ($filters['status'] !== '' && !in_array($filters['status'], ['received', 'unmapped', 'unknown', 'ignored', 'pending'], true)) {
        return [];
    }

    $scopeForTicketDetail = $filters['ticket_id'] > 0;
    [$scopeSql, $scopeParams] = email_log_service_scope_sql($currentUser, 't', $scopeForTicketDetail);
    $where = ' WHERE 1=1';
    if ($scopeSql !== '') {
        $scopeCondition = preg_replace('/^\s*AND\s+/', '', $scopeSql) ?? '';
        if ($scopeCondition !== '') {
            $where .= ' AND (ei.ticket_id IS NULL OR t.ticket_id IS NULL OR (' . $scopeCondition . '))';
        }
    }
    $params = $scopeParams;

    if ($filters['ticket_id'] > 0) {
        $where .= ' AND ei.ticket_id = :ticket_id';
        $params[':ticket_id'] = $filters['ticket_id'];
    }

    if ($filters['status'] === 'received') {
        $where .= ' AND (ei.processing_result = :processing_created OR ei.processing_result = :processing_replied OR (ei.processing_result = :processing_pending AND ei.ticket_id IS NOT NULL))';
        $params[':processing_created'] = 'created';
        $params[':processing_replied'] = 'replied';
        $params[':processing_pending'] = 'pending';
    } elseif ($filters['status'] === 'unmapped') {
        $where .= ' AND ei.processing_result = :processing_unmapped';
        $params[':processing_unmapped'] = 'unmapped';
    } elseif ($filters['status'] === 'unknown') {
        $where .= ' AND ei.processing_result = :processing_unknown';
        $params[':processing_unknown'] = 'unknown';
    } elseif ($filters['status'] === 'ignored') {
        $where .= ' AND ei.processing_result = :processing_ignored';
        $params[':processing_ignored'] = 'ignored';
    } elseif ($filters['status'] === 'pending') {
        $where .= ' AND ei.processing_result = :processing_pending';
        $params[':processing_pending'] = 'pending';
    }

    if ($filters['email'] !== '') {
        $where .= ' AND ei.from_email LIKE :from_email';
        $params[':from_email'] = '%' . $filters['email'] . '%';
    }

    if ($filters['from_date'] !== '') {
        $where .= ' AND DATE(COALESCE(ei.received_at, ei.created_at)) >= :from_date';
        $params[':from_date'] = $filters['from_date'];
    }

    if ($filters['to_date'] !== '') {
        $where .= ' AND DATE(COALESCE(ei.received_at, ei.created_at)) <= :to_date';
        $params[':to_date'] = $filters['to_date'];
    }

    if ($filters['search'] !== '') {
        $where .= ' AND (
            COALESCE(ei.subject, \'\') LIKE :search_subject
            OR COALESCE(ei.body, \'\') LIKE :search_body
            OR COALESCE(ei.raw_message, \'\') LIKE :search_raw
            OR COALESCE(ei.from_email, \'\') LIKE :search_email
            OR COALESCE(t.customer, \'\') LIKE :search_customer
            OR COALESCE(t.external_ticket_id, ei.external_ticket_id, \'\') LIKE :search_external
            OR CAST(COALESCE(ei.ticket_id, 0) AS CHAR) LIKE :search_ticket_id
        )';
        $params[':search_subject'] = '%' . $filters['search'] . '%';
        $params[':search_body'] = '%' . $filters['search'] . '%';
        $params[':search_raw'] = '%' . $filters['search'] . '%';
        $params[':search_email'] = '%' . $filters['search'] . '%';
        $params[':search_customer'] = '%' . $filters['search'] . '%';
        $params[':search_external'] = '%' . $filters['search'] . '%';
        $params[':search_ticket_id'] = '%' . $filters['search'] . '%';
    }

    $flagUserId = email_log_flag_user_id($currentUser);
    if (!empty($filters['flagged'])) {
        [$flagSql, $flagParams] = email_log_flag_exists_sql($flagUserId, 'incoming', 'ei.id');
        $where .= $flagSql;
        $params += $flagParams;
    }

    $inReplyToSelect = email_log_service_inbox_has_column($pdo, 'in_reply_to') ? 'ei.in_reply_to' : 'NULL AS in_reply_to';
    $referencesSelect = email_log_service_inbox_has_column($pdo, 'references_header') ? 'ei.references_header' : 'NULL AS references_header';
    $hasInboxPreviewCol = email_inbox_assets_has_column($pdo, 'body_preview_html');
    $storedPreviewSelect = $hasInboxPreviewCol
        ? '(CASE WHEN ei.body_preview_html IS NOT NULL AND LENGTH(ei.body_preview_html) > 0 THEN 1 ELSE 0 END) AS has_stored_preview'
        : '0 AS has_stored_preview';
    $sql = 'SELECT
                ei.id AS log_id,
                ei.ticket_id,
                ei.message_id,
                ' . $inReplyToSelect . ',
                ' . $referencesSelect . ',
                ei.subject,
                ei.body,
                SUBSTRING(COALESCE(ei.raw_message, \'\'), 1, 16384) AS raw_header_chunk,
                LENGTH(COALESCE(ei.raw_message, \'\')) AS raw_message_bytes,
                (CASE WHEN COALESCE(ei.raw_message, \'\') LIKE \'%Content-Disposition:%attachment%\' THEN 1 ELSE 0 END) AS has_attachment_hint,
                ' . $storedPreviewSelect . ',
                ei.from_email,
                ei.received_at,
                ei.processed,
                ei.processed_at,
                ei.processing_result,
                ei.ignored_reason,
                ei.created_at,
                COALESCE(t.external_ticket_id, ei.external_ticket_id) AS external_ticket_id,
                t.issue,
                t.customer,
                t.customer_email,
                t.status AS ticket_status,
                t.priority,
                t.source,
                t.created_at AS ticket_created_at,
                assignee.name AS assignee_name,
                creator.name AS creator_name
            FROM email_inbox_log ei
            LEFT JOIN tickets t ON t.ticket_id = ei.ticket_id
            LEFT JOIN users assignee ON assignee.user_id = t.assign_to
            LEFT JOIN users creator ON creator.user_id = t.created_by' . $where . '
            ORDER BY COALESCE(ei.received_at, ei.created_at) DESC, ei.id DESC
            LIMIT ' . (int) $filters['limit'];

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['direction'] = 'incoming';
        $result = (string) ($row['processing_result'] ?? '');
        $row['mail_status'] = in_array($result, ['ignored', 'unmapped', 'unknown'], true) ? $result : 'received';
        $row['subject'] = trim((string) ($row['subject'] ?? '')) !== '' ? (string) $row['subject'] : (string) ($row['issue'] ?? '');
        $row['from_email'] = trim((string) ($row['from_email'] ?? '')) !== '' ? (string) $row['from_email'] : (string) ($row['customer_email'] ?? '');
        $row['received_at'] = (string) ($row['received_at'] ?: $row['created_at']);
        $row['body'] = trim((string) ($row['body'] ?? ''));
        unset($row['raw_message']);
    }

    return $rows;
}

// Returns outgoing SMTP activity from the email outbox.
function email_log_service_outgoing(PDO $pdo, array $filters, ?array $currentUser = null): array
{
    $filters = email_log_service_filters($filters);
    if ($filters['direction'] === 'incoming') {
        return [];
    }

    require_once __DIR__ . '/email_outbox_assets_service.php';
    email_outbox_assets_ensure_schema($pdo);

    $hasCcEmail = email_log_service_outbox_has_column($pdo, 'cc_email');
    $hasFromEmail = email_log_service_outbox_has_column($pdo, 'from_email');
    $hasEmailAccountId = email_log_service_outbox_has_column($pdo, 'email_account_id');
    $hasMessageId = email_log_service_outbox_has_column($pdo, 'message_id');
    $hasInReplyTo = email_log_service_outbox_has_column($pdo, 'in_reply_to');
    $hasReferences = email_log_service_outbox_has_column($pdo, 'references_header');
    $hasBodyIsHtml = email_log_service_outbox_has_column($pdo, 'body_is_html');
    $scopeForTicketDetail = $filters['ticket_id'] > 0;
    [$scopeSql, $scopeParams] = email_log_service_scope_sql($currentUser, 't', $scopeForTicketDetail);
    $where = ' WHERE 1=1' . $scopeSql;
    $params = $scopeParams;

    if ($filters['ticket_id'] > 0) {
        $where .= ' AND eo.ticket_id = :ticket_id';
        $params[':ticket_id'] = $filters['ticket_id'];
    }

    if ($filters['email'] !== '') {
        $emailParts = ['eo.to_email LIKE :to_email'];
        if ($hasCcEmail) {
            $emailParts[] = 'eo.cc_email LIKE :cc_email';
        }
        if ($hasFromEmail) {
            $emailParts[] = 'eo.from_email LIKE :from_email';
        }
        $where .= ' AND (' . implode(' OR ', $emailParts) . ')';
        $params[':to_email'] = '%' . $filters['email'] . '%';
        if ($hasCcEmail) {
            $params[':cc_email'] = '%' . $filters['email'] . '%';
        }
        if ($hasFromEmail) {
            $params[':from_email'] = '%' . $filters['email'] . '%';
        }
    }

    if ($filters['status'] !== '' && $filters['status'] !== 'received') {
        $where .= ' AND eo.status = :mail_status';
        $params[':mail_status'] = $filters['status'];
    }

    if ($filters['from_date'] !== '') {
        $where .= ' AND DATE(COALESCE(eo.sent_at, eo.created_at)) >= :from_date';
        $params[':from_date'] = $filters['from_date'];
    }

    if ($filters['to_date'] !== '') {
        $where .= ' AND DATE(COALESCE(eo.sent_at, eo.created_at)) <= :to_date';
        $params[':to_date'] = $filters['to_date'];
    }

    if ($filters['search'] !== '') {
        $where .= ' AND (
            eo.subject LIKE :search_subject
            OR eo.body LIKE :search_body
            ' . ($hasCcEmail ? 'OR COALESCE(eo.cc_email, \'\') LIKE :search_cc' : '') . '
            ' . ($hasFromEmail ? 'OR COALESCE(eo.from_email, \'\') LIKE :search_from' : '') . '
            OR COALESCE(t.issue, \'\') LIKE :search_issue
            OR COALESCE(t.customer, \'\') LIKE :search_customer
        )';
        $params[':search_subject'] = '%' . $filters['search'] . '%';
        $params[':search_body'] = '%' . $filters['search'] . '%';
        if ($hasCcEmail) {
            $params[':search_cc'] = '%' . $filters['search'] . '%';
        }
        if ($hasFromEmail) {
            $params[':search_from'] = '%' . $filters['search'] . '%';
        }
        $params[':search_issue'] = '%' . $filters['search'] . '%';
        $params[':search_customer'] = '%' . $filters['search'] . '%';
    }

    $flagUserId = email_log_flag_user_id($currentUser);
    if (!empty($filters['flagged'])) {
        [$flagSql, $flagParams] = email_log_flag_exists_sql($flagUserId, 'outgoing', 'eo.id');
        $where .= $flagSql;
        $params += $flagParams;
    }

    $accountSelect = $hasEmailAccountId ? 'eo.email_account_id' : 'NULL AS email_account_id';
    $fromSelect = $hasFromEmail ? 'eo.from_email' : 'NULL AS from_email';
    $ccSelect = $hasCcEmail ? 'eo.cc_email' : 'NULL AS cc_email';
    $messageIdSelect = $hasMessageId ? 'eo.message_id' : 'NULL AS message_id';
    $inReplyToSelect = $hasInReplyTo ? 'eo.in_reply_to' : 'NULL AS in_reply_to';
    $referencesSelect = $hasReferences ? 'eo.references_header' : 'NULL AS references_header';
    $bodyIsHtmlSelect = $hasBodyIsHtml ? 'eo.body_is_html' : '0 AS body_is_html';
    $hasBodyPreviewHtml = email_outbox_assets_has_column($pdo, 'body_preview_html');
    $storedPreviewSelect = $hasBodyPreviewHtml
        ? '(CASE WHEN eo.body_preview_html IS NOT NULL AND LENGTH(eo.body_preview_html) > 0 THEN 1 ELSE 0 END) AS has_stored_preview'
        : '0 AS has_stored_preview';
    $sql = 'SELECT
                eo.id AS log_id,
                eo.ticket_id,
                ' . $accountSelect . ',
                ' . $fromSelect . ',
                eo.to_email,
                ' . $ccSelect . ',
                ' . $messageIdSelect . ',
                ' . $inReplyToSelect . ',
                ' . $referencesSelect . ',
                eo.subject,
                eo.body,
                ' . $storedPreviewSelect . ',
                ' . $bodyIsHtmlSelect . ',
                eo.status AS mail_status,
                eo.error_message,
                eo.sent_at,
                eo.created_at,
                t.external_ticket_id,
                t.issue,
                t.customer,
                t.customer_email,
                t.status AS ticket_status,
                t.priority,
                t.source,
                t.created_at AS ticket_created_at,
                assignee.name AS assignee_name,
                creator.name AS creator_name
            FROM email_outbox_log eo
            LEFT JOIN tickets t ON t.ticket_id = eo.ticket_id
            LEFT JOIN users assignee ON assignee.user_id = t.assign_to
            LEFT JOIN users creator ON creator.user_id = t.created_by' . $where . '
            ORDER BY COALESCE(eo.sent_at, eo.created_at) DESC, eo.id DESC
            LIMIT ' . (int) $filters['limit'];

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['direction'] = 'outgoing';
    }

    return $rows;
}

function email_log_service_recent_addresses(PDO $pdo, ?array $currentUser = null, int $limit = 60): array
{
    $addresses = [];
    $add = static function (?string $value) use (&$addresses): void {
        foreach (email_log_service_split_addresses((string) $value) as $email) {
            $addresses[strtolower($email)] = $email;
        }
    };

    foreach (email_log_service_outgoing($pdo, ['direction' => 'outgoing', 'limit' => 100], $currentUser) as $email) {
        $add($email['from_email'] ?? '');
        $add($email['to_email'] ?? '');
        $add($email['cc_email'] ?? '');
        $add($email['customer_email'] ?? '');
    }

    foreach (email_log_service_incoming($pdo, ['direction' => 'incoming', 'limit' => 100], $currentUser) as $email) {
        $add($email['from_email'] ?? '');
        $add($email['customer_email'] ?? '');
    }

    try {
        $stmt = $pdo->query('SELECT email FROM users WHERE email IS NOT NULL AND email <> \'\' AND deleted = 0 ORDER BY name ASC LIMIT 100');
        foreach ($stmt->fetchAll() as $user) {
            $add($user['email'] ?? '');
        }
    } catch (Throwable $throwable) {
        // Suggestions are optional; sending should never depend on this helper.
    }

    return array_slice(array_values($addresses), 0, max(1, $limit));
}

function email_log_service_thread_for_ticket(PDO $pdo, int $ticketId, ?array $currentUser = null): array
{
    $filters = ['ticket_id' => $ticketId, 'limit' => 100];
    $incoming = email_log_service_incoming($pdo, $filters + ['direction' => 'incoming'], $currentUser);
    $outgoing = email_log_service_outgoing($pdo, $filters + ['direction' => 'outgoing'], $currentUser);
    $thread = [];

    foreach ($incoming as $email) {
        $thread[] = [
            'direction' => 'incoming',
            'label' => (($email['processing_result'] ?? '') === 'replied') ? 'Incoming Reply' : 'Incoming Email',
            'subject' => (string) ($email['subject'] ?? ''),
            'body' => (string) ($email['body'] ?? ''),
            'from_email' => (string) ($email['from_email'] ?? ''),
            'to_email' => '',
            'cc_email' => '',
            'message_id' => (string) ($email['message_id'] ?? ''),
            'in_reply_to' => (string) ($email['in_reply_to'] ?? ''),
            'references_header' => (string) ($email['references_header'] ?? ''),
            'mail_status' => (string) ($email['mail_status'] ?? 'received'),
            'occurred_at' => (string) (($email['received_at'] ?? '') ?: ($email['created_at'] ?? '')),
            'log_id' => (int) ($email['log_id'] ?? 0),
        ];
    }

    foreach ($outgoing as $email) {
        $thread[] = [
            'direction' => 'outgoing',
            'label' => 'Outgoing Email',
            'subject' => (string) ($email['subject'] ?? ''),
            'body' => (string) ($email['body'] ?? ''),
            'from_email' => (string) ($email['from_email'] ?? ''),
            'to_email' => (string) ($email['to_email'] ?? ''),
            'cc_email' => (string) ($email['cc_email'] ?? ''),
            'message_id' => (string) ($email['message_id'] ?? ''),
            'in_reply_to' => (string) ($email['in_reply_to'] ?? ''),
            'references_header' => (string) ($email['references_header'] ?? ''),
            'mail_status' => (string) ($email['mail_status'] ?? 'pending'),
            'occurred_at' => (string) (($email['sent_at'] ?? '') ?: ($email['created_at'] ?? '')),
            'log_id' => (int) ($email['log_id'] ?? 0),
        ];
    }

    usort($thread, static function (array $left, array $right): int {
        $leftTime = strtotime((string) ($left['occurred_at'] ?? '')) ?: 0;
        $rightTime = strtotime((string) ($right['occurred_at'] ?? '')) ?: 0;
        if ($leftTime === $rightTime) {
            return ((int) ($left['log_id'] ?? 0)) <=> ((int) ($right['log_id'] ?? 0));
        }

        return $leftTime <=> $rightTime;
    });

    return $thread;
}

function email_log_service_fetch_incoming_by_id(PDO $pdo, int $logId, ?array $currentUser): ?array
{
    if ($logId <= 0) {
        return null;
    }

    email_inbox_service_ensure_table($pdo);
    require_once __DIR__ . '/email_inbox_assets_service.php';
    email_inbox_assets_ensure_schema($pdo);

    [$scopeSql, $scopeParams] = email_log_service_scope_sql($currentUser, 't', true);
    $where = ' WHERE ei.id = :log_id';
    $params = array_merge([':log_id' => $logId], $scopeParams);
    if ($scopeSql !== '') {
        $scopeCondition = preg_replace('/^\s*AND\s+/', '', $scopeSql) ?? '';
        if ($scopeCondition !== '') {
            $where .= ' AND (ei.ticket_id IS NULL OR t.ticket_id IS NULL OR (' . $scopeCondition . '))';
        }
    }

    $inReplyToSelect = email_log_service_inbox_has_column($pdo, 'in_reply_to') ? 'ei.in_reply_to' : 'NULL AS in_reply_to';
    $referencesSelect = email_log_service_inbox_has_column($pdo, 'references_header') ? 'ei.references_header' : 'NULL AS references_header';
    $hasInboxPreviewCol = email_inbox_assets_has_column($pdo, 'body_preview_html');
    $storedPreviewSelect = $hasInboxPreviewCol
        ? '(CASE WHEN ei.body_preview_html IS NOT NULL AND LENGTH(ei.body_preview_html) > 0 THEN 1 ELSE 0 END) AS has_stored_preview'
        : '0 AS has_stored_preview';

    $sql = 'SELECT
                ei.id AS log_id,
                ei.ticket_id,
                ei.message_id,
                ' . $inReplyToSelect . ',
                ' . $referencesSelect . ',
                ei.subject,
                ei.body,
                SUBSTRING(COALESCE(ei.raw_message, \'\'), 1, 16384) AS raw_header_chunk,
                LENGTH(COALESCE(ei.raw_message, \'\')) AS raw_message_bytes,
                (CASE WHEN COALESCE(ei.raw_message, \'\') LIKE \'%Content-Disposition:%attachment%\' THEN 1 ELSE 0 END) AS has_attachment_hint,
                ' . $storedPreviewSelect . ',
                ei.from_email,
                ei.received_at,
                ei.processed,
                ei.processed_at,
                ei.processing_result,
                ei.ignored_reason,
                ei.created_at,
                COALESCE(t.external_ticket_id, ei.external_ticket_id) AS external_ticket_id,
                t.issue,
                t.customer,
                t.customer_email,
                t.status AS ticket_status,
                t.priority,
                t.source,
                t.created_at AS ticket_created_at,
                assignee.name AS assignee_name,
                creator.name AS creator_name
            FROM email_inbox_log ei
            LEFT JOIN tickets t ON t.ticket_id = ei.ticket_id
            LEFT JOIN users assignee ON assignee.user_id = t.assign_to
            LEFT JOIN users creator ON creator.user_id = t.created_by' . $where . '
            LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $row['direction'] = 'incoming';
    $result = (string) ($row['processing_result'] ?? '');
    $row['mail_status'] = in_array($result, ['ignored', 'unmapped', 'unknown'], true) ? $result : 'received';
    $row['subject'] = trim((string) ($row['subject'] ?? '')) !== '' ? (string) $row['subject'] : (string) ($row['issue'] ?? '');
    $row['from_email'] = trim((string) ($row['from_email'] ?? '')) !== '' ? (string) $row['from_email'] : (string) ($row['customer_email'] ?? '');
    $row['received_at'] = (string) ($row['received_at'] ?: $row['created_at']);
    $row['body'] = trim((string) ($row['body'] ?? ''));

    return $row;
}

function email_log_service_fetch_outgoing_by_id(PDO $pdo, int $logId, ?array $currentUser): ?array
{
    if ($logId <= 0) {
        return null;
    }

    require_once __DIR__ . '/email_outbox_assets_service.php';
    email_outbox_assets_ensure_schema($pdo);

    $hasCcEmail = email_log_service_outbox_has_column($pdo, 'cc_email');
    $hasFromEmail = email_log_service_outbox_has_column($pdo, 'from_email');
    $hasEmailAccountId = email_log_service_outbox_has_column($pdo, 'email_account_id');
    $hasMessageId = email_log_service_outbox_has_column($pdo, 'message_id');
    $hasInReplyTo = email_log_service_outbox_has_column($pdo, 'in_reply_to');
    $hasReferences = email_log_service_outbox_has_column($pdo, 'references_header');
    $hasBodyIsHtml = email_log_service_outbox_has_column($pdo, 'body_is_html');

    [$scopeSql, $scopeParams] = email_log_service_scope_sql($currentUser, 't', true);
    $where = ' WHERE eo.id = :log_id' . $scopeSql;
    $params = array_merge([':log_id' => $logId], $scopeParams);

    $accountSelect = $hasEmailAccountId ? 'eo.email_account_id' : 'NULL AS email_account_id';
    $fromSelect = $hasFromEmail ? 'eo.from_email' : 'NULL AS from_email';
    $ccSelect = $hasCcEmail ? 'eo.cc_email' : 'NULL AS cc_email';
    $messageIdSelect = $hasMessageId ? 'eo.message_id' : 'NULL AS message_id';
    $inReplyToSelect = $hasInReplyTo ? 'eo.in_reply_to' : 'NULL AS in_reply_to';
    $referencesSelect = $hasReferences ? 'eo.references_header' : 'NULL AS references_header';
    $bodyIsHtmlSelect = $hasBodyIsHtml ? 'eo.body_is_html' : '0 AS body_is_html';
    $hasBodyPreviewHtml = email_outbox_assets_has_column($pdo, 'body_preview_html');
    $storedPreviewSelect = $hasBodyPreviewHtml
        ? '(CASE WHEN eo.body_preview_html IS NOT NULL AND LENGTH(eo.body_preview_html) > 0 THEN 1 ELSE 0 END) AS has_stored_preview'
        : '0 AS has_stored_preview';

    $sql = 'SELECT
                eo.id AS log_id,
                eo.ticket_id,
                ' . $accountSelect . ',
                ' . $fromSelect . ',
                eo.to_email,
                ' . $ccSelect . ',
                ' . $messageIdSelect . ',
                ' . $inReplyToSelect . ',
                ' . $referencesSelect . ',
                eo.subject,
                eo.body,
                ' . $storedPreviewSelect . ',
                ' . $bodyIsHtmlSelect . ',
                eo.status AS mail_status,
                eo.error_message,
                eo.sent_at,
                eo.created_at,
                t.external_ticket_id,
                t.issue,
                t.customer,
                t.customer_email,
                t.status AS ticket_status,
                t.priority,
                t.source,
                t.created_at AS ticket_created_at,
                assignee.name AS assignee_name,
                creator.name AS creator_name
            FROM email_outbox_log eo
            LEFT JOIN tickets t ON t.ticket_id = eo.ticket_id
            LEFT JOIN users assignee ON assignee.user_id = t.assign_to
            LEFT JOIN users creator ON creator.user_id = t.created_by' . $where . '
            LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $row['direction'] = 'outgoing';

    return $row;
}

// Returns email activity for one ticket, ready for the detail page.
function email_log_service_for_ticket(PDO $pdo, int $ticketId, ?array $currentUser = null): array
{
    $filters = ['ticket_id' => $ticketId, 'limit' => 50];

    return [
        'incoming' => email_log_service_incoming($pdo, $filters + ['direction' => 'incoming'], $currentUser),
        'outgoing' => email_log_service_outgoing($pdo, $filters + ['direction' => 'outgoing'], $currentUser),
        'thread' => email_log_service_thread_for_ticket($pdo, $ticketId, $currentUser),
    ];
}
