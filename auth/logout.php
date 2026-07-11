<?php
require_once __DIR__ . '/../includes/auth.php';

app_session_start();

$logoutUserId = (int) ($_SESSION['user_pk'] ?? 0);
$logoutUserName = (string) ($_SESSION['name'] ?? '');

require_once __DIR__ . '/../system_logs/log_helper.php';
if ($logoutUserId > 0 && $logoutUserName !== '') {
    log_logout_activity($pdo, $logoutUserId, $logoutUserName);
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}

session_destroy();

redirect('auth/login.php');
