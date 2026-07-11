<?php

/**
 * Per-user reusable message templates for email compose flows.
 */

function message_templates_ensure_table(PDO $pdo): void
{
    static $ready = false;

    if ($ready) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS message_templates (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            title VARCHAR(190) NOT NULL,
            content LONGTEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_message_templates_user_updated (user_id, updated_at),
            INDEX idx_message_templates_user_title (user_id, title)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $ready = true;
}

function message_templates_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function message_templates_list(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }

    message_templates_ensure_table($pdo);

    $stmt = $pdo->prepare(
        'SELECT id, user_id, title, content, created_at, updated_at
         FROM message_templates
         WHERE user_id = :user_id
         ORDER BY updated_at DESC, id DESC'
    );
    $stmt->execute([':user_id' => $userId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function message_templates_get(PDO $pdo, int $userId, int $templateId): ?array
{
    if ($userId <= 0 || $templateId <= 0) {
        return null;
    }

    message_templates_ensure_table($pdo);

    $stmt = $pdo->prepare(
        'SELECT id, user_id, title, content, created_at, updated_at
         FROM message_templates
         WHERE id = :id AND user_id = :user_id
         LIMIT 1'
    );
    $stmt->execute([
        ':id' => $templateId,
        ':user_id' => $userId,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function message_templates_save(PDO $pdo, int $userId, string $title, string $content, ?int $templateId = null): array
{
    if ($userId <= 0) {
        throw new InvalidArgumentException('Invalid user.');
    }

    message_templates_ensure_table($pdo);

    $title = trim($title);
    $content = trim($content);

    if ($title === '') {
        throw new InvalidArgumentException('Template title is required.');
    }

    if ($content === '') {
        throw new InvalidArgumentException('Template content is required.');
    }

    if (message_templates_length($title) > 190) {
        throw new InvalidArgumentException('Template title must be 190 characters or less.');
    }

    if (message_templates_length($content) > 50000) {
        throw new InvalidArgumentException('Template content is too long.');
    }

    if ($templateId !== null && $templateId > 0) {
        $stmt = $pdo->prepare(
            'UPDATE message_templates
             SET title = :title,
                 content = :content,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND user_id = :user_id'
        );
        $stmt->execute([
            ':title' => $title,
            ':content' => $content,
            ':id' => $templateId,
            ':user_id' => $userId,
        ]);

        if ($stmt->rowCount() === 0 && message_templates_get($pdo, $userId, $templateId) === null) {
            throw new RuntimeException('Template not found.');
        }

        return message_templates_get($pdo, $userId, $templateId) ?? [
            'id' => $templateId,
            'user_id' => $userId,
            'title' => $title,
            'content' => $content,
            'created_at' => null,
            'updated_at' => null,
        ];
    }

    $stmt = $pdo->prepare(
        'INSERT INTO message_templates (user_id, title, content, created_at, updated_at)
         VALUES (:user_id, :title, :content, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':title' => $title,
        ':content' => $content,
    ]);

    $templateId = (int) $pdo->lastInsertId();

    return message_templates_get($pdo, $userId, $templateId) ?? [
        'id' => $templateId,
        'user_id' => $userId,
        'title' => $title,
        'content' => $content,
        'created_at' => null,
        'updated_at' => null,
    ];
}

function message_templates_delete(PDO $pdo, int $userId, int $templateId): bool
{
    if ($userId <= 0 || $templateId <= 0) {
        return false;
    }

    message_templates_ensure_table($pdo);

    $stmt = $pdo->prepare(
        'DELETE FROM message_templates
         WHERE id = :id AND user_id = :user_id'
    );
    $stmt->execute([
        ':id' => $templateId,
        ':user_id' => $userId,
    ]);

    return $stmt->rowCount() > 0;
}
