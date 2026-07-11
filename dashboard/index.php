<?php
/**
 * Monitoring dashboard — period-scoped ticket KPIs, charts, assignee modal, recent rows.
 *
 * Date bounds come from GET (?from_date=&to_date=) validated via email_inbox_service_normalize_date().
 * Heavy lifting lives in services/dashboard_metrics_service.php (single load per request; no polling).
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../services/email_inbox_service.php';
require_once __DIR__ . '/../services/party_service.php';
require_once __DIR__ . '/../services/dashboard_metrics_service.php';

$currentUser = require_login($pdo);

$pageTitle = 'Dashboard';
$pageHeading = 'Monitoring Dashboard';
$pageDescription = ($currentUser['role'] ?? '') === 'Admin'
    ? 'Org-wide ticket volume, status mix, and trends for the selected period.'
    : 'Tickets you can work on (assigned to you or still unassigned), trends, and quick alerts for this period.';

$fromDate = email_inbox_service_normalize_date($_GET['from_date'] ?? '');
$toDate = email_inbox_service_normalize_date($_GET['to_date'] ?? '');

if ($fromDate === '') {
    $fromDate = date('Y-m-d');
}

if ($toDate === '') {
    $toDate = date('Y-m-d');
}

if ($fromDate > $toDate) {
    [$fromDate, $toDate] = [$toDate, $fromDate];
}

$metrics = dashboard_metrics_load($pdo, $currentUser, $fromDate, $toDate);
$ticketStats = $metrics['ticketStats'];
$unassignedActive = $metrics['unassignedActive'];
$highPriorityActive = $metrics['highPriorityActive'];
$periodDays = $metrics['periodDays'];
$ticketStatsPrev = $metrics['ticketStatsPrev'];
$trendLabels = $metrics['trendLabels'];
$trendValues = $metrics['trendValues'];
$trendIsoDates = $metrics['trendIsoDates'];
$trendBreakdown = $metrics['trendBreakdown'];
$trendWeeklyLabels = $metrics['trendWeeklyLabels'];
$trendWeeklyValues = $metrics['trendWeeklyValues'];
$trendWeeklyDates = $metrics['trendWeeklyDates'];
$trendWeeklyBreakdown = $metrics['trendWeeklyBreakdown'];
$assigneeBreakdownRows = $metrics['assigneeBreakdownRows'];
$assigneeBreakdownTotals = $metrics['assigneeBreakdownTotals'];
$recentTickets = $metrics['recentTickets'];
$statusChart = $metrics['statusChart'];
$statusLegendRows = $metrics['statusLegendRows'];
$trendTotal = $metrics['trendTotal'];
$trendOpen = $metrics['trendOpen'];
$trendProgress = $metrics['trendProgress'];
$trendClosed = $metrics['trendClosed'];
$today = $metrics['today'];
$preset7From = $metrics['preset7From'];
$preset30From = $metrics['preset30From'];

/** Safe embedding of PHP data inside <script> (prevents </script>-style breaks + UTF-8 issues). */
$dashboardJsEncode = static function ($value) {
    $flags = JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_INVALID_UTF8_SUBSTITUTE;
    $json = json_encode($value, $flags);

    return is_string($json) ? $json : '{}';
};

