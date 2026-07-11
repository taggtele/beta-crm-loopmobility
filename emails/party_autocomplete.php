<?php

declare(strict_types=1);

/**
 * Read-only party search/detail for compose and other non-admin mail flows.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../services/party_service.php';

require_login($pdo);

header('Content-Type: application/json; charset=UTF-8');

$action = trim((string) ($_GET['action'] ?? ''));

try {
    if ($action === 'search') {
        $q = trim((string) ($_GET['q'] ?? ''));
        if (strlen($q) < 1) {
            echo json_encode(['ok' => true, 'results' => []], JSON_UNESCAPED_SLASHES);
            exit;
        }

        $rows = party_service_search_active($pdo, $q, 30);
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'primary_email' => (string) ($row['primary_email'] ?? ''),
            ];
        }
        echo json_encode(['ok' => true, 'results' => $out], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'detail') {
        $id = (int) ($_GET['id'] ?? 0);
        $party = party_service_get_active_party($pdo, $id);
        if (!$party) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Party not found or inactive.'], JSON_UNESCAPED_SLASHES);
            exit;
        }

        $emails = party_service_party_emails_ordered($pdo, $id);
        $list = [];
        foreach ($emails as $er) {
            $list[] = [
                'email' => (string) ($er['email'] ?? ''),
                'is_primary' => (int) ($er['is_primary'] ?? 0),
            ];
        }

        echo json_encode(
            [
                'ok' => true,
                'party' => [
                    'id' => (int) $party['id'],
                    'name' => (string) ($party['name'] ?? ''),
                ],
                'emails' => $list,
            ],
            JSON_UNESCAPED_SLASHES
        );
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown action.'], JSON_UNESCAPED_SLASHES);
} catch (Throwable $throwable) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $throwable->getMessage()], JSON_UNESCAPED_SLASHES);
}
