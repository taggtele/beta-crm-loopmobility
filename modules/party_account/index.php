<?php

declare(strict_types=1);

/**
 * Party Account workspace — Admin/Finance (manage), Sales (view-only).
 */

require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/middleware/require_party_account_access.php';

$currentUser = require_login($pdo);
party_account_middleware_gate_view($currentUser);
party_account_ensure_schema($pdo);

$paPermissions = party_account_permissions_for_user($currentUser);

$pageTitle = 'Party Account';
$pageEyebrow = 'Finance';
$pageHeading = 'Party Account';
$pageDescription = 'Finance profiles: contact, banking, credit, loop entity.';
$includeSidebar = true;

$config = [
    'csrf' => csrf_token(),
    'endpoints' => [
        'datatable' => url('modules/party_account/ajax/datatable.php'),
        'account' => url('modules/party_account/ajax/account.php'),
        'bulk' => url('modules/party_account/ajax/bulk.php'),
        'entities' => url('modules/party_account/ajax/loop_entities.php'),
        'export' => url('modules/party_account/ajax/export.php'),
        'import' => url('modules/party_account/ajax/import.php'),
        'ledger_page' => url('modules/party_account/ledger.php'),
    ],
    'permissions' => $paPermissions,
    'defaults' => [
        'currency' => 'INR',
        'scope' => 'live',
        'sort' => ['key' => 'updated_at', 'dir' => 'desc'],
    ],
    'statuses' => party_account_statuses(),
    'currencies' => party_account_currencies(),
    'currency_symbols' => array_column(array_map(function($c) { return ['currency' => $c, 'symbol' => party_account_currency_symbol($c)]; }, party_account_currencies()), 'symbol', 'currency'),
    'phone_countries' => party_account_country_phone_catalog(),
    'default_country' => 'India',
    'viewer_name' => (string) ($currentUser['name'] ?? ''),
];

$extraStylesheets = [
    'assets/css/pages/party-account.css',
];

require_once dirname(__DIR__, 2) . '/includes/header.php';
require __DIR__ . '/views/workspace.php';
?>

<div id="pa-toasts" class="pa-toasts" aria-live="polite" aria-atomic="true"></div>
<script>
window.PartyAccountBootstrap = <?= json_encode(
    $config,
    JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
); ?>;
</script>
<script defer src="<?= e(url('assets/js/pages/party-account.js')); ?>"></script>

<?php
require_once dirname(__DIR__, 2) . '/includes/footer.php';
