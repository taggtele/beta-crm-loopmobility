<?php
// Core Bootstrap - loads essential components
defined('APP_NAME') || define('APP_NAME', 'Internal Ticket System');
defined('APP_ROOT') || define('APP_ROOT', realpath(dirname(__DIR__)));
defined('CORE_PATH') || define('CORE_PATH', __DIR__);
defined('SERVICES_PATH') || define('SERVICES_PATH', APP_ROOT . '/services');
defined('STORAGE_PATH') || define('STORAGE_PATH', APP_ROOT . '/storage');
defined('LOGS_PATH') || define('LOGS_PATH', STORAGE_PATH . '/logs');

spl_autoload_register(function ($class) {
    $coreFile = CORE_PATH . '/' . $class . '.php';
    $serviceFile = SERVICES_PATH . '/' . $class . '.php';
    if (file_exists($coreFile)) require_once $coreFile;
    elseif (file_exists($serviceFile)) require_once $serviceFile;
});

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once APP_ROOT . '/config/db.php';
