<?php

declare(strict_types=1);

/**
 * Party / Party AM mapping / Email logs access for CRM roles.
 * Admin: full manage. Agent: read parties/mapping/logs; manage mail on logs.
 * Finance: Party Account manage only (scoped). Sales: read-only on Party Account, parties, mapping, mail logs.
 */

function rbac_current_role(?array $user = null): string
{
    if ($user !== null) {
        return (string) ($user['role'] ?? '');
    }

    app_session_start();

    return (string) ($_SESSION['role'] ?? '');
}

function rbac_is_admin(?array $user = null): bool
{
    return rbac_current_role($user) === 'Admin';
}

function rbac_is_agent(?array $user = null): bool
{
    return rbac_current_role($user) === 'Agent';
}

function rbac_is_finance(?array $user = null): bool
{
    return rbac_current_role($user) === 'Finance';
}

function rbac_is_sales(?array $user = null): bool
{
    return rbac_current_role($user) === 'Sales';
}

/**
 * Party Account module access.
 * Admin: view + manage. Finance: view + manage (CRM scoped to this module only).
 * Sales: view only. Agent: denied.
 */
function rbac_can_view_party_accounts(?array $user = null): bool
{
    return rbac_is_admin($user) || rbac_is_finance($user) || rbac_is_sales($user);
}

function rbac_can_manage_party_accounts(?array $user = null): bool
{
    return rbac_is_admin($user) || rbac_is_finance($user);
}

function rbac_require_party_accounts_view(?array $user = null): void
{
    if (!rbac_can_view_party_accounts($user)) {
        http_response_code(403);
        exit('Access denied.');
    }
}

function rbac_require_party_accounts_manage(?array $user = null): void
{
    if (!rbac_can_manage_party_accounts($user)) {
        http_response_code(403);
        exit('Access denied.');
    }
}

/** @deprecated use rbac_require_party_accounts_view or rbac_require_party_accounts_manage */
function rbac_require_party_accounts_access(?array $user = null): void
{
    rbac_require_party_accounts_view($user);
}

/**
 * Combined path blob for scope checks (front controller sets PHP_SELF to index.php).
 */
function rbac_request_path_blob(): string
{
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $self = $_SERVER['PHP_SELF'] ?? '';
    $script = $_SERVER['SCRIPT_NAME'] ?? '';

    return strtolower(str_replace('\\', '/', trim((string) $uri . ' ' . (string) $self . ' ' . (string) $script)));
}

/**
 * Finance users may only use Party Account (+ profile, logout, notification endpoints).
 */
function rbac_finance_request_is_allowed(): bool
{
    $path = rbac_request_path_blob();

    $needles = [
        'party_account',
        '/profile',
        'auth/logout.php',
        'notifications_stream',
        '/modules/notifications/',
    ];

    foreach ($needles as $needle) {
        if (str_contains($path, strtolower($needle))) {
            return true;
        }
    }

    return false;
}

function rbac_enforce_finance_party_account_scope(): void
{
    if (!rbac_is_finance()) {
        return;
    }

    if (rbac_finance_request_is_allowed()) {
        return;
    }

    redirect('modules/party_account/index.php');
}

/** Post-login / guest redirect target for Finance (Party Account workspace). */
function rbac_finance_home_path(): string
{
    return 'modules/party_account/index.php';
}

function rbac_can_read_parties(?array $user = null): bool
{
    return rbac_is_admin($user) || rbac_is_agent($user) || rbac_is_sales($user);
}

function rbac_can_manage_parties(?array $user = null): bool
{
    return rbac_is_admin($user);
}

function rbac_can_read_party_mapping(?array $user = null): bool
{
    return rbac_is_admin($user) || rbac_is_agent($user) || rbac_is_sales($user);
}

function rbac_can_manage_party_mapping(?array $user = null): bool
{
    return rbac_is_admin($user);
}

/** Email Logs page: browse incoming/outgoing mail (read-only for Sales). */
function rbac_can_read_email_logs(?array $user = null): bool
{
    return rbac_is_admin($user) || rbac_is_agent($user) || rbac_is_sales($user);
}

/** Compose, reply, map unmapped, and other write actions on Email Logs. */
function rbac_can_manage_email_logs(?array $user = null): bool
{
    return rbac_is_admin($user) || rbac_is_agent($user);
}

/** Email Logs: mailbox-wide visibility (same as Admin) for support trackability. */
function rbac_email_logs_full_visibility(?array $user = null): bool
{
    return rbac_is_admin($user) || rbac_is_agent($user) || rbac_is_sales($user);
}

function rbac_require_party_read(): void
{
    if (!rbac_can_read_parties()) {
        http_response_code(403);
        exit('Access denied.');
    }
}

function rbac_require_party_manage(): void
{
    if (!rbac_can_manage_parties()) {
        http_response_code(403);
        exit('Access denied.');
    }
}

function rbac_require_party_mapping_read(): void
{
    if (!rbac_can_read_party_mapping()) {
        http_response_code(403);
        exit('Access denied.');
    }
}

function rbac_require_party_mapping_manage(): void
{
    if (!rbac_can_manage_party_mapping()) {
        http_response_code(403);
        exit('Access denied.');
    }
}

function rbac_require_email_logs_read(?array $user = null): void
{
    if (!rbac_can_read_email_logs($user)) {
        http_response_code(403);
        exit('Access denied.');
    }
}

function rbac_require_email_logs_manage(?array $user = null): void
{
    if (!rbac_can_manage_email_logs($user)) {
        http_response_code(403);
        exit('Access denied.');
    }
}

/** Ticket status changes and auto-acknowledgement toggles on ticket view page. */
function rbac_can_change_ticket_status(?array $user = null): bool
{
    return rbac_is_admin($user) || rbac_is_agent($user);
}

function rbac_require_ticket_status_change(?array $user = null): void
{
    if (!rbac_can_change_ticket_status($user)) {
        http_response_code(403);
        exit('Access denied.');
    }
}

/** Release Management page: view live version, features, and history. */
function rbac_can_read_release_management(?array $user = null): bool
{
    return rbac_is_admin($user) || rbac_is_agent($user);
}

/** Publish or edit releases (Admin only). */
function rbac_can_manage_release_management(?array $user = null): bool
{
    return rbac_is_admin($user);
}

function rbac_require_release_management_read(?array $user = null): void
{
    if (!rbac_can_read_release_management($user)) {
        http_response_code(403);
        exit('Access denied.');
    }
}

function rbac_require_release_management_manage(?array $user = null): void
{
    if (!rbac_can_manage_release_management($user)) {
        http_response_code(403);
        exit('Access denied.');
    }
}

function rbac_deny_post_for_readonly(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    rbac_require_party_manage();
}
