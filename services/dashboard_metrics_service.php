<?php

/**
 * Monitoring dashboard — server-side metrics for dashboard/index.php.
 *
 * Loads KPI aggregates, trend series (daily + weekly buckets), assignee breakdown,
 * and recent ticket rows for the selected created-at window. Uses the same ticket_scope()
 * rules as the dashboard controller (caller must already have authenticated session).
 *
 * This file intentionally performs no rendering; it returns structured arrays only.
 */

/**
 * Week bucket key (Monday start) for a calendar date YYYY-MM-DD.
 *
 * @param non-empty-string $ymd
 * @return non-empty-string
 */
function dashboard_week_start_monday(string $ymd): string
{
    $ts = strtotime($ymd . ' 12:00:00');
    if ($ts === false) {
        return $ymd;
    }
    $dow = (int) date('N', $ts);

    return date('Y-m-d', strtotime('-' . ($dow - 1) . ' days', $ts));
}

/**
 * KPI pill helper vs previous period (HTML fragment + CSS modifier class).
 *
 * @return array{html: string, class: string}
 */
function dashboard_trend_vs_prev(int $current, int $previous): array
{
    if ($previous === 0) {
        if ($current === 0) {
            return ['html' => 'Same as previous period', 'class' => 'dashboard-stat-trend--flat'];
        }

        return ['html' => '↑ +' . $current . ' <span class="dashboard-stat-trend-sub">vs prev. period</span>', 'class' => 'dashboard-stat-trend--up'];
    }
    $pct = (int) round((($current - $previous) / $previous) * 100);
    $sign = $pct > 0 ? '+' : '';
    $arrow = $pct >= 0 ? '↑' : '↓';
    $cls = $pct >= 0 ? 'dashboard-stat-trend--up' : 'dashboard-stat-trend--down';

    return [
        'html' => $arrow . ' ' . $sign . $pct . '% <span class="dashboard-stat-trend-sub">vs prev. period</span>',
        'class' => $cls,
    ];
}

/**
 * Present assignee labels readably in the workload modal without mangling mixed-case names.
 */
function dashboard_modal_assignee_display(string $label): string
{
    $t = trim($label);
    if ($t === '') {
        return '—';
    }
    if (strcasecmp($t, 'Unassigned') === 0) {
        return 'Unassigned';
    }
    if (preg_match('/\p{Lu}/u', $t)) {
        return $t;
    }

    return mb_convert_case($t, MB_CASE_TITLE, 'UTF-8');
}

/**
 * @param array<string, mixed> $currentUser
 * @return array<string, mixed>
 */
