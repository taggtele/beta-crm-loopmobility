<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../notifications/notification_service.php';
require_once __DIR__ . '/../email/smtp_service.php';
require_once __DIR__ . '/../../services/party_service.php';
require_once __DIR__ . '/../../services/ticket_log_service.php';

// Loads one ticket together with creator and assignee contact details.
function ticket_service_get_ticket_with_user_context(PDO $pdo, int $ticketId, bool $includeDeleted = false): ?array
{
    $deletedClause = $includeDeleted ? '' : ' AND t.deleted_at IS NULL AND t.is_deleted = 0';
    $stmt = $pdo->prepare(
        'SELECT
            t.ticket_id,
            t.issue,
            t.description,
            t.status,
            t.priority,
            t.customer,
            t.customer_email,
            t.country,
            t.source,
            t.assign_to,
            t.created_by,
            t.mail_message_id,
            t.mail_thread_id,
            t.is_deleted,
            ' . party_service_ticket_select_columns($pdo) . ',
            t.external_ticket_id,
            t.reference,
            t.created_at,
            t.closed_at,
            t.updated_at,
            t.updated_by,
            t.deleted_at,
            initiator.name AS initiator_party_name,
            vendor.name AS assigned_vendor_name,
            assignee.name AS assignee_name,
            assignee.email AS assignee_email,
            creator.name AS creator_name,
            creator.email AS creator_email,
            updater.name AS updater_name
         FROM tickets t
         LEFT JOIN parties initiator ON initiator.id = t.initiator_party_id
         LEFT JOIN parties vendor ON vendor.id = t.assigned_vendor_id
         LEFT JOIN users assignee ON assignee.user_id = t.assign_to
         LEFT JOIN users creator ON creator.user_id = t.created_by
         LEFT JOIN users updater ON updater.user_id = t.updated_by
WHERE t.ticket_id = :ticket_id' . $deletedClause . '
           LIMIT 1'
    );
    $stmt->execute([':ticket_id' => $ticketId]);
    $ticket = $stmt->fetch();

    return $ticket ?: null;
}

function ticket_service_current_user_id(?array $currentUser): ?string
{
    $userId = trim((string) ($currentUser['user_id'] ?? ''));

    return $userId !== '' ? $userId : null;
}

function ticket_service_updated_json_fields(?array $ticket): array
{
    if (!$ticket) {
        return [
            'updated_at' => '',
            'updated_at_label' => '',
            'updater_name' => '',
            'closed_at' => '',
            'closed_at_label' => '',
        ];
    }

    $updatedAt = trim((string) ($ticket['updated_at'] ?? ''));
    if ($updatedAt === '') {
        $updatedAt = trim((string) ($ticket['created_at'] ?? ''));
    }

    $updaterName = trim((string) ($ticket['updater_name'] ?? ''));
    if ($updaterName === '') {
        $updaterName = trim((string) ($ticket['creator_name'] ?? ''));
    }
    if ($updaterName === '') {
        $updaterName = trim((string) ($ticket['updated_by'] ?? ''));
    }

    $status = trim((string) ($ticket['status'] ?? ''));
    $closedAt = trim((string) ($ticket['closed_at'] ?? ''));
    if ($closedAt === '' && $status === 'Closed') {
        $closedAt = trim((string) ($ticket['updated_at'] ?? ''));
    }

    return [
        'updated_at' => $updatedAt,
        'updated_at_label' => $updatedAt !== '' ? format_date($updatedAt, 'd M Y, H:i') : '',
        'updater_name' => $updaterName !== '' ? $updaterName : 'System',
        'closed_at' => $closedAt,
        'closed_at_label' => ($status === 'Closed' && $closedAt !== '')
            ? format_date($closedAt, 'd M, H:i')
            : '',
    ];
}

// Loads one user by public user ID for assignment notifications.
function ticket_service_get_user_by_user_id(PDO $pdo, ?string $userId): ?array
{
    $userId = trim((string) $userId);
    if ($userId === '') {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id, user_id, name, email, role
         FROM users
         WHERE user_id = :user_id
         AND deleted = 0
         LIMIT 1'
    );
    $stmt->execute([':user_id' => $userId]);
    $user = $stmt->fetch();

    return $user ?: null;
}

