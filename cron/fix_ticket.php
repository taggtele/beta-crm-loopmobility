<?php
require_once __DIR__ . '/../config/db.php';

$ticketId = (int) ($_GET['id'] ?? 1720);

echo "Testing ticket update for #$ticketId\n\n";

$stmt = $pdo->prepare('SELECT ticket_id, status, priority, assign_to FROM tickets WHERE ticket_id = ?');
$stmt->execute([$ticketId]);
$ticket = $stmt->fetch();

if (!$ticket) {
    echo "Ticket not found!\n";
    exit;
}

echo "Before: Status={$ticket['status']}, Priority={$ticket['priority']}, AssignTo={$ticket['assign_to']}\n";

// Test update to In-Progress
$update = $pdo->prepare('UPDATE tickets SET status = ?, assign_to = ? WHERE ticket_id = ?');
$update->execute(['In-Progress', 'U001', $ticketId]);

// Re-fetch
$stmt->execute([$ticketId]);
$ticket = $stmt->fetch();

echo "After:  Status={$ticket['status']}, Priority={$ticket['priority']}, AssignTo={$ticket['assign_to']}\n";