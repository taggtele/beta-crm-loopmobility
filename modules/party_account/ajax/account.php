<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/bootstrap.php';
require_once dirname(__DIR__) . '/middleware/require_party_account_access.php';

$currentUser = require_login($pdo);
party_account_middleware_gate_view($currentUser);
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
    $repo = new PartyAccountRepository($pdo);
    $audit = new PartyAccountActivityLogService($pdo);

    if ($action === 'detail') {
        $id = (int) ($body['id'] ?? 0);
        if ($id <= 0) {
            party_account_json_exit(['ok' => false, 'error' => 'invalid_id'], 400);
        }

        $includeTrash = ($body['include_deleted'] ?? false) === true;
        $row = $repo->findById($id, true);

        if (!$row || (!$includeTrash && $row['deleted_at'] !== null)) {
            party_account_json_exit(['ok' => false, 'error' => 'not_found'], 404);
        }

        $timeline = $audit->forParty($id);
        $row['additional_emails'] = party_account_emails_additional($pdo, $id);

        $currencyLedgers = [];
        if (!empty($row['is_multi_currency'])) {
            $currencyLedgers = $repo->findCurrencyLedgers($id);
        }

        party_account_json_exit([
            'ok' => true,
            'record' => $row,
            'currency_ledgers' => $currencyLedgers,
            'timeline' => $timeline,
            'masked_account_number' => party_account_mask_account_number((string) ($row['account_number'] ?? '')),
            'csrf_token' => csrf_token(),
        ]);
    }

    $mutating = in_array($action, ['create', 'update', 'delete', 'restore'], true);
    if ($mutating) {
        party_account_middleware_gate_manage($currentUser);
    }

    $service = new PartyAccountService($pdo, $repo, $audit);

    switch ($action) {
        case 'create':
            $newId = $service->create($body['payload'] ?? [], $currentUser);
            party_account_json_exit(['ok' => true, 'id' => $newId, 'csrf_token' => csrf_token()]);

        case 'update':
            $id = (int) ($body['id'] ?? 0);
            $service->update($id, $body['payload'] ?? [], $currentUser);
            party_account_json_exit(['ok' => true, 'id' => $id, 'csrf_token' => csrf_token()]);

        case 'delete':
            $id = (int) ($body['id'] ?? 0);
            if ($id <= 0) {
                party_account_json_exit(['ok' => false, 'error' => 'invalid_id'], 400);
            }
            if (!$service->softDelete($id, $currentUser)) {
                party_account_json_exit([
                    'ok' => false,
                    'error' => 'no_change',
                    'message' => 'Account is already archived or not found.',
                ], 400);
            }

            party_account_json_exit(['ok' => true, 'csrf_token' => csrf_token()]);

        case 'restore':
            $id = (int) ($body['id'] ?? 0);
            if ($id <= 0) {
                party_account_json_exit(['ok' => false, 'error' => 'invalid_id'], 400);
            }
            if (!$service->restore($id, $currentUser)) {
                party_account_json_exit([
                    'ok' => false,
                    'error' => 'no_change',
                    'message' => 'Account is already live or not found.',
                ], 400);
            }

            party_account_json_exit(['ok' => true, 'csrf_token' => csrf_token()]);

        default:
            party_account_json_exit(['ok' => false, 'error' => 'unknown_action'], 400);
    }
} catch (RuntimeException $e) {
    $payload = json_decode($e->getMessage(), true);

    if (is_array($payload) && isset($payload['errors'])) {
        party_account_json_exit([
            'ok' => false,
            'validation' => true,
            'errors' => $payload['errors'],
            'csrf_token' => csrf_token(),
        ], 422);
    }

    if (stripos($e->getMessage(), 'csrf') !== false || stripos($e->getMessage(), 'csrf') !== false) {
        party_account_json_exit(['ok' => false, 'error' => 'csrf'], 419);
    }

    party_account_json_exit(['ok' => false, 'error' => $e->getMessage()], 400);
}
