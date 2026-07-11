<?php
require_once __DIR__ . '/../config/db.php';

$rows = $pdo->query('SELECT id, title, message, created_at FROM notifications ORDER BY id DESC LIMIT 15')->fetchAll();

foreach($rows as $r) {
    echo $r['id'] . ' | ' . substr($r['title'],0,30) . ' | ' . $r['created_at'] . PHP_EOL;
    echo '    ' . substr($r['message'],0,60) . PHP_EOL;
}