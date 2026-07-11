<?php
require_once __DIR__ . '/../config/db.php';

$rows = $pdo->query('SELECT id, subject, processing_result, ticket_id, external_ticket_id FROM email_inbox_log ORDER BY id DESC LIMIT 10')->fetchAll();

echo "Recent Emails:\n";
foreach($rows as $r) {
    echo "#" . $r['id'] . " [" . $r['processing_result'] . "] Ticket#" . $r['ticket_id'] . " Ext:" . $r['external_ticket_id'] . "\n";
    echo "  " . substr($r['subject'], 0, 50) . "\n";
}