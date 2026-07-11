<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/middleware/require_party_account_access.php';

$currentUser = require_login($pdo);
party_account_middleware_gate_ledger($currentUser);
party_account_ensure_schema($pdo);

$pageTitle = 'Party Ledger';
$pageEyebrow = 'Finance';
$pageHeading = 'Party Ledger';
$pageDescription = 'Party-wise ledger, month closing, and statements.';
$includeSidebar = true;
$extraStylesheets = ['assets/css/pages/party-ledger.css'];

$config = [
    'csrf' => csrf_token(),
    'role' => (string) ($currentUser['role'] ?? ''),
    'is_admin' => rbac_is_admin($currentUser),
    'endpoints' => [
        'ledger' => url('modules/party_account/ajax/ledger.php'),
        'export' => url('modules/party_account/ajax/ledger_export.php'),
    ],
    'currencies' => party_account_currencies(),
    'currency_symbols' => array_column(array_map(function($c) { return ['currency' => $c, 'symbol' => party_account_currency_symbol($c)]; }, party_account_currencies()), 'symbol', 'currency'),
];

require_once dirname(__DIR__, 2) . '/includes/header.php';
require __DIR__ . '/views/ledger_workspace.php';
?>
<div id="pa-toasts" class="pa-toasts" aria-live="polite" aria-atomic="true"></div>
<script>
window.PartyLedgerBootstrap = <?= json_encode($config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
</script>
<script defer src="<?= e(url('assets/js/pages/party-ledger.js')); ?>"></script>
<?php
require_once dirname(__DIR__, 2) . '/includes/footer.php';
