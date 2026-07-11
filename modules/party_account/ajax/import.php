<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/bootstrap.php';
require_once dirname(__DIR__) . '/middleware/require_party_account_access.php';

$currentUser = require_login($pdo);
party_account_middleware_gate_view($currentUser);
party_account_ensure_schema($pdo);
party_account_ensure_import_logs_table($pdo);

try {
    $action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');

    if ($action === 'template') {
        party_account_middleware_gate_manage($currentUser);
        party_account_require_csrf((string) ($_POST['csrf_token'] ?? $_GET['csrf_token'] ?? ''));
        party_account_import_stream_template();
        exit;
    }

    if (!party_account_is_xhr()) {
        party_account_json_exit(['ok' => false, 'error' => 'xhr_only'], 400);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        party_account_json_exit(['ok' => false, 'error' => 'method_not_allowed'], 405);
    }

    party_account_require_csrf((string) ($_POST['csrf_token'] ?? ''));

    $repo = new PartyAccountRepository($pdo);
    $audit = new PartyAccountActivityLogService($pdo);
    $partyService = new PartyAccountService($pdo, $repo, $audit);
    $importService = new PartyAccountImportService($pdo, $repo, $partyService);

    if ($action === 'history') {
        party_account_json_exit([
            'ok' => true,
            'logs' => $importService->recentLogs(20),
            'csrf_token' => csrf_token(),
        ]);
    }

    party_account_middleware_gate_manage($currentUser);

    if ($action === 'preview' || $action === 'import') {
        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
            party_account_json_exit(['ok' => false, 'error' => 'no_file', 'message' => 'Choose a .csv or .xlsx file.'], 400);
        }

        $file = $_FILES['file'];
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            party_account_json_exit(['ok' => false, 'error' => 'upload_failed', 'message' => 'File upload failed.'], 400);
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        $name = (string) ($file['name'] ?? 'import.csv');
        $size = (int) ($file['size'] ?? 0);
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            party_account_json_exit(['ok' => false, 'error' => 'invalid_upload'], 400);
        }
        if ($size > 5 * 1024 * 1024) {
            party_account_json_exit(['ok' => false, 'error' => 'file_too_large', 'message' => 'Max file size is 5 MB.'], 400);
        }

        if ($action === 'preview') {
            $preview = $importService->preview($tmp, $name);
            party_account_json_exit([
                'ok' => true,
                'preview' => $preview,
                'can_import' => $preview['total_rows'] > 0
                    && ($preview['errors'] === [] || $preview['ready'] !== []),
                'csrf_token' => csrf_token(),
            ]);
        }

        $skipDuplicates = ($_POST['skip_duplicates'] ?? '1') !== '0';
        $result = $importService->runImport($tmp, $name, $currentUser, $skipDuplicates);

        $audit->log(
            null,
            $currentUser,
            'imported',
            sprintf(
                'Bulk import %s: %u ok, %u skipped, %u failed',
                $name,
                $result['success_count'],
                $result['skipped_count'],
                $result['failed_count']
            ),
            ['log_id' => $result['log_id']]
        );

        party_account_json_exit([
            'ok' => true,
            'result' => $result,
            'csrf_token' => csrf_token(),
        ]);
    }

    party_account_json_exit(['ok' => false, 'error' => 'unknown_action'], 400);
} catch (InvalidArgumentException $e) {
    party_account_json_exit(['ok' => false, 'error' => 'invalid_file', 'message' => $e->getMessage()], 400);
} catch (Throwable $e) {
    if (app_debug_enabled()) {
        party_account_json_exit(['ok' => false, 'error' => 'import_failed', 'message' => $e->getMessage()], 500);
    }

    party_account_json_exit(['ok' => false, 'error' => 'import_failed', 'message' => 'Import failed. Check file format and try again.'], 500);
}
