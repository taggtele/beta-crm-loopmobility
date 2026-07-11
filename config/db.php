<?php

defined('APP_NAME') || define('APP_NAME', 'Internal Ticket System');
defined('APP_ROOT') || define('APP_ROOT', realpath(dirname(__DIR__)));
defined('STORAGE_PATH') || define('STORAGE_PATH', APP_ROOT . '/storage');
defined('LOGS_PATH') || define('LOGS_PATH', STORAGE_PATH . '/logs');

require_once APP_ROOT . '/core/env.php';

app_load_env(APP_ROOT . '/.env');

date_default_timezone_set((string) env_value('APP_TIMEZONE', 'Asia/Kolkata'));

if (!is_dir(LOGS_PATH)) {
    mkdir(LOGS_PATH, 0775, true);
}

if (app_debug_enabled()) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}
ini_set('log_errors', '1');
ini_set('error_log', LOGS_PATH . '/php-error.log');

$host = (string) env_value('DB_HOST', '127.0.0.1');
$dbname = (string) env_value('DB_NAME', 'taggteleservices_noc_db');
$username = (string) env_value('DB_USER', 'root');
$password = (string) env_value('DB_PASS', '');

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_PERSISTENT => false,
];

function create_pdo_connection(): PDO {
    global $host, $dbname, $username, $password, $options;
    $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";
    return new PDO($dsn, $username, $password, $options);
}

$pdo = create_pdo_connection();

/* Migration runner disabled for production stability.
   Schema is managed at runtime by service ensure_schema functions.
   To re-enable: uncomment the lines below.
require_once APP_ROOT . '/database/migrate.php';
migrations_run($pdo, APP_ROOT . '/database/migrations');
*/

/**
 * Get the current database connection, reconnecting if necessary.
 * Uses a static variable to maintain the connection across calls.
 */
function pdo_connection_is_lost(PDOException $e): bool
{
    $errorMsg = strtolower($e->getMessage());
    $driverCode = (int) ($e->errorInfo[1] ?? 0);

    return $driverCode === 2006
        || $driverCode === 2013
        || str_contains($errorMsg, 'server has gone away')
        || str_contains($errorMsg, 'lost connection')
        || str_contains($errorMsg, 'no connection to the server');
}

function get_pdo(): PDO
{
    global $pdo;
    static $pdoInstance = null;

    if ($pdoInstance === null) {
        $pdoInstance = create_pdo_connection();
        $pdo = $pdoInstance;

        return $pdoInstance;
    }

    try {
        $pdoInstance->query('SELECT 1');
    } catch (PDOException $e) {
        if (!pdo_connection_is_lost($e)) {
            throw $e;
        }

        $pdoInstance = create_pdo_connection();
        $pdo = $pdoInstance;
    }

    return $pdoInstance;
}

if (!function_exists('app_base_path')) {
    function app_base_path(): string
    {
        static $basePath = null;

        if ($basePath !== null) {
            return $basePath;
        }

        $documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
        $publicRoot = realpath(defined('PUBLIC_ROOT') ? PUBLIC_ROOT : APP_ROOT . '/public');

        if ($documentRoot && $publicRoot && stripos($publicRoot, $documentRoot) === 0) {
            $relativePath = trim(str_replace('\\', '/', substr($publicRoot, strlen($documentRoot))), '/');
            $basePath = $relativePath === '' ? '' : '/' . $relativePath;
            return $basePath;
        }

        $basePath = '';
        return $basePath;
    }
}

if (!function_exists('url')) {
    function url(string $path = ''): string
    {
        $path = ltrim($path, '/');
        $basePath = app_base_path();

        if ($path === '') {
            return $basePath === '' ? '/' : $basePath . '/';
        }

        return $basePath . '/' . $path;
    }
}
