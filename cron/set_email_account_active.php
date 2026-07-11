<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../modules/email/imap_service.php';

$accountId = 1;
$enabled = false;

foreach ($argv as $argument) {
    if (str_starts_with($argument, '--account=')) {
        $accountId = (int) substr($argument, strlen('--account='));
    }

    if ($argument === '--enable') {
        $enabled = true;
    }

    if ($argument === '--disable') {
        $enabled = false;
    }
}

email_imap_set_account_active($pdo, $accountId, $enabled);

$account = email_imap_account_by_id($pdo, $accountId, true);
if (!$account) {
    fwrite(STDERR, 'Account not found.' . PHP_EOL);
    exit(1);
}

echo 'Account: ' . $account['id'] . PHP_EOL;
echo 'Email: ' . $account['email'] . PHP_EOL;
echo 'Active: ' . ((int) ($account['is_active'] ?? 0) === 1 ? 'Yes' : 'No') . PHP_EOL;
