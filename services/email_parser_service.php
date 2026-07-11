<?php

/**
 * Normalizes ticket reference tokens so matching stays consistent.
 * Strips brackets, whitespace, leading #, forces uppercase.
 */
function email_parser_normalize_ticket_reference(string $reference): string
{
    $reference = strtoupper(trim($reference));
    $reference = trim($reference, "[]()<> \t\n\r\0\x0B");

    if (str_starts_with($reference, '#')) {
        $reference = ltrim($reference, '#');
    }

    return $reference;
}

/**
 * Returns true if the reference looks like a meaningful ticket ID.
 * Requirements:
 * - Minimum 4 characters total
 * - Must contain at least one digit (no purely alphabetic strings)
 * - If pure numeric, must be at least 6 digits
 */
function email_parser_is_valid_ticket_id(string $ref): bool
{
    $ref = email_parser_normalize_ticket_reference($ref);
    if ($ref === '') return false;

    // Must have at least one digit (excludes "NUMBER", "ABC", etc.)
    if (!preg_match('/\d/', $ref)) return false;

    // Minimum length 4 (excludes "A1", "AB1", very short)
    if (strlen($ref) < 4) return false;

    // Pure numeric IDs must be at least 6 digits to avoid random numbers
    if (ctype_digit($ref) && strlen($ref) < 6) return false;

    return true;
}

// Internal ticket IDs are the app-generated LM-YYYYMMDD-NN references.
function email_parser_is_internal_ticket_reference(string $reference): bool
{
    $reference = email_parser_normalize_ticket_reference($reference);

    return (bool) preg_match('/^LM-\d{8}-\d{1,6}$/', $reference);
}

/**
 * Extracts internal ticket IDs that agents/customers include in mail text.
 * Supports the visible LM serial plus explicit numeric internal_ticket_id labels.
 */
function email_parser_extract_internal_ticket_references(string $subject, string $body): array
{
    $source = $subject . "\n" . mb_substr($body, 0, 4000);
    $matches = [];

    $patterns = [
        '/\b(LM-\d{8}-\d{1,6})\b/i',
        '/\b(?:internal[_\s-]*ticket[_\s-]*id|internal[_\s-]*id|noc[_\s-]*ticket|ticket[_\s-]*id)\s*[:#-]?\s*(\d{1,18})\b/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match_all($pattern, $source, $found)) {
            foreach ($found[1] as $value) {
                $normalized = email_parser_normalize_ticket_reference((string) $value);
                if ($normalized !== '') {
                    $matches[] = $normalized;
                }
            }
        }
    }

    return array_values(array_unique($matches));
}

// Checks whether an outgoing message manually contains the selected ticket ID.
function email_parser_message_has_internal_ticket_id(string $subject, string $body, int $ticketId, string $ticketSerial): bool
{
    $source = strtoupper($subject . "\n" . $body);
    $serial = email_parser_normalize_ticket_reference($ticketSerial);

    if ($serial !== '' && str_contains($source, $serial)) {
        return true;
    }

    if ($ticketId > 0) {
        $patterns = [
            '/\bINTERNAL[_\s-]*TICKET[_\s-]*ID\s*[:#-]?\s*' . preg_quote((string) $ticketId, '/') . '\b/',
            '/\bINTERNAL[_\s-]*ID\s*[:#-]?\s*' . preg_quote((string) $ticketId, '/') . '\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $source)) {
                return true;
            }
        }
    }

    return false;
}

// Configurable: minimum issue length to auto-create ticket (prevent spam)
const EMAIL_MIN_ISSUE_LENGTH = 5; // reduced from 10

// Configurable: subject patterns that should NEVER create a ticket even if ID found
const EMAIL_SUBJECT_BLACKLIST = [
    // keep empty to allow all subjects, or add only truly generic ones
    // 'hello', 'hi', 'hey', 'test', 'testing', 'check', 'checking',
    // 'spam', 'junk', 'xyz', 'abc', 'sample', 'demo', 'fw:', 'fwd:',
    // 'thank you', 'thanks', 'regards', 'sincerely', 'best regards',
    // 'notification', 'alert', 'warning', 'error', 'issue',
]; // emptied to allow test subjects

// Configurable: sender domains that are allowed to auto-create (empty = allow all)
const EMAIL_ALLOWED_SENDER_DOMAINS = []; // Empty = all allowed

// Configurable: minimum body length when no ticket ID present
const EMAIL_MIN_BODY_LENGTH = 5; // reduced from 20

