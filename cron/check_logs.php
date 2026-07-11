<?php
require_once __DIR__ . '/../config/db.php';

$rows = $pdo->query('SELECT ticket_id, action, message, created_at FROM ticket_logs ORDER BY id DESC LIMIT 15')->fetchAll();

echo "Recent Ticket Logs:\n";
foreach($rows as $r) {
    echo "Ticket #" . $r['ticket_id'] . " [" . $r['action'] . "] " . $r['created_at'] . "\n";
    echo "  " . substr($r['message'], 0, 60) . "\n";
}