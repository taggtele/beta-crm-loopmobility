<?php
require_once __DIR__ . '/../includes/auth.php';

$currentUser = require_login($pdo);
$flash = get_flash();
$currentUser = require_login($pdo);

$statsStmt = $pdo->prepare(
    'SELECT
        SUM(CASE WHEN assign_to = :user_id_a THEN 1 ELSE 0 END) AS assigned_total,
        SUM(CASE WHEN assign_to = :user_id_b AND status = :open_status THEN 1 ELSE 0 END) AS open_assigned_total,
        SUM(CASE WHEN created_by = :user_id_c THEN 1 ELSE 0 END) AS created_total
     FROM tickets'
);
$statsStmt->execute([
    ':user_id_a' => $currentUser['user_id'],
    ':user_id_b' => $currentUser['user_id'],
    ':user_id_c' => $currentUser['user_id'],
    ':open_status' => 'Open',
]);
$ticketStats = $statsStmt->fetch() ?: [];

$profileStats = [
    'assigned_total' => (int) ($ticketStats['assigned_total'] ?? 0),
    'open_assigned_total' => (int) ($ticketStats['open_assigned_total'] ?? 0),
    'created_total' => (int) ($ticketStats['created_total'] ?? 0),
];

$displayUser = $currentUser;
