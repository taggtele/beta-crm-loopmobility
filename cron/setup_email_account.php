<?php
/**
 * One-off CLI: inserts a row into email_accounts.
 * Do not deploy with hardcoded credentials; use environment variables only.
 *
 * Usage (PowerShell):
 *   $env:SETUP_EMAIL_ACCOUNT_EMAIL="user@domain.com"
 *   $env:SETUP_EMAIL_ACCOUNT_PASSWORD="app-password"
 *   php cron/setup_email_account.php
 */
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('This script is CLI-only.');
}

require_once __DIR__ . '/../config/db.php';

$email = trim((string) (getenv('SETUP_EMAIL_ACCOUNT_EMAIL') ?: ''));
$password = (string) (getenv('SETUP_EMAIL_ACCOUNT_PASSWORD') ?: '');
$userIdRaw = getenv('SETUP_EMAIL_ACCOUNT_USER_DB_ID');
$userId = ($userIdRaw !== false && $userIdRaw !== '') ? (int) $userIdRaw : 1;

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
    fwrite(STDERR, "Missing or invalid SETUP_EMAIL_ACCOUNT_EMAIL / SETUP_EMAIL_ACCOUNT_PASSWORD.\n");
    exit(1);
}

$imap_host = trim((string) (getenv('SETUP_EMAIL_IMAP_HOST') ?: 'outlook.office365.com'));
$imap_port = (int) (getenv('SETUP_EMAIL_IMAP_PORT') ?: 993);
$imap_encryption = trim((string) (getenv('SETUP_EMAIL_IMAP_ENCRYPTION') ?: 'ssl'));
$smtp_host = trim((string) (getenv('SETUP_EMAIL_SMTP_HOST') ?: 'smtp.office365.com'));
$smtp_port = (int) (getenv('SETUP_EMAIL_SMTP_PORT') ?: 587);
$smtp_encryption = trim((string) (getenv('SETUP_EMAIL_SMTP_ENCRYPTION') ?: 'tls'));

$stmt = $pdo->prepare(
    'INSERT INTO email_accounts (
        user_id,
        email,
        password,
        imap_host,
        imap_port,
        encryption,
        smtp_host,
        smtp_port,
        smtp_encryption,
        is_active
    ) VALUES (
        :user_id,
        :email,
        :password,
        :imap_host,
        :imap_port,
        :encryption,
        :smtp_host,
        :smtp_port,
        :smtp_encryption,
        1
    )'
);

$stmt->execute([
    ':user_id' => $userId,
    ':email' => $email,
    ':password' => $password,
    ':imap_host' => $imap_host,
    ':imap_port' => $imap_port,
    ':encryption' => $imap_encryption,
    ':smtp_host' => $smtp_host,
    ':smtp_port' => $smtp_port,
    ':smtp_encryption' => $smtp_encryption,
]);

echo 'Email account added id=' . $pdo->lastInsertId(), "\n";
