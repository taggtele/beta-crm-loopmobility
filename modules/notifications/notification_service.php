<?php
require_once __DIR__ . '/../../includes/auth.php';

function notifications_ensure_table(PDO $pdo): void
{
    static $ready = false;

    if ($ready) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS notifications (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id VARCHAR(100) NOT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            type VARCHAR(50) NOT NULL,
            link_url VARCHAR(512) NULL,
            inbox_log_id BIGINT UNSIGNED NULL,
            ticket_id BIGINT NULL,
            meta_json JSON NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_notifications_user_read_created (user_id, is_read, created_at),
            INDEX idx_notifications_type_created (type, created_at),
            INDEX idx_notifications_inbox_log (inbox_log_id, type, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    notifications_ensure_extended_columns($pdo);

    $ready = true;
}

function notifications_ensure_extended_columns(PDO $pdo): void
{
    static $columnsReady = false;
    if ($columnsReady) {
        return;
    }

    $existing = [];
    foreach ($pdo->query('SHOW COLUMNS FROM notifications')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $field = (string) ($row['Field'] ?? '');
        if ($field !== '') {
            $existing[$field] = true;
        }
    }

    $alter = [];
    if (!isset($existing['link_url'])) {
        $alter[] = 'ADD COLUMN link_url VARCHAR(512) NULL AFTER type';
    }
    if (!isset($existing['inbox_log_id'])) {
        $alter[] = 'ADD COLUMN inbox_log_id BIGINT UNSIGNED NULL AFTER link_url';
    }
    if (!isset($existing['ticket_id'])) {
        $alter[] = 'ADD COLUMN ticket_id BIGINT NULL AFTER inbox_log_id';
    }
    if (!isset($existing['meta_json'])) {
        $alter[] = 'ADD COLUMN meta_json JSON NULL AFTER ticket_id';
    }

    if ($alter !== []) {
        $pdo->exec('ALTER TABLE notifications ' . implode(', ', $alter));
    }

    $columnsReady = true;
}

function notifications_normalize_context(array $context): array
{
    $linkUrl = trim((string) ($context['link_url'] ?? ''));
    $inboxLogId = max(0, (int) ($context['inbox_log_id'] ?? 0));
    $ticketId = max(0, (int) ($context['ticket_id'] ?? 0));

    $meta = $context;
    unset($meta['link_url'], $meta['inbox_log_id'], $meta['ticket_id']);

    $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($metaJson === false) {
        $metaJson = '{}';
    }

    return [
        'link_url' => $linkUrl !== '' ? $linkUrl : null,
        'inbox_log_id' => $inboxLogId > 0 ? $inboxLogId : null,
        'ticket_id' => $ticketId > 0 ? $ticketId : null,
        'meta_json' => $metaJson,
    ];
}

function notifications_is_duplicate(PDO $pdo, string $userId, string $type, ?int $inboxLogId): bool
{
    if ($inboxLogId === null || $inboxLogId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare(
        'SELECT 1 FROM notifications
         WHERE user_id = :user_id
         AND type = :type
         AND inbox_log_id = :inbox_log_id
         AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
         LIMIT 1'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':type' => $type,
        ':inbox_log_id' => $inboxLogId,
    ]);

    return (bool) $stmt->fetchColumn();
}

function notifications_select_columns_sql(): string
{
    return 'id, title, message, type, link_url, inbox_log_id, ticket_id, meta_json, is_read, created_at';
}

// Stores a single notification row safely for one user.
function notifications_create(PDO $pdo, string $userId, string $title, string $message, string $type, array $context = []): void
{
    notifications_ensure_table($pdo);

    $normalized = notifications_normalize_context($context);
    if (notifications_is_duplicate($pdo, $userId, $type, $normalized['inbox_log_id'])) {
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO notifications (user_id, title, message, type, link_url, inbox_log_id, ticket_id, meta_json, is_read, created_at)
         VALUES (:user_id, :title, :message, :type, :link_url, :inbox_log_id, :ticket_id, :meta_json, 0, NOW())'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':title' => $title,
        ':message' => $message,
        ':type' => $type,
        ':link_url' => $normalized['link_url'],
        ':inbox_log_id' => $normalized['inbox_log_id'],
        ':ticket_id' => $normalized['ticket_id'],
        ':meta_json' => $normalized['meta_json'],
    ]);
}

// Stores the same notification for multiple distinct users.
function notifications_create_for_users(PDO $pdo, array $userIds, string $title, string $message, string $type, array $context = []): void
{
    $userIds = array_values(array_unique(array_filter(array_map('trim', $userIds))));

    foreach ($userIds as $userId) {
        notifications_create($pdo, $userId, $title, $message, $type, $context);
    }
}

// Returns the newest notifications for the current user.
function notifications_recent_for_user(PDO $pdo, string $userId, int $limit = 6): array
{
    try {
        notifications_ensure_table($pdo);

        $stmt = $pdo->prepare(
            'SELECT ' . notifications_select_columns_sql() . '
             FROM notifications
             WHERE user_id = :user_id
             ORDER BY created_at DESC, id DESC
             LIMIT ' . (int) max(1, $limit)
        );
        $stmt->execute([':user_id' => $userId]);

        return $stmt->fetchAll();
    } catch (PDOException $exception) {
        return [];
    }
}

function notifications_dropdown_feed_for_user(PDO $pdo, string $userId, int $unreadLimit = 30, int $latestLimit = 10): array
{
    try {
        notifications_ensure_table($pdo);

        $unreadStmt = $pdo->prepare(
            'SELECT ' . notifications_select_columns_sql() . '
             FROM notifications
             WHERE user_id = :user_id
             AND is_read = 0
             ORDER BY created_at DESC, id DESC
             LIMIT ' . (int) max(1, $unreadLimit)
        );
        $unreadStmt->execute([':user_id' => $userId]);
        $unreadItems = $unreadStmt->fetchAll();

        $latestStmt = $pdo->prepare(
            'SELECT ' . notifications_select_columns_sql() . '
             FROM notifications
             WHERE user_id = :user_id
             ORDER BY created_at DESC, id DESC
             LIMIT ' . (int) max(1, $latestLimit)
        );
        $latestStmt->execute([':user_id' => $userId]);
        $latestItems = $latestStmt->fetchAll();

        $merged = [];
        foreach (array_merge($unreadItems, $latestItems) as $notification) {
            $merged[(int) ($notification['id'] ?? 0)] = $notification;
        }

        usort($merged, static function (array $left, array $right): int {
            $leftUnread = (int) ($left['is_read'] ?? 0) === 0 ? 1 : 0;
            $rightUnread = (int) ($right['is_read'] ?? 0) === 0 ? 1 : 0;

            if ($leftUnread !== $rightUnread) {
                return $rightUnread <=> $leftUnread;
            }

            $leftCreated = strtotime((string) ($left['created_at'] ?? '')) ?: 0;
            $rightCreated = strtotime((string) ($right['created_at'] ?? '')) ?: 0;

            if ($leftCreated !== $rightCreated) {
                return $rightCreated <=> $leftCreated;
            }

            return (int) ($right['id'] ?? 0) <=> (int) ($left['id'] ?? 0);
        });

        return array_values($merged);
    } catch (PDOException $exception) {
        return [];
    }
}

// Marks one notification as read for the current user.
function notifications_mark_read(PDO $pdo, string $userId, int $notificationId): void
{
    try {
        notifications_ensure_table($pdo);

        $stmt = $pdo->prepare(
            'UPDATE notifications
             SET is_read = 1
             WHERE user_id = :user_id
             AND id = :id'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':id' => $notificationId,
        ]);
    } catch (PDOException $exception) {
    }
}

// Returns the unread notification count for the current user.
function notifications_unread_count(PDO $pdo, string $userId): int
{
    try {
        notifications_ensure_table($pdo);

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND is_read = 0'
        );
        $stmt->execute([':user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    } catch (PDOException $exception) {
        return 0;
    }
}

// Marks all notifications as read for the current user.
function notifications_mark_all_read(PDO $pdo, string $userId): void
{
    try {
        notifications_ensure_table($pdo);

        $stmt = $pdo->prepare(
            'UPDATE notifications
             SET is_read = 1
             WHERE user_id = :user_id
             AND is_read = 0'
        );
        $stmt->execute([':user_id' => $userId]);
    } catch (PDOException $exception) {
    }
}

// Fetches all active admin user IDs for broadcast-style alerts.
function notifications_active_admin_user_ids(PDO $pdo): array
{
    $stmt = $pdo->prepare(
        'SELECT user_id
         FROM users
         WHERE deleted = 0
         AND status = :status
         AND role = :role
         ORDER BY id ASC'
    );
    $stmt->execute([
        ':status' => 'Active',
        ':role' => 'Admin',
    ]);

    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}
