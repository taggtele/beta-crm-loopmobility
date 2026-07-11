# Dashboard metrics (server-side)

## Purpose

The Monitoring dashboard (`dashboard/index.php`) renders KPI cards, trend charts, status donut, assignee workload modal, and recent tickets for a **created-at date window**.

All aggregates are computed **once per full page request** in PHP — there is no client-side polling for metrics.

## Entry points

| Path | Role |
|------|------|
| `dashboard/index.php` | Controller + view: validates GET dates, calls loader, renders markup. |
| `services/dashboard_metrics_service.php` | Data layer: SQL + derived arrays passed back to the view. |
| `views/dashboard/recent_tickets_block.php` | Recent ticket table fragment (expects `$recentTickets`, `$currentUser` in scope). |
| `public/assets/js/app.js` | Chart bootstrap + dashboard-only behaviours (filter collapse, trend granularity). |

## Date handling

- Query params: `from_date`, `to_date` (`YYYY-MM-DD`), normalized via `email_inbox_service_normalize_date()` (see `services/email_inbox_service.php`).
- Default empty params → today-only window (logic remains in `dashboard/index.php`).
- SQL filters use `DATE(t.created_at)` between bounds.

## Authorization scope

Metrics honour `ticket_scope($currentUser, 't', true)` from `includes/auth.php`.  
The metrics service assumes **`includes/auth.php` has already been loaded** (via `require_login`) so `ticket_schema()` / ticket_scope helpers exist.

## Loader contract

`dashboard_metrics_load(PDO $pdo, array $currentUser, string $fromDate, string $toDate): array`

Returns associative keys consumed by the dashboard view (`dashboard/index.php` assigns each entry to a local variable). Adding new keys requires updating both the service return array and the controller assignments + view.

## Helpers

| Function | Use |
|----------|-----|
| `dashboard_week_start_monday()` | Weekly trend buckets (Monday week start). |
| `dashboard_trend_vs_prev()` | KPI delta HTML + CSS class vs previous period. |
| `dashboard_modal_assignee_display()` | Assignee label formatting in the workload modal. |

## Frontend charts

`window.dashboardChartConfig` is emitted inline from PHP (`json_encode`). Charts use canvas helpers in `public/assets/js/app.js` (`SimpleCharts`, `drawLineChart`, `drawDonutChart`). Changing payload shape requires coordinated PHP + JS updates.
