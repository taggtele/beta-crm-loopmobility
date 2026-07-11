<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/bootstrap.php';
require_once dirname(__DIR__) . '/middleware/require_party_account_access.php';

$currentUser = require_login($pdo);
party_account_middleware_gate_manage($currentUser);
party_account_ensure_schema($pdo);

try {
    if (!party_account_is_xhr()) {
        party_account_json_exit(['ok' => false, 'error' => 'xhr_only'], 400);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        party_account_json_exit(['ok' => false, 'error' => 'method_not_allowed'], 405);
    }

    $body = party_account_read_json_body();
    party_account_require_csrf(isset($body['csrf_token']) ? (string) $body['csrf_token'] : null);

    $action = (string) ($body['action'] ?? '');
    $idsRaw = $body['ids'] ?? [];
    $ids = is_array($idsRaw)
        ? array_values(array_unique(array_filter(array_map(static fn ($v) => (int) $v, $idsRaw))))
        : [];

    if ($ids === []) {
        party_account_json_exit(['ok' => false, 'error' => 'nothing_selected'], 400);
    }

    $repo = new PartyAccountRepository($pdo);
    $audit = new PartyAccountActivityLogService($pdo);

    $service = new PartyAccountService($pdo, $repo, $audit);

    if ($action === 'bulk_delete') {
        $affected = $service->bulkSoftDelete($ids, $currentUser);
        if ($affected < 1) {
            party_account_json_exit([
                'ok' => false,
                'error' => 'no_change',
                'message' => 'No live accounts were archived. They may already be archived.',
                'affected' => 0,
                'csrf_token' => csrf_token(),
            ], 400);
        }
        party_account_json_exit(['ok' => true, 'affected' => $affected, 'csrf_token' => csrf_token()]);
    }

    if ($action === 'bulk_restore') {
        $affected = $service->bulkRestore($ids, $currentUser);
        if ($affected < 1) {
            party_account_json_exit([
                'ok' => false,
                'error' => 'no_change',
                'message' => 'No archived accounts were restored. They may already be live.',
                'affected' => 0,
                'csrf_token' => csrf_token(),
            ], 400);
        }
        party_account_json_exit(['ok' => true, 'affected' => $affected, 'csrf_token' => csrf_token()]);
    }

    party_account_json_exit(['ok' => false, 'error' => 'unknown_action'], 400);
} catch (RuntimeException $e) {
    $isCsrf = stripos($e->getMessage(), 'csrf') !== false;
    party_account_json_exit(
        ['ok' => false, 'error' => $isCsrf ? 'csrf' : $e->getMessage()],
        $isCsrf ? 419 : 400
    );
}
