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
        http_response_code(400);
        party_account_json_exit(['ok' => false, 'error' => 'xhr_only'], 400);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        party_account_json_exit(['ok' => false, 'error' => 'method_not_allowed'], 405);
    }

    $body = party_account_read_json_body();
    party_account_require_csrf(isset($body['csrf_token']) ? (string) $body['csrf_token'] : null);

    $filters = is_array($body['filters'] ?? null) ? $body['filters'] : [];

    $sortKey = preg_replace('/[^a-z_]/', '', strtolower((string) ($body['sort']['key'] ?? 'updated_at')));
    if ($sortKey === '') {
        $sortKey = 'updated_at';
    }

    $sortDir = strtolower((string) ($body['sort']['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

    $page = max(1, (int) ($body['page'] ?? 1));
    $perPage = max(10, min(100, (int) ($body['per_page'] ?? 25)));

    $repo = new PartyAccountRepository($pdo);
    [$rows, $total] = $repo->paginate($filters, $sortKey, $sortDir, $page, $perPage);
    $summary = $repo->summarize($filters);

    foreach ($rows as &$row) {
        $row['currency_ledgers'] = [];
        if (!empty($row['is_multi_currency'])) {
            $row['currency_ledgers'] = $repo->findCurrencyLedgers((int) $row['id']);
        }
    }

    party_account_json_exit([
        'ok' => true,
        'rows' => $rows,
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'pages' => (int) ceil($total / $perPage),
        ],
        'summary' => $summary,
        'csrf_token' => csrf_token(),
    ]);
} catch (PDOException $e) {
    party_account_json_exit(party_account_pdo_error_payload($pdo, $e), 500);
} catch (RuntimeException $e) {
    if (str_contains($e->getMessage(), 'csrf') || stripos($e->getMessage(), 'csrf') !== false) {
        party_account_json_exit(['ok' => false, 'error' => 'csrf'], 419);
    }

    party_account_json_exit(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    party_account_json_exit([
        'ok' => false,
        'error' => app_debug_enabled() ? $e->getMessage() : 'server_error',
    ], 500);
}
