<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../modules/notifications/notification_service.php';
require_once __DIR__ . '/../modules/email/imap_service.php';
require_once __DIR__ . '/../modules/email/smtp_service.php';
require_once __DIR__ . '/../services/ticket_log_service.php';

notifications_ensure_table($pdo);
ticket_log_service_ensure_table($pdo);

$mailPollLimit = max(0, (int) env_value('MAIL_POLL_LIMIT', 25));

$imapSummary = email_imap_import_messages($pdo, $mailPollLimit, 0, true); // respect cron_enabled
$smtpSummary = email_smtp_process_outbox($pdo, $mailPollLimit > 0 ? $mailPollLimit : 25);

echo 'Mail cycle completed at ' . date('Y-m-d H:i:s') . PHP_EOL;
echo 'IMAP Accounts: ' . $imapSummary['accounts'] . PHP_EOL;
echo 'IMAP Messages: ' . $imapSummary['messages'] . PHP_EOL;
echo 'IMAP Created: ' . $imapSummary['created'] . PHP_EOL;
echo 'IMAP Unmapped: ' . ($imapSummary['unmapped'] ?? 0) . PHP_EOL;
echo 'IMAP Unknown: ' . ($imapSummary['unknown'] ?? 0) . PHP_EOL;
echo 'IMAP Ignored: ' . $imapSummary['ignored'] . PHP_EOL;
echo 'IMAP Duplicates: ' . $imapSummary['duplicates'] . PHP_EOL;
echo 'IMAP Baseline Initialized: ' . $imapSummary['baseline_initialized'] . PHP_EOL;
echo 'IMAP Failed: ' . $imapSummary['failed'] . PHP_EOL;
echo 'SMTP Processed: ' . $smtpSummary['processed'] . PHP_EOL;
echo 'SMTP Sent: ' . $smtpSummary['sent'] . PHP_EOL;
echo 'SMTP Failed: ' . $smtpSummary['failed'] . PHP_EOL;
