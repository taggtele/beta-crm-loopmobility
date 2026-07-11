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
        http_response_code(400);
        echo 'Empty request body.';
        exit;
    }

    $body = json_decode($raw, true);
    if (!is_array($body)) {
        http_response_code(400);
        echo 'Invalid JSON.';
        exit;
    }

    $sessionToken = $_SESSION['csrf_token'] ?? '';
    $postedToken = (string) ($body['csrf_token'] ?? '');
    if ($sessionToken === '' || $postedToken === '' || !hash_equals($sessionToken, $postedToken)) {
        http_response_code(400);
        echo 'Invalid CSRF token.';
        exit;
    }

    $action = strtolower(trim((string) ($body['action'] ?? '')));
    if ($action !== 'export_logs') {
        http_response_code(400);
        echo 'Invalid action.';
        exit;
    }

    $filters = [];
    if (!empty($body['search']) && is_string($body['search'])) {
        $filters['search'] = trim($body['search']);
    }
    if (!empty($body['module_name']) && is_string($body['module_name'])) {
        $filters['module_name'] = trim($body['module_name']);
    }
    if (!empty($body['page_name']) && is_string($body['page_name'])) {
        $filters['page_name'] = trim($body['page_name']);
    }
    if (!empty($body['action_type']) && is_string($body['action_type'])) {
        $filters['action_type'] = strtoupper(trim($body['action_type']));
    }
    if (!empty($body['export_format']) && is_string($body['export_format'])) {
        $filters['export_format'] = trim($body['export_format']);
    }
    if (!empty($body['status']) && is_string($body['status'])) {
        $filters['status'] = strtoupper(trim($body['status']));
    }
    if (!empty($body['date_from']) && is_string($body['date_from'])) {
        $filters['date_from'] = trim($body['date_from']);
    }
    if (!empty($body['date_to']) && is_string($body['date_to'])) {
        $filters['date_to'] = trim($body['date_to']);
    }

    $result = export_logs_list($pdo, $filters, 1, (int) ($body['per_page'] ?? 1000));
    $logs = $result['logs'];

    $filename = 'export-logs-' . date('Ymd-His') . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'wb');
    if (!$out) {
        http_response_code(500);
        echo 'Unable to initialize export stream.';
        exit;
    }

    fputcsv($out, ['Date & Time', 'Username', 'Module', 'Action', 'Format', 'Records', 'Filters', 'Status', 'Remarks', 'IP Address']);

    foreach ($logs as $log) {
        $filtersJson = $log['filters_json'] ?? null;
        $filtersDisplay = '';
        if ($filtersJson !== null && $filtersJson !== '') {
            $decoded = json_decode($filtersJson, true);
            if (is_array($decoded) && !empty($decoded)) {
                $parts = [];
                foreach ($decoded as $k => $v) {
                    if ($v !== null && $v !== '' && $v !== []) {
                        $parts[] = (string) $k . ': ' . (is_array($v) ? implode(', ', $v) : (string) $v);
                    }
                }
                $filtersDisplay = implode('; ', $parts);
            }
        }

        fputcsv($out, [
            $log['created_at'] ?? '',
            $log['user_name'] ?? '',
            $log['module_name'] ?? '',
            $log['action_type'] ?? '',
            $log['export_format'] ?? '',
            $log['total_records'] ?? 0,
            $filtersDisplay,
            $log['status'] ?? '',
            $log['remarks'] ?? '',
            $log['ip_address'] ?? '',
        ]);
    }

    fclose($out);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Export failed.';
    exit;
}
