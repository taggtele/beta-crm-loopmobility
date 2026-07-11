<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/log_helper.php';

$currentUser = require_login($pdo);
require_role(['Admin']);

system_logs_ensure_schema($pdo);

// Sanitize inputs
$search = trim((string) ($_GET['search'] ?? ''));
$statusFilter = strtoupper(trim((string) ($_GET['status'] ?? 'ALL')));
if (!in_array($statusFilter, ['ALL', 'SUCCESS', 'FAILED'], true)) {
    $statusFilter = 'ALL';
}
$dateFilter = trim((string) ($_GET['date'] ?? ''));

$today = date('Y-m-d');
if ($dateFilter !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFilter)) {
    $dateFilter = '';
}

$perPageOptions = [25, 50, 100, 200];
$perPage = (int) ($_GET['per_page'] ?? 50);
if (!in_array($perPage, $perPageOptions, true)) {
    $perPage = 50;
}
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

// Build Query safely with distinct named parameters to prevent HY093 errors
$where = [];
$params = [];

if ($dateFilter !== '') {
    $where[] = 'DATE(sl.login_time) = :date_filter';
    $params[':date_filter'] = $dateFilter;
}

if ($statusFilter !== 'ALL') {
    $where[] = 'sl.status = :status_filter';
    $params[':status_filter'] = $statusFilter;
}

