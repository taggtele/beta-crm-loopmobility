<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../modules/email/smtp_service.php';

$ccSelect = email_smtp_outbox_has_column($pdo, 'cc_email') ? 'cc_email' : 'NULL AS cc_email';
$accountSelect = email_smtp_outbox_has_column($pdo, 'email_account_id') ? 'email_account_id' : 'NULL AS email_account_id';
$bodyIsHtmlSelect = email_smtp_outbox_has_column($pdo, 'body_is_html') ? 'body_is_html' : '0 AS body_is_html';
$row = $pdo->query('SELECT id, ticket_id, ' . $accountSelect . ', to_email, ' . $ccSelect . ', subject, body, ' . $bodyIsHtmlSelect . ' FROM email_outbox_log WHERE status = "pending" ORDER BY id ASC LIMIT 1')->fetch();

if (!$row) {
    echo "No pending emails\n";
    exit;
}

echo "Sending to: " . $row['to_email'] . "\n";

try {
    $account = !empty($row['email_account_id'])
        ? email_smtp_active_account($pdo, (int) $row['email_account_id'])
        : email_smtp_active_account($pdo);
    if (!$account) {
        echo "No active SMTP account\n";
        exit;
    }

    $ccEmails = email_smtp_parse_recipient_list((string) ($row['cc_email'] ?? ''))['valid'];
    $attachments = email_smtp_load_outbox_attachments($pdo, (int) $row['id']);
    email_smtp_send_message($account, $row['to_email'], $row['subject'], $row['body'], $attachments, $ccEmails, null, '', '', !empty($row['body_is_html']));
    
    $stmt = $pdo->prepare('UPDATE email_outbox_log SET status = "sent", sent_at = NOW() WHERE id = :id');
    $stmt->execute([':id' => $row['id']]);
    
    echo "SENT! ID: " . $row['id'] . "\n";
} catch (Throwable $e) {
    $stmt = $pdo->prepare('UPDATE email_outbox_log SET status = "failed", error_message = :error WHERE id = :id');
    $stmt->execute([':error' => $e->getMessage(), ':id' => $row['id']]);
    echo "FAILED: " . $e->getMessage() . "\n";
}
