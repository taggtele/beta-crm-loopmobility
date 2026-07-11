<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../modules/email/smtp_service.php';

$summary = email_smtp_process_outbox($pdo, 25);

echo 'Processed: ' . $summary['processed'] . PHP_EOL;
echo 'Sent: ' . $summary['sent'] . PHP_EOL;
echo 'Failed: ' . $summary['failed'] . PHP_EOL;
