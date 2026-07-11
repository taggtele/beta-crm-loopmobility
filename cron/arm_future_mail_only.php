<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../modules/email/imap_service.php';

$accountId = 1;
$enableAfterArm = false;

foreach ($argv as $argument) {
    if (str_starts_with($argument, '--account=')) {
        $accountId = (int) substr($argument, strlen('--account='));
    }

    if ($argument === '--enable') {
        $enableAfterArm = true;
    }
}

try {
    $result = email_imap_arm_future_only_baseline($pdo, $accountId);

    if ($enableAfterArm) {
        email_imap_set_account_active($pdo, $accountId, true);
    }

    echo 'Armed Account: ' . $result['account_id'] . PHP_EOL;
    echo 'Email: ' . $result['email'] . PHP_EOL;
    echo 'Highest UID: ' . $result['highest_uid'] . PHP_EOL;
    echo 'Messages In Inbox: ' . $result['message_count'] . PHP_EOL;
    echo 'Armed At: ' . $result['armed_at'] . PHP_EOL;
    echo 'Enabled After Arm: ' . ($enableAfterArm ? 'Yes' : 'No') . PHP_EOL;
} catch (Throwable $throwable) {
    fwrite(STDERR, 'Arm failed: ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}