function ticket_referral_ensure_table(PDO $pdo): void
{
    static $ready = false;

    if ($ready) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS ticket_referrals (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            ticket_id BIGINT NOT NULL,
            referred_user_id VARCHAR(100) NOT NULL,
            referred_by_user_id VARCHAR(100) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ticket_referrals_ticket_user (ticket_id, referred_user_id),
            INDEX idx_ticket_referrals_ticket (ticket_id),
            INDEX idx_ticket_referrals_user (referred_user_id),
            CONSTRAINT fk_ticket_referrals_ticket_id
                FOREIGN KEY (ticket_id) REFERENCES tickets (ticket_id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $ready = true;
}

// Builds the ticket-access condition for owners, collaborators, and admins.
function ticket_service_access_scope_sql(PDO $pdo, array $currentUser, string $alias = 't', bool $includeUnassignedForAgents = false): array
{
    ticket_referral_ensure_table($pdo);

    $prefix = $alias !== '' ? $alias . '.' : '';

    if (($currentUser['role'] ?? '') === 'Admin') {
        return ['1=1', []];
    }

    $userId = (string) ($currentUser['user_id'] ?? '');

    // Use unique placeholder names to avoid PDO duplicate parameter error
    $assignPlaceholder = ':current_user_id_assign';
    $existsPlaceholder = ':current_user_id_exists';

    $clauses = [
        $prefix . 'assign_to = ' . $assignPlaceholder,
        'EXISTS (
            SELECT 1
            FROM ticket_referrals tr
            WHERE tr.ticket_id = ' . $prefix . 'ticket_id
            AND tr.referred_user_id = ' . $existsPlaceholder . '
        )',
    ];

    if ($includeUnassignedForAgents) {
        $clauses[] = $prefix . 'assign_to IS NULL';
        $clauses[] = $prefix . 'assign_to = \'\'';
    }

    return [
        '(' . implode(' OR ', $clauses) . ')',
        [$assignPlaceholder => $userId, $existsPlaceholder => $userId],
    ];
}

function ticket_referral_is_collaborator(PDO $pdo, int $ticketId, string $userId): bool
{
    $userId = trim($userId);
    if ($ticketId <= 0 || $userId === '') {
        return false;
    }

    ticket_referral_ensure_table($pdo);

    $stmt = $pdo->prepare(
        'SELECT 1
         FROM ticket_referrals
         WHERE ticket_id = :ticket_id
         AND referred_user_id = :referred_user_id
         LIMIT 1'
    );
    $stmt->execute([
        ':ticket_id' => $ticketId,
        ':referred_user_id' => $userId,
    ]);

    return (bool) $stmt->fetchColumn();
}

function ticket_service_user_can_view(PDO $pdo, array $ticket, array $currentUser, bool $includeUnassignedForAgents = true): bool
{
    if (($currentUser['role'] ?? '') === 'Admin') {
        return true;
    }

    $assignee = normalize_assignee($ticket['assign_to'] ?? null);
    $currentUserId = (string) ($currentUser['user_id'] ?? '');

    if ($assignee !== null && $assignee === $currentUserId) {
        return true;
    }

    if ($includeUnassignedForAgents && ($currentUser['role'] ?? '') === 'Agent' && $assignee === null) {
        return true;
    }

    return ticket_referral_is_collaborator($pdo, (int) ($ticket['ticket_id'] ?? 0), $currentUserId);
}

function ticket_service_user_can_operate(PDO $pdo, array $ticket, array $currentUser): bool
{
    if (($currentUser['role'] ?? '') === 'Admin') {
        return true;
    }

    $currentUserId = (string) ($currentUser['user_id'] ?? '');
    $assignee = normalize_assignee($ticket['assign_to'] ?? null);

    if ($assignee !== null && $assignee === $currentUserId) {
        return true;
    }

    return ticket_referral_is_collaborator($pdo, (int) ($ticket['ticket_id'] ?? 0), $currentUserId);
}

function ticket_referral_active_agent_by_user_id(PDO $pdo, string $userId): ?array
{
    $userId = trim($userId);
    if ($userId === '') {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id, user_id, name, email, role
         FROM users
         WHERE user_id = :user_id
         AND deleted = 0
         AND status = :status
         AND role = :role
         LIMIT 1'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':status' => 'Active',
        ':role' => 'Agent',
    ]);
    $agent = $stmt->fetch();

    return $agent ?: null;
}

function ticket_referral_for_ticket(PDO $pdo, int $ticketId): array
{
    if ($ticketId <= 0) {
        return [];
    }

    ticket_referral_ensure_table($pdo);

    $stmt = $pdo->prepare(
        'SELECT
            tr.id,
            tr.ticket_id,
            tr.referred_user_id,
            tr.referred_by_user_id,
            tr.created_at,
            referred.name AS referred_name,
            referred.email AS referred_email,
            referrer.name AS referred_by_name
         FROM ticket_referrals tr
         LEFT JOIN users referred ON referred.user_id COLLATE utf8mb4_unicode_ci = tr.referred_user_id
         LEFT JOIN users referrer ON referrer.user_id COLLATE utf8mb4_unicode_ci = tr.referred_by_user_id
         WHERE tr.ticket_id = :ticket_id
         ORDER BY tr.created_at ASC, tr.id ASC'
    );
    $stmt->execute([':ticket_id' => $ticketId]);

    return $stmt->fetchAll();
}

