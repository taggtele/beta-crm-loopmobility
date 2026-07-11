<?php

declare(strict_types=1);

/**
 * AJAX / JSON helpers for Party Account (API-ready envelopes).
 */

function party_account_is_xhr(): bool
{
    return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
}

function party_account_read_json_body(): array
{
    $raw = (string) file_get_contents('php://input');
    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : [];
}

/**
 * Validates CSRF for JSON (`$postedToken`) or classic form posts via `csrf_token`.
 */
function party_account_resolve_csrf_posted(?string $postedJsonToken): string
{
    $posted = trim((string) ($postedJsonToken ?? ''));
    if ($posted !== '') {
        return $posted;
    }

    return trim((string) ($_POST['csrf_token'] ?? ''));
}

function party_account_require_csrf(?string $postedJsonToken): void
{
    app_session_start();

    $sessionToken = $_SESSION['csrf_token'] ?? '';
    $posted = party_account_resolve_csrf_posted($postedJsonToken);

    if ($sessionToken === '' || $posted === '' || !hash_equals($sessionToken, $posted)) {
        throw new RuntimeException('Invalid CSRF token.');
    }
}


function party_account_json_exit(array $payload, int $statusCode = 200): void
{
    ticket_json_response($payload, $statusCode);
}

function party_account_mask_account_number(?string $value): string
{
    $digits = preg_replace('/\D+/', '', (string) $value);

    return $digits === '' ? '-' : ('•••• ' . substr($digits, -4));
}

function party_account_client_ip(): string
{
    $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    foreach ($keys as $key) {
        $raw = (string) ($_SERVER[$key] ?? '');
        if ($raw !== '') {
            $first = explode(',', $raw)[0];

            return trim($first);
        }
    }

    return '';
}
