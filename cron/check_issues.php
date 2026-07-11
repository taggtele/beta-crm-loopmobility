<?php
require_once __DIR__ . '/../config/db.php';

$rows = $pdo->query('SELECT ticket_id, external_ticket_id, issue, customer FROM tickets ORDER BY ticket_id DESC LIMIT 15')->fetchAll();

echo "Recent Tickets:\n";
echo str_repeat("=", 80) . "\n";
foreach($rows as $r) {
    echo "#" . $r['ticket_id'] . " [" . $r['external_ticket_id'] . "]\n";
    echo "Issue: " . substr($r['issue'], 0, 70) . "\n";
    echo "From: " . $r['customer'] . "\n";
    echo str_repeat("-", 60) . "\n";
}