function ticket_referral_available_agents(PDO $pdo, array $ticket, array $currentUser): array
{
    ticket_referral_ensure_table($pdo);

    $ticketId = (int) ($ticket['ticket_id'] ?? 0);
    $assignee = normalize_assignee($ticket['assign_to'] ?? null);
    $currentUserId = (string) ($currentUser['user_id'] ?? '');
    $existingRows = ticket_referral_for_ticket($pdo, $ticketId);
    $existing = array_fill_keys(array_map(static fn(array $row): string => (string) $row['referred_user_id'], $existingRows), true);

    $stmt = $pdo->prepare(
        'SELECT id, user_id, name, email, role
         FROM users
         WHERE deleted = 0
         AND status = :status
         AND role = :role
         ORDER BY name ASC'
    );
    $stmt->execute([
        ':status' => 'Active',
        ':role' => 'Agent',
    ]);

    return array_values(array_filter($stmt->fetchAll(), static function (array $agent) use ($assignee, $currentUserId, $existing): bool {
        $agentUserId = (string) ($agent['user_id'] ?? '');
        if ($agentUserId === '') {
            return false;
        }
        if ($agentUserId === $assignee || $agentUserId === $currentUserId) {
            return false;
        }

        return !isset($existing[$agentUserId]);
    }));
}

function ticket_referral_create(PDO $pdo, int $ticketId, string $targetUserId, array $currentUser): array
{
    ticket_referral_ensure_table($pdo);

    $ticket = ticket_service_get_ticket_with_user_context($pdo, $ticketId);
    if (!$ticket) {
        throw new RuntimeException('Ticket not found.');
    }

    if (!ticket_service_user_can_operate($pdo, $ticket, $currentUser)) {
        throw new RuntimeException('You do not have permission to refer this ticket.');
    }

    $target = ticket_referral_active_agent_by_user_id($pdo, $targetUserId);
    if (!$target) {
        throw new RuntimeException('Select a valid active agent.');
    }

    $currentUserId = (string) ($currentUser['user_id'] ?? '');
    if ($target['user_id'] === $currentUserId) {
        throw new RuntimeException('You cannot refer a ticket to yourself.');
    }

    if (normalize_assignee($ticket['assign_to'] ?? null) === $target['user_id']) {
        throw new RuntimeException('This agent is already the primary owner.');
    }

    if (ticket_referral_is_collaborator($pdo, $ticketId, (string) $target['user_id'])) {
        throw new RuntimeException('This ticket is already shared with that agent.');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO ticket_referrals (
            ticket_id,
            referred_user_id,
            referred_by_user_id,
            created_at
         ) VALUES (
            :ticket_id,
            :referred_user_id,
            :referred_by_user_id,
            NOW()
         )'
    );
    $stmt->execute([
        ':ticket_id' => $ticketId,
        ':referred_user_id' => $target['user_id'],
        ':referred_by_user_id' => $currentUserId,
    ]);

    $ticketSerial = format_ticket_serial($pdo, $ticket);
    $targetLabel = trim((string) ($target['name'] ?? '')) ?: (string) $target['user_id'];
    $referrerLabel = trim((string) ($currentUser['name'] ?? '')) ?: $currentUserId;

    ticket_log_service_add(
        $pdo,
        $ticketId,
        'ticket_referred',
        'Ticket ' . $ticketSerial . ' shared with ' . $targetLabel . ' by ' . $referrerLabel . '. Primary owner remains ' . (($ticket['assignee_name'] ?? '') ?: (($ticket['assign_to'] ?? '') ?: 'Unassigned')) . '.'
    );

    notifications_create(
        $pdo,
        (string) $target['user_id'],
        'Ticket shared with you',
        'Ticket ' . $ticketSerial . ' was shared with you by ' . $referrerLabel . '.',
        'ticket_referred'
    );

    return [
        'ticket' => $ticket,
        'agent' => $target,
    ];
}

function ticket_service_normalize_message_id(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $value = trim($value, " \t\n\r\0\x0B<>");

    return '<' . $value . '>';
}

