<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../services/notification_ui_service.php';

$currentUser = require_login($pdo);
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

ignore_user_abort(true);
set_time_limit(0);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

$userId = (string) ($currentUser['user_id'] ?? '');
$lastId = (int) ($_GET['last_id'] ?? 0);

for ($i = 0; $i < 15; $i++) {
    $latest = notifications_dropdown_feed_for_user($pdo, $userId, 30, 10);
    $unread = notifications_unread_count($pdo, $userId);
    $latestId = 0;
    foreach ($latest as $notification) {
        $latestId = max($latestId, (int) $notification['id']);
    }

    $payload = [
        'unread_count' => $unread,
        'latest_id' => $latestId,
        'has_new' => $latestId > $lastId,
        'html' => notification_ui_service_render_items($latest),
        'notifications' => $latest,
    ];

    echo "event: notifications\n";
    echo 'data: ' . json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n\n";
    @ob_flush();
    flush();

    $lastId = max($lastId, $latestId);
    sleep(4);

    if (connection_aborted()) {
        break;
    }
}
