<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/includes/auth.php';

/**
 * Party Account RBAC middleware.
 *
 * Admin     — full access (all CRM + this module).
 * Finance   — full access to this module only (other CRM routes redirect).
 * Sales     — view-only on this module (list, detail, filters).
 * Agent     — no access.
 */

function party_account_middleware_gate_view(array $currentUser): void
{
    rbac_require_party_accounts_view($currentUser);
}

function party_account_middleware_gate_manage(array $currentUser): void
{
    rbac_require_party_accounts_manage($currentUser);
}

/** @deprecated alias — use gate_view */
function party_account_middleware_gate_ledger(array $currentUser): void
{
    if (!rbac_is_admin($currentUser) && !rbac_is_finance($currentUser)) {
        http_response_code(403);
        exit('Access denied.');
    }
}

function party_account_middleware_gate_ledger_admin(array $currentUser): void
{
    if (!rbac_is_admin($currentUser)) {
        http_response_code(403);
        exit('Access denied.');
    }
}

function party_account_middleware_gate(array $currentUser): void
{
    party_account_middleware_gate_view($currentUser);
}

/** @return array{can_view: bool, can_manage: bool, role: string} */
function party_account_permissions_for_user(array $currentUser): array
{
    return [
        'can_view' => rbac_can_view_party_accounts($currentUser),
        'can_manage' => rbac_can_manage_party_accounts($currentUser),
        'role' => rbac_current_role($currentUser),
    ];
}
