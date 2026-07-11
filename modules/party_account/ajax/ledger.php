<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/bootstrap.php';
require_once dirname(__DIR__) . '/middleware/require_party_account_access.php';

$currentUser = require_login($pdo);
party_account_middleware_gate_ledger($currentUser);
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

    $action = (string) ($body['action'] ?? 'list');
    $service = new PartyLedgerService($pdo, new PartyAccountActivityLogService($pdo));

    switch ($action) {
        case 'list':
            party_account_json_exit($service->ledgerRecords(is_array($body['filters'] ?? null) ? $body['filters'] : []) + [
                'ok' => true,
                'csrf_token' => csrf_token(),
            ]);

        case 'detail':
            $partyId = (int) ($body['party_id'] ?? 0);
            $currency = (string) ($body['currency'] ?? '');
            party_account_json_exit($service->ledger($partyId, $currency, $body['from'] ?? null, $body['to'] ?? null) + [
                'ok' => true,
                'months' => $service->monthlySummary($partyId, $currency),
                'csrf_token' => csrf_token(),
            ]);

        case 'save':
            $payload = is_array($body['payload'] ?? null) ? $body['payload'] : [];
            $id = $service->saveTransaction($payload, $currentUser, (string) ($body['expected_currency'] ?? ''));
            party_account_json_exit(['ok' => true, 'id' => $id, 'csrf_token' => csrf_token()]);

        case 'delete':
            $service->deleteTransaction((int) ($body['id'] ?? 0), $currentUser, (string) ($body['expected_currency'] ?? ''));
            party_account_json_exit(['ok' => true, 'csrf_token' => csrf_token()]);

        case 'close_month':
            $service->closeMonth((int) ($body['party_id'] ?? 0), (string) ($body['currency'] ?? ''), (string) ($body['period'] ?? ''), $currentUser);
            party_account_json_exit(['ok' => true, 'csrf_token' => csrf_token()]);

        case 'reopen_month':
            party_account_middleware_gate_ledger_admin($currentUser);
            $service->reopenMonth((int) ($body['party_id'] ?? 0), (string) ($body['currency'] ?? ''), (string) ($body['period'] ?? ''), $currentUser);
            party_account_json_exit(['ok' => true, 'csrf_token' => csrf_token()]);

        default:
            party_account_json_exit(['ok' => false, 'error' => 'unknown_action'], 400);
    }
} catch (RuntimeException $e) {
    if (stripos($e->getMessage(), 'csrf') !== false) {
        party_account_json_exit(['ok' => false, 'error' => 'csrf'], 419);
    }
    party_account_json_exit(['ok' => false, 'error' => $e->getMessage(), 'message' => $e->getMessage(), 'csrf_token' => csrf_token()], 400);
} catch (PDOException $e) {
    party_account_json_exit(party_account_pdo_error_payload($pdo, $e), 500);
} catch (Throwable $e) {
    party_account_json_exit(['ok' => false, 'error' => app_debug_enabled() ? $e->getMessage() : 'server_error'], 500);
}
