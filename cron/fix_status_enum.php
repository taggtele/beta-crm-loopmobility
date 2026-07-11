<?php
require_once __DIR__ . '/../config/db.php';

echo "Fixing status ENUM values...\n";

// Fix status values in database - convert lowercase to proper case
$fix = $pdo->prepare("UPDATE tickets SET status = 'Open' WHERE LOWER(status) = 'open'");
$fix->execute();
$fix = $pdo->prepare("UPDATE tickets SET status = 'In-Progress' WHERE LOWER(status) = 'in_progress'");
$fix->execute();
$fix = $pdo->prepare("UPDATE tickets SET status = 'Closed' WHERE LOWER(status) = 'closed'");
$fix->execute();

$stmt = $pdo->query("SELECT ticket_id, status FROM tickets ORDER BY ticket_id DESC LIMIT 10");
$tickets = $stmt->fetchAll();

echo "Recent tickets:\n";
foreach ($tickets as $t) {
    echo "  #{$t['ticket_id']} = '{$t['status']}'\n";
}