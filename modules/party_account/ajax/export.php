<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/bootstrap.php';
require_once dirname(__DIR__) . '/middleware/require_party_account_access.php';
require_once dirname(__DIR__, 3) . '/system_logs/log_helper.php';

$currentUser = require_login($pdo);
party_account_middleware_gate_view($currentUser);
party_account_ensure_schema($pdo);

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo 'POST only.';
        exit;
    }

    if (!party_account_is_xhr()) {
        echo 'XHR only.';
        exit;
    }

    $body = party_account_read_json_body();
    party_account_require_csrf(isset($body['csrf_token']) ? (string) $body['csrf_token'] : null);

    $filters = is_array($body['filters'] ?? null) ? $body['filters'] : [];

    $filename = sprintf('party-accounts-export-%s.csv', date('Ymd-His'));

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');

    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');
    if (!$out) {
        throw new RuntimeException('Unable to initialize export stream.');
    }

    $headersRow = [
        'ID',
        'Party Name',
        'Party Email',
        'Phone',
        'Country',
        'Loop Entity',
        'Assistant Manager',
        'Business Manager',
        'Credit Limit',
        'Currency',
        'Payment Terms',
        'Bank Name',
        'Account Holder',
        'Account Number',
        'IFSC / SWIFT',
        'IBAN',
        'Status',
        'Archived (Y/N)',
        'Created At',
        'Updated At',
        'Notes',
    ];

    fputcsv($out, $headersRow);

    $repo = new PartyAccountRepository($pdo);
    $exportCount = 0;
    foreach ($repo->iterateForExport($filters) as $row) {
        $isArchived = $row['deleted_at'] ?? null ? 'Y' : 'N';
        $exportCount++;

        fputcsv($out, [
            (int) $row['id'],
            (string) $row['party_name'],
            (string) ($row['party_email'] ?? ''),
            (string) ($row['party_phone'] ?? ''),
            (string) ($row['country'] ?? ''),
            (string) ($row['loop_entity_name'] ?? ''),
            (string) ($row['assistant_manager_name'] ?? ''),
            (string) ($row['business_manager_name'] ?? ''),
            (string) ($row['credit_limit'] ?? ''),
            (string) ($row['currency'] ?? ''),
            (string) ($row['payment_terms'] ?? ''),
            (string) ($row['bank_name'] ?? ''),
            (string) ($row['account_holder_name'] ?? ''),
            (string) ($row['account_number'] ?? ''),
            (string) ($row['ifsc_swift_code'] ?? ''),
            (string) ($row['iban_number'] ?? ''),
            (string) ($row['status'] ?? ''),
            $isArchived,
            (string) ($row['created_at'] ?? ''),
            (string) ($row['updated_at'] ?? ''),
            (string) ($row['notes'] ?? ''),
        ]);
    }

    fclose($out);

    $userId = isset($currentUser['user_id']) && $currentUser['user_id'] !== '' ? (int) $currentUser['user_id'] : null;
    $userName = $currentUser['name'] ?? $currentUser['user_id'] ?? null;
    $filtersSnapshot = is_array($filters) && !empty($filters) ? $filters : null;
    [$browser, $device, $os] = system_logs_parse_user_agent();

    log_export_activity(
        $pdo,
        $userId,
        $userName,
        'Party Account',
        'Party Accounts',
        'EXPORT',
        'CSV',
        $exportCount,
        $filtersSnapshot,
        'SUCCESS',
        null,
        $browser,
        $device,
        $os
    );

    exit;
} catch (Throwable $e) {
    $userId = isset($currentUser['user_id']) && $currentUser['user_id'] !== '' ? (int) $currentUser['user_id'] : null;
    $userName = $currentUser['name'] ?? $currentUser['user_id'] ?? null;
    [$browser, $device, $os] = system_logs_parse_user_agent();

    log_export_activity(
        $pdo,
        $userId,
        $userName,
        'Party Account',
        'Party Accounts',
        'EXPORT',
        'CSV',
        0,
        null,
        'FAILED',
        $e->getMessage(),
        $browser,
        $device,
        $os
    );

    http_response_code(500);
    echo 'Export failed.';
    exit;
}
