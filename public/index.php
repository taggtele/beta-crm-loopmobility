<?php
define('PUBLIC_ROOT', __DIR__);
define('APP_ROOT', realpath(__DIR__ . '/..'));

chdir(APP_ROOT);

function public_route_path(): string
{
    $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));

    if ($scriptDir !== '/' && $scriptDir !== '.' && str_starts_with($uriPath, $scriptDir . '/')) {
        $uriPath = substr($uriPath, strlen($scriptDir));
    }

    $path = trim(rawurldecode($uriPath), '/');
    return $path === '' ? 'index.php' : $path;
}

function public_send_storage_file(string $route): bool
{
    $allowedPrefixes = [
        'storage/profile-images/',
        'storage/attachments/',
        'storage/email_outbox_assets/',
        'storage/email_inbox_assets/',
    ];

    $normalized = str_replace('\\', '/', $route);
    $allowed = false;
    foreach ($allowedPrefixes as $prefix) {
        if (str_starts_with($normalized, $prefix)) {
            $allowed = true;
            break;
        }
    }

    if (!$allowed || str_contains($normalized, '..')) {
        return false;
    }

    $file = realpath(APP_ROOT . '/' . $normalized);
    $storageRoot = realpath(APP_ROOT . '/storage');
    if (!$file || !$storageRoot || !is_file($file) || strpos($file, $storageRoot . DIRECTORY_SEPARATOR) !== 0) {
        return false;
    }

    $mime = function_exists('mime_content_type') ? (mime_content_type($file) ?: 'application/octet-stream') : 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($file));
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    return true;
}

function public_send_document_file(string $route): bool
{
    $normalized = str_replace('\\', '/', $route);
    if (!str_starts_with($normalized, 'docs/') || str_contains($normalized, '..')) {
        return false;
    }

    $file = realpath(APP_ROOT . '/' . $normalized);
    $docsRoot = realpath(APP_ROOT . '/docs');
    if (!$file || !$docsRoot || !is_file($file) || strpos($file, $docsRoot . DIRECTORY_SEPARATOR) !== 0) {
        return false;
    }

    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $contentTypes = [
        'md' => 'text/markdown; charset=UTF-8',
        'txt' => 'text/plain; charset=UTF-8',
        'mmd' => 'text/plain; charset=UTF-8',
    ];

    if (!isset($contentTypes[$extension])) {
        return false;
    }

    header('Content-Type: ' . $contentTypes[$extension]);
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    return true;
}

$route = public_route_path();

if (public_send_storage_file($route) || public_send_document_file($route)) {
    exit;
}

$allowedWebDirs = [
    'admin',
    'auth',
    'controllers',
    'cron',
    'dashboard',
    'email',
    'emails',
    'modules',
    'profile',
    'system_logs',
    'tickets',
    'users',
];

if ($route === 'index.php') {
    require_once APP_ROOT . '/includes/auth.php';

    app_session_start();

    if (current_user($pdo)) {
        require_once APP_ROOT . '/includes/rbac.php';
        $loggedIn = current_user($pdo);
        if ($loggedIn && rbac_is_finance($loggedIn)) {
            redirect(rbac_finance_home_path());
        }
        redirect('dashboard/index.php');
    }

    redirect('auth/login.php');
} else {
    $firstSegment = strtok($route, '/');
    $extension = strtolower(pathinfo($route, PATHINFO_EXTENSION));
    $targetFile = APP_ROOT . '/' . $route;

    if (
        $extension !== 'php'
        || str_contains($route, '..')
        || !in_array($firstSegment, $allowedWebDirs, true)
        || !is_file($targetFile)
    ) {
        http_response_code(404);
        exit('Not found.');
    }
}

$_SERVER['SCRIPT_FILENAME'] = $targetFile;
$_SERVER['SCRIPT_NAME'] = '/' . $route;

require $targetFile;
