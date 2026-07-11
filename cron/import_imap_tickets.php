<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../modules/notifications/notification_service.php';
require_once __DIR__ . '/../modules/email/imap_service.php';
require_once __DIR__ . '/../services/ticket_log_service.php';

notifications_ensure_table($pdo);
ticket_log_service_ensure_table($pdo);

$mailPollLimit = max(0, (int) env_value('MAIL_POLL_LIMIT', 25));
$summary = email_imap_import_messages($pdo, $mailPollLimit, 0, true); // true = respect cron_enabled filter

echo 'Accounts: ' . $summary['accounts'] . PHP_EOL;
echo 'Messages: ' . $summary['messages'] . PHP_EOL;
echo 'Created: ' . $summary['created'] . PHP_EOL;
echo 'Replied: ' . ($summary['replied'] ?? 0) . PHP_EOL;
echo 'Unmapped: ' . ($summary['unmapped'] ?? 0) . PHP_EOL;
echo 'Unknown: ' . ($summary['unknown'] ?? 0) . PHP_EOL;
echo 'Ignored: ' . $summary['ignored'] . PHP_EOL;
echo 'Duplicates: ' . $summary['duplicates'] . PHP_EOL;
echo 'Baseline Initialized: ' . $summary['baseline_initialized'] . PHP_EOL;
echo 'Failed: ' . $summary['failed'] . PHP_EOL;
