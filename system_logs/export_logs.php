<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/log_helper.php';

$currentUser = require_login($pdo);
require_role(['Admin']);

export_logs_ensure_schema($pdo);

$search = trim((string) ($_GET['search'] ?? ''));
$actionTypeFilter = strtoupper(trim((string) ($_GET['action_type'] ?? '')));
$exportFormatFilter = trim((string) ($_GET['export_format'] ?? ''));
$statusFilter = strtoupper(trim((string) ($_GET['status'] ?? '')));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));

if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = '';
}
if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = '';
}

$perPageOptions = [25, 50, 100, 200];
$perPage = (int) ($_GET['per_page'] ?? 50);
if (!in_array($perPage, $perPageOptions, true)) {
    $perPage = 50;
}
$page = max(1, (int) ($_GET['page'] ?? 1));

$filters = [];
if ($search !== '') {
    $filters['search'] = $search;
}
if ($actionTypeFilter !== '') {
    $filters['action_type'] = $actionTypeFilter;
}
if ($exportFormatFilter !== '') {
    $filters['export_format'] = $exportFormatFilter;
}
if ($statusFilter !== '') {
    $filters['status'] = $statusFilter;
}
if ($dateFrom !== '') {
    $filters['date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $filters['date_to'] = $dateTo;
}

$result = export_logs_list($pdo, $filters, $page, $perPage);
$logs = $result['logs'];
$totalLogs = $result['total'];
$totalPages = $result['total_pages'];

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
if ($isAjax) {
    header('Content-Type: application/json; charset=UTF-8');
    ob_start();
    include __DIR__ . '/export_logs_table.php';
    $raw = ob_get_clean();

    $tableHtml = '';
    $pagerHtml = '';

    if (preg_match('#<div[^>]*id=["\']el-ajax-table["\'][^>]*>(.*?)</div>\s*#is', $raw, $tableMatch)) {
        $tableHtml = $tableMatch[1];
    } elseif (preg_match('#<div[^>]*id=["\']el-ajax-table["\'][^>]*>(.*?)</div>#is', $raw, $tableMatch)) {
        $tableHtml = $tableMatch[1];
    }

    if (preg_match('#<div[^>]*id=["\']el-ajax-pager["\'][^>]*>(.*?)</div>\s*#is', $raw, $pagerMatch)) {
        $pagerHtml = $pagerMatch[1];
    } elseif (preg_match('#<div[^>]*id=["\']el-ajax-pager["\'][^>]*>(.*?)</div>#is', $raw, $pagerMatch)) {
        $pagerHtml = $pagerMatch[1];
    }

    echo json_encode([
        'table' => $tableHtml,
        'pager' => $pagerHtml,
        'page' => $page,
        'totalPages' => $totalPages,
        'totalLogs' => $totalLogs,
    ]);
    exit;
}

$pageTitle = 'Export Logs';
$pageHeading = 'Export Logs';
$pageDescription = 'Track and audit all data export and import operations across the platform.';
$includeSidebar = true;

include __DIR__ . '/../includes/header.php';
?>

<style>
    :root {
        --bg-main: #f8fafc;
        --bg-card: #ffffff;
        --border-color: #e2e8f0;
        --text-primary: #0f172a;
        --text-secondary: #475569;
        --text-muted: #94a3b8;
        --primary: #2563eb;
        --primary-hover: #1d4ed8;
        --success: #10b981;
        --success-bg: #ecfdf5;
        --danger: #ef4444;
        --danger-bg: #ffeded;
        --info: #0ea5e9;
        --info-bg: #f0f9ff;
        --radius-md: 8px;
        --radius-lg: 12px;
        --shadow-sm: 0 1px 3px 0 rgba(0, 0, 0, 0.05), 0 1px 2px -1px rgba(0, 0, 0, 0.05);
        --font-stack: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    }

    body {
        background-color: var(--bg-main);
        color: var(--text-primary);
        font-family: var(--font-stack);
        margin: 0;
    }

    .page-content {
        padding: 20px 16px;
        max-width: 1600px;
        margin: 0 auto;
        box-sizing: border-box;
    }

    .apd-page-head {
        margin-bottom: 16px;
    }

    .apd-page-head h2 {
        font-size: 1.35rem;
        font-weight: 700;
        color: var(--text-primary);
        margin: 0 0 6px 0;
        letter-spacing: -0.02em;
    }

    .apd-page-head p {
        font-size: 0.7rem;
        color: var(--text-secondary);
        margin: 0;
    }

    .apd-filter-bar {
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: 8px;
        padding: 12px;
        margin-bottom: 16px;
        box-shadow: none;
        display: flex;
        gap: 10px;
        align-items: flex-end;
        flex-wrap: wrap;
    }

    .apd-filter-bar .input-group {
        display: flex;
        flex-direction: column;
        gap: 6px;
        flex: 1 1 140px;
    }

    .apd-filter-bar label {
        font-size: 0.7rem;
        font-weight: 600;
        color: var(--text-secondary);
        text-transform: uppercase;
        letter-spacing: 0.02em;
    }

    .el-search-wrapper {
        position: relative;
        flex: 2 1 220px;
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .el-search-inner {
        position: relative;
        width: 100%;
    }

    .apd-filter-bar input[type="search"],
    .apd-filter-bar input[type="date"],
    .apd-filter-bar select {
        width: 100%;
        height: 36px;
        padding: 7px 10px;
        font-size: 0.8rem;
        font-family: var(--font-stack);
        border: 1px solid var(--border-color);
        border-radius: var(--radius-md);
        background-color: #fff;
        color: var(--text-primary);
        box-sizing: border-box;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }

    .el-search-inner input[type="search"] {
        padding-right: 32px;
    }

    .apd-filter-bar input:focus,
    .apd-filter-bar select:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.08);
    }

    .el-search-spinner {
        position: absolute;
        right: 14px;
        top: 50%;
        transform: translateY(-50%);
        width: 14px;
        height: 14px;
        border: 2px solid var(--border-color);
        border-top-color: var(--primary);
        border-radius: 50%;
        opacity: 0;
        transition: opacity 0.15s ease;
        pointer-events: none;
        box-sizing: border-box;
    }

    .el-search-wrapper[data-loading="1"] .el-search-spinner {
        opacity: 1;
        animation: el-spin 0.6s linear infinite;
    }

    @keyframes el-spin {
        to {
            transform: translateY(-50%) rotate(360deg);
        }
    }

    .apd-filter-bar__actions {
        display: flex;
        gap: 6px;
        height: 36px;
        align-items: center;
    }

    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.8rem;
        font-weight: 600;
        font-family: var(--font-stack);
        border-radius: var(--radius-md);
        padding: 0 14px;
        height: 36px;
        cursor: pointer;
        text-decoration: none;
        transition: background-color 0.15s ease, border-color 0.15s ease, color 0.15s ease;
        border: 1px solid transparent;
        box-sizing: border-box;
        white-space: nowrap;
    }

    .btn-primary {
        background-color: var(--primary);
        color: #fff;
    }

    .btn-primary:hover {
        background-color: var(--primary-hover);
    }

    .btn-outline {
        background-color: transparent;
        border-color: var(--border-color);
        color: var(--text-secondary);
    }

    .btn-outline:hover {
        background-color: #f1f5f9;
        color: var(--text-primary);
        border-color: var(--text-muted);
    }

    .apd-table-scroll {
        transition: opacity 0.2s ease;
    }

    .apd-table-scroll[data-loading="1"] {
        position: relative;
        min-height: 100px;
        opacity: 0.5;
        pointer-events: none;
    }

    @media (max-width: 992px) {
        .apd-filter-bar {
            flex-direction: column;
            align-items: stretch;
        }

        .apd-filter-bar .input-group,
        .el-search-wrapper {
            flex: 1 1 auto;
        }

        .apd-filter-bar__actions {
            margin-top: 8px;
            justify-content: flex-end;
        }
    }

    html.theme-dark {
        --bg-main: #07111f;
        --bg-card: #0f1b2d;
        --border-color: #223553;
        --text-primary: #e2e8f0;
        --text-secondary: #9fb0c7;
        --text-muted: #9fb0c7;
        --primary: #60a5fa;
        --primary-hover: #3b82f6;
        --success: #4ade80;
        --success-bg: rgba(34, 197, 94, 0.18);
        --danger: #fca5a5;
        --danger-bg: rgba(239, 68, 68, 0.18);
        --info: #7cc2ff;
        --info-bg: rgba(59, 130, 246, 0.2);
    }