function dashboard_metrics_load(PDO $pdo, array $currentUser, string $fromDate, string $toDate): array
{
    [$dashScopeSql, $dashScopeParams] = ticket_scope($currentUser, 't', true);
    $dashboardWhereSql = ' WHERE (' . $dashScopeSql . ') AND DATE(t.created_at) >= :from_date AND DATE(t.created_at) <= :to_date';
    $dashboardParams = array_merge($dashScopeParams, [
        ':from_date' => $fromDate,
        ':to_date' => $toDate,
    ]);


    $ticketStatsStmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS total_tickets,
            SUM(CASE WHEN t.status = 'Open' THEN 1 ELSE 0 END) AS open_tickets,
            SUM(CASE WHEN t.status = 'In-Progress' THEN 1 ELSE 0 END) AS progress_tickets,
            SUM(CASE WHEN t.status = 'Closed' THEN 1 ELSE 0 END) AS closed_tickets,
            SUM(CASE WHEN (t.assign_to IS NULL OR t.assign_to = '')
                AND t.status IN ('Open','In-Progress') THEN 1 ELSE 0 END) AS unassigned_active,
            SUM(CASE WHEN t.priority = 'High'
                AND t.status IN ('Open','In-Progress') THEN 1 ELSE 0 END) AS high_priority_active
         FROM tickets t" . $dashboardWhereSql . " AND t.deleted_at IS NULL AND t.is_deleted = 0"
    );

    $ticketStatsStmt->execute($dashboardParams);
    $ticketStats = $ticketStatsStmt->fetch() ?: [];
    $unassignedActive = (int) ($ticketStats['unassigned_active'] ?? 0);
    $highPriorityActive = (int) ($ticketStats['high_priority_active'] ?? 0);

    $periodDays = (int) floor((strtotime($toDate) - strtotime($fromDate)) / 86400) + 1;
    if ($periodDays < 1) {
        $periodDays = 1;
    }
    $prevPeriodTo = date('Y-m-d', strtotime($fromDate . ' -1 day'));
    $prevPeriodFrom = date('Y-m-d', strtotime($prevPeriodTo . ' -' . ($periodDays - 1) . ' days'));
    $dashboardPrevParams = array_merge($dashScopeParams, [
        ':from_date' => $prevPeriodFrom,
        ':to_date' => $prevPeriodTo,
    ]);
    $dashboardPrevWhereSql = ' WHERE (' . $dashScopeSql . ') AND DATE(t.created_at) >= :from_date AND DATE(t.created_at) <= :to_date';
    $ticketStatsPrevStmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS total_tickets,
            SUM(CASE WHEN t.status = 'Open' THEN 1 ELSE 0 END) AS open_tickets,
            SUM(CASE WHEN t.status = 'In-Progress' THEN 1 ELSE 0 END) AS progress_tickets,
            SUM(CASE WHEN t.status = 'Closed' THEN 1 ELSE 0 END) AS closed_tickets
         FROM tickets t" . $dashboardPrevWhereSql . " AND t.deleted_at IS NULL AND t.is_deleted = 0"
    );
    $ticketStatsPrevStmt->execute($dashboardPrevParams);
    $ticketStatsPrev = $ticketStatsPrevStmt->fetch() ?: [];

    $trendStmt = $pdo->prepare(
        'SELECT DATE(t.created_at) AS report_day,
            COUNT(*) AS total,
            SUM(CASE WHEN t.status = \'Open\' THEN 1 ELSE 0 END) AS open_cnt,
            SUM(CASE WHEN t.status = \'In-Progress\' THEN 1 ELSE 0 END) AS progress_cnt,
            SUM(CASE WHEN t.status = \'Closed\' THEN 1 ELSE 0 END) AS closed_cnt,
            SUM(CASE WHEN (t.assign_to IS NULL OR TRIM(COALESCE(t.assign_to, \'\')) = \'\') THEN 1 ELSE 0 END) AS unassigned_cnt
         FROM tickets t '
        . $dashboardWhereSql . ' AND t.deleted_at IS NULL AND t.is_deleted = 0'
        . '
         GROUP BY DATE(t.created_at)
         ORDER BY DATE(t.created_at) ASC'
    );

    $trendStmt->execute($dashboardParams);
    $trendRows = $trendStmt->fetchAll();

    $trendMap = [];
    $trendBreakdownByDay = [];
    foreach ($trendRows as $row) {
        $day = $row['report_day'];
        $trendMap[$day] = (int) $row['total'];
        $trendBreakdownByDay[$day] = [
            'open' => (int) ($row['open_cnt'] ?? 0),
            'in_progress' => (int) ($row['progress_cnt'] ?? 0),
            'closed' => (int) ($row['closed_cnt'] ?? 0),
            'unassigned' => (int) ($row['unassigned_cnt'] ?? 0),
        ];
    }

    $trendZeroBreakdown = [
        'open' => 0,
        'in_progress' => 0,
        'closed' => 0,
        'unassigned' => 0,
    ];

    $trendLabels = [];
    $trendValues = [];
    $trendIsoDates = [];
    $trendBreakdown = [];
    $rangeStart = strtotime($fromDate);
    $rangeEnd = strtotime($toDate);

    for ($cursor = $rangeStart; $cursor !== false && $rangeEnd !== false && $cursor <= $rangeEnd; $cursor = strtotime('+1 day', $cursor)) {
        $dateValue = date('Y-m-d', $cursor);
        $trendLabels[] = date('d M', $cursor);
        $trendValues[] = $trendMap[$dateValue] ?? 0;
        $trendIsoDates[] = $dateValue;
        $trendBreakdown[] = $trendBreakdownByDay[$dateValue] ?? $trendZeroBreakdown;
    }

    $trendWeeklyBuckets = [];
    $trendWeeklyBreakdownBuckets = [];
    for ($cursor = $rangeStart; $cursor !== false && $rangeEnd !== false && $cursor <= $rangeEnd; $cursor = strtotime('+1 day', $cursor)) {
        $dateValue = date('Y-m-d', $cursor);
        $wk = dashboard_week_start_monday($dateValue);
        $trendWeeklyBuckets[$wk] = ($trendWeeklyBuckets[$wk] ?? 0) + ($trendMap[$dateValue] ?? 0);

        $bd = $trendBreakdownByDay[$dateValue] ?? $trendZeroBreakdown;
        if (!isset($trendWeeklyBreakdownBuckets[$wk])) {
            $trendWeeklyBreakdownBuckets[$wk] = [
                'open' => 0,
                'in_progress' => 0,
                'closed' => 0,
                'unassigned' => 0,
            ];
        }
        $trendWeeklyBreakdownBuckets[$wk]['open'] += $bd['open'];
        $trendWeeklyBreakdownBuckets[$wk]['in_progress'] += $bd['in_progress'];
        $trendWeeklyBreakdownBuckets[$wk]['closed'] += $bd['closed'];
        $trendWeeklyBreakdownBuckets[$wk]['unassigned'] += $bd['unassigned'];
    }
    ksort($trendWeeklyBuckets, SORT_STRING);
    $trendWeeklyLabels = [];
    $trendWeeklyValues = [];
    $trendWeeklyDates = [];
    $trendWeeklyBreakdown = [];
    foreach ($trendWeeklyBuckets as $weekStart => $weekCount) {
        $trendWeeklyLabels[] = date('d M', strtotime($weekStart . ' 12:00:00'));
        $trendWeeklyValues[] = (int) $weekCount;
        $trendWeeklyDates[] = $weekStart;
        $trendWeeklyBreakdown[] = $trendWeeklyBreakdownBuckets[$weekStart] ?? [
            'open' => 0,
            'in_progress' => 0,
            'closed' => 0,
            'unassigned' => 0,
        ];
    }

    $assigneeBreakdownStmt = $pdo->prepare(
        "SELECT
            CASE WHEN (t.assign_to IS NULL OR TRIM(COALESCE(t.assign_to, '')) = '')
                THEN '__unassigned__'
                ELSE t.assign_to END AS bucket_uid,
            MAX(
                COALESCE(
                    NULLIF(TRIM(u.name), ''),
                    NULLIF(TRIM(t.assign_to), ''),
                    'Unassigned'
                )
            ) AS assignee_label,
            COUNT(*) AS ticket_count,
            SUM(CASE WHEN t.status = 'Open' THEN 1 ELSE 0 END) AS open_count,
            SUM(CASE WHEN t.status = 'In-Progress' THEN 1 ELSE 0 END) AS progress_count,
            SUM(CASE WHEN t.status = 'Closed' THEN 1 ELSE 0 END) AS closed_count
         FROM tickets t
         LEFT JOIN users u ON u.user_id = t.assign_to AND COALESCE(u.deleted, 0) = 0
         " . $dashboardWhereSql . " AND t.deleted_at IS NULL AND t.is_deleted = 0
         GROUP BY bucket_uid
         ORDER BY ticket_count DESC, assignee_label ASC"
    );
    $assigneeBreakdownStmt->execute($dashboardParams);
    $assigneeBreakdownRows = $assigneeBreakdownStmt->fetchAll();

    $assigneeBreakdownTotals = ['total' => 0, 'open' => 0, 'in_progress' => 0, 'closed' => 0];
    foreach ($assigneeBreakdownRows as $abr) {
        $assigneeBreakdownTotals['total'] += (int) ($abr['ticket_count'] ?? 0);
        $assigneeBreakdownTotals['open'] += (int) ($abr['open_count'] ?? 0);
        $assigneeBreakdownTotals['in_progress'] += (int) ($abr['progress_count'] ?? 0);
        $assigneeBreakdownTotals['closed'] += (int) ($abr['closed_count'] ?? 0);
    }

    party_service_ensure_schema($pdo);

    $recentTicketsWhereSql = ' WHERE (' . $dashScopeSql . ') AND t.deleted_at IS NULL AND t.is_deleted = 0';
    $recentTicketsStmt = $pdo->prepare(
        "SELECT
            t.ticket_id,
            t.customer,
            t.customer_email,
            t.country,
            t.issue,
            t.description,
            t.status,
            t.priority,
            t.assign_to,
            t.created_at,
            t.closed_at,
            t.updated_at,
            t.external_ticket_id,
            t.internal_ticket_id,
            t.created_by,
            t.updated_by,
            t.is_deleted,
            assignee.name AS assignee_name,
            creator.name AS creator_name,
            updater.name AS updater_name,
            vendor.name AS assigned_vendor_name
         FROM tickets t
         LEFT JOIN users assignee ON assignee.user_id = t.assign_to
         LEFT JOIN users creator ON creator.user_id = t.created_by
         LEFT JOIN users updater ON updater.user_id = t.updated_by
         LEFT JOIN parties vendor ON vendor.id = t.assigned_vendor_id
         " . $recentTicketsWhereSql . "
         ORDER BY t.ticket_id DESC
         LIMIT 10"
    );

    $recentTicketsStmt->execute($dashScopeParams);
    $recentTickets = $recentTicketsStmt->fetchAll();

    $statusChart = [
        'labels' => ['Open', 'In-Progress', 'Closed'],
        'values' => [
            (int) ($ticketStats['open_tickets'] ?? 0),
            (int) ($ticketStats['progress_tickets'] ?? 0),
            (int) ($ticketStats['closed_tickets'] ?? 0),
        ],
        'colors' => ['#2563eb', '#f59e0b', '#10b981'],
    ];

    $totalTicketsForPct = (int) ($ticketStats['total_tickets'] ?? 0);
    $statusLegendRows = [
        [
            'label' => 'Open',
            'count' => (int) ($ticketStats['open_tickets'] ?? 0),
            'color' => '#2563eb',
        ],
        [
            'label' => 'In-Progress',
            'count' => (int) ($ticketStats['progress_tickets'] ?? 0),
            'color' => '#f59e0b',
        ],
        [
            'label' => 'Closed',
            'count' => (int) ($ticketStats['closed_tickets'] ?? 0),
            'color' => '#10b981',
        ],
    ];
    foreach ($statusLegendRows as $i => $row) {
        $statusLegendRows[$i]['pct'] = $totalTicketsForPct > 0
            ? round(100 * $row['count'] / $totalTicketsForPct, 1)
            : 0.0;
    }

    $trendTotal = dashboard_trend_vs_prev(
        (int) ($ticketStats['total_tickets'] ?? 0),
        (int) ($ticketStatsPrev['total_tickets'] ?? 0)
    );
    $trendOpen = dashboard_trend_vs_prev(
        (int) ($ticketStats['open_tickets'] ?? 0),
        (int) ($ticketStatsPrev['open_tickets'] ?? 0)
    );
    $trendProgress = dashboard_trend_vs_prev(
        (int) ($ticketStats['progress_tickets'] ?? 0),
        (int) ($ticketStatsPrev['progress_tickets'] ?? 0)
    );
    $trendClosed = dashboard_trend_vs_prev(
        (int) ($ticketStats['closed_tickets'] ?? 0),
        (int) ($ticketStatsPrev['closed_tickets'] ?? 0)
    );

    $today = date('Y-m-d');
    $preset7From = date('Y-m-d', strtotime('-6 days'));
    $preset30From = date('Y-m-d', strtotime('-29 days'));

    return [
        'ticketStats' => $ticketStats,
        'unassignedActive' => $unassignedActive,
        'highPriorityActive' => $highPriorityActive,
        'periodDays' => $periodDays,
        'ticketStatsPrev' => $ticketStatsPrev,
        'trendLabels' => $trendLabels,
        'trendValues' => $trendValues,
        'trendIsoDates' => $trendIsoDates,
        'trendBreakdown' => $trendBreakdown,
        'trendWeeklyLabels' => $trendWeeklyLabels,
        'trendWeeklyValues' => $trendWeeklyValues,
        'trendWeeklyDates' => $trendWeeklyDates,
        'trendWeeklyBreakdown' => $trendWeeklyBreakdown,
        'assigneeBreakdownRows' => $assigneeBreakdownRows,
        'assigneeBreakdownTotals' => $assigneeBreakdownTotals,
        'recentTickets' => $recentTickets,
        'statusChart' => $statusChart,
        'statusLegendRows' => $statusLegendRows,
        'trendTotal' => $trendTotal,
        'trendOpen' => $trendOpen,
        'trendProgress' => $trendProgress,
        'trendClosed' => $trendClosed,
        'today' => $today,
        'preset7From' => $preset7From,
        'preset30From' => $preset30From,
    ];
}