include __DIR__ . '/../includes/header.php';
?>
<div class="dashboard-dense">
<form method="GET" class="filter-card dashboard-filter-card">
    <div class="filter-header dashboard-filter-header-row">
        <div>
            <h3>Monitoring period</h3>
            <p class="dashboard-filter-lede">
                Metrics below use tickets created between <strong><?php echo e($fromDate); ?></strong> and <strong><?php echo e($toDate); ?></strong>.
                <?php if (($currentUser['role'] ?? '') !== 'Admin'): ?>
                    Scope matches the ticket list (your assignments plus unassigned queue).
                <?php endif; ?>
            </p>
        </div>
        <div class="dashboard-filter-header-actions">
            <button
                type="button"
                class="btn btn-outline btn-sm"
                id="dashboard-filter-toggle"
                aria-expanded="true"
                aria-controls="dashboard-filter-collapsible"
            >Hide filters</button>
            <a href="<?php echo e(url('dashboard/index.php')); ?>" class="btn btn-outline btn-sm">Reset</a>
        </div>
    </div>

    <div id="dashboard-filter-collapsible" class="dashboard-filter-drawer">
        <div class="dashboard-filter-toolbar">
            <div class="dashboard-date-presets dashboard-date-presets--compact" aria-label="Quick date ranges">
                <span class="dashboard-presets-label">Quick:</span>
                <a href="<?php echo e(url('dashboard/index.php?from_date=' . urlencode($today) . '&to_date=' . urlencode($today))); ?>" class="dashboard-preset-chip">Today</a>
                <a href="<?php echo e(url('dashboard/index.php?from_date=' . urlencode($preset7From) . '&to_date=' . urlencode($today))); ?>" class="dashboard-preset-chip">Last 7 days</a>
                <a href="<?php echo e(url('dashboard/index.php?from_date=' . urlencode($preset30From) . '&to_date=' . urlencode($today))); ?>" class="dashboard-preset-chip">Last 30 days</a>
            </div>

            <div class="dashboard-date-range-bar" role="group" aria-labelledby="dashboard-date-range-label">
                <span id="dashboard-date-range-label" class="dashboard-date-range-heading">Date range</span>
                <div class="dashboard-date-range-shell">
                    <label class="dashboard-date-range-cell">
                        <span class="dashboard-date-range-sublabel">Start</span>
                        <input type="date" id="from_date" name="from_date" value="<?php echo e($fromDate); ?>" autocomplete="off">
                    </label>
                    <span class="dashboard-date-range-sep" aria-hidden="true">→</span>
                    <label class="dashboard-date-range-cell">
                        <span class="dashboard-date-range-sublabel">End</span>
                        <input type="date" id="to_date" name="to_date" value="<?php echo e($toDate); ?>" autocomplete="off">
                    </label>
                    <button type="submit" class="btn btn-primary btn-sm dashboard-date-range-submit">Apply</button>
                </div>
            </div>
        </div>
    </div>
</form>

<?php if ($unassignedActive > 0 || $highPriorityActive > 0): ?>
<div class="dashboard-alert-strip" role="region" aria-label="Attention items">
    <?php if ($unassignedActive > 0): ?>
        <a href="<?php echo e(url('tickets/list.php?assign_to=' . urlencode('__unassigned__'))); ?>" class="dashboard-alert-pill dashboard-alert-pill--warn">
            <strong><?php echo e((string) $unassignedActive); ?></strong>
            <span>unassigned · Open / In-Progress</span>
        </a>
    <?php endif; ?>
    <?php if ($highPriorityActive > 0): ?>
        <a href="<?php echo e(url('tickets/list.php?priority=High')); ?>" class="dashboard-alert-pill dashboard-alert-pill--danger">
            <strong><?php echo e((string) $highPriorityActive); ?></strong>
            <span>high priority · active</span>
        </a>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="stats-grid dashboard-stats-grid">
    <div
        class="dashboard-stat-card stat-card stat-card--total stat-card--interactive"
        id="dashboard-total-stat-trigger"
        role="button"
        tabindex="0"
        aria-haspopup="dialog"
        aria-controls="dashboard-assignee-modal"
        aria-label="Open workload breakdown by assignee for tickets created in this period"
    >
        <div class="dashboard-stat-top">
            <span class="dashboard-stat-icon dashboard-stat-icon--neutral" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M12 18v-6"/><path d="M9 15h6"/></svg>
            </span>
            <span class="dashboard-stat-eyebrow">Created in Period</span>
        </div>
        <div class="dashboard-stat-main dashboard-stat-main--solo">
            <span class="dashboard-stat-number"><?php echo e((string) (int) ($ticketStats['total_tickets'] ?? 0)); ?></span>
        </div>
        <p class="dashboard-stat-trend <?php echo e($trendTotal['class']); ?>"><?php echo $trendTotal['html']; ?></p>
        <p class="dashboard-stat-hint">Click · workload by assignee</p>
    </div>

    <div class="dashboard-stat-card stat-card stat-card--open">
        <div class="dashboard-stat-top">
            <span class="dashboard-stat-icon dashboard-stat-icon--open" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
            </span>
            <span class="dashboard-stat-eyebrow">Open</span>
        </div>
        <div class="dashboard-stat-main dashboard-stat-main--solo">
            <span class="dashboard-stat-number dashboard-stat-number--open"><?php echo e((string) (int) ($ticketStats['open_tickets'] ?? 0)); ?></span>
        </div>
        <p class="dashboard-stat-trend <?php echo e($trendOpen['class']); ?>"><?php echo $trendOpen['html']; ?></p>
    </div>

    <div class="dashboard-stat-card stat-card stat-card--progress">
        <div class="dashboard-stat-top">
            <span class="dashboard-stat-icon dashboard-stat-icon--progress" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M12 2v4"/><path d="M12 18v4"/><path d="m4.93 4.93 2.83 2.83"/><path d="m16.24 16.24 2.83 2.83"/><path d="M2 12h4"/><path d="M18 12h4"/><path d="m4.93 19.07 2.83-2.83"/><path d="m16.24 7.76 2.83-2.83"/></svg>
            </span>
            <span class="dashboard-stat-eyebrow">In Progress</span>
        </div>
        <div class="dashboard-stat-main dashboard-stat-main--solo">
            <span class="dashboard-stat-number dashboard-stat-number--progress"><?php echo e((string) (int) ($ticketStats['progress_tickets'] ?? 0)); ?></span>
        </div>
        <p class="dashboard-stat-trend <?php echo e($trendProgress['class']); ?>"><?php echo $trendProgress['html']; ?></p>
    </div>

    <div class="dashboard-stat-card stat-card stat-card--closed">
        <div class="dashboard-stat-top">
            <span class="dashboard-stat-icon dashboard-stat-icon--closed" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 11 3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
            </span>
            <span class="dashboard-stat-eyebrow">Closed Tickets</span>
        </div>
        <div class="dashboard-stat-main dashboard-stat-main--solo">
            <span class="dashboard-stat-number dashboard-stat-number--closed"><?php echo e((string) (int) ($ticketStats['closed_tickets'] ?? 0)); ?></span>
        </div>
        <p class="dashboard-stat-trend <?php echo e($trendClosed['class']); ?>"><?php echo $trendClosed['html']; ?></p>
    </div>