function ticket_service_collect_message_ids_for_thread(string ...$rawValues): array
{
    $ordered = [];
    $seen = [];
    foreach ($rawValues as $raw) {
        $raw = trim($raw);
        if ($raw === '') {
            continue;
        }
        if (preg_match_all('/<([^<>]+)>/', $raw, $matches)) {
            foreach ($matches[1] as $match) {
                $norm = ticket_service_normalize_message_id((string) $match);
                if ($norm !== '' && !isset($seen[$norm])) {
                    $seen[$norm] = true;
                    $ordered[] = $norm;
                }
            }
            continue;
        }
        $norm = ticket_service_normalize_message_id($raw);
        if ($norm !== '' && !isset($seen[$norm])) {
            $seen[$norm] = true;
            $ordered[] = $norm;
        }
    }

    return $ordered;
}

/**
 * Builds In-Reply-To / References / Re: subject so customer auto-mails stay in the same email thread.
 *
 * @return array{in_reply_to: string, references_header: string, reply_subject: string, has_thread: bool}
 */
function ticket_service_resolve_customer_reply_thread(PDO $pdo, array $ticket): array
{
    $baseSubject = trim((string) ($ticket['reference'] ?? ''));
    if ($baseSubject === '') {
        $baseSubject = trim((string) ($ticket['issue'] ?? ''));
    }
    while (preg_match('/^\s*(re|fw|fwd)\s*:\s*/i', $baseSubject)) {
        $baseSubject = preg_replace('/^\s*(re|fw|fwd)\s*:\s*/i', '', $baseSubject) ?? $baseSubject;
        $baseSubject = trim($baseSubject);
    }
    if ($baseSubject === '') {
        $baseSubject = 'Your request';
    }

    $rawIds = [
        (string) ($ticket['mail_message_id'] ?? ''),
        (string) ($ticket['mail_thread_id'] ?? ''),
    ];

    $ticketId = (int) ($ticket['ticket_id'] ?? 0);
    $latestInboundId = '';
    if ($ticketId > 0) {
        require_once __DIR__ . '/../../services/email_inbox_service.php';
        email_inbox_service_ensure_table($pdo);
        $stmt = $pdo->prepare(
            'SELECT message_id, in_reply_to, references_header, subject
             FROM email_inbox_log
             WHERE ticket_id = :ticket_id
             AND message_id IS NOT NULL
             AND message_id <> \'\'
             ORDER BY COALESCE(received_at, created_at) ASC'
        );
        $stmt->execute([':ticket_id' => $ticketId]);
        foreach ($stmt->fetchAll() as $row) {
            $rawIds[] = (string) ($row['message_id'] ?? '');
            $rawIds[] = (string) ($row['in_reply_to'] ?? '');
            $rawIds[] = (string) ($row['references_header'] ?? '');
            $rowSubject = trim((string) ($row['subject'] ?? ''));
            if ($rowSubject !== '') {
                $baseSubject = $rowSubject;
                while (preg_match('/^\s*(re|fw|fwd)\s*:\s*/i', $baseSubject)) {
                    $baseSubject = preg_replace('/^\s*(re|fw|fwd)\s*:\s*/i', '', $baseSubject) ?? $baseSubject;
                    $baseSubject = trim($baseSubject);
                }
            }
            $latestInboundId = (string) ($row['message_id'] ?? '');
        }
    }

    $ids = ticket_service_collect_message_ids_for_thread(...$rawIds);
    $inReplyTo = $latestInboundId !== ''
        ? ticket_service_normalize_message_id($latestInboundId)
        : ($ids !== [] ? $ids[array_key_last($ids)] : '');

    return [
        'in_reply_to' => $inReplyTo,
        'references_header' => implode(' ', $ids),
        'reply_subject' => 'Re: ' . $baseSubject,
        'has_thread' => $inReplyTo !== '',
    ];
}

function ticket_service_customer_auto_subject(PDO $pdo, array $ticket): string
{
    $issue = trim((string) ($ticket['issue'] ?? ''));
    $ticketSerial = format_ticket_serial($pdo, $ticket);

    return $issue !== '' ? $issue . ' ' . $ticketSerial : $ticketSerial;
}

function ticket_service_customer_auto_ticket_id_html(string $ticketSerial, bool $prominent = false): string
{
    $safe = htmlspecialchars($ticketSerial, ENT_QUOTES, 'UTF-8');
    if ($prominent) {
        return '<span style="font-size:20px;font-weight:700;color:#1e3a8a;letter-spacing:0.02em;">' . $safe . '</span>';
    }

    return '<strong style="color:#1e3a8a;">' . $safe . '</strong>';
}

function ticket_service_customer_auto_html_p(string $html): string
{
    return '<p style="margin:0 0 16px;font-size:14px;line-height:1.65;color:#374151;">' . $html . '</p>';
}

function ticket_service_customer_auto_html_ticket_banner(string $ticketSerial): string
{
    $tid = ticket_service_customer_auto_ticket_id_html($ticketSerial, true);

    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px;background-color:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;">'
        . '<tr><td style="padding:14px 18px;">'
        . '<p style="margin:0 0 6px;font-family:Segoe UI,Arial,sans-serif;font-size:11px;font-weight:600;color:#1d4ed8;text-transform:uppercase;letter-spacing:0.05em;">Support Ticket ID</p>'
        . '<p style="margin:0;font-family:Segoe UI,Arial,sans-serif;line-height:1.3;">' . $tid . '</p>'
        . '</td></tr></table>';
}

function ticket_service_customer_auto_html_signature(): string
{
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:4px 0 0;">'
        . '<tr><td style="padding-top:18px;border-top:1px solid #e5e7eb;">'
        . '<p style="margin:0 0 4px;font-family:Segoe UI,Arial,sans-serif;font-size:14px;line-height:1.5;color:#1f2937;">Best Regards,</p>'
        . '<p style="margin:0 0 2px;font-family:Segoe UI,Arial,sans-serif;font-size:14px;font-weight:600;line-height:1.5;color:#111827;">Support Desk</p>'
        . '<p style="margin:0 0 10px;font-family:Segoe UI,Arial,sans-serif;font-size:14px;line-height:1.5;color:#374151;">Loop Mobility Ltd</p>'
        . '<p style="margin:0;font-family:Segoe UI,Arial,sans-serif;font-size:13px;line-height:1.5;">'
        . '<a href="https://www.loopmobility.com" style="color:#1d4ed8;text-decoration:none;">www.loopmobility.com</a>'
        . '</p>'
        . '</td></tr></table>';
}

function ticket_service_customer_auto_html_disclaimer(): string
{
    return '<p style="margin:0;font-family:Segoe UI,Arial,sans-serif;font-size:12px;line-height:1.55;font-style:italic;color:#6b7280;">'
        . 'This is a system-generated acknowledgement email. Please do not remove the Ticket ID while replying to this email.'
        . '</p>';
}

function ticket_service_customer_auto_html_wrap(string $contentHtml): string
{
    return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Loop Mobility Support</title></head>'
        . '<body style="margin:0;padding:0;background-color:#f4f6f8;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f6f8;padding:24px 12px;">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background-color:#ffffff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">'
        . '<tr><td style="background-color:#1d4ed8;padding:22px 28px;">'
        . '<p style="margin:0;font-family:Segoe UI,Arial,sans-serif;font-size:18px;font-weight:600;color:#ffffff;letter-spacing:0.02em;">Loop Mobility Ltd</p>'
        . '<p style="margin:6px 0 0;font-family:Segoe UI,Arial,sans-serif;font-size:13px;color:#dbeafe;">Support Desk</p>'
        . '</td></tr>'
        . '<tr><td style="padding:28px 28px 8px;">'
        . $contentHtml
        . ticket_service_customer_auto_html_signature()
        . '</td></tr>'
        . '<tr><td style="padding:12px 28px 24px;border-top:1px solid #e5e7eb;background-color:#f9fafb;">'
        . ticket_service_customer_auto_html_disclaimer()
        . '</td></tr>'
        . '</table></td></tr></table></body></html>';
}

function ticket_service_customer_auto_html_body(string $ticketSerial, array $paragraphs): string
{
    $content = ticket_service_customer_auto_html_p(
        '<span style="font-size:15px;color:#111827;">Dear Team,</span>'
    );
    $content .= ticket_service_customer_auto_html_p(
        'Greetings from <strong style="color:#111827;">Loop Mobility Ltd</strong>.'
    );
    $content .= ticket_service_customer_auto_html_ticket_banner($ticketSerial);
    foreach ($paragraphs as $paragraph) {
        $content .= ticket_service_customer_auto_html_p($paragraph);
    }

    return ticket_service_customer_auto_html_wrap($content);
}

function ticket_service_queue_customer_threaded_email(
    PDO $pdo,
    string $customerEmail,
    string $fallbackSubject,
    string $body,
    array $ticket,
    ?int $accountId
): ?int {
    $thread = ticket_service_resolve_customer_reply_thread($pdo, $ticket);
    $subject = $thread['has_thread'] ? $thread['reply_subject'] : $fallbackSubject;
    $inReplyTo = $thread['has_thread'] ? $thread['in_reply_to'] : null;
    $references = $thread['has_thread'] ? $thread['references_header'] : null;

    return email_smtp_queue(
        $pdo,
        $customerEmail,
        $subject,
        $body,
        (int) ($ticket['ticket_id'] ?? 0),
        [],
        $accountId,
        null,
        true,
        $inReplyTo,
        $references
    );
}

