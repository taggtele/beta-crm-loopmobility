<?php
require_once __DIR__ . '/../config/db.php';

$rows = $pdo->query('SELECT ticket_id, external_ticket_id, issue, source, created_at FROM tickets ORDER BY ticket_id DESC LIMIT 20')->fetchAll();

foreach($rows as $r) {
    echo $r['ticket_id'] . ' | ' . ($r['external_ticket_id']?:'N/A') . ' | ' . substr($r['issue'],0,40) . ' | ' . $r['created_at'] . PHP_EOL;
}