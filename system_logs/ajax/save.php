<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__) . '/log_helper.php';

$currentUser = require_login($pdo);
export_logs_ensure_schema($pdo);

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo 'POST only.';
        exit;
    }

    if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
        echo 'XHR only.';
        exit;
    }

    $raw = (string) file_get_contents('php://input');
    if ($raw === '') {
        ticket_json_response(['success' => false, 'error' => 'Empty request body.'], 400);
    }

    $body = json_decode($raw, true);
    if (!is_array($body)) {
        ticket_json_response(['success' => false, 'error' => 'Invalid JSON.'], 400);
    }

    $sessionToken = $_SESSION['csrf_token'] ?? '';
    $postedToken = (string) ($body['csrf_token'] ?? '');
    if ($sessionToken === '' || $postedToken === '' || !hash_equals($sessionToken, $postedToken)) {
        ticket_json_response(['success' => false, 'error' => 'Invalid CSRF token.'], 400);
    }

    $moduleName = trim((string) ($body['module_name'] ?? ''));
    $pageName = trim((string) ($body['page_name'] ?? ''));
    $actionType = strtoupper(trim((string) ($body['action_type'] ?? '')));
    $exportFormat = trim((string) ($body['export_format'] ?? ''));
    $totalRecords = (int) ($body['total_records'] ?? 0);
    $filters = is_array($body['filters'] ?? null) ? $body['filters'] : null;
    $status = strtoupper(trim((string) ($body['status'] ?? 'SUCCESS')));
    $remarks = trim((string) ($body['remarks'] ?? ''));

    if ($moduleName === '' || $pageName === '' || $actionType === '') {
        ticket_json_response(['success' => false, 'error' => 'module_name, page_name, and action_type are required.'], 400);
    }

    if (!in_array($actionType, ['EXPORT', 'IMPORT'], true)) {
        ticket_json_response(['success' => false, 'error' => 'Invalid action_type. Must be EXPORT or IMPORT.'], 400);
    }

    if (!in_array($status, ['SUCCESS', 'FAILED'], true)) {
        $status = 'SUCCESS';
    }

    $userId = isset($currentUser['user_id']) && $currentUser['user_id'] !== '' ? (int) $currentUser['user_id'] : null;
    $userName = $currentUser['name'] ?? $currentUser['user_id'] ?? null;

    $saved = log_export_activity(
        $pdo,
        $userId,
        $userName,
        $moduleName,
        $pageName,
        $actionType,
        $exportFormat !== '' ? $exportFormat : null,
        $totalRecords,
        $filters,
        $status,
        $remarks !== '' ? $remarks : null
    );

    if ($saved) {
        ticket_json_response(['success' => true]);
    } else {
        ticket_json_response(['success' => false, 'error' => 'Failed to save export log.'], 500);
    }
} catch (Throwable $e) {
    ticket_json_response(['success' => false, 'error' => 'Server error.'], 500);
}
