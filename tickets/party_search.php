<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../services/party_service.php';

require_login($pdo);

header('Content-Type: application/json; charset=UTF-8');

$q = trim((string) ($_GET['q'] ?? ''));
if ($q === '') {
    echo json_encode(['success' => true, 'results' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$rows = party_service_search_active($pdo, $q, 40);
$out = [];
foreach ($rows as $row) {
    $out[] = [
        'id' => (int) ($row['id'] ?? 0),
        'name' => (string) ($row['name'] ?? ''),
        'primary_email' => (string) ($row['primary_email'] ?? ''),
        'country' => trim((string) ($row['country'] ?? '')),
    ];
}

echo json_encode(['success' => true, 'results' => $out], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
