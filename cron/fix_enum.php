<?php
require_once __DIR__ . '/../config/db.php';

echo "Before:\n";
$stmt = $pdo->query("DESCRIBE tickets");
foreach ($stmt as $col) {
    if ($col['Field'] === 'status') echo "  Type: {$col['Type']}\n";
}

// Fix enum to use proper case with hyphens
$pdo->exec("ALTER TABLE tickets MODIFY COLUMN status ENUM('Open','In-Progress','Closed') DEFAULT 'Open'");

echo "\nAfter:\n";
$stmt = $pdo->query("DESCRIBE tickets");
foreach ($stmt as $col) {
    if ($col['Field'] === 'status') echo "  Type: {$col['Type']}\n";
}

// Fix all existing tickets
$pdo->exec("UPDATE tickets SET status = 'Open' WHERE LOWER(status) = 'open'");
$pdo->exec("UPDATE tickets SET status = 'In-Progress' WHERE status = 'in_progress'");
$pdo->exec("UPDATE tickets SET status = 'Closed' WHERE LOWER(status) = 'closed'");

echo "\nFixed all tickets!\n";