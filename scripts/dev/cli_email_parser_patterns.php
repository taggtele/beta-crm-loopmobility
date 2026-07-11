<?php

declare(strict_types=1);

/**
 * CLI dev helper: validates email_parser external-ID patterns + guardrails.
 * Run from project root: php scripts/dev/cli_email_parser_patterns.php
 */
require_once __DIR__ . '/../../services/email_parser_service.php';

echo "=== TESTING PARSER PATTERNS ===\n\n";

$testCases = [
    ['TT-123123', 'body', 'TT-123123'],
    ['TT<452457823>', 'body', 'TT-452457823'],
    ['TCK-2483746174', 'body', 'TCK-2483746174'],
    ['Ticket TT-123456', 'body', 'TT-123456'],
    ['Ref: TCK-98765', 'body', 'TCK-98765'],
    ['Ticket LM-20260429-01 - Printer issue', 'body', 'LM-20260429-01'],
    ['Re: Ticket LM-20260429-01', 'body', 'LM-20260429-01'],
    ['Case #TKT-98765: Login', 'body', 'TKT-98765'],
    ['Ref: ABC-12345', 'body', 'ABC-12345'],
    ['Your ticket number: 123456789', 'body', '123456789'],
    ['Ticket 123456', 'body', '123456'],
    ['Ticket 123', 'body', null],
    ['Code 4567', 'body', null],
    ['[TT-123456]', 'body', 'TT-123456'],
    ['(TCK-987654)', 'body', 'TCK-987654'],
    ['<LM-20260429-01>', 'body', 'LM-20260429-01'],
    ['Hello world', 'body', null],
    ['Test email', 'body', null],
];

foreach ($testCases as [$subject, $body, $expected]) {
    $detected = email_parser_detect_external_ticket_id($subject, $body);
    $status = ($detected === $expected) ? '✓ PASS' : '✗ FAIL';
    echo "$status | Subject: '$subject'\n";
    echo '       Expected: ' . var_export($expected, true) . ', Got: ' . var_export($detected, true) . "\n\n";
}

echo "\n=== TESTING GUARDRAILS ===\n\n";

$guardrailTests = [
    ['Subject: TT-123456 - Urgent issue', 'Body: Printer is broken...', 'customer@example.com', true],
    ['Ticket LM-20260429-01', 'Body: The system is down...', 'vendor@company.com', true],
    ['TCK-987654', 'Body: Need assistance with login', 'support@client.com', true],
    ['Hello', 'Body: Just saying hi', 'test@example.com', false],
    ['Test', 'Body: Testing 1 2 3', 'test@example.com', false],
    ['Hi there', 'Body: Hi there', 'spam@example.com', false],
    ['Thanks', 'Body: Thank you', 'noreply@example.com', false],
    ['Brief', 'OK', 'user@example.com', false],
    ['Update', 'Done', 'user@example.com', false],
    ['TT-123456', 'OK', 'user@example.com', true],
];

foreach ($guardrailTests as [$subject, $body, $fromEmail, $shouldCreate]) {
    $externalId = email_parser_detect_external_ticket_id($subject, $body);
    $issue = ($externalId !== null) ? 'Issue with ID' : 'Short text';
    if ($externalId === null) {
        $issue = mb_substr(trim($body ?: $subject), 0, 50);
    }
    $allowed = email_parser_should_create_ticket($subject, $body, $fromEmail, $externalId, $issue);
    $status = ($allowed === $shouldCreate) ? '✓ PASS' : '✗ FAIL';
    echo "$status | '{$subject}' | ExternalID=" . var_export($externalId, true) . ' | ShouldCreate=' . var_export($shouldCreate, true) . ' | Got=' . var_export($allowed, true) . "\n";
}
