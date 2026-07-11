<?php
require_once __DIR__ . '/../config/db.php';

$rows = $pdo->query('SELECT id, ticket_id, to_email, subject, status FROM email_outbox_log ORDER BY id DESC LIMIT 10')->fetchAll();

echo "Outbox Emails:\n";
foreach($rows as $r) {
    echo "#" . $r['id'] . " Ticket#" . $r['ticket_id'] . " [" . $r['status'] . "] To: " . $r['to_email'] . "\n";
    echo "  Subject: " . substr($r['subject'], 0, 50) . "\n";
}