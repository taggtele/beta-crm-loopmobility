<?php
require_once __DIR__ . '/../config/db.php';

$stmt = $pdo->query("DESCRIBE tickets");
$columns = $stmt->fetchAll();

foreach ($columns as $col) {
    if ($col['Field'] === 'status') {
        echo "Status column:\n";
        echo "  Type: {$col['Type']}\n";
        echo "  Null: {$col['Null']}\n";
        echo "  Default: {$col['Default']}\n";
    }
}