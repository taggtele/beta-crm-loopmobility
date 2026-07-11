<?php

/**
 * Per-user email signature storage (1:1 with users.id).
 */

function user_email_signature_ensure_table(PDO $pdo): void
{
    static $ready = false;

    if ($ready) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS user_email_signatures (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            signature_html TEXT NULL,
            signature_text TEXT NULL,
            logo_url VARCHAR(2048) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_user_email_signatures_user_id (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $ready = true;
}

/**
 * @return array<string, mixed>|null
 */
function getUserSignature(PDO $pdo, int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    try {
        user_email_signature_ensure_table($pdo);

        $stmt = $pdo->prepare(
            'SELECT id, user_id, signature_html, signature_text, logo_url, created_at, updated_at
             FROM user_email_signatures
             WHERE user_id = :user_id
             LIMIT 1'
        );
        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    } catch (PDOException $exception) {
        return null;
    }
}

function user_email_signature_normalize_logo_url(?string $logoUrl): ?string
{
    $logoUrl = trim((string) $logoUrl);
    if ($logoUrl === '') {
        return null;
    }
    if (!filter_var($logoUrl, FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException('Logo image URL must be a valid http or https URL.');
    }
    $scheme = strtolower((string) parse_url($logoUrl, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true)) {
        throw new InvalidArgumentException('Logo image URL must use http or https.');
    }

    return $logoUrl;
}

/**
 * @return array{signature_html: string, signature_text: string, logo_url: ?string}
 */
function user_email_signature_build_payload(string $body, bool $useHtml, ?string $logoUrl): array
{
    $body = trim($body);
    $logoUrl = user_email_signature_normalize_logo_url($logoUrl);

    if ($body === '' && $logoUrl === null) {
        throw new InvalidArgumentException('Enter signature text or a logo image URL.');
    }

    $signatureText = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $body)));
    if ($signatureText === '' && $logoUrl !== null) {
        $signatureText = '';
    }

    if ($useHtml) {
        $signatureHtml = $body;
    } else {
        $escaped = htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $signatureHtml = nl2br($escaped, false);
    }

    if ($logoUrl !== null) {
        $img = '<img src="' . htmlspecialchars($logoUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" alt="" style="max-width:180px;height:auto;display:block;margin:0 0 8px;">';
        $signatureHtml = $img . $signatureHtml;
    }

    return [
        'signature_html' => $signatureHtml,
        'signature_text' => $signatureText,
        'logo_url' => $logoUrl,
    ];
}

function user_email_signature_save(PDO $pdo, int $userId, string $body, bool $useHtml, ?string $logoUrl): void
{
    user_email_signature_ensure_table($pdo);

    $payload = user_email_signature_build_payload($body, $useHtml, $logoUrl);

    $stmt = $pdo->prepare(
        'INSERT INTO user_email_signatures (user_id, signature_html, signature_text, logo_url, created_at, updated_at)
         VALUES (:user_id, :signature_html, :signature_text, :logo_url, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            signature_html = VALUES(signature_html),
            signature_text = VALUES(signature_text),
            logo_url = VALUES(logo_url),
            updated_at = NOW()'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':signature_html' => $payload['signature_html'],
        ':signature_text' => $payload['signature_text'],
        ':logo_url' => $payload['logo_url'],
    ]);
}

function user_email_signature_delete(PDO $pdo, int $userId): void
{
    user_email_signature_ensure_table($pdo);

    $stmt = $pdo->prepare('DELETE FROM user_email_signatures WHERE user_id = :user_id');
    $stmt->execute([':user_id' => $userId]);
}

function isSignatureAlreadyInjected(string $emailBody): bool
{
    return stripos($emailBody, 'data-crm-email-signature="1"') !== false
        || stripos($emailBody, "data-crm-email-signature='1'") !== false;
}

function appendSignatureToEmailBody(string $emailBody, array $signature): string
{
    $html = trim((string) ($signature['signature_html'] ?? ''));
    if ($html === '') {
        return $emailBody;
    }

    if (isSignatureAlreadyInjected($emailBody)) {
        return $emailBody;
    }

    $block = '<div data-crm-email-signature="1" style="margin-top:16px;padding-top:8px;border-top:1px solid #e2e8f0;">' . $html . '</div>';
    $body = trim($emailBody);

    if ($body === '' || $body === '<p><br></p>' || $body === '<br>') {
        return '<p><br></p>' . $block;
    }

    return $body . '<p><br></p>' . $block;
}

/**
 * JSON-safe payload for compose UI (no raw secrets).
 *
 * @return array{html: string, text: string, logo_url: string}|null
 */
function user_email_signature_apply_to_outgoing_body(PDO $pdo, int $userId, string $body, bool $bodyIsHtml = true): string
{
    if ($userId <= 0 || trim($body) === '') {
        return $body;
    }

    $signature = getUserSignature($pdo, $userId);
    if (!$signature) {
        return $body;
    }

    if ($bodyIsHtml) {
        return appendSignatureToEmailBody($body, $signature);
    }

    $text = trim((string) ($signature['signature_text'] ?? ''));
    if ($text === '') {
        $text = trim(strip_tags((string) ($signature['signature_html'] ?? '')));
    }
    if ($text === '' || stripos($body, $text) !== false) {
        return $body;
    }

    return rtrim($body) . "\n\n" . $text;
}

/**
 * JSON-safe payload for compose UI (no raw secrets).
 *
 * @return array{html: string, text: string, logo_url: string}|null
 */
function user_email_signature_compose_payload(?array $signature): ?array
{
    if (!$signature) {
        return null;
    }

    $html = trim((string) ($signature['signature_html'] ?? ''));
    if ($html === '') {
        return null;
    }

    return [
        'html' => $html,
        'text' => trim((string) ($signature['signature_text'] ?? '')),
        'logo_url' => trim((string) ($signature['logo_url'] ?? '')),
    ];
}