</div>

<div class="dashboard-grid">
    <div class="chart-card">
        <div class="chart-meta chart-meta--trend">
            <div class="chart-meta-trend-text">
                <h2 class="section-title dashboard-chart-title-row">
                    <span class="dashboard-chart-title-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
                    </span>
                    Tickets Over Time
                </h2>
                <p class="section-subtitle" id="dashboard-trend-subtitle">New tickets per day (created date in range).</p>
                <div id="trend-chart-summary" class="dashboard-trend-summary" aria-live="polite"></div>
            </div>
            <div class="dashboard-chart-controls">
                <div class="dashboard-seg" role="group" aria-label="Trend granularity">
                    <button type="button" class="dashboard-seg-btn is-active" data-dashboard-trend-mode="daily">Daily</button>
                    <button type="button" class="dashboard-seg-btn" data-dashboard-trend-mode="weekly">Weekly</button>
                </div>
                <div class="chart-legend chart-legend--inline">
                    <span class="legend-item"><span class="legend-swatch dashboard-trend-legend-swatch"></span>Tickets created</span>
                </div>
            </div>
        </div>
        <div class="chart-shell dashboard-trend-chart-shell">
            <canvas id="trendChart" class="chart-canvas"></canvas>
        </div>
    </div>

    <div class="chart-card">
        <div class="chart-meta">
            <div>
                <h2 class="section-title dashboard-chart-title-row">
                    <span class="dashboard-chart-title-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>
                    </span>
                    Tickets By Status
                </h2>
                <p class="section-subtitle">Snapshot for tickets created in this period.</p>
            </div>
        </div>
        <div class="dashboard-status-chart-wrap">
            <div class="chart-shell dashboard-status-donut-shell">
                <canvas id="statusChart" class="chart-canvas dashboard-status-donut-canvas"></canvas>
            </div>
            <div class="dashboard-status-legend-wrap">
                <table class="dashboard-status-legend-table" aria-label="Ticket counts by status">
                    <colgroup>
                        <col class="dashboard-status-col-label">
                        <col class="dashboard-status-col-num">
                        <col class="dashboard-status-col-num">
                    </colgroup>
                    <thead>
                        <tr>
                            <th scope="col">Status</th>
                            <th scope="col" class="num">Count</th>
                            <th scope="col" class="num">Share</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($statusLegendRows as $sr): ?>
                            <?php
                            $rowTip = $sr['label'] . ': ' . (string) $sr['count'] . ' ticket'
                                . ($sr['count'] === 1 ? '' : 's')
                                . ' · ' . (string) $sr['pct'] . '% of tickets created this period';
                            ?>
                            <tr class="dashboard-status-legend-row" title="<?php echo e($rowTip); ?>">
                                <td>
                                    <span class="dashboard-status-dot" style="background:<?php echo e($sr['color']); ?>"></span>
                                    <?php echo e($sr['label']); ?>
                                </td>
                                <td class="num"><?php echo e((string) $sr['count']); ?></td>
                                <td class="num"><?php echo e((string) $sr['pct']); ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
