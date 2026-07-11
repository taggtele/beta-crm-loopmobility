<?php
require_once __DIR__ . '/../config/db.php';

$stmt = $pdo->query("SELECT ticket_id, status, priority, assign_to, issue FROM tickets ORDER BY ticket_id DESC LIMIT 5");
$tickets = $stmt->fetchAll();

echo "Tickets - UI Test:\n";
foreach ($tickets as $t) {
    echo "#{$t['ticket_id']} | {$t['status']} | {$t['priority']} | " . substr($t['issue'], 0, 25) . "\n";
}