// Queues the customer-facing created email when an address is available.
function ticket_service_queue_created_email(PDO $pdo, array $ticket): ?int
{
    $customerEmail = trim((string) ($ticket['customer_email'] ?? ''));
    if ($customerEmail === '') {
        return null;
    }

    $ticketSerial = format_ticket_serial($pdo, $ticket);
    $subject = ticket_service_customer_auto_subject($pdo, $ticket);
    $body = ticket_service_customer_auto_html_body($ticketSerial, [
        'This is an automated acknowledgement confirming that we have received your request.',
        'We regret the inconvenience caused. Our support team is currently reviewing the issue and coordinating with the concerned teams for resolution on priority.',
        'Further updates will be shared with you shortly.',
    ]);

    // Use auto-reply account if configured
    $autoReplyAccount = email_smtp_get_auto_reply_account($pdo);
    $accountId = $autoReplyAccount ? (int)$autoReplyAccount['id'] : null;

    return ticket_service_queue_customer_threaded_email($pdo, $customerEmail, $subject, $body, $ticket, $accountId);
}

// Queues the assignee-facing assignment email when an address is available.
function ticket_service_queue_assigned_email(PDO $pdo, array $ticket, ?array $assignee = null): ?int
{
    $assignee = $assignee ?: ticket_service_get_user_by_user_id($pdo, $ticket['assign_to'] ?? null);
    $assigneeEmail = trim((string) ($assignee['email'] ?? ''));
    if ($assigneeEmail === '') {
        return null;
    }

    $ticketSerial = format_ticket_serial($pdo, $ticket);
    $subject = 'Ticket ' . $ticketSerial . ' assigned to you';
    $body = "Hello " . trim((string) ($assignee['name'] ?? 'Team Member')) . ",\n\n"
        . 'A ticket has been assigned to you.' . "\n\n"
        . 'Ticket ID: ' . $ticketSerial . "\n"
        . 'External Ticket ID: ' . (($ticket['external_ticket_id'] ?? '') !== '' ? $ticket['external_ticket_id'] : 'N/A') . "\n"
        . 'Customer: ' . $ticket['customer'] . "\n"
        . 'Issue: ' . $ticket['issue'] . "\n"
        . 'Priority: ' . $ticket['priority'] . "\n"
        . 'Status: ' . $ticket['status'] . "\n";

    // Use auto-reply account if configured
    $autoReplyAccount = email_smtp_get_auto_reply_account($pdo);
    $accountId = $autoReplyAccount ? (int)$autoReplyAccount['id'] : null;

    return email_smtp_queue($pdo, $assigneeEmail, $subject, $body, (int) $ticket['ticket_id'], [], $accountId);
}

// Queues the customer-facing closed email when an address is available.
function ticket_service_queue_closed_email(PDO $pdo, array $ticket): ?int
{
    $customerEmail = trim((string) ($ticket['customer_email'] ?? ''));
    if ($customerEmail === '') {
        return null;
    }

    $ticketSerial = format_ticket_serial($pdo, $ticket);
    $subject = ticket_service_customer_auto_subject($pdo, $ticket);
    $body = ticket_service_customer_auto_html_body($ticketSerial, [
        'This is regarding your support request referenced above.',
        'The necessary corrective actions and route optimizations have been completed from our end for the reported concern.',
        'Kindly perform a fresh test and share your feedback with us. If any further assistance is required, please feel free to contact our support team.',
        'Thank you for your patience and cooperation.',
    ]);

    // Use auto-reply account if configured
    $autoReplyAccount = email_smtp_get_auto_reply_account($pdo);
    $accountId = $autoReplyAccount ? (int)$autoReplyAccount['id'] : null;

    return ticket_service_queue_customer_threaded_email($pdo, $customerEmail, $subject, $body, $ticket, $accountId);
}

// Queues the customer-facing in-progress email when an address is available.
function ticket_service_queue_in_progress_email(PDO $pdo, array $ticket): ?int
{
    $customerEmail = trim((string) ($ticket['customer_email'] ?? ''));
    if ($customerEmail === '') {
        return null;
    }

    $ticketSerial = format_ticket_serial($pdo, $ticket);
    $subject = ticket_service_customer_auto_subject($pdo, $ticket);
    $body = ticket_service_customer_auto_html_body($ticketSerial, [
        'This is regarding your support request referenced above.',
        'Please note that the reported issue is currently under investigation with the concerned operator/technical partner. We are actively following up on the matter and will share further updates shortly.',
        'The issue is being handled on priority. We appreciate your patience and cooperation.',
    ]);

    // Use auto-reply account if configured
    $autoReplyAccount = email_smtp_get_auto_reply_account($pdo);
    $accountId = $autoReplyAccount ? (int)$autoReplyAccount['id'] : null;

    return ticket_service_queue_customer_threaded_email($pdo, $customerEmail, $subject, $body, $ticket, $accountId);
}

