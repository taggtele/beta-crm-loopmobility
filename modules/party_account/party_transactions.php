<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/services/PartyLedgerService.php';
require_once __DIR__ . '/helpers/transaction_export.php';
require_once __DIR__ . '/middleware/require_party_account_access.php';
require_once __DIR__ . '/../../system_logs/log_helper.php';

$currentUser = require_login($pdo);
party_account_middleware_gate_ledger($currentUser);

function h($v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function party_tx_query(array $overrides = []): string
{
    $allowed = [
        'party_id' => '',
        'party_ids' => [],
        'currency' => '',
        'from_date' => '',
        'to_date' => '',
        'status' => '',
        'q' => '',
        'sort' => '',
        'dir' => '',
        'page' => '',
        'export' => '',
    ];
    $query = array_merge($allowed, array_intersect_key($_GET, $allowed), $overrides);
    foreach ($query as $key => $value) {
        if ($value === '' || $value === null || $value === []) {
            unset($query[$key]);
            continue;
        }
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $item) {
                if ($item === '' || $item === null) {
                    continue;
                }
                $clean[] = $item;
            }
            if ($clean === []) {
                unset($query[$key]);
                continue;
            }
            $query[$key] = array_values($clean);
        }
    }

    return http_build_query($query);
}

function party_tx_icon(string $name, int $size = 16): string
{
    if (function_exists('lucide_icon_svg')) {
        return lucide_icon_svg($name, ['size' => $size, 'class' => 'tx-icon']);
    }

    return '';
}

$partyIds = party_transactions_extract_party_ids($_GET['party_ids'] ?? null);
if ($partyIds === []) {
    $partyIds = party_transactions_extract_party_ids($_GET['party_id'] ?? null);
}
$currency = trim((string) ($_GET['currency'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
$q = trim((string) ($_GET['q'] ?? ''));
$sort = in_array($_GET['sort'] ?? '', ['party_name', 'invoice_period', 'payment_in_date', 'payment_out_date', 'customer_invoice_no', 'customer_invoice_value', 'vendor_invoice_no', 'vendor_invoice_value', 'payment_in', 'payment_out', 'running_balance', 'status', 'created_by'], true) ? (string) $_GET['sort'] : 'invoice_period';
$dir = (($_GET['dir'] ?? '') === 'asc') ? 'ASC' : 'DESC';
$page = max(1, (int) ($_GET['page'] ?? 1));
$per = (int) ($_GET['per_page'] ?? 20);
if (!in_array($per, [10, 20, 50, 100], true)) {
    $per = 20;
}
$offset = ($page - 1) * $per;

$filters = [];
$params = [];
if ($partyIds !== []) {
    if (count($partyIds) === 1) {
        $filters[] = 'lt.party_account_id = ?';
        $params[] = $partyIds[0];
    } else {
        $placeholders = implode(',', array_fill(0, count($partyIds), '?'));
        $filters[] = 'lt.party_account_id IN (' . $placeholders . ')';
        foreach ($partyIds as $id) {
            $params[] = $id;
        }
    }
}
if ($currency !== '') {
    $filters[] = 'lt.currency = ?';
    $params[] = $currency;
}
if ($status === 'closed') {
    $filters[] = "EXISTS (SELECT 1 FROM party_ledger_monthly_closing c WHERE c.party_account_id = lt.party_account_id AND c.period_month = lt.invoice_period AND c.status = 'closed')";
} elseif ($status === 'open') {
    $filters[] = "NOT EXISTS (SELECT 1 FROM party_ledger_monthly_closing c WHERE c.party_account_id = lt.party_account_id AND c.period_month = lt.invoice_period AND c.status = 'closed')";
}
if ($q !== '') {
    $filters[] = '(lt.customer_invoice_no LIKE ? OR lt.vendor_invoice_no LIKE ? OR pa.party_name LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

$where = array_merge(['lt.deleted_at IS NULL', 'pa.deleted_at IS NULL'], $filters);

$countSql = 'SELECT COUNT(*) FROM party_ledger_transactions lt JOIN party_accounts pa ON pa.id = lt.party_account_id WHERE ' . implode(' AND ', $where);
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();

$summarySql = '
    SELECT
        COALESCE(COUNT(*), 0) AS total_transactions,
        COALESCE(SUM(lt.customer_invoice_value), 0) AS total_customer_invoice_value,
        COALESCE(SUM(lt.vendor_invoice_value), 0) AS total_vendor_invoice_value,
        COALESCE(SUM(lt.payment_in), 0) AS total_payment_in,
        COALESCE(SUM(lt.payment_out), 0) AS total_payment_out,
        COALESCE(SUM(lt.customer_invoice_value - lt.vendor_invoice_value - lt.payment_in + lt.payment_out), 0) AS net_balance
    FROM party_ledger_transactions lt
    JOIN party_accounts pa ON pa.id = lt.party_account_id
    WHERE ' . implode(' AND ', $where);
$stmt = $pdo->prepare($summarySql);
$stmt->execute($params);
$summary = $stmt->fetch() ?: [];

$sortMap = [
    'party_name' => 'pa.party_name',
    'currency' => 'lt.currency',
    'invoice_period' => 'lt.invoice_period',
    'payment_in_date' => 'lt.payment_in_date',
    'payment_out_date' => 'lt.payment_out_date',
    'customer_invoice_no' => 'lt.customer_invoice_no',
    'customer_invoice_value' => 'lt.customer_invoice_value',
    'vendor_invoice_no' => 'lt.vendor_invoice_no',
    'vendor_invoice_value' => 'lt.vendor_invoice_value',
    'payment_in' => 'lt.payment_in',
    'payment_out' => 'lt.payment_out',
    'running_balance' => 'lt.customer_invoice_value - lt.vendor_invoice_value - lt.payment_in + lt.payment_out',
    'status' => "CASE WHEN EXISTS (SELECT 1 FROM party_ledger_monthly_closing c WHERE c.party_account_id = lt.party_account_id AND c.period_month = lt.invoice_period AND c.status = 'closed') THEN 'closed' ELSE 'open' END",
    'created_by' => 'lt.created_by',
];

$sql = '
    SELECT
        lt.*,
        pa.party_name,
        CASE
            WHEN EXISTS (
                SELECT 1
                FROM party_ledger_monthly_closing c
                WHERE c.party_account_id = lt.party_account_id
                  AND c.period_month = lt.invoice_period
                  AND c.status = \'closed\'
            ) THEN \'closed\'
            ELSE \'open\'
        END AS derived_status
    FROM party_ledger_transactions lt
    JOIN party_accounts pa ON pa.id = lt.party_account_id
    WHERE ' . implode(' AND ', $where) . '
    ORDER BY ' . ($sortMap[$sort] ?? 'lt.invoice_period') . ' ' . $dir . ', lt.id ' . $dir . '
    LIMIT ? OFFSET ?';
$stmt = $pdo->prepare($sql);
$queryParams = $params;
$queryParams[] = $per;
$queryParams[] = $offset;
$stmt->execute($queryParams);
$rows = $stmt->fetchAll();

$exportFormat = strtolower((string) ($_GET['export'] ?? ''));
if ($exportFormat === 'xlsx') {
    $exportSql = '
        SELECT
            lt.*,
            pa.party_name,
            CASE
                WHEN EXISTS (
                    SELECT 1
                    FROM party_ledger_monthly_closing c
                    WHERE c.party_account_id = lt.party_account_id
                      AND c.period_month = lt.invoice_period
                      AND c.status = \'closed\'
                ) THEN \'closed\'
                ELSE \'open\'
            END AS derived_status
        FROM party_ledger_transactions lt
        JOIN party_accounts pa ON pa.id = lt.party_account_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY ' . ($sortMap[$sort] ?? 'lt.invoice_period') . ' ' . $dir . ', lt.id ' . $dir;
    $exportStmt = $pdo->prepare($exportSql);
    $exportStmt->execute($params);
    $exportRows = $exportStmt->fetchAll();

    $filenameBase = 'party-transactions-' . date('Ymd-His');

    $exportPartyIds = $partyIds !== []
        ? $partyIds
        : party_transactions_extract_party_ids(array_column($exportRows, 'party_account_id'));

    $groupedRows = party_transactions_group_rows_by_party($exportRows);
    $sheetPayloads = [];

    if ($exportPartyIds !== []) {
        $partyPlaceholders = implode(',', array_fill(0, count($exportPartyIds), '?'));
        $partyInfoSql = '
            SELECT
                pa.*,
                le.name AS loop_entity_name
            FROM party_accounts pa
            LEFT JOIN loop_entities le ON le.id = pa.loop_entity_id
            WHERE pa.deleted_at IS NULL AND pa.id IN (' . $partyPlaceholders . ')
            ORDER BY pa.party_name ASC, pa.id ASC';
        $partyInfoStmt = $pdo->prepare($partyInfoSql);
        $partyInfoStmt->execute($exportPartyIds);
        $partyInfoRows = $partyInfoStmt->fetchAll();

        foreach ($partyInfoRows as $partyRow) {
            $sheetPayloads[] = party_transactions_build_sheet_payload($groupedRows, $partyRow);
        }
    }

    if ($sheetPayloads === []) {
        $sheetPayloads[] = [
            'sheet_name' => 'Party Transactions',
            'party' => [
                'party_name' => 'No transactions found',
            ],
            'rows' => [],
            'summary' => [
                'total_transactions' => 0,
                'total_credit' => 0.0,
                'total_debit' => 0.0,
                'opening_balance' => 0.0,
                'closing_balance' => 0.0,
                'current_balance' => 0.0,
            ],
            'currency_label' => '-',
            'has_opening_balance' => false,
        ];
    }

    $totalRecords = count($exportRows);

    $userId = isset($currentUser['user_id']) && $currentUser['user_id'] !== '' ? (int) $currentUser['user_id'] : null;
    $userName = $currentUser['name'] ?? $currentUser['user_id'] ?? null;
    $filterSnapshot = [];
    if ($partyIds !== []) {
        $filterSnapshot['party_ids'] = $partyIds;
    }
    if ($currency !== '') {
        $filterSnapshot['currency'] = $currency;
    }
    if ($status !== '') {
        $filterSnapshot['status'] = $status;
    }
    if ($q !== '') {
        $filterSnapshot['search'] = $q;
    }
    if ($filterSnapshot === []) {
        $filterSnapshot = null;
    }

    log_export_activity(
        $pdo,
        $userId,
        $userName,
        'Party Transactions',
        'Party Transactions',
        'EXPORT',
        'XLSX',
        $totalRecords,
        $filterSnapshot,
        'SUCCESS',
        null
    );

    party_transactions_export_xlsx($sheetPayloads, $filenameBase);
}

$pageEyebrow = 'Finance';
$pageHeading = 'Party Transactions';
$pageDescription = 'Transaction explorer and reporting for party accounts.';
$includeSidebar = true;
$extraStylesheets = ['assets/css/pages/party-ledger.css'];

require_once __DIR__ . '/../../includes/header.php';

$pageTitle = 'Party Transactions';

$partyStmt = $pdo->prepare('SELECT id, party_name FROM party_accounts WHERE deleted_at IS NULL ORDER BY party_name ASC');
$partyStmt->execute();
$partyList = $partyStmt->fetchAll();

$pages = max(1, (int) ceil(max(1, $total) / $per));
$startRow = $total > 0 ? $offset + 1 : 0;
$endRow = $total > 0 ? min($offset + $per, $total) : 0;
$hasFilters = $partyIds !== [] || $status !== '' || $q !== '';
?>

<div class="container-fluid">
    <section class="pl-head mb-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h1 class="pt-title fs-3 fw-bold mb-1">Party Transactions</h1>
                <p class="pt-subtitle fs-6 text-muted mb-0">Transaction explorer and reporting for party accounts.</p>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <a href="<?php echo e(url('modules/party_account/index.php')); ?>" class="btn btn-light btn-sm tx-icon-btn"><?php echo party_tx_icon('panel_left_close', 16); ?><span>Back</span></a>
                <a href="<?php echo e(url('modules/party_account/party_transactions.php?' . party_tx_query(['export' => 'xlsx']))); ?>" class="btn btn-outline-primary btn-sm tx-icon-btn"><?php echo party_tx_icon('download', 16); ?><span>Export XLSX</span></a>
                <button type="button" id="toggle-filters" class="btn btn-outline-secondary btn-sm tx-icon-btn"><?php echo party_tx_icon('panel_left_close', 16); ?><span>Show Filters</span></button>
            </div>
        </div>
    </section>

    <section class="pl-filters card mb-3 sticky-filter" id="filters-panel" style="display:none;">
        <div class="card-body">
            <form method="get" id="pt-filter-form" class="pt-filter-grid">
                <div class="filter-col">
                    <label class="form-label small">Party</label>
                    <select name="party_ids[]" class="form-select pt-input pt-party-multi" multiple size="5">
                        <?php foreach ($partyList as $party): ?>
                            <option value="<?php echo h($party['id']); ?>" <?php echo in_array((int) $party['id'], $partyIds, true) ? 'selected' : ''; ?>><?php echo h($party['party_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Leave blank for all parties. Hold Ctrl/Cmd to pick multiple.</small>
                </div>
                <div class="filter-col">
                    <label class="form-label small">Currency</label>
                    <select name="currency" class="form-select pt-input">
                        <option value="">All Currencies</option>
                        <?php foreach (party_account_currencies() as $cur): ?>
                            <option value="<?php echo h($cur); ?>" <?php echo $currency === $cur ? 'selected' : ''; ?>><?php echo h($cur); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-col">
                    <label class="form-label small">Status</label>
                    <select name="status" class="form-select pt-input">
                        <option value="">Any</option>
                        <option value="open" <?php echo $status === 'open' ? 'selected' : ''; ?>>Open</option>
                        <option value="closed" <?php echo $status === 'closed' ? 'selected' : ''; ?>>Closed</option>
                    </select>
                </div>
                <div class="filter-col filter-col-wide">
                    <label class="form-label small">Search</label>
                    <div class="input-group">
                        <span class="input-group-text"><?php echo party_tx_icon('search', 16); ?></span>
                        <input type="search" name="q" value="<?php echo h($q); ?>" class="form-control pt-input" placeholder="Invoice, Party">
                    </div>
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary pt-btn tx-icon-btn"><?php echo party_tx_icon('sliders_horizontal', 16); ?><span>Apply Filters</span></button>
                    <a href="<?php echo e(url('modules/party_account/party_transactions.php')); ?>" class="btn btn-outline-secondary pt-btn tx-icon-btn"><?php echo party_tx_icon('circle_help', 16); ?><span>Reset</span></a>
                </div>
            </form>
        </div>
    </section>

    <div class="kpi-cards mb-3">
        <div class="tx-summary-strip">
            <div class="tx-summary-card tx-summary-card-total">
                <div class="tx-summary-head">
                    <div class="tx-summary-icon tx-summary-icon-total"><?php echo party_tx_icon('ticket', 18); ?></div>
                    <div class="text-muted small">Total Transactions</div>
                </div>
                <div class="tx-summary-value"><?php echo h((int) ($summary['total_transactions'] ?? 0)); ?></div>
            </div>
            <div class="tx-summary-card tx-summary-card-customer">
                <div class="tx-summary-head">
                    <div class="tx-summary-icon tx-summary-icon-customer"><?php echo party_tx_icon('square_plus', 18); ?></div>
                    <div class="text-muted small">Customer Invoice Value</div>
                </div>
                <div class="tx-summary-value"><?php echo h(number_format((float) ($summary['total_customer_invoice_value'] ?? 0), 2)); ?></div>
            </div>
            <div class="tx-summary-card tx-summary-card-vendor">
                <div class="tx-summary-head">
                    <div class="tx-summary-icon tx-summary-icon-vendor"><?php echo party_tx_icon('inbox', 18); ?></div>
                    <div class="text-muted small">Vendor Invoice Value</div>
                </div>
                <div class="tx-summary-value"><?php echo h(number_format((float) ($summary['total_vendor_invoice_value'] ?? 0), 2)); ?></div>
            </div>
            <div class="tx-summary-card tx-summary-card-payment-in">
                <div class="tx-summary-head">
                    <div class="tx-summary-icon tx-summary-icon-in"><?php echo party_tx_icon('zap', 18); ?></div>
                    <div class="text-muted small">Payment In</div>
                </div>
                <div class="tx-summary-value text-success"><?php echo h(number_format((float) ($summary['total_payment_in'] ?? 0), 2)); ?></div>
            </div>
            <div class="tx-summary-card tx-summary-card-payment-out">
                <div class="tx-summary-head">
                    <div class="tx-summary-icon tx-summary-icon-out"><?php echo party_tx_icon('download', 18); ?></div>
                    <div class="text-muted small">Payment Out</div>
                </div>
                <div class="tx-summary-value text-danger"><?php echo h(number_format((float) ($summary['total_payment_out'] ?? 0), 2)); ?></div>
            </div>
            <div class="tx-summary-card tx-summary-card-emphasis">
                <div class="tx-summary-head">
                    <div class="tx-summary-icon tx-summary-icon-net"><?php echo party_tx_icon('landmark', 18); ?></div>
                    <div class="text-muted small">Net Balance</div>
                </div>
                <div class="tx-summary-value"><?php echo h(number_format((float) ($summary['net_balance'] ?? 0), 2)); ?></div>
            </div>
        </div>
    </div>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <div class="text-muted small">Showing <?php echo h($startRow); ?> - <?php echo h($endRow); ?> of <?php echo h($total); ?> transactions<?php echo $hasFilters ? ' with filters applied' : ''; ?></div>
        <div class="tx-toolbar-actions">
            <form method="get" id="toolbar-search-form" class="tx-toolbar-search">
                <?php foreach ($partyIds as $selectedPartyId): ?>
                    <input type="hidden" name="party_ids[]" value="<?php echo h($selectedPartyId); ?>">
                <?php endforeach; ?>
                <input type="hidden" name="status" value="<?php echo h($status); ?>">
                <input type="hidden" name="sort" value="<?php echo h($sort); ?>">
                <input type="hidden" name="dir" value="<?php echo h(strtolower($dir)); ?>">
                <input type="hidden" name="per_page" value="<?php echo h($per); ?>">
                <input type="hidden" name="page" value="1">
                <div class="input-group input-group-sm tx-search-group">
                    <span class="input-group-text tx-search-lens"><?php echo party_tx_icon('search', 16); ?></span>
                    <input id="toolbar-search" type="search" name="q" class="form-control" placeholder="Search party, invoice, period..." value="<?php echo h($q); ?>">
                    <?php if ($q !== ''): ?>
                        <button type="button" id="toolbar-search-clear" class="btn btn-outline-secondary"><?php echo party_tx_icon('circle_help', 16); ?></button>
                    <?php endif; ?>
                </div>
            </form>
            <button id="toolbar-refresh" class="btn btn-outline-secondary btn-sm tx-refresh-btn" type="button" aria-label="Refresh results"><?php echo party_tx_icon('panel_left_close', 16); ?></button>
            <a href="<?php echo e(url('modules/party_account/party_transactions.php')); ?>" class="btn btn-outline-secondary btn-sm tx-icon-btn tx-reset-btn"><?php echo party_tx_icon('circle_help', 16); ?><span>Reset</span></a>
        </div>
    </div>

    <section class="pl-table-card card">
        <div class="card-header d-flex justify-content-between align-items-center gap-2">
            <div>
                <strong>Party Transactions</strong>
                <div class="small text-muted">Showing <?php echo h($startRow); ?> - <?php echo h($endRow); ?> of <?php echo h($total); ?> records</div>
            </div>
            <div class="small text-muted"><?php echo $hasFilters ? 'Filtered view' : 'All records'; ?></div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0 align-middle">
                    <thead>
                        <tr>
                            <th><a href="?<?php echo h(party_tx_query(['sort' => 'party_name', 'dir' => $sort === 'party_name' && $dir === 'ASC' ? 'desc' : 'asc', 'page' => 1])); ?>">Party Name</a></th>
                            <th><a href="?<?php echo h(party_tx_query(['sort' => 'currency', 'dir' => $sort === 'currency' && $dir === 'ASC' ? 'desc' : 'asc', 'page' => 1])); ?>">Currency</a></th>
                            <th><a href="?<?php echo h(party_tx_query(['sort' => 'invoice_period', 'dir' => $sort === 'invoice_period' && $dir === 'ASC' ? 'desc' : 'asc', 'page' => 1])); ?>">Invoice Period</a></th>
                            <th><a href="?<?php echo h(party_tx_query(['sort' => 'customer_invoice_no', 'dir' => $sort === 'customer_invoice_no' && $dir === 'ASC' ? 'desc' : 'asc', 'page' => 1])); ?>">Customer Invoice No</a></th>
                            <th class="text-end"><a href="?<?php echo h(party_tx_query(['sort' => 'customer_invoice_value', 'dir' => $sort === 'customer_invoice_value' && $dir === 'ASC' ? 'desc' : 'asc', 'page' => 1])); ?>">Customer Invoice Value</a></th>
                            <th><a href="?<?php echo h(party_tx_query(['sort' => 'vendor_invoice_no', 'dir' => $sort === 'vendor_invoice_no' && $dir === 'ASC' ? 'desc' : 'asc', 'page' => 1])); ?>">Vendor Invoice No</a></th>
                            <th class="text-end"><a href="?<?php echo h(party_tx_query(['sort' => 'vendor_invoice_value', 'dir' => $sort === 'vendor_invoice_value' && $dir === 'ASC' ? 'desc' : 'asc', 'page' => 1])); ?>">Vendor Invoice Value</a></th>
                            <th class="text-end"><a href="?<?php echo h(party_tx_query(['sort' => 'payment_in', 'dir' => $sort === 'payment_in' && $dir === 'ASC' ? 'desc' : 'asc', 'page' => 1])); ?>">Payment In</a></th>
                            <th class="text-end"><a href="?<?php echo h(party_tx_query(['sort' => 'payment_in_date', 'dir' => $sort === 'payment_in_date' && $dir === 'ASC' ? 'desc' : 'asc', 'page' => 1])); ?>">Payment In Date</a></th>
                            <th class="text-end"><a href="?<?php echo h(party_tx_query(['sort' => 'payment_out', 'dir' => $sort === 'payment_out' && $dir === 'ASC' ? 'desc' : 'asc', 'page' => 1])); ?>">Payment Out</a></th>
                            <th class="text-end"><a href="?<?php echo h(party_tx_query(['sort' => 'payment_out_date', 'dir' => $sort === 'payment_out_date' && $dir === 'ASC' ? 'desc' : 'asc', 'page' => 1])); ?>">Payment Out Date</a></th>
                            <th class="text-end"><a href="?<?php echo h(party_tx_query(['sort' => 'running_balance', 'dir' => $sort === 'running_balance' && $dir === 'ASC' ? 'desc' : 'asc', 'page' => 1])); ?>">Net Balance</a></th>
                            <th><a href="?<?php echo h(party_tx_query(['sort' => 'status', 'dir' => $sort === 'status' && $dir === 'ASC' ? 'desc' : 'asc', 'page' => 1])); ?>">Status</a></th>
                            <th><a href="?<?php echo h(party_tx_query(['sort' => 'created_by', 'dir' => $sort === 'created_by' && $dir === 'ASC' ? 'desc' : 'asc', 'page' => 1])); ?>">Created By</a></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr>
                                <td colspan="12">
                                    <div class="empty-state-card text-center my-3">
                                        <div class="title">No transactions found</div>
                                        <div class="desc">No results match your current filters. Try adjusting the filters or clearing the search.</div>
                                    </div>
                                </td>
                            </tr>
                        <?php else: foreach ($rows as $r): ?>
                            <?php
                                $st = (string) ($r['derived_status'] ?? 'open');
                                $badge = $st === 'closed' ? 'danger' : 'success';
                            ?>
                            <tr>
                                <td><?php echo h($r['party_name']); ?></td>
                                <td><?php echo h($r['currency']); ?></td>
                                <td><?php echo h($r['invoice_period']); ?></td>
                                <td><?php echo h($r['customer_invoice_no']); ?></td>
                                <td class="text-end currency"><?php echo h(number_format((float) $r['customer_invoice_value'], 2)); ?></td>
                                <td><?php echo h($r['vendor_invoice_no']); ?></td>
                                <td class="text-end currency"><?php echo h(number_format((float) $r['vendor_invoice_value'], 2)); ?></td>
                                <td class="text-end currency <?php echo ((float) $r['payment_in'] > 0) ? 'text-success' : ''; ?>"><?php echo h(number_format((float) $r['payment_in'], 2)); ?></td>
                                <td><?php echo h($r['payment_in_date'] ?? ''); ?></td>
                                <td class="text-end currency <?php echo ((float) $r['payment_out'] > 0) ? 'text-danger' : ''; ?>"><?php echo h(number_format((float) $r['payment_out'], 2)); ?></td>
                                <td><?php echo h($r['payment_out_date'] ?? ''); ?></td>
                                <td class="text-end currency <?php echo (((float) $r['customer_invoice_value'] - (float) $r['vendor_invoice_value'] - (float) $r['payment_in'] + (float) $r['payment_out']) < 0) ? 'text-danger' : ''; ?>"><?php echo h(number_format((float) ($r['customer_invoice_value'] - $r['vendor_invoice_value'] - $r['payment_in'] + $r['payment_out']), 2)); ?></td>
                                <td><span class="badge bg-<?php echo $badge; ?>"><?php echo h(ucfirst($st)); ?></span></td>
                                <td><?php echo h((string) ($r['created_by'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer tx-footer">
            <div class="tx-pagination-layout">
                <div class="tx-pagination-info">
                    <div class="small text-muted">Showing <?php echo h($startRow); ?> - <?php echo h($endRow); ?> of <?php echo h($total); ?> records</div>
                    <div class="tx-pagination-meta small text-muted">Page <?php echo h($page); ?> of <?php echo h($pages); ?></div>
                    <form method="get" class="tx-per-page">
                        <?php foreach ($partyIds as $selectedPartyId): ?>
                            <input type="hidden" name="party_ids[]" value="<?php echo h($selectedPartyId); ?>">
                        <?php endforeach; ?>
                        <input type="hidden" name="status" value="<?php echo h($status); ?>">
                        <input type="hidden" name="q" value="<?php echo h($q); ?>">
                        <input type="hidden" name="sort" value="<?php echo h($sort); ?>">
                        <input type="hidden" name="dir" value="<?php echo h(strtolower($dir)); ?>">
                        <input type="hidden" name="page" value="1">
                        <label class="small text-muted mb-0">Per page</label>
                        <select name="per_page" class="form-select form-select-sm" onchange="this.form.submit()">
                            <?php foreach ([10, 20, 50, 100] as $pageSize): ?>
                                <option value="<?php echo h($pageSize); ?>" <?php echo $per === $pageSize ? 'selected' : ''; ?>><?php echo h($pageSize); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
                <nav class="tx-pagination" aria-label="Party transactions pagination">
                    <ul class="pagination pagination-sm mb-0 tx-pagination-list">
                        <li class="page-item <?php echo ($page === 1) ? 'disabled' : ''; ?>"><a class="page-link" href="?<?php echo h(party_tx_query(['page' => 1])); ?>" aria-label="First page">First</a></li>
                        <li class="page-item <?php echo ($page === 1) ? 'disabled' : ''; ?>"><a class="page-link" href="?<?php echo h(party_tx_query(['page' => max(1, $page - 1)])); ?>" aria-label="Previous page">Prev</a></li>
                        <?php
                        $visiblePages = [];
                        $visiblePages[] = 1;
                        $visiblePages[] = $pages;
                        for ($p = max(1, $page - 1); $p <= min($pages, $page + 1); $p++) {
                            $visiblePages[] = $p;
                        }
                        $visiblePages = array_values(array_unique(array_filter($visiblePages, static fn ($v) => $v >= 1 && $v <= $pages)));
                        sort($visiblePages);
                        $prevPage = 0;
                        foreach ($visiblePages as $p):
                        ?>
                            <?php if ($prevPage !== 0 && $p - $prevPage > 1): ?>
                                <li class="page-item disabled"><span class="page-link tx-ellipsis">&hellip;</span></li>
                            <?php endif; ?>
                            <li class="page-item <?php echo ($p === $page) ? 'active' : ''; ?>"><a class="page-link" href="?<?php echo h(party_tx_query(['page' => $p])); ?>" aria-current="<?php echo ($p === $page) ? 'page' : 'false'; ?>"><?php echo h($p); ?></a></li>
                        <?php
                            $prevPage = $p;
                        endforeach;
                        ?>
                        <li class="page-item <?php echo ($page >= $pages) ? 'disabled' : ''; ?>"><a class="page-link" href="?<?php echo h(party_tx_query(['page' => min($pages, $page + 1)])); ?>" aria-label="Next page">Next</a></li>
                        <li class="page-item <?php echo ($page >= $pages) ? 'disabled' : ''; ?>"><a class="page-link" href="?<?php echo h(party_tx_query(['page' => $pages])); ?>" aria-label="Last page">Last</a></li>
                    </ul>
                </nav>
            </div>
        </div>
    </section>

    <div id="pt-skeleton" style="display:none;" class="mt-3">
        <?php for ($i = 0; $i < 6; $i++): ?>
            <div class="skeleton skeleton-row"></div>
        <?php endfor; ?>
    </div>
</div>

<script>
(function () {
    var form = document.getElementById('pt-filter-form');
    var skeleton = document.getElementById('pt-skeleton');
    var table = document.querySelector('.table-responsive');
    var refresh = document.getElementById('toolbar-refresh');
    var search = document.getElementById('toolbar-search');
    var searchForm = document.getElementById('toolbar-search-form');
    var searchClear = document.getElementById('toolbar-search-clear');
    function showSkeleton() {
        if (!skeleton || !table) return;
        table.style.display = 'none';
        skeleton.style.display = 'block';
    }
    function hideSkeleton() {
        if (!skeleton || !table) return;
        skeleton.style.display = 'none';
        table.style.display = 'block';
    }
    if (form) {
        form.addEventListener('submit', function () {
            showSkeleton();
        });
    }
    if (refresh) {
        refresh.addEventListener('click', function (e) {
            e.preventDefault();
            showSkeleton();
            window.location.reload();
        });
    }
    if (search) {
        search.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && searchForm) {
                e.preventDefault();
                searchForm.submit();
            }
        });
    }
    if (searchClear && search) {
        searchClear.addEventListener('click', function () {
            search.value = '';
            if (searchForm) {
                searchForm.submit();
            }
        });
    }
    var toggleFilters = document.getElementById('toggle-filters');
    var filtersPanel = document.getElementById('filters-panel');
    if (toggleFilters && filtersPanel) {
        var hidden = true;
        try {
            var saved = window.localStorage.getItem('party_transactions_filters_hidden');
            if (saved !== null) {
                hidden = saved === '1';
            }
        } catch (e) {}
        var label = toggleFilters.querySelector('span');
        function renderFiltersState() {
            filtersPanel.style.display = hidden ? 'none' : '';
            toggleFilters.innerHTML = hidden
                ? '<?php echo party_tx_icon("panel_left_close", 16); ?><span>Show Filters</span>'
                : '<?php echo party_tx_icon("panel_left_close", 16); ?><span>Hide Filters</span>';
        }
        renderFiltersState();
        toggleFilters.addEventListener('click', function () {
            hidden = !hidden;
            try {
                window.localStorage.setItem('party_transactions_filters_hidden', hidden ? '1' : '0');
            } catch (e) {}
            renderFiltersState();
        });
    }
    window.addEventListener('load', hideSkeleton);
})();
</script>

<?php include __DIR__ . '/../../includes/footer.php';