</style>

<main class="page-content">
    <div class="apd apd--logs">
        <div class="apd-page-head">
            <h2><?php echo e($pageHeading); ?></h2>
            <p><?php echo e($pageDescription); ?></p>
        </div>

        <form method="GET" class="apd-filter-bar" id="el-filters" autocomplete="off">
            <div class="el-search-wrapper" id="el-search-wrapper">
                <label for="el-search">Search</label>
                <div class="el-search-inner">
                    <input type="search" id="el-search" name="search" value="<?php echo e($search); ?>" placeholder="Search username, module, or page..." autofocus>
                    <span class="el-search-spinner" id="el-search-spinner"></span>
                </div>
            </div>
            <div class="input-group">
                <label for="el-action">Action</label>
                <select id="el-action" name="action_type">
                    <option value="">All Actions</option>
                    <option value="EXPORT" <?php echo $actionTypeFilter === 'EXPORT' ? 'selected' : ''; ?>>Export</option>
                    <option value="IMPORT" <?php echo $actionTypeFilter === 'IMPORT' ? 'selected' : ''; ?>>Import</option>
                </select>
            </div>
            <div class="input-group">
                <label for="el-format">Format</label>
                <select id="el-format" name="export_format">
                    <option value="">All Formats</option>
                    <option value="Excel" <?php echo $exportFormatFilter === 'Excel' ? 'selected' : ''; ?>>Excel</option>
                    <option value="CSV" <?php echo $exportFormatFilter === 'CSV' ? 'selected' : ''; ?>>CSV</option>
                    <option value="PDF" <?php echo $exportFormatFilter === 'PDF' ? 'selected' : ''; ?>>PDF</option>
                    <option value="XLSX" <?php echo $exportFormatFilter === 'XLSX' ? 'selected' : ''; ?>>XLSX</option>
                </select>
            </div>
            <div class="input-group">
                <label for="el-status">Status</label>
                <select id="el-status" name="status">
                    <option value="">All Statuses</option>
                    <option value="SUCCESS" <?php echo $statusFilter === 'SUCCESS' ? 'selected' : ''; ?>>Success</option>
                    <option value="FAILED" <?php echo $statusFilter === 'FAILED' ? 'selected' : ''; ?>>Failed</option>
                </select>
            </div>
            <div class="input-group">
                <label for="el-date-from">Date From</label>
                <input type="date" id="el-date-from" name="date_from" value="<?php echo e($dateFrom); ?>">
            </div>
            <div class="input-group">
                <label for="el-date-to">Date To</label>
                <input type="date" id="el-date-to" name="date_to" value="<?php echo e($dateTo); ?>">
            </div>
            <div class="input-group" style="flex: 0 1 160px;">
                <label for="el-per-page">Per Page</label>
                <select id="el-per-page" name="per_page">
                    <?php foreach ($perPageOptions as $opt): ?>
                        <option value="<?php echo e((string) $opt); ?>" <?php echo $perPage === $opt ? 'selected' : ''; ?>><?php echo e((string) $opt); ?> records</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="apd-filter-bar__actions">
                <button type="submit" class="btn btn-primary">Execute Filter</button>
                <a href="<?php echo e(url('system_logs/export_logs.php')); ?>" class="btn btn-outline">Reset Grid</a>
                <button type="button" class="btn btn-outline" id="el-btn-export-page">Export This Page</button>
            </div>
        </form>

        <?php include __DIR__ . '/export_logs_table.php'; ?>
    </div>
