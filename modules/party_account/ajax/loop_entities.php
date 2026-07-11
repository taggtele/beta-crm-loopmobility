<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/bootstrap.php';
require_once dirname(__DIR__) . '/middleware/require_party_account_access.php';

$currentUser = require_login($pdo);
party_account_middleware_gate_view($currentUser);
party_account_ensure_schema($pdo);

try {
    $method = ($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if ($method === 'GET') {
        // lightweight JSON list — used by SPA boot + refresh after entity CRUD
        $repo = new LoopEntityRepository($pdo);
        party_account_json_exit([
            'ok' => true,
            'rows' => $repo->listActive(),
            'csrf_token' => csrf_token(),
        ]);
    }

    if ($method !== 'POST') {
        party_account_json_exit(['ok' => false, 'error' => 'method_not_allowed'], 405);
    }

    party_account_middleware_gate_manage($currentUser);

    if (!party_account_is_xhr()) {
        party_account_json_exit(['ok' => false, 'error' => 'xhr_only'], 400);
    }

    $body = party_account_read_json_body();
    party_account_require_csrf(isset($body['csrf_token']) ? (string) $body['csrf_token'] : null);

    $action = (string) ($body['action'] ?? '');
    $repoData = new LoopEntityRepository($pdo);
    $audit = new PartyAccountActivityLogService($pdo);
    $service = new LoopEntityService($repoData, $audit);

    switch ($action) {
        case 'manage_list':
            $filters = [
                'search' => trim((string) ($body['search'] ?? '')),
                'include_deleted' => filter_var($body['include_deleted'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
            [$rows, $total] = $repoData->listAllIncludingDeleted($filters, (int) ($body['page'] ?? 1), (int) ($body['per_page'] ?? 40));
            party_account_json_exit([
                'ok' => true,
                'rows' => $rows,
                'total' => $total,
                'csrf_token' => csrf_token(),
            ]);

        case 'create':
            $newId = $service->create((array) ($body['payload'] ?? []), $currentUser);
            $row = $repoData->findById($newId);
            party_account_json_exit([
                'ok' => true,
                'id' => $newId,
                'row' => $row,
                'csrf_token' => csrf_token(),
            ]);

        case 'update':
            $service->update((array) ($body['payload'] ?? []), $currentUser);
            party_account_json_exit(['ok' => true, 'csrf_token' => csrf_token()]);

        case 'delete':
            $id = (int) ($body['id'] ?? 0);
            if ($id <= 0) {
                party_account_json_exit(['ok' => false, 'error' => 'invalid_id'], 400);
            }

            $service->delete($id, $currentUser);
            party_account_json_exit(['ok' => true, 'csrf_token' => csrf_token()]);

        case 'restore':
            $id = (int) ($body['id'] ?? 0);
            if ($id <= 0) {
                party_account_json_exit(['ok' => false, 'error' => 'invalid_id'], 400);
            }

            $service->restore($id, $currentUser);
            party_account_json_exit(['ok' => true, 'csrf_token' => csrf_token()]);

        default:
            party_account_json_exit(['ok' => false, 'error' => 'unknown_action'], 400);
    }
} catch (PDOException $e) {
    party_account_json_exit(party_account_pdo_error_payload($pdo, $e), 500);
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

    party_account_json_exit([
        'ok' => false,
        'error' => stripos($e->getMessage(), 'csrf') !== false ? 'csrf' : $e->getMessage(),
    ], 400);
} catch (Throwable $e) {
    party_account_json_exit([
        'ok' => false,
        'error' => app_debug_enabled() ? $e->getMessage() : 'server_error',
    ], 500);
}