// Tries to deliver a queued email right away while preserving the queued fallback.
function ticket_service_attempt_outbox_delivery(PDO $pdo, ?int $outboxId, int $ticketId, string $context): void
{
    if (($outboxId ?? 0) <= 0) {
        return;
    }

    if (email_smtp_process_outbox_item($pdo, (int) $outboxId)) {
        return;
    }

    ticket_log_service_add(
        $pdo,
        $ticketId,
        'email_queued',
        $context . ' email remains queued for retry by the outbox cron.'
    );
}

// Handles all additive automation for a newly created ticket.
function ticket_service_handle_ticket_created(PDO $pdo, array $ticket, bool $sendCustomerAcknowledgement = true): void
{
    $ticketSerial = format_ticket_serial($pdo, $ticket);

    ticket_log_service_add(
        $pdo,
        (int) $ticket['ticket_id'],
        'created',
        'Ticket ' . $ticketSerial . ' created with subject "' . $ticket['issue'] . '".'
    );

    notifications_create_for_users(
        $pdo,
        notifications_active_admin_user_ids($pdo),
        'Ticket created',
        'Ticket ' . $ticketSerial . ' was created for ' . $ticket['customer'] . '.',
        'ticket_created'
    );
    ticket_log_service_add(
        $pdo,
        (int) $ticket['ticket_id'],
        'notification_sent',
        'Creation notification sent to active admin users.'
    );

    if ($sendCustomerAcknowledgement) {
        $outboxId = ticket_service_queue_created_email($pdo, $ticket);
        ticket_log_service_add(
            $pdo,
            (int) $ticket['ticket_id'],
            'email_queued',
            'Ticket-created email queued for ' . ($ticket['customer_email'] ?: 'no customer email') . '.'
        );
        ticket_service_attempt_outbox_delivery($pdo, $outboxId, (int) $ticket['ticket_id'], 'Ticket-created');
    } else {
        ticket_log_service_add(
            $pdo,
            (int) $ticket['ticket_id'],
            'email_skipped',
            'Customer auto-acknowledgement mail disabled at ticket creation.'
        );
    }

     if (ticket_is_assigned($ticket['assign_to'] ?? null)) {
         $assignee = ticket_service_get_user_by_user_id($pdo, $ticket['assign_to']);

         if ($assignee) {
             notifications_create(
                 $pdo,
                 $assignee['user_id'],
                 'Ticket assigned',
                 'Ticket ' . $ticketSerial . ' has been assigned to you.',
                 'assigned'
             );
            ticket_log_service_add(
                $pdo,
                (int) $ticket['ticket_id'],
                'notification_sent',
                'Assignment notification sent to ' . $assignee['user_id'] . '.'
            );
            ticket_service_queue_assigned_email($pdo, $ticket, $assignee);
            ticket_log_service_add(
                $pdo,
                (int) $ticket['ticket_id'],
                'email_queued',
                'Assignment email queued for ' . ($assignee['email'] ?: $assignee['user_id']) . '.'
            );
        }
    }
}

