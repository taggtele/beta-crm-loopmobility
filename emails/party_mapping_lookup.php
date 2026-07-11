<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../services/vendor_am_service.php';

require_login($pdo);

header('Content-Type: application/json; charset=UTF-8');

$partyId = (int) ($_GET['party_id'] ?? 0);

if ($partyId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'party_id is required.'], JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $payload = vendor_am_service_resolve_party_mapping_for_compose($pdo, $partyId);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
} catch (Throwable $throwable) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $throwable->getMessage()], JSON_UNESCAPED_SLASHES);
}
