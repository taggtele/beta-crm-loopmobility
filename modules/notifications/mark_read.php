<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/notification_service.php';

$currentUser = require_login($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $notificationId = (int) ($_POST['notification_id'] ?? 0);

    if ($notificationId > 0) {
        notifications_mark_read($pdo, $currentUser['user_id'], $notificationId);
    } else {
        notifications_mark_all_read($pdo, $currentUser['user_id']);
    }

    if (
        strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
        || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
    ) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => true], JSON_UNESCAPED_SLASHES);
        exit;
    }
}

$redirectTo = trim((string) ($_POST['redirect_to'] ?? ''));
if ($redirectTo === '' || preg_match('/^https?:\/\//i', $redirectTo)) {
    $redirectTo = url('dashboard/index.php');
}

header('Location: ' . $redirectTo);
exit;
