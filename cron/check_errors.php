<?php
require_once __DIR__ . '/../config/db.php';

$rows = $pdo->query('SELECT id, ticket_id, to_email, subject, status, error_message FROM email_outbox_log WHERE error_message IS NOT NULL AND error_message != "" ORDER BY id DESC LIMIT 5')->fetchAll();

echo "Emails with errors:\n";
foreach($rows as $r) {
    echo "#" . $r['id'] . " [" . $r['status'] . "] Error: " . $r['error_message'] . "\n";
}