</main>

<script>
    (function() {
        var tableContainer = document.querySelector('.apd-table-scroll');
        var pagerContainer = document.querySelector('.apd-pagination-footer');
        var form = document.getElementById('el-filters');
        var perPageSelect = document.getElementById('el-per-page');
        var searchInput = document.getElementById('el-search');
        var searchWrapper = document.getElementById('el-search-wrapper');
        var actionSelect = document.getElementById('el-action');
        var formatSelect = document.getElementById('el-format');
        var statusSelect = document.getElementById('el-status');
        var dateFromInput = document.getElementById('el-date-from');
        var dateToInput = document.getElementById('el-date-to');
        var debounceTimer = null;
        var currentRequest = null;

        function setLoading(loading) {
            if (!tableContainer) return;
            if (loading) {
                tableContainer.setAttribute('data-loading', '1');
                if (searchWrapper) searchWrapper.setAttribute('data-loading', '1');
            } else {
                tableContainer.removeAttribute('data-loading');
                if (searchWrapper) searchWrapper.removeAttribute('data-loading');
            }
        }

        function buildQueryString(extraParams) {
            var params = new URLSearchParams();
            var searchVal = searchInput ? searchInput.value.trim() : '';
            if (searchVal) params.append('search', searchVal);
            if (actionSelect && actionSelect.value) params.append('action_type', actionSelect.value);
            if (formatSelect && formatSelect.value) params.append('export_format', formatSelect.value);
            if (statusSelect && statusSelect.value) params.append('status', statusSelect.value);
            if (dateFromInput && dateFromInput.value) params.append('date_from', dateFromInput.value);
            if (dateToInput && dateToInput.value) params.append('date_to', dateToInput.value);
            if (perPageSelect) params.append('per_page', perPageSelect.value);
            if (extraParams && extraParams.page) params.append('page', extraParams.page);
            return params.toString();
        }

        function abortCurrent() {
            if (currentRequest) {
                currentRequest.abort();
                currentRequest = null;
            }
        }

        function showError(message) {
            if (!tableContainer) return;
            tableContainer.innerHTML = '<div style="padding:40px 24px;text-align:center;color:var(--danger);font-weight:600;background:var(--bg-card);border:1px solid var(--border-color);border-radius:var(--radius-lg);">' + message + '</div>';
        }

        function bindPagerButtons() {
            var buttons = document.querySelectorAll('.pagination-btn:not(.disabled)');
            buttons.forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    var href = btn.getAttribute('href');
                    if (!href) return;
                    var url = new URL(href, window.location.origin);
                    var page = url.searchParams.get('page');
                    if (page) loadPage(parseInt(page, 10));
                });
            });
        }

        function loadPage(page) {
            if (!tableContainer) return;
            setLoading(true);
            abortCurrent();
            var xhr = new XMLHttpRequest();
            currentRequest = xhr;
            xhr.open('GET', '<?php echo e(url('system_logs/export_logs.php')); ?>?' + buildQueryString({
                page: page
            }), true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.onreadystatechange = function() {
                if (xhr.readyState !== 4) return;
                setLoading(false);
                if (xhr.status === 200) {
                    try {
                        var data = JSON.parse(xhr.responseText);

                        var tempDiv = document.createElement('div');
                        tempDiv.innerHTML = data.table;
                        var dynamicScroll = tempDiv.querySelector('.apd-table-scroll');
                        if (dynamicScroll && tableContainer) {
                            tableContainer.innerHTML = dynamicScroll.innerHTML;
                        } else if (data.table) {
                            tableContainer.innerHTML = data.table;
                        }

                        if (data.pager && pagerContainer) {
                            pagerContainer.innerHTML = data.pager;
                            bindPagerButtons();
                        }
                    } catch (e) {
                        showError('Critical Parsing Exception: Render pipeline failed.');
                    }
                    if (window.history.replaceState) {
                        window.history.replaceState(null, '', '?' + buildQueryString({
                            page: page
                        }));
                    }
                } else if (xhr.status !== 0) {
                    showError('Communication Fault Event (' + xhr.status + ')');
                }
                if (currentRequest === xhr) currentRequest = null;
            };
            xhr.send();
        }

        function submitFilters(resetPage) {
            if (!tableContainer) return;
            setLoading(true);
            abortCurrent();
            var page = resetPage ? 1 : undefined;
            var qs = buildQueryString(page ? {
                page: page
            } : undefined);
            var url = '<?php echo e(url('system_logs/export_logs.php')); ?>?' + qs;
            var xhr = new XMLHttpRequest();
            currentRequest = xhr;
            xhr.open('GET', url, true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.onreadystatechange = function() {
                if (xhr.readyState !== 4) return;
                setLoading(false);
                if (xhr.status === 200) {
                    try {
                        var data = JSON.parse(xhr.responseText);

                        var tempDiv = document.createElement('div');
                        tempDiv.innerHTML = data.table;
                        var dynamicScroll = tempDiv.querySelector('.apd-table-scroll');
                        if (dynamicScroll && tableContainer) {
                            tableContainer.innerHTML = dynamicScroll.innerHTML;
                        } else if (data.table) {
                            tableContainer.innerHTML = data.table;
                        }

                        if (data.pager && pagerContainer) {
                            pagerContainer.innerHTML = data.pager;
                            bindPagerButtons();
                        }
                    } catch (e) {
                        showError('System Exception: Invalid asynchronous stream payload.');
                    }
                    if (window.history.replaceState) {
                        window.history.replaceState(null, '', '?' + qs);
                    }
                } else if (xhr.status !== 0) {
                    showError('Server Request Termination (' + xhr.status + ')');
                }
                if (currentRequest === xhr) currentRequest = null;
            };
            xhr.send();
        }

        if (searchInput) {
            searchInput.addEventListener('input', function() {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(function() {
                    submitFilters(true);
                }, 250);
            });
        }

        [actionSelect, formatSelect, statusSelect, dateFromInput, dateToInput, perPageSelect].forEach(function(element) {
            if (element) {
                element.addEventListener('change', function() {
                    submitFilters(true);
                });
            }
        });

        if (form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                submitFilters(true);
            });
        }

        var exportBtn = document.getElementById('el-btn-export-page');
        if (exportBtn) {
            exportBtn.addEventListener('click', async function() {
                var body = {
                    csrf_token: '<?php echo e(csrf_token()); ?>',
                    action: 'export_logs',
                    search: searchInput ? searchInput.value.trim() : '',
                    action_type: actionSelect ? actionSelect.value : '',
                    export_format: formatSelect ? formatSelect.value : '',
                    status: statusSelect ? statusSelect.value : '',
                    date_from: dateFromInput ? dateFromInput.value : '',
                    date_to: dateToInput ? dateToInput.value : '',
                    per_page: perPageSelect ? perPageSelect.value : 1000,
                };

                try {
                    var r = await fetch('<?php echo e(url('system_logs/ajax/export.php')); ?>', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body: JSON.stringify(body),
                    });
                    if (r.ok) {
                        var b = await r.blob();
                        var disp = r.headers.get('Content-Disposition') || '';
                        var nm = (disp.match(/filename="([^"]+)"/) || [])[1] || 'export-logs.csv';
                        var a = document.createElement('a');
                        a.href = URL.createObjectURL(b);
                        a.download = nm;
                        a.click();
                        URL.revokeObjectURL(a.href);
                    } else {
                        showError('Export failed with status ' + r.status);
                    }
                } catch (e) {
                    showError('Export failed due to network error.');
                }
            });
        }

        bindPagerButtons();
    })();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