$footerRangeLabel = date('M j, Y', strtotime($fromDate)) . ' – ' . date('M j, Y', strtotime($toDate));
?>
<footer class="dashboard-live-footer" role="contentinfo">
    <div class="dashboard-live-footer-left">
        <span class="dashboard-live-footer-icon" aria-hidden="true">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
        </span>
        <span>Date range: <strong><?php echo e($footerRangeLabel); ?></strong> <?php echo e('(' . $periodDays . ' ' . ($periodDays === 1 ? 'day' : 'days') . ')'); ?></span>
    </div>
    <div class="dashboard-live-footer-right">
        <span class="dashboard-live-pulse" aria-hidden="true"></span>
        <span class="dashboard-live-footer-live" aria-live="polite">
            <strong class="dashboard-live-footer-live-label">Live</strong>
            <time id="dashboard-live-clock" class="dashboard-live-clock"></time>
        </span>
        <span class="dashboard-live-footer-muted"> · local time · reload page for latest ticket counts</span>
    </div>
</footer>

<div id="dashboard-assignee-modal" class="ticket-modal" hidden aria-hidden="true">
    <div class="ticket-modal-backdrop" data-dashboard-assignee-modal-close tabindex="-1" aria-hidden="true"></div>
    <div
        class="ticket-modal-panel form-card dashboard-assignee-modal-panel"
        role="dialog"
        aria-modal="true"
        aria-labelledby="dashboard-assignee-modal-title"
        aria-describedby="dashboard-assignee-modal-desc"
        tabindex="-1"
    >
        <div class="ticket-modal-header dashboard-assignee-modal-head">
            <div class="dashboard-assignee-modal-head-main">
                <span class="dashboard-assignee-modal-title-icon" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                        <circle cx="9" cy="7" r="4"/>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                    </svg>
                </span>
                <h2 id="dashboard-assignee-modal-title" class="ticket-modal-title">Workload by Assignee</h2>
            </div>
            <button type="button" class="ticket-modal-close" data-dashboard-assignee-modal-close aria-label="Close">&times;</button>
        </div>
        <p class="dashboard-assignee-modal-desc" id="dashboard-assignee-modal-desc">
            Tickets created from <strong><?php echo e($fromDate); ?></strong> through <strong><?php echo e($toDate); ?></strong>,
            grouped by <strong>current assignee</strong>.
        </p>
        <?php if (($currentUser['role'] ?? '') !== 'Admin'): ?>
            <p class="dashboard-assignee-modal-scope">Only tickets you can see in the ticket list are included.</p>
        <?php endif; ?>
        <div class="dashboard-assignee-table-scroll">
            <?php if ($assigneeBreakdownRows): ?>
                <table class="dashboard-assignee-table">
                    <colgroup>
                        <col class="dashboard-assignee-col-name">
                        <col class="dashboard-assignee-col-num">
                        <col class="dashboard-assignee-col-num">
                        <col class="dashboard-assignee-col-num">
                        <col class="dashboard-assignee-col-num">
                    </colgroup>
                    <thead>
                        <tr>
                            <th scope="col" class="dashboard-assignee-th-assignee">
                                <span class="dashboard-assignee-th-label">Assignee</span>
                            </th>
                            <th scope="col" class="dashboard-assignee-th-num dashboard-assignee-th-total-col">
                                <span class="dashboard-assignee-th-label">Total tickets</span>
                            </th>
                            <th scope="col" class="dashboard-assignee-th-num dashboard-assignee-th-open-col">
                                <span class="dashboard-assignee-th-label">Open</span>
                            </th>
                            <th scope="col" class="dashboard-assignee-th-num dashboard-assignee-th-progress-col">
                                <span class="dashboard-assignee-th-label">In progress</span>
                            </th>
                            <th scope="col" class="dashboard-assignee-th-num dashboard-assignee-th-closed-col">
                                <span class="dashboard-assignee-th-label">Closed</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assigneeBreakdownRows as $abr): ?>
                            <?php
                            $lbl = (string) ($abr['assignee_label'] ?? '');
                            $tot = (int) ($abr['ticket_count'] ?? 0);
                            $oc = (int) ($abr['open_count'] ?? 0);
                            $pc = (int) ($abr['progress_count'] ?? 0);
                            $cc = (int) ($abr['closed_count'] ?? 0);
                            ?>
                            <tr class="dashboard-assignee-tr">
                                <td class="dashboard-assignee-td-name">
                                    <span class="dashboard-assignee-user-cell">
                                        <span class="dashboard-assignee-avatar" aria-hidden="true">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <circle cx="12" cy="8" r="4"/>
                                                <path d="M4 20v-2a8 8 0 0 1 16 0v2"/>
                                            </svg>
                                        </span>
                                        <span class="dashboard-assignee-name-txt"><?php echo e(dashboard_modal_assignee_display($lbl)); ?></span>
                                    </span>
                                </td>
                                <td class="dashboard-assignee-td-num">
                                    <span class="dashboard-assignee-count-badge dashboard-assignee-count-badge--total"><?php echo e((string) $tot); ?></span>
                                </td>
                                <td class="dashboard-assignee-td-num">
                                    <span class="dashboard-assignee-count-badge dashboard-assignee-count-badge--open"><?php echo e((string) $oc); ?></span>
                                </td>
                                <td class="dashboard-assignee-td-num">
                                    <span class="dashboard-assignee-count-badge dashboard-assignee-count-badge--progress"><?php echo e((string) $pc); ?></span>
                                </td>
                                <td class="dashboard-assignee-td-num">
                                    <span class="dashboard-assignee-count-badge dashboard-assignee-count-badge--closed"><?php echo e((string) $cc); ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="dashboard-assignee-tfoot-row">
                            <th scope="row">
                                <span class="dashboard-assignee-user-cell">
                                    <span class="dashboard-assignee-avatar dashboard-assignee-avatar--summary" aria-hidden="true">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <line x1="18" x2="18" y1="20" y2="10"/>
                                            <line x1="12" x2="12" y1="20" y2="4"/>
                                            <line x1="6" x2="6" y1="20" y2="14"/>
                                        </svg>
                                    </span>
                                    <span class="dashboard-assignee-name-txt">All Assignees</span>
                                </span>
                            </th>
                            <td class="dashboard-assignee-td-num">
                                <span class="dashboard-assignee-count-badge dashboard-assignee-count-badge--total"><?php echo e((string) $assigneeBreakdownTotals['total']); ?></span>
                            </td>
                            <td class="dashboard-assignee-td-num">
                                <span class="dashboard-assignee-count-badge dashboard-assignee-count-badge--open"><?php echo e((string) $assigneeBreakdownTotals['open']); ?></span>
                            </td>
                            <td class="dashboard-assignee-td-num">
                                <span class="dashboard-assignee-count-badge dashboard-assignee-count-badge--progress"><?php echo e((string) $assigneeBreakdownTotals['in_progress']); ?></span>
                            </td>
                            <td class="dashboard-assignee-td-num">
                                <span class="dashboard-assignee-count-badge dashboard-assignee-count-badge--closed"><?php echo e((string) $assigneeBreakdownTotals['closed']); ?></span>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            <?php else: ?>
                <p class="dashboard-assignee-table-empty">No tickets in this period for the current scope.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="panel-grid">
    <?php include __DIR__ . '/../views/dashboard/recent_tickets_block.php'; ?>

    <div class="hero-card">
        <h2 class="section-title">Quick Actions</h2>
        <p class="section-subtitle dashboard-hero-lede">Fast routes for the tasks you do most.</p>

        <div class="stack-actions dashboard-quick-actions-row">
            <a href="<?php echo e(url('tickets/create.php')); ?>" class="btn btn-primary">Create Ticket</a>
            <a href="<?php echo e(url('tickets/list.php')); ?>" class="btn btn-secondary">Ticket List</a>
            <a href="<?php echo e(url('emails/logs.php')); ?>" class="btn btn-outline">Email Logs</a>
            <a href="<?php echo e(url('profile/index.php')); ?>" class="btn btn-outline">My Profile</a>
        </div>

        <?php if ($currentUser['role'] === 'Admin'): ?>
            <div class="stack-actions dashboard-hero-admin-actions">
                <a href="<?php echo e(url('users/create.php')); ?>" class="btn btn-outline">Create User</a>
                <a href="<?php echo e(url('users/list.php')); ?>" class="btn btn-outline">Manage Users</a>
            </div>
        <?php endif; ?>

        <div class="info-strip">
            <div>
                <strong>Closing Rule</strong>
                <p>Tickets must be assigned before they can be marked closed.</p>
            </div>
        </div>

        <div class="info-strip">
            <div>
                <strong>Assignment Flow</strong>
                <p>Agents can pick unassigned tickets for themselves anytime from the ticket list.</p>
            </div>
        </div>
    </div>
