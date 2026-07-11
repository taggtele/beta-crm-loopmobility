<?php
require_once __DIR__ . '/../modules/notifications/notification_service.php';

function notification_ui_service_decode_meta(array $notification): array
{
    $raw = (string) ($notification['meta_json'] ?? '');
    if ($raw === '') {
        return [];
    }

    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $ignored) {
        return [];
    }

    return is_array($decoded) ? $decoded : [];
}

function notification_ui_service_append_open_params(string $url, int $inboxLogId, string $direction = 'incoming'): string
{
    if ($inboxLogId <= 0 || $url === '') {
        return $url;
    }

    $parts = parse_url($url);
    if ($parts === false) {
        return $url;
    }

    $query = [];
    if (!empty($parts['query'])) {
        parse_str((string) $parts['query'], $query);
    }

    $query['open_log_id'] = $inboxLogId;
    $query['open_direction'] = $direction !== '' ? $direction : 'incoming';
    if (!isset($query['direction'])) {
        $query['direction'] = 'incoming';
    }

    $path = (string) ($parts['path'] ?? '');
    $rebuilt = $path . '?' . http_build_query($query);
    if (!empty($parts['fragment'])) {
        $rebuilt .= '#' . $parts['fragment'];
    }

    return $rebuilt;
}

function notification_ui_service_email_logs_link(array $context): string
{
    $params = ['direction' => 'incoming', 'limit' => 50];

    $ticketId = max(0, (int) ($context['ticket_id'] ?? 0));
    $inboxLogId = max(0, (int) ($context['inbox_log_id'] ?? 0));
    $status = trim((string) ($context['mail_status'] ?? ''));

    if ($ticketId > 0) {
        $params['ticket_id'] = $ticketId;
    }

    if ($inboxLogId > 0) {
        $params['open_log_id'] = $inboxLogId;
        $params['open_direction'] = 'incoming';
    }

    if ($status !== '' && in_array($status, ['unmapped', 'unknown', 'ignored', 'received', 'pending'], true)) {
        $params['status'] = $status;
    } elseif ($ticketId <= 0 && $inboxLogId > 0) {
        $params['status'] = '';
    }

    return url('emails/logs.php?' . http_build_query($params));
}

function notification_ui_service_link(array $notification): string
{
    $inboxLogId = max(0, (int) ($notification['inbox_log_id'] ?? 0));
    $meta = notification_ui_service_decode_meta($notification);
    if ($inboxLogId <= 0) {
        $inboxLogId = max(0, (int) ($meta['inbox_log_id'] ?? 0));
    }

    $stored = trim((string) ($notification['link_url'] ?? ''));
    if ($stored !== '') {
        if ($inboxLogId > 0 && !str_contains($stored, 'open_log_id=')) {
            return notification_ui_service_append_open_params($stored, $inboxLogId, 'incoming');
        }

        return $stored;
    }

    if (!empty($meta['inbox_log_id']) || !empty($meta['ticket_id'])) {
        return notification_ui_service_email_logs_link($meta);
    }

    $ticketId = max(0, (int) ($notification['ticket_id'] ?? 0));
    if ($ticketId > 0) {
        return url('tickets/view.php?id=' . $ticketId);
    }

    $message = (string) ($notification['message'] ?? '');
    if (preg_match('/#(\d+)/', $message, $matches)) {
        return url('tickets/view.php?id=' . (int) $matches[1]);
    }

    if (preg_match('/\bLM-\d{8}-\d{2}\b/i', $message)) {
        return url('emails/logs.php?direction=incoming&limit=50');
    }

    $type = strtolower(trim((string) ($notification['type'] ?? '')));
    if (str_starts_with($type, 'email_')) {
        return url('emails/logs.php?direction=incoming&limit=50');
    }

    return url('tickets/list.php');
}

function notification_ui_service_type_icon(string $type): string
{
    return match (strtolower(trim($type))) {
        'email_reply' => 'reply',
        'email_received' => 'mail',
        'email_unmapped' => 'alert',
        'email_unknown' => 'help',
        'ticket_created' => 'ticket',
        'ticket_assigned' => 'assign',
        'ticket_closed' => 'done',
        'ticket_referred' => 'share',
        default => 'bell',
    };
}

function notification_ui_service_relative_time(?string $createdAt): string
{
    $timestamp = $createdAt ? strtotime($createdAt) : false;
    if ($timestamp === false) {
        return '';
    }

    $diff = time() - $timestamp;
    if ($diff < 45) {
        return 'Just now';
    }
    if ($diff < 3600) {
        return max(1, (int) floor($diff / 60)) . 'm ago';
    }
    if ($diff < 86400) {
        return max(1, (int) floor($diff / 3600)) . 'h ago';
    }
    if ($diff < 604800) {
        return max(1, (int) floor($diff / 86400)) . 'd ago';
    }

    return date('d M, h:i A', $timestamp);
}

function notification_ui_service_display(array $notification): array
{
    $meta = notification_ui_service_decode_meta($notification);
    $senderName = trim((string) ($meta['sender_name'] ?? ''));
    $senderEmail = trim((string) ($meta['sender_email'] ?? ''));
    $subject = trim((string) ($meta['subject'] ?? ''));
    $snippet = trim((string) ($meta['snippet'] ?? ''));

    if ($senderName === '' && $senderEmail !== '') {
        $senderName = $senderEmail;
    }
    if ($senderName === '') {
        $senderName = trim((string) ($notification['title'] ?? 'Notification'));
    }

    if ($subject === '') {
        $subject = trim((string) ($notification['title'] ?? ''));
    }

    if ($snippet === '') {
        $snippet = trim((string) ($notification['message'] ?? ''));
    }

    if (mb_strlen($snippet) > 160) {
        $snippet = rtrim(mb_substr($snippet, 0, 157)) . '…';
    }

    return [
        'sender_name' => $senderName,
        'sender_email' => $senderEmail,
        'subject' => $subject,
        'snippet' => $snippet,
        'has_attachment' => !empty($meta['has_attachment']),
        'time_label' => notification_ui_service_relative_time((string) ($notification['created_at'] ?? '')),
        'time_full' => !empty($notification['created_at']) ? date('d M Y, h:i A', strtotime((string) $notification['created_at'])) : '',
        'icon' => notification_ui_service_type_icon((string) ($notification['type'] ?? '')),
        'type_label' => ucwords(str_replace('_', ' ', strtolower(trim((string) ($notification['type'] ?? ''))))),
    ];
}

// Renders the notification dropdown item list as HTML for SSR and SSE refreshes.
function notification_ui_service_render_items(array $notifications): string
{
    ob_start();
    include __DIR__ . '/../views/notifications/dropdown_items.php';

    return ob_get_clean();
}
