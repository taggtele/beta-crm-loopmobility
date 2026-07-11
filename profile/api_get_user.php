<?php
require_once __DIR__ . '/../includes/auth.php';

$currentUser = require_login($pdo);

// Only admins can fetch other user data
if ($currentUser['role'] !== 'Admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$userId = (int) ($_GET['id'] ?? 0);
if ($userId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
    exit;
}

$stmt = $pdo->prepare('SELECT id, user_id, name, profile_image, role, status FROM users WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $userId]);
$user = $stmt->fetch();

if (!$user) {
    echo json_encode(['success' => false, 'error' => 'User not found']);
    exit;
}

// Generate avatar HTML using existing helper
$avatarHtml = user_avatar_html($user, 'profile-avatar profile-avatar-lg');

echo json_encode([
    'success' => true,
    'user' => [
        'id' => (int) $user['id'],
        'user_id' => $user['user_id'],
        'name' => $user['name'],
        'profile_image' => $user['profile_image'],
        'role' => $user['role'],
        'status' => $user['status'],
        'avatar_html' => $avatarHtml,
    ]
], JSON_UNESCAPED_SLASHES);
