<?php

require_once __DIR__ . '/email_log_service.php';
require_once __DIR__ . '/party_service.php';

/**
 * Isolated customer vs vendor quick-reply resolution for ticket view → Email Logs compose.
 */
function ticket_quick_reply_normalize_email(string $email): string
{
    $email = trim(strtolower($email));
    if ($email === '') {
        return '';
    }

    if (preg_match('/<([^<>]+)>/', $email, $matches)) {
        $email = trim((string) $matches[1]);
    }

    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

/**
 * @return list<string>
 */
function ticket_quick_reply_collect_party_emails(PDO $pdo, int $partyId): array
{
    $emails = [];
    foreach (party_service_party_emails_ordered($pdo, $partyId) as $row) {
        $normalized = ticket_quick_reply_normalize_email((string) ($row['email'] ?? ''));
        if ($normalized !== '') {
            $emails[$normalized] = $normalized;
        }
    }

    return array_values($emails);
}

/**
 * @return array{
 *   customer_emails: array<string, string>,
 *   vendor_emails: array<string, string>,
 *   initiator_party_id: int,
 *   assigned_vendor_id: int,
 *   customer_email: string
 * }
 */
function ticket_quick_reply_context_for_ticket(PDO $pdo, array $ticket): array
{
    $customerEmails = [];
    $vendorEmails = [];

    $customerEmail = ticket_quick_reply_normalize_email((string) ($ticket['customer_email'] ?? ''));
    if ($customerEmail !== '') {
        $customerEmails[$customerEmail] = $customerEmail;
    }

    $initiatorPartyId = (int) ($ticket['initiator_party_id'] ?? 0);
    $assignedVendorId = (int) ($ticket['assigned_vendor_id'] ?? 0);

    if ($initiatorPartyId > 0) {
        foreach (ticket_quick_reply_collect_party_emails($pdo, $initiatorPartyId) as $email) {
            $customerEmails[$email] = $email;
        }
    }

    if ($assignedVendorId > 0) {
        foreach (ticket_quick_reply_collect_party_emails($pdo, $assignedVendorId) as $email) {
            $vendorEmails[$email] = $email;
        }

        try {
            $mapStmt = $pdo->prepare(
                'SELECT vendor_email
                 FROM vendor_am_mapping
                 WHERE is_active = 1
                 AND (
                    party_id = :party_id
                    OR (party_id IS NULL AND vendor_email <> \'\')
                 )'
            );
            $mapStmt->execute([':party_id' => $assignedVendorId]);
            foreach ($mapStmt->fetchAll(PDO::FETCH_COLUMN) as $mapped) {
                $normalized = ticket_quick_reply_normalize_email((string) $mapped);
                if ($normalized !== '') {
                    $vendorEmails[$normalized] = $normalized;
                }
            }
        } catch (Throwable $ignored) {
            // vendor_am_mapping optional
        }
    }

  // Customer wins on overlap — vendor must never receive customer-classified mail.
    foreach (array_keys($customerEmails) as $email) {
        unset($vendorEmails[$email]);
    }

    return [
        'customer_emails' => $customerEmails,
        'vendor_emails' => $vendorEmails,
        'initiator_party_id' => $initiatorPartyId,
        'assigned_vendor_id' => $assignedVendorId,
        'customer_email' => $customerEmail,
    ];
}

function ticket_quick_reply_flow_is_valid(string $flow): bool
{
    return in_array($flow, ['customer', 'vendor'], true);
}

/**
 * @param array<string, string> $set
 */
function ticket_quick_reply_email_in_set(string $email, array $set): bool
{
    $email = ticket_quick_reply_normalize_email($email);

    return $email !== '' && isset($set[$email]);
}

/**
 * @param array{
 *   customer_emails: array<string, string>,
 *   vendor_emails: array<string, string>,
 *   initiator_party_id: int,
 *   assigned_vendor_id: int
 * } $context
 */
function ticket_quick_reply_classify_message(array $message, array $context): ?string
{
    $vendorPartyId = (int) ($context['assigned_vendor_id'] ?? 0);
    $initiatorPartyId = (int) ($context['initiator_party_id'] ?? 0);
    $partyId = (int) ($message['party_id'] ?? 0);

    if ($partyId > 0 && $vendorPartyId > 0 && $partyId === $vendorPartyId) {
        return 'vendor';
    }
    if ($partyId > 0 && $initiatorPartyId > 0 && $partyId === $initiatorPartyId) {
        return 'customer';
    }

    $direction = (string) ($message['direction'] ?? '');
    if ($direction === 'incoming') {
        $from = ticket_quick_reply_normalize_email((string) ($message['from_email'] ?? ''));
        if ($from === '') {
            return null;
        }
        if (ticket_quick_reply_email_in_set($from, $context['vendor_emails'])) {
            return 'vendor';
        }
        if (ticket_quick_reply_email_in_set($from, $context['customer_emails'])) {
            return 'customer';
        }

        return null;
    }

    if ($direction === 'outgoing') {
        $to = ticket_quick_reply_normalize_email((string) ($message['to_email'] ?? ''));
        if ($to === '') {
            return null;
        }
        if (ticket_quick_reply_email_in_set($to, $context['vendor_emails'])) {
            return 'vendor';
        }
        if (ticket_quick_reply_email_in_set($to, $context['customer_emails'])) {
            return 'customer';
        }

        return null;
    }

    return null;
}

/**
 * @return list<array<string, mixed>>
 */
function ticket_quick_reply_messages_for_ticket(PDO $pdo, int $ticketId, ?array $currentUser): array
{
    if ($ticketId <= 0) {
        return [];
    }

    $filters = ['ticket_id' => $ticketId, 'limit' => 100, 'direction' => 'all', 'flagged' => false];
    $incoming = email_log_service_incoming($pdo, $filters, $currentUser);
    $outgoing = email_log_service_outgoing($pdo, $filters, $currentUser);

    $hasPartyId = email_log_service_outbox_has_column($pdo, 'party_id');
    $partyByOutbox = [];
    if ($hasPartyId && $outgoing !== []) {
        $ids = array_values(array_filter(array_map(static fn (array $row): int => (int) ($row['log_id'] ?? 0), $outgoing)));
        if ($ids !== []) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $partyStmt = $pdo->prepare(
                'SELECT id, party_id FROM email_outbox_log WHERE id IN (' . $placeholders . ')'
            );
            $partyStmt->execute($ids);
            foreach ($partyStmt->fetchAll(PDO::FETCH_ASSOC) as $partyRow) {
                $partyByOutbox[(int) ($partyRow['id'] ?? 0)] = (int) ($partyRow['party_id'] ?? 0);
            }
        }
    }

    $messages = [];

    foreach ($incoming as $email) {
        $occurred = (string) (($email['received_at'] ?? '') ?: ($email['created_at'] ?? ''));
        $messages[] = [
            'direction' => 'incoming',
            'log_id' => (int) ($email['log_id'] ?? 0),
            'from_email' => (string) ($email['from_email'] ?? ''),
            'to_email' => '',
            'party_id' => 0,
            'subject' => (string) ($email['subject'] ?? ''),
            'body' => (string) ($email['body'] ?? ''),
            'message_id' => (string) ($email['message_id'] ?? ''),
            'in_reply_to' => (string) ($email['in_reply_to'] ?? ''),
            'references_header' => (string) ($email['references_header'] ?? ''),
            'parsed_reply_to' => '',
            'occurred_at' => $occurred,
            'time_full' => function_exists('format_date') ? format_date($occurred ?: null) : $occurred,
        ];
    }

    foreach ($outgoing as $email) {
        $logId = (int) ($email['log_id'] ?? 0);
        $occurred = (string) (($email['sent_at'] ?? '') ?: ($email['created_at'] ?? ''));
        $messages[] = [
            'direction' => 'outgoing',
            'log_id' => $logId,
            'from_email' => (string) ($email['from_email'] ?? ''),
            'to_email' => (string) ($email['to_email'] ?? ''),
            'party_id' => $partyByOutbox[$logId] ?? 0,
            'subject' => (string) ($email['subject'] ?? ''),
            'body' => (string) ($email['body'] ?? ''),
            'message_id' => (string) ($email['message_id'] ?? ''),
            'in_reply_to' => (string) ($email['in_reply_to'] ?? ''),
            'references_header' => (string) ($email['references_header'] ?? ''),
            'parsed_reply_to' => '',
            'occurred_at' => $occurred,
            'time_full' => function_exists('format_date') ? format_date($occurred ?: null) : $occurred,
        ];
    }

    usort($messages, static function (array $a, array $b): int {
        $ta = strtotime((string) ($a['occurred_at'] ?? '')) ?: 0;
        $tb = strtotime((string) ($b['occurred_at'] ?? '')) ?: 0;
        if ($ta === $tb) {
            return ((int) ($b['log_id'] ?? 0)) <=> ((int) ($a['log_id'] ?? 0));
        }

        return $tb <=> $ta;
    });

    return $messages;
}

function ticket_quick_reply_strip_re_subject(string $subject): string
{
    $subject = trim($subject);
    while (preg_match('/^\s*re:\s*/i', $subject)) {
        $subject = preg_replace('/^\s*re:\s*/i', '', $subject) ?? $subject;
        $subject = trim($subject);
    }

    return $subject !== '' ? $subject : 'Message';
}

function ticket_quick_reply_format_message_id(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    $value = trim($value, " \t\n\r\0\x0B<>");

    return $value !== '' ? '<' . $value . '>' : '';
}

/**
 * @return list<string>
 */
function ticket_quick_reply_extract_message_ids(?string $references): array
{
    $references = trim((string) $references);
    if ($references === '') {
        return [];
    }

    $ids = [];
    if (preg_match_all('/<([^<>]+)>/', $references, $matches)) {
        foreach ($matches[1] as $match) {
            $formatted = ticket_quick_reply_format_message_id((string) $match);
            if ($formatted !== '') {
                $ids[$formatted] = $formatted;
            }
        }
    }

    return array_values($ids);
}

/**
 * @return array{in_reply_to: string, references_header: string}
 */
function ticket_quick_reply_thread_headers(?array $latestMessage): array
{
    if ($latestMessage === null) {
        return ['in_reply_to' => '', 'references_header' => ''];
    }

    $parentId = ticket_quick_reply_format_message_id((string) ($latestMessage['message_id'] ?? ''));
    if ($parentId === '') {
        return ['in_reply_to' => '', 'references_header' => ''];
    }

    $refs = ticket_quick_reply_extract_message_ids((string) ($latestMessage['references_header'] ?? ''));
    $refs[] = $parentId;

    return [
        'in_reply_to' => $parentId,
        'references_header' => implode(' ', array_values(array_unique($refs))),
    ];
}

function ticket_quick_reply_plain_body(string $htmlOrText): string
{
    $text = trim(html_entity_decode(strip_tags($htmlOrText), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $text = preg_replace("/\r\n?/", "\n", $text) ?? $text;

    return trim((string) $text);
}

function ticket_quick_reply_quote_block(array $message): string
{
    $body = ticket_quick_reply_plain_body((string) ($message['body'] ?? ''));
    $subject = (string) ($message['subject'] ?? '');
    $date = (string) ($message['time_full'] ?? $message['occurred_at'] ?? '');
    $from = (string) ($message['from_email'] ?? $message['to_email'] ?? '');

    $header = 'On ' . ($date !== '' ? $date : 'unknown date');
    if ($from !== '') {
        $header .= ', ' . $from . ' wrote';
    }
    $header .= ':';

    $quoted = $header . "\n";
    foreach (preg_split("/\n/", $body) ?: [] as $line) {
        $quoted .= '> ' . $line . "\n";
    }

    return rtrim($quoted);
}

/**
 * @return array<string, mixed>|null
 */
function ticket_quick_reply_latest_for_flow(
    PDO $pdo,
    int $ticketId,
    ?array $currentUser,
    string $flow,
    array $ticket
): ?array {
    if (!ticket_quick_reply_flow_is_valid($flow)) {
        return null;
    }

    $context = ticket_quick_reply_context_for_ticket($pdo, $ticket);
    $messages = ticket_quick_reply_messages_for_ticket($pdo, $ticketId, $currentUser);

    foreach ($messages as $message) {
        if (ticket_quick_reply_classify_message($message, $context) === $flow) {
            return $message;
        }
    }

    return null;
}

/**
 * @return array{ok: bool, message: string, prefill: array<string, mixed>}
 */
function ticket_quick_reply_build_compose_prefill(
    PDO $pdo,
    int $ticketId,
    ?array $currentUser,
    string $flow,
    array $ticket
): array {
    if (!ticket_quick_reply_flow_is_valid($flow)) {
        return ['ok' => false, 'message' => 'Invalid quick reply type.', 'prefill' => []];
    }

    $context = ticket_quick_reply_context_for_ticket($pdo, $ticket);
    $latest = ticket_quick_reply_latest_for_flow($pdo, $ticketId, $currentUser, $flow, $ticket);

    $replyTo = '';
    $subjectBase = trim((string) ($ticket['issue'] ?? ''));
    $partyId = 0;
    $threadHeaders = ticket_quick_reply_thread_headers($latest);

    if ($flow === 'customer') {
        if ($latest !== null) {
            if (($latest['direction'] ?? '') === 'incoming') {
                $replyTo = ticket_quick_reply_normalize_email((string) ($latest['from_email'] ?? ''));
            } else {
                $replyTo = ticket_quick_reply_normalize_email((string) ($latest['to_email'] ?? ''));
            }
            $subjectBase = ticket_quick_reply_strip_re_subject((string) ($latest['subject'] ?? $subjectBase));
        } else {
            $replyTo = $context['customer_email'];
        }

        if ($replyTo === '' || !ticket_quick_reply_email_in_set($replyTo, $context['customer_emails'])) {
            $fallback = array_values($context['customer_emails']);

            return [
                'ok' => false,
                'message' => $fallback === []
                    ? 'No customer email is available for this ticket.'
                    : 'Could not resolve a safe customer recipient for this reply.',
                'prefill' => [],
            ];
        }

        $partyId = 0;
    } else {
        $vendorPartyId = (int) $context['assigned_vendor_id'];
        if ($vendorPartyId <= 0) {
            return ['ok' => false, 'message' => 'No vendor is assigned to this ticket.', 'prefill' => []];
        }

        if ($latest !== null) {
            if (($latest['direction'] ?? '') === 'incoming') {
                $replyTo = ticket_quick_reply_normalize_email((string) ($latest['from_email'] ?? ''));
            } else {
                $replyTo = ticket_quick_reply_normalize_email((string) ($latest['to_email'] ?? ''));
            }
            $subjectBase = ticket_quick_reply_strip_re_subject((string) ($latest['subject'] ?? $subjectBase));
        } else {
            $vendorList = array_values($context['vendor_emails']);
            $replyTo = $vendorList[0] ?? '';
        }

        if ($replyTo === '' || !ticket_quick_reply_email_in_set($replyTo, $context['vendor_emails'])) {
            return [
                'ok' => false,
                'message' => 'Could not resolve a safe vendor recipient for this reply.',
                'prefill' => [],
            ];
        }

        $partyId = $vendorPartyId;
    }

    return [
        'ok' => true,
        'message' => '',
        'prefill' => [
            'open_compose' => true,
            'to' => $replyTo,
            'subject' => 'Re: ' . ticket_quick_reply_strip_re_subject($subjectBase),
            'ticket_id' => $ticketId,
            'party_id' => $partyId,
            'cc' => '',
            'quoted_plain' => '',
            'quote_kind' => 'reply',
            'quick_reply_flow' => $flow,
            'compose_in_reply_to' => (string) ($threadHeaders['in_reply_to'] ?? ''),
            'compose_references_header' => (string) ($threadHeaders['references_header'] ?? ''),
        ],
    ];
}

/**
 * @param list<string> $ccEmails
 * @return array{ok: bool, message: string, party_id: int, cc_emails: list<string>}
 */
function ticket_quick_reply_validate_outgoing(
    PDO $pdo,
    int $ticketId,
    string $flow,
    string $toEmail,
    array $ccEmails,
    int $partyId,
    array $ticket
): array {
    if ($flow === '') {
        return ['ok' => true, 'message' => '', 'party_id' => $partyId, 'cc_emails' => $ccEmails];
    }

    if (!ticket_quick_reply_flow_is_valid($flow)) {
        return ['ok' => false, 'message' => 'Invalid quick reply channel.', 'party_id' => $partyId, 'cc_emails' => $ccEmails];
    }

    $context = ticket_quick_reply_context_for_ticket($pdo, $ticket);
    $toEmail = ticket_quick_reply_normalize_email($toEmail);

    if ($flow === 'customer') {
        if (!ticket_quick_reply_email_in_set($toEmail, $context['customer_emails'])) {
            return [
                'ok' => false,
                'message' => 'Recipient must be a customer contact for this ticket. Vendor addresses are not allowed.',
                'party_id' => 0,
                'cc_emails' => [],
            ];
        }

        $safeCc = [];
        foreach ($ccEmails as $cc) {
            $normalized = ticket_quick_reply_normalize_email((string) $cc);
            if ($normalized === '') {
                continue;
            }
            if (ticket_quick_reply_email_in_set($normalized, $context['vendor_emails'])) {
                return [
                    'ok' => false,
                    'message' => 'Vendor contacts cannot be copied on a customer reply.',
                    'party_id' => 0,
                    'cc_emails' => [],
                ];
            }
            if (ticket_quick_reply_email_in_set($normalized, $context['customer_emails'])) {
                $safeCc[] = $normalized;
            }
        }

        return ['ok' => true, 'message' => '', 'party_id' => 0, 'cc_emails' => $safeCc];
    }

    $vendorPartyId = (int) $context['assigned_vendor_id'];
    if ($vendorPartyId <= 0) {
        return ['ok' => false, 'message' => 'No vendor is assigned to this ticket.', 'party_id' => 0, 'cc_emails' => []];
    }

    if (!ticket_quick_reply_email_in_set($toEmail, $context['vendor_emails'])) {
        return [
            'ok' => false,
            'message' => 'Recipient must be a vendor contact for this ticket. Customer addresses are not allowed.',
            'party_id' => $vendorPartyId,
            'cc_emails' => [],
        ];
    }

    $safeCc = [];
    foreach ($ccEmails as $cc) {
        $normalized = ticket_quick_reply_normalize_email((string) $cc);
        if ($normalized === '') {
            continue;
        }
        if (ticket_quick_reply_email_in_set($normalized, $context['customer_emails'])
            && !ticket_quick_reply_email_in_set($normalized, $context['vendor_emails'])) {
            return [
                'ok' => false,
                'message' => 'Customer contacts cannot be copied on a vendor reply.',
                'party_id' => $vendorPartyId,
                'cc_emails' => [],
            ];
        }
        $safeCc[] = $normalized;
    }

    return ['ok' => true, 'message' => '', 'party_id' => $vendorPartyId, 'cc_emails' => $safeCc];
}