if ($search !== '') {
    $where[] = '(sl.user_name LIKE :search_user OR sl.login_identifier LIKE :search_ident OR sl.ip_address LIKE :search_ip)';
    $params[':search_user']  = '%' . $search . '%';
    $params[':search_ident'] = '%' . $search . '%';
    $params[':search_ip']    = '%' . $search . '%';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Count Total Records using distinct parameter mappings
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM system_logs sl {$whereSql}");
foreach ($params as $key => $val) {
    $countStmt->bindValue($key, $val, PDO::PARAM_STR);
}
$countStmt->execute();
$totalLogs = (int) $countStmt->fetchColumn();

// Fetch System Metrics Dashboard
$statsStmt = $pdo->prepare(
    "SELECT
        SUM(CASE WHEN attempt_type = 'LOGIN' AND DATE(login_time) = :today1 THEN 1 ELSE 0 END) AS total_logins_today,
        SUM(CASE WHEN attempt_type = 'LOGIN' AND status = 'SUCCESS' AND DATE(login_time) = :today2 THEN 1 ELSE 0 END) AS successful_today,
        SUM(CASE WHEN attempt_type = 'LOGIN' AND status = 'FAILED' AND DATE(login_time) = :today3 THEN 1 ELSE 0 END) AS failed_today,
        COUNT(DISTINCT CASE WHEN attempt_type = 'LOGIN' AND logout_time IS NULL THEN user_id END) AS active_users
     FROM system_logs"
);
$statsStmt->execute([':today1' => $today, ':today2' => $today, ':today3' => $today]);
$stats = $statsStmt->fetch() ?: [];

// Calculate Pagination Boundaries
$totalPages = (int) ceil($totalLogs / $perPage);
if ($totalPages < 1) {
    $totalPages = 1;
}
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

// Core Data Fetch Statement
$stmt = $pdo->prepare(
    "SELECT sl.user_name, sl.login_identifier, sl.status, sl.ip_address, sl.location, sl.browser, sl.device, sl.os, sl.login_time, sl.logout_time, sl.created_at
     FROM system_logs sl {$whereSql}
     ORDER BY sl.created_at DESC
     LIMIT :limit OFFSET :offset"
);

foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val, PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll() ?: [];

// Intercept Ajax Fragment Requests
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
if ($isAjax) {
    header('Content-Type: application/json; charset=UTF-8');
    ob_start();
    include __DIR__ . '/login_logs.php';
    $raw = ob_get_clean();

    $tableHtml = '';
    $pagerHtml = '';

    if (preg_match('#<div[^>]*id=["\']sl-ajax-table["\'][^>]*>(.*?)</div>\s*#is', $raw, $tableMatch)) {
        $tableHtml = $tableMatch[1];
    } elseif (preg_match('#<div[^>]*id=["\']sl-ajax-table["\'][^>]*>(.*?)</div>#is', $raw, $tableMatch)) {
        $tableHtml = $tableMatch[1];
    }

    if (preg_match('#<div[^>]*id=["\']sl-ajax-pager["\'][^>]*>(.*?)</div>\s*#is', $raw, $pagerMatch)) {
        $pagerHtml = $pagerMatch[1];
    } elseif (preg_match('#<div[^>]*id=["\']sl-ajax-pager["\'][^>]*>(.*?)</div>#is', $raw, $pagerMatch)) {
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

$pageTitle = 'Login Activity Logs';
$pageHeading = 'Login Activity';
$pageDescription = 'Track audit trails, secure vectors, and persistent sessions across the platform.';
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
        --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.08), 0 2px 4px -2px rgba(0, 0, 0, 0.04);
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

    /* Metric Widgets Grid */
    .apd-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 10px;
        margin-bottom: 16px;
    }

    .sl-stat {
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: 10px;
        padding: 16px;
        box-shadow: none;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        position: relative;
        overflow: hidden;
        transition: transform 0.15s ease, box-shadow 0.15s ease;
    }

    .sl-stat:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px -2px rgba(0, 0, 0, 0.08);
    }

    .sl-stat__icon {
        position: absolute;
        top: 16px;
        right: 16px;
        width: 36px;
        height: 36px;
        padding: 8px;
        border-radius: 10px;
        background: currentColor;
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        pointer-events: none;
        opacity: 1;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    
    .sl-stat:hover .sl-stat__icon {
        transform: scale(1.1);
        box-shadow: 0 6px 16px rgba(0,0,0,0.2);
    }
    
    .sl-stat:nth-child(1) .sl-stat__icon { background: var(--primary); }
    .sl-stat:nth-child(2) .sl-stat__icon { background: var(--success); }
    .sl-stat:nth-child(3) .sl-stat__icon { background: var(--danger); }
    .sl-stat:nth-child(4) .sl-stat__icon { background: var(--info); }
    
    .sl-stat__icon svg {
        width: 100%;
        height: 100%;
        stroke: #fff;
    }

    .sl-stat::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 4px;
        height: 100%;
        background: var(--primary);
    }

    .sl-stat:nth-child(1) { background: linear-gradient(135deg, #ffffff 0%, #f8faff 100%); }
    .sl-stat:nth-child(2)::before {
        background: var(--success);
    }
    .sl-stat:nth-child(2) { background: linear-gradient(135deg, #ffffff 0%, #f8fdf9 100%); }
    .sl-stat:nth-child(3)::before {
        background: var(--danger);
    }
    .sl-stat:nth-child(3) { background: linear-gradient(135deg, #ffffff 0%, #fef8f8 100%); }
    .sl-stat:nth-child(4)::before {
        background: var(--info);
    }
    .sl-stat:nth-child(4) { background: linear-gradient(135deg, #ffffff 0%, #f8fbfe 100%); }
    
    .sl-stat::after {
        content: """";
        position: absolute;
        top: -40px;
        right: -40px;
        width: 100px;
        height: 100px;
        border-radius: 50%;
        background: var(--primary);
        opacity: 0.03;
        pointer-events: none;
    }
    
    .sl-stat:nth-child(2)::after { background: var(--success); }
    .sl-stat:nth-child(3)::after { background: var(--danger); }
    .sl-stat:nth-child(4)::after { background: var(--info); }

    .apd-stat__label {
        font-size: 0.7rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--text-muted);
        margin-bottom: 6px;
    }

    .apd-stat__value {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--text-primary);
        line-height: 1;
        letter-spacing: -0.02em;
    }

    /* Filter Console Block */
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

    .sl-search-wrapper {
        position: relative;
        flex: 2 1 220px;
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .sl-search-inner {
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

    .sl-search-inner input[type="search"] {
        padding-right: 32px;
    }

    .apd-filter-bar input:focus,
    .apd-filter-bar select:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.08);
    }

    .sl-search-spinner {
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

    .sl-search-wrapper[data-loading="1"] .sl-search-spinner {
        opacity: 1;
        animation: sl-spin 0.6s linear infinite;
    }

    @keyframes sl-spin {
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

    /* Operational Async Protection Layer */
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
        .sl-search-wrapper {
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

        <div class="apd-stats">
            <div class="apd-stat sl-stat">
                <div class="sl-stat__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 16l4-8 4 4 6-8"/></svg></div>
                <span class="apd-stat__label">Total Logins Today</span>
                <span class="apd-stat__value"><?php echo e((string) ((int) ($stats['total_logins_today'] ?? 0))); ?></span>
            </div>
            <div class="apd-stat sl-stat">
                <div class="sl-stat__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg></div>
                <span class="apd-stat__label">Successful Logins</span>
                <span class="apd-stat__value"><?php echo e((string) ((int) ($stats['successful_today'] ?? 0))); ?></span>
            </div>
            <div class="apd-stat sl-stat">
                <div class="sl-stat__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M10 10l4 4M14 10l-4 4"/></svg></div>
                <span class="apd-stat__label">Failed Logins</span>
                <span class="apd-stat__value"><?php echo e((string) ((int) ($stats['failed_today'] ?? 0))); ?></span>
            </div>
            <div class="apd-stat sl-stat">
                <div class="sl-stat__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v4M12 16v4M6 8h4M14 8h4"/><circle cx="12" cy="12" r="3"/></svg></div>
                <span class="apd-stat__label">Active Sessions</span>
                <span class="apd-stat__value"><?php echo e((string) ((int) ($stats['active_users'] ?? 0))); ?></span>
            </div>
        </div>

        <form method="GET" class="apd-filter-bar" id="sl-filters" autocomplete="off">
            <div class="sl-search-wrapper" id="sl-search-wrapper">
                <label for="sl-search">Search Operations</label>
                <div class="sl-search-inner">
                    <input type="search" id="sl-search" name="search" value="<?php echo e($search); ?>" placeholder="Search identity, username, or IP address profile..." autofocus>
                    <span class="sl-search-spinner" id="sl-search-spinner"></span>
                </div>
            </div>
            <div class="input-group">
                <label for="sl-status">Status Metric</label>
                <select id="sl-status" name="status">
                    <option value="ALL" <?php echo $statusFilter === 'ALL' ? 'selected' : ''; ?>>All Verifications</option>
                    <option value="SUCCESS" <?php echo $statusFilter === 'SUCCESS' ? 'selected' : ''; ?>>Success Matrix</option>
                    <option value="FAILED" <?php echo $statusFilter === 'FAILED' ? 'selected' : ''; ?>>Failed Vector</option>
                </select>
            </div>
            <div class="input-group">
                <label for="sl-date">Timeline Frame</label>
                <input type="date" id="sl-date" name="date" value="<?php echo e($dateFilter); ?>">
            </div>
            <div class="input-group" style="flex: 0 1 160px;">
                <label for="sl-per-page">Page Horizon</label>
                <select id="sl-per-page" name="per_page">
                    <?php foreach ($perPageOptions as $opt): ?>
                        <option value="<?php echo e((string) $opt); ?>" <?php echo $perPage === $opt ? 'selected' : ''; ?>><?php echo e((string) $opt); ?> records</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="apd-filter-bar__actions">
                <button type="submit" class="btn btn-primary">Execute Filter</button>
                <a href="<?php echo e(url('system_logs/index.php')); ?>" class="btn btn-outline">Reset Grid</a>
            </div>
        </form>

        <?php include __DIR__ . '/login_logs.php'; ?>
    </div>
</main>

<script>
    (function() {
        var tableContainer = document.querySelector('.apd-table-scroll');
        var pagerContainer = document.querySelector('.apd-pagination-footer');
        var form = document.getElementById('sl-filters');
        var perPageSelect = document.getElementById('sl-per-page');
        var searchInput = document.getElementById('sl-search');
        var searchWrapper = document.getElementById('sl-search-wrapper');
        var statusSelect = document.getElementById('sl-status');
        var dateInput = document.getElementById('sl-date');
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
            if (statusSelect && statusSelect.value !== 'ALL') params.append('status', statusSelect.value);
            if (dateInput && dateInput.value) params.append('date', dateInput.value);
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
            xhr.open('GET', '<?php echo e(url('system_logs/index.php')); ?>?' + buildQueryString({
                page: page
            }), true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.onreadystatechange = function() {
                if (xhr.readyState !== 4) return;
                setLoading(false);
                if (xhr.status === 200) {
                    try {
                        var data = JSON.parse(xhr.responseText);

                        // Direct dynamic DOM node update checks
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
            var url = '<?php echo e(url('system_logs/index.php')); ?>?' + qs;
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

        [statusSelect, dateInput, perPageSelect].forEach(function(element) {
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

        bindPagerButtons();
    })();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>