</div>
</div>

<script src="<?php echo e(url('assets/js/tickets-list.js')); ?>"></script>
<script>
window.dashboardChartConfig = {
    status: <?php echo $dashboardJsEncode($statusChart); ?>,
    trendDaily: <?php echo $dashboardJsEncode([
        'labels' => $trendLabels,
        'values' => $trendValues,
        'dates' => $trendIsoDates,
        'breakdown' => $trendBreakdown,
        'compact' => true,
        'bucketUnit' => 'day',
    ]); ?>,
    trendWeekly: <?php echo $dashboardJsEncode([
        'labels' => $trendWeeklyLabels,
        'values' => $trendWeeklyValues,
        'dates' => $trendWeeklyDates,
        'breakdown' => $trendWeeklyBreakdown,
        'compact' => true,
        'bucketUnit' => 'week',
    ]); ?>
};
(function () {
    var el = document.getElementById('dashboard-live-clock');
    if (!el) {
        return;
    }
    var timer;
    function tick() {
        var now = new Date();
        el.setAttribute('datetime', now.toISOString());
        el.textContent = now.toLocaleString(undefined, {
            weekday: 'short',
            dateStyle: 'medium',
            timeStyle: 'medium'
        });
    }
    tick();
    timer = window.setInterval(tick, 1000);
    window.addEventListener('pagehide', function () {
        if (timer) {
            window.clearInterval(timer);
        }
    });
})();
</script>
<script>
(function () {
    /** Own script block so a chart-config parse error cannot prevent binding. Modal is moved under body on open — avoids clipping from .page-content overflow. */
    var modal = document.getElementById('dashboard-assignee-modal');
    var trigger = document.getElementById('dashboard-total-stat-trigger');
    if (!modal || !trigger) {
        return;
    }
    var panel = modal.querySelector('.dashboard-assignee-modal-panel');
    var closeSelector = '[data-dashboard-assignee-modal-close]';

    function ensureModalMounted() {
        if (modal.parentElement === document.body) {
            return;
        }
        document.body.appendChild(modal);
    }

    function openModal() {
        ensureModalMounted();
        modal.removeAttribute('hidden');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('dashboard-assignee-modal-open');
        if (panel) {
            try {
                panel.focus();
            } catch (err) {
                /* Focus can fail when document is not foreground; dialog still usable */
            }
        }
    }

    function closeModal() {
        modal.setAttribute('hidden', 'hidden');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('dashboard-assignee-modal-open');
        trigger.focus();
    }

    trigger.addEventListener('click', function (event) {
        event.preventDefault();
        openModal();
    });
    trigger.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            openModal();
        }
    });

    modal.querySelectorAll(closeSelector).forEach(function (node) {
        node.addEventListener('click', function (event) {
            event.preventDefault();
            closeModal();
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !modal.hasAttribute('hidden')) {
            closeModal();
        }
    });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
