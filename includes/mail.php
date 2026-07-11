<?php
require_once __DIR__ . '/../modules/email/smtp_service.php';
require_once __DIR__ . '/../modules/tickets/ticket_service.php';

// Queues the ticket-created email without blocking the current request.
function queue_ticket_created_email(PDO $pdo, array $ticket, bool $autoAck = true): ?int
{
    if (!$autoAck) {
        return null;
    }

    return ticket_service_queue_created_email($pdo, $ticket);
}

// Queues the ticket-assigned email without blocking the current request.
function queue_ticket_assigned_email(PDO $pdo, array $ticket, ?array $assignee = null): ?int
{
    return ticket_service_queue_assigned_email($pdo, $ticket, $assignee);
}

// Queues the ticket-closed email without blocking the current request.
function queue_ticket_closed_email(PDO $pdo, array $ticket): ?int
{
    return ticket_service_queue_closed_email($pdo, $ticket);
}

// Processes the pending email outbox queue.
function process_pending_mail_queue(PDO $pdo, int $limit = 25): array
{
    return email_smtp_process_outbox($pdo, $limit);
}