// Configurable vendor/client external ID patterns. Keys can be:
// - default
// - exact sender email, e.g. support@vendor.com
// - sender domain with @, e.g. @vendor.com
// - sender domain without @, e.g. vendor.com
const EMAIL_VENDOR_EXTERNAL_PATTERNS = [
    'default' => [
        '/\b(TKT-[A-Z0-9-]{2,100})\b/i',
        '/\b(TT-[A-Z0-9-]{2,100})\b/i',
        '/\b(TCK-[A-Z0-9-]{2,100})\b/i',
        '/\b(INC-[A-Z0-9-]{2,100})\b/i',
        '/\b(SR-[A-Z0-9-]{2,100})\b/i',
        '/\b(TT[-\s]?[<\[(]?[A-Z0-9]{5,}[]\)>]?)\b/i',
        '/\b(TCK[-\s]?[<\[(]?[A-Z0-9]{5,}[]\)>]?)\b/i',
        '/\b(?:external(?:\s+ticket)?(?:\s+id)?|vendor(?:\s+ticket)?(?:\s+id)?|case|reference|ref)\s*#\s*([A-Z0-9][A-Z0-9-]{2,100})\b/i',
        '/\b(?:external(?:\s+ticket)?(?:\s+id)?|vendor(?:\s+ticket)?(?:\s+id)?|case|reference|ref)\s*[:#-]?\s*([A-Z0-9][A-Z0-9-]{2,100})\b/i',
        '/\b(?:ticket\s+number|case\s+id|ref\s+id)\s*[:#-]?\s*(\d{6,})\b/i',
        '/\b(?:case|ref)\s+(\d{6,})\b/i',
    ],
];

function email_parser_patterns_for_sender(string $fromEmail): array
{
    $fromEmail = strtolower(trim($fromEmail));
    $domain = strtolower(trim(explode('@', $fromEmail)[1] ?? ''));
    $patterns = EMAIL_VENDOR_EXTERNAL_PATTERNS['default'] ?? [];

    foreach ([$fromEmail, '@' . $domain, $domain] as $key) {
        if ($key !== '' && isset(EMAIL_VENDOR_EXTERNAL_PATTERNS[$key])) {
            $patterns = array_merge($patterns, EMAIL_VENDOR_EXTERNAL_PATTERNS[$key]);
        }
    }

    return array_values(array_unique($patterns));
}

/**
 * Extracts candidate external ticket references from email subject + body.
 * Internal LM-* references are intentionally excluded from external ID output.
 */
function email_parser_extract_ticket_references(string $subject, string $body, string $fromEmail = ''): array
{
    $source = $subject . "\n" . mb_substr($body, 0, 4000);
    $matches = [];
    $patterns = email_parser_patterns_for_sender($fromEmail);

    foreach ($patterns as $pattern) {
        if (preg_match_all($pattern, $source, $found)) {
            foreach ($found[1] as $value) {
                $normalized = email_parser_normalize_ticket_reference((string) $value);
                if (email_parser_is_valid_ticket_id($normalized) && !email_parser_is_internal_ticket_reference($normalized)) {
                    $matches[] = $normalized;
                }
            }
        }
    }

    return array_values(array_unique($matches));
}

/**
 * Detects external ticket ID from email. Returns NULL if none valid found.
 * Prioritizes non-numeric IDs (alphanumeric) over pure digits.
 */
function email_parser_detect_external_ticket_id(string $subject, string $body, string $fromEmail = ''): ?string
{
    $candidates = email_parser_extract_ticket_references($subject, $body, $fromEmail);

    if (empty($candidates)) {
        return null;
    }

    // Prefer alphanumeric over pure numeric
    foreach ($candidates as $candidate) {
        if (!ctype_digit($candidate) && !email_parser_is_internal_ticket_reference($candidate)) {
            return $candidate;
        }
    }

    // All numeric or internal-looking? Return the first non-internal candidate.
    foreach ($candidates as $candidate) {
        if (!email_parser_is_internal_ticket_reference($candidate)) {
            return $candidate;
        }
    }

    return null;
}

/**
 * Determines if this email should be allowed to create a new ticket.
 * Guardrails prevent spam/generic emails from flooding the system.
 *
 * @return bool true if allowed to create, false to ignore
 */
function email_parser_should_create_ticket(string $subject, string $body, string $fromEmail, ?string $externalTicketId, string $issue): bool
{
    // If external ticket ID already detected, always allow
    if ($externalTicketId !== null && $externalTicketId !== '') {
        return true;
    }

    // Issue must meet minimum length
    $issueTrimmed = trim($issue);
    if (strlen($issueTrimmed) < EMAIL_MIN_ISSUE_LENGTH) {
        return false;
    }

    // Subject blacklist - only check if blacklist not empty
    if (!empty(EMAIL_SUBJECT_BLACKLIST)) {
        $subjectLower = strtolower(trim($subject));
        foreach (EMAIL_SUBJECT_BLACKLIST as $blacklisted) {
            if ($subjectLower === $blacklisted || str_starts_with($subjectLower, $blacklisted)) {
                return false;
            }
        }
    }

    // Body must be at least minimum length
    $bodyTrimmed = trim($body);
    if (strlen($bodyTrimmed) < EMAIL_MIN_BODY_LENGTH) {
        return false;
    }

    // Sender domain whitelist (if configured)
    if (!empty(EMAIL_ALLOWED_SENDER_DOMAINS)) {
        $domain = strtolower(trim(explode('@', $fromEmail)[1] ?? ''));
        if (!in_array($domain, array_map('strtolower', EMAIL_ALLOWED_SENDER_DOMAINS), true)) {
            return false; // Sender domain not in whitelist
        }
    }

    // All checks passed - allow ticket creation
    return true;
}