// Handles additive automation after a ticket update by comparing before/after state.
// $sendCustomerEmailsOnUpdate: null => honour ticket.send_auto_acknowledgement from beforeTicket.
// true => force emails on, false => force all customer emails off for this save.
function ticket_service_handle_ticket_updated(
    PDO $pdo,
    array $beforeTicket,
    array $afterTicket,
    ?bool $sendCustomerEmailsOnUpdate = null
): void {
    $storedAutoAck = !empty($beforeTicket['send_auto_acknowledgement']);
    if ($sendCustomerEmailsOnUpdate === true) {
        $autoAck = true;
    } elseif ($sendCustomerEmailsOnUpdate === false) {
        $autoAck = false;
    } else {
        $autoAck = $storedAutoAck;
    }
    $sendCustomerStatusEmails = $sendCustomerEmailsOnUpdate !== false && $autoAck;
    $sendCustomerAcknowledgement = $sendCustomerEmailsOnUpdate === true || ($sendCustomerEmailsOnUpdate === null && $autoAck);
    $afterTicketSerial = format_ticket_serial($pdo, $afterTicket);

    ticket_log_service_add(
        $pdo,
        (int) $afterTicket['ticket_id'],
        'updated',
        'Ticket updated. Status: ' . ($beforeTicket['status'] ?? '-') . ' -> ' . ($afterTicket['status'] ?? '-')
            . '; Assignee: ' . (($beforeTicket['assign_to'] ?? '') ?: 'Unassigned') . ' -> ' . (($afterTicket['assign_to'] ?? '') ?: 'Unassigned')
            . '; auto acknowledge on save=' . ($autoAck ? 'on' : 'off') . '.'
    );

    $beforeAssignee = normalize_assignee($beforeTicket['assign_to'] ?? null);
    $afterAssignee = normalize_assignee($afterTicket['assign_to'] ?? null);

    if ($afterAssignee !== null && $afterAssignee !== $beforeAssignee) {
        $assignee = ticket_service_get_user_by_user_id($pdo, $afterAssignee);

        if ($assignee) {
            notifications_create(
                $pdo,
                $assignee['user_id'],
                'Ticket assigned',
                'Ticket ' . $afterTicketSerial . ' has been assigned to you.',
                'assigned'
            );
            ticket_log_service_add(
                $pdo,
                (int) $afterTicket['ticket_id'],
                'notification_sent',
                'Assignment notification sent to ' . $assignee['user_id'] . '.'
            );
            ticket_service_queue_assigned_email($pdo, $afterTicket, $assignee);
            ticket_log_service_add(
                $pdo,
                (int) $afterTicket['ticket_id'],
                'email_queued',
                'Assignment email queued for ' . ($assignee['email'] ?: $assignee['user_id']) . '.'
            );
        }
    }

    if (($beforeTicket['status'] ?? '') !== 'Closed' && ($afterTicket['status'] ?? '') === 'Closed') {
        $primaryOwner = ($afterTicket['assignee_name'] ?? '') ?: (($afterTicket['assign_to'] ?? '') ?: 'Unassigned');
        notifications_create_for_users(
            $pdo,
            notifications_active_admin_user_ids($pdo),
            'Ticket closed',
            'Ticket ' . $afterTicketSerial . ' has been closed.',
            'closed'
        );
        ticket_log_service_add(
            $pdo,
            (int) $afterTicket['ticket_id'],
            'notification_sent',
            'Close notification sent to active admin users.'
        );
        if ($sendCustomerStatusEmails) {
            $outboxId = ticket_service_queue_closed_email($pdo, $afterTicket);
            ticket_log_service_add(
                $pdo,
                (int) $afterTicket['ticket_id'],
                'closed',
                'Ticket closed by primary owner ' . $primaryOwner . '; close email queued for ' . ($afterTicket['customer_email'] ?: 'no customer email') . '.'
            );
            ticket_service_attempt_outbox_delivery($pdo, $outboxId, (int) $afterTicket['ticket_id'], 'Ticket-closed');
        } elseif ($sendCustomerEmailsOnUpdate === false) {
            ticket_log_service_add(
                $pdo,
                (int) $afterTicket['ticket_id'],
                'email_skipped',
                'Customer close email skipped (auto acknowledgement disabled on update).'
            );
        }
    }

    // Detect status change: Open → In-Progress
    if (($beforeTicket['status'] ?? '') !== 'In-Progress' && ($afterTicket['status'] ?? '') === 'In-Progress') {
        if ($sendCustomerStatusEmails) {
            $outboxId = ticket_service_queue_in_progress_email($pdo, $afterTicket);
            ticket_log_service_add(
                $pdo,
                (int) $afterTicket['ticket_id'],
                'email_queued',
                'In-Progress status email queued for ' . ($afterTicket['customer_email'] ?: 'no customer email') . '.'
            );
            ticket_service_attempt_outbox_delivery($pdo, $outboxId, (int) $afterTicket['ticket_id'], 'Ticket-in-progress');
        } elseif ($sendCustomerEmailsOnUpdate === false) {
            ticket_log_service_add(
                $pdo,
                (int) $afterTicket['ticket_id'],
                'email_skipped',
                'Customer in-progress email skipped (auto acknowledgement disabled on update).'
            );
        }
    }
}

// Returns an array [ticket_id => ['count'=>int, 'names'=>string]] for the given tickets.
function ticket_referral_counts_for_tickets(PDO $pdo, array $ticketIds): array
{
    if (empty($ticketIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ticketIds), '?'));
    $stmt = $pdo->prepare("
        SELECT tr.ticket_id, COUNT(*) as cnt, GROUP_CONCAT(COALESCE(referred.name, tr.referred_user_id) SEPARATOR ', ') AS names
        FROM ticket_referrals tr
        LEFT JOIN users referred ON referred.user_id COLLATE utf8mb4_unicode_ci = tr.referred_user_id
        WHERE tr.ticket_id IN ($placeholders)
        GROUP BY tr.ticket_id
    ");
    $stmt->execute($ticketIds);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [];
    foreach ($rows as $row) {
        $result[(int) $row['ticket_id']] = [
            'count' => (int) $row['cnt'],
            'names' => trim((string) ($row['names'] ?? ''), ', '),
        ];
    }

    return $result;
}
