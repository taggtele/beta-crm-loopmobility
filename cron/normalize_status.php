<?php
require_once __DIR__ . '/../config/db.php';

$stmt = $pdo->query("SELECT ticket_id, status FROM tickets WHERE LOWER(status) = 'open' LIMIT 5");
$tickets = $stmt->fetchAll();

echo "Tickets with lowercase 'open': " . count($tickets) . "\n";

foreach ($tickets as $t) {
    echo "  #{$t['ticket_id']} = '{$t['status']}'\n";
}

// Fix all
$fix = $pdo->prepare("UPDATE tickets SET status = 'Open' WHERE LOWER(status) = 'open'");
$fix->execute();

$stmt = $pdo->query("SELECT ticket_id, status FROM tickets ORDER BY ticket_id DESC LIMIT 5");
$tickets = $stmt->fetchAll();

echo "\nAfter fix:\n";
foreach ($tickets as $t) {
    echo "  #{$t['ticket_id']} = '{$t['status']}'\n";
}