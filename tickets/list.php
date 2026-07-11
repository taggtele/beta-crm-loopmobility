<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../services/ticket_query_service.php';
require_once __DIR__ . '/../services/ticket_log_service.php';
require_once __DIR__ . '/../services/party_service.php';
require_once __DIR__ . '/../modules/tickets/ticket_service.php';



$currentUser = require_login($pdo);
$pageTitle = 'Tickets';
$pageHeading = 'Ticket List';
$pageDescription = 'Track internal and external ticket IDs, search subjects, and open full ticket threads.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = trim((string) ($_POST['action'] ?? ''));
    $ticketId = (int) ($_POST['ticket_id'] ?? 0);
    $wantsJson = (strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') || (isset($_POST['bulk_action']));

    $fetchTicketForAction = static function (PDO $pdo, array $currentUser, int $ticketId): ?array {
        if ($ticketId <= 0) {
            return null;
        }

        [$scopeSql, $scopeParams] = ticket_scope($currentUser, 't', true);
        $stmt = $pdo->prepare(
            'SELECT ticket_id, assign_to, status, priority
             FROM tickets t
             WHERE t.ticket_id = :ticket_id
             AND ' . $scopeSql . '
             LIMIT 1'
        );
        $stmt->execute(array_merge([':ticket_id' => $ticketId], $scopeParams));

        $row = $stmt->fetch();

        return $row ?: null;
    };

    $fetchDeletedTicketForAction = static function (PDO $pdo, array $currentUser, int $ticketId): ?array {
        if ($ticketId <= 0) {
            return null;
        }

        [$scopeSql, $scopeParams] = ticket_scope($currentUser, 't', true);
        $stmt = $pdo->prepare(
            'SELECT ticket_id, assign_to, status, priority, is_deleted, deleted_at
             FROM tickets t
             WHERE t.ticket_id = :ticket_id
             AND ' . $scopeSql . '
             LIMIT 1'
        );
        $stmt->execute(array_merge([':ticket_id' => $ticketId], $scopeParams));

        $row = $stmt->fetch();

        return $row ?: null;
    };

    if ($ticketId > 0 && in_array($action, ['assign_self', 'quick_assign', 'quick_status', 'soft_delete', 'restore_ticket', 'bulk_archive', 'bulk_restore'], true)) {
        $includeDeleted = in_array($action, ['restore_ticket', 'bulk_restore'], true);
        $ticket = $includeDeleted
            ? $fetchDeletedTicketForAction($pdo, $currentUser, $ticketId)
            : $fetchTicketForAction($pdo, $currentUser, $ticketId);
        $jsonError = static function (string $message) use ($wantsJson): void {
            if ($wantsJson) {
                ticket_json_response(['success' => false, 'message' => $message], 422);
            }
            set_flash('error', $message);
            redirect('tickets/list.php');
        };

        if (!$ticket) {
            $jsonError('Ticket not found or access denied.');
        }

        if ($action === 'soft_delete') {
            if (!ticket_user_can_soft_delete($currentUser)) {
                $jsonError('You do not have permission to delete tickets.');
            }

            $reason = trim((string) ($_POST['reason'] ?? ''));
            $deleteStmt = $pdo->prepare(
                'UPDATE tickets
                 SET deleted_at = NOW(), is_deleted = 1, delete_reason = :reason
                 WHERE ticket_id = :ticket_id'
            );
            $deleteStmt->execute([':ticket_id' => $ticketId, ':reason' => $reason]);

            if ($wantsJson) {
                ticket_json_response(['success' => true, 'deleted' => true]);
            }
            set_flash('success', 'Ticket archived successfully.');
            redirect('tickets/list.php');
        }

        if ($action === 'restore_ticket') {
            if (!ticket_user_can_soft_delete($currentUser)) {
                $jsonError('You do not have permission to restore tickets.');
            }

            $restoreStmt = $pdo->prepare(
                'UPDATE tickets
                 SET deleted_at = NULL, is_deleted = 0, delete_reason = NULL
                 WHERE ticket_id = :ticket_id AND is_deleted = 1'
            );
            $restoreStmt->execute([':ticket_id' => $ticketId]);

            if ($wantsJson) {
                ticket_json_response(['success' => true, 'restored' => true]);
            }
            set_flash('success', 'Ticket restored successfully.');
            redirect('tickets/list.php');
        }

        if ($action === 'quick_status') {
            $newStatus = trim((string) ($_POST['status'] ?? ''));
            if (!in_array($newStatus, ticket_statuses(), true)) {
                $jsonError('Invalid status selected.');
            }
            if (!ticket_user_can_change_status($currentUser, $ticket)) {
                $jsonError('You cannot change status for this ticket.');
            }
            if ($newStatus === 'Closed' && !ticket_is_assigned($ticket['assign_to'] ?? null)) {
                $jsonError('Assign the ticket before closing it.');
            }

            $beforeTicket = ticket_service_get_ticket_with_user_context($pdo, $ticketId);
            $closedAt = $newStatus === 'Closed' ? date('Y-m-d H:i:s') : null;
            $updateStmt = $pdo->prepare(
                'UPDATE tickets
                 SET status = :status,
                     closed_at = :closed_at,
                     updated_by = :updated_by
                 WHERE ticket_id = :ticket_id'
            );
            $updateStmt->execute([
                ':status' => $newStatus,
                ':closed_at' => $closedAt,
                ':updated_by' => ticket_service_current_user_id($currentUser),
                ':ticket_id' => $ticketId,
            ]);

            $afterTicket = ticket_service_get_ticket_with_user_context($pdo, $ticketId);
            if ($beforeTicket && $afterTicket) {
                ticket_service_handle_ticket_updated($pdo, $beforeTicket, $afterTicket);
            }

            if ($wantsJson) {
                ticket_json_response(array_merge(
                    ['success' => true, 'status' => $newStatus],
                    ticket_service_updated_json_fields($afterTicket)
                ));
            }
            set_flash('success', 'Ticket status updated.');
            redirect('tickets/list.php');
        }

        if ($action === 'assign_self' || $action === 'quick_assign') {
            if ($action === 'assign_self' || ($currentUser['role'] ?? '') === 'Agent') {
                if (!ticket_user_can_self_assign($currentUser, $ticket)) {
                    $jsonError('This ticket cannot be self-assigned.');
                }

                $assignTo = $currentUser['user_id'];
            } else {
                if (!ticket_user_can_assign_others($currentUser)) {
                    $jsonError('You cannot assign tickets to other users.');
                }

                $assignTo = normalize_assignee(trim((string) ($_POST['assign_to'] ?? '')));
                $assigneeIds = array_column(active_users($pdo), 'user_id');
                if ($assignTo !== null && !in_array($assignTo, $assigneeIds, true)) {
                    $jsonError('Assigned user must be active.');
                }
            }

            $beforeTicket = ticket_service_get_ticket_with_user_context($pdo, $ticketId);
            $updateStmt = $pdo->prepare(
                'UPDATE tickets
                 SET assign_to = :assign_to,
                     updated_by = :updated_by
                 WHERE ticket_id = :ticket_id'
            );
            $updateStmt->execute([
                ':assign_to' => $assignTo,
                ':updated_by' => ticket_service_current_user_id($currentUser),
                ':ticket_id' => $ticketId,
            ]);

            $afterTicket = ticket_service_get_ticket_with_user_context($pdo, $ticketId);
            if ($beforeTicket && $afterTicket) {
                ticket_service_handle_ticket_updated($pdo, $beforeTicket, $afterTicket);
            }

            if ($wantsJson) {
                ticket_json_response(array_merge(
                    [
                        'success' => true,
                        'assign_to' => $assignTo,
                        'assignee_name' => $afterTicket['assignee_name'] ?? ($assignTo ?: 'Unassigned'),
                    ],
                    ticket_service_updated_json_fields($afterTicket)
                ));
            }
            set_flash('success', $assignTo ? 'Ticket assigned successfully.' : 'Ticket unassigned.');
            redirect('tickets/list.php');
        }
    }

    if ($ticketId <= 0) {
        $ticketIds = array_filter(array_map('intval', (array) ($_POST['ticket_ids'] ?? [])));
        $bulkAction = trim((string) ($_POST['bulk_action'] ?? ''));

        if (!empty($ticketIds) && in_array($bulkAction, ['bulk_archive', 'bulk_restore'], true)) {
            if (!ticket_user_can_soft_delete($currentUser)) {
                if ($wantsJson) {
                    ticket_json_response(['success' => false, 'message' => 'You do not have permission to perform bulk actions.'], 403);
                }
                set_flash('error', 'You do not have permission to perform bulk actions.');
                redirect('tickets/list.php');
            }

            $placeholders = implode(',', array_fill(0, count($ticketIds), '?'));
            $scopeSql = ticket_scope($currentUser, 't', true)[0];

            if ($bulkAction === 'bulk_archive') {
                $reason = trim((string) ($_POST['reason'] ?? ''));
                $bulkStmt = $pdo->prepare(
                    'UPDATE tickets SET deleted_at = NOW(), is_deleted = 1, delete_reason = ?
                     WHERE ticket_id IN (' . $placeholders . ') AND ' . $scopeSql . ' AND is_deleted = 0'
                );
                $bulkStmt->execute(array_merge([$reason], $ticketIds));
                if ($wantsJson) {
                    ticket_json_response(['success' => true]);
                }
                set_flash('success', 'Selected tickets archived successfully.');
            } elseif ($bulkAction === 'bulk_restore') {
                $bulkStmt = $pdo->prepare(
                    'UPDATE tickets SET deleted_at = NULL, is_deleted = 0, delete_reason = NULL
                     WHERE ticket_id IN (' . $placeholders . ') AND ' . $scopeSql . ' AND is_deleted = 1'
                );
                $bulkStmt->execute($ticketIds);
                if ($wantsJson) {
                    ticket_json_response(['success' => true]);
                }
                set_flash('success', 'Selected tickets restored successfully.');
            }
            if (!$wantsJson) {
                redirect('tickets/list.php');
            }
        }
    }
}

$filters = ticket_query_service_filters($_GET);
$result = ticket_query_service_list($pdo, $filters);
$listAssignees = active_users($pdo);
$listPartyOptions = party_service_active_options($pdo);
$modalTicketCountryOptions = party_service_ticket_country_options($pdo);
$modalTicketPartySearchUrl = url('tickets/party_search.php');
$extraStylesheets = ['assets/css/tickets_ui_party_country.css', 'assets/css/pages/tickets-list-premium.css'];
$csrfToken = csrf_token();
$tickets = $result['tickets'];
$totalTickets = $result['total'];
$totalPages = $result['total_pages'];
$currentPageNumber = $result['page'];
$rangeStart = $result['range_start'];
$rangeEnd = $result['range_end'];

$statusCountsStmt = $pdo->query(
    "SELECT
        SUM(CASE WHEN t.is_deleted = 1 THEN 1 ELSE 0 END) AS deleted_count,
        SUM(CASE WHEN t.is_deleted = 1 AND t.deleted_at IS NOT NULL THEN 1 ELSE 0 END) AS archived_count,
        SUM(CASE WHEN t.is_deleted = 0 AND t.deleted_at IS NULL THEN 1 ELSE 0 END) AS active_count
     FROM tickets t WHERE t.assign_to = '' OR t.assign_to IS NULL"
);
$statusCounts = $statusCountsStmt->fetch() ?: [];

if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    ob_start();
    include __DIR__ . '/../views/tickets/list_rows.php';

    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => true,
        'html' => ob_get_clean(),
        'total' => $totalTickets,
        'pages' => $totalPages,
        'page' => $currentPageNumber,
        'range_start' => $rangeStart,
        'range_end' => $rangeEnd,
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$flash = get_flash();

include __DIR__ . '/../includes/header.php';
?>

<?php if ($flash): ?>
    <div class="flash flash-<?php echo e($flash['type']); ?>"><?php echo e($flash['message']); ?></div>
<?php endif; ?>

<div class="ticket-workspace">
<div class="ticket-workspace-header">
    <div>
        <nav class="ticket-breadcrumb" aria-label="Breadcrumb">
            <a href="<?php echo e(url('dashboard/index.php')); ?>">Dashboard</a>
            <span aria-hidden="true">/</span>
            <span aria-current="page">Tickets</span>
        </nav>
        <h2 class="ticket-workspace-title">Support Tickets</h2>
        <p class="ticket-workspace-counts" id="ticket-list-summary">
            <span><strong id="ticket-count"><?php echo e($totalTickets); ?></strong> matched</span>
            <span class="ticket-summary-dot" aria-hidden="true">·</span>
            <span>showing <strong id="ticket-range"><?php echo e($rangeStart); ?>-<?php echo e($rangeEnd); ?></strong></span>
        </p>
    </div>
    <div class="toolbar">
        <button type="button" class="btn btn-primary btn-sm" id="ticket-create-modal-open">Create Ticket</button>
        <a href="<?php echo e(url('tickets/create.php')); ?>" class="btn btn-outline btn-sm ticket-create-fullpage-link">Full form</a>
    </div>
</div>

<?php include __DIR__ . '/../views/tickets/list_filters.php'; ?>

<div class="table-card ticket-list-card ticket-list-compact" id="ticket-list-root" data-csrf="<?php echo e($csrfToken); ?>">
    <div class="table-actions-bar" style="padding: 8px 12px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 12px;">
        <div class="bulk-actions-left" style="display: flex; align-items: center; gap: 8px;">
            <label class="checkbox-label">
                <input type="checkbox" id="select-all-tickets">
                <span>Select all</span>
            </label>
        </div>
        <div class="bulk-actions-right" style="margin-left: auto; font-size: 13px; color: var(--muted);">
            <span id="selected-count">0</span> <span>selected</span>
        </div>
        <div class="bulk-actions-delete" style="display: none; align-items: center; gap: 8px;">
            <button type="button" id="top-bulk-action-btn" class="ticket-delete-btn" title="Archive selected">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
            </button>
            <button type="button" id="top-bulk-restore-btn" class="ticket-delete-btn" title="Restore selected" style="display: none;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12H2"/><path d="M18 8l4 4l-4 4"/></svg>
            </button>
        </div>
    </div>
    <div class="table-wrap ticket-table-wrap">
        <table class="ticket-data-table ticket-table-dense">
            <thead>
                <tr>
                    <th style="width: 36px;"></th>
                    <th class="sortable" data-sort="ticket_id">Ticket</th>
                    <th class="sortable" data-sort="issue">Details</th>
                    <th class="ticket-col-team ticket-cell-hide-md">Team</th>
                    <th class="sortable" data-sort="created_at">Timeline</th>
                    <th class="ticket-col-actions">Actions</th>
                </tr>
            </thead>
            <tbody id="tickets-tbody">
                <?php include __DIR__ . '/../views/tickets/list_rows.php'; ?>
            </tbody>
        </table>
    </div>

    <div class="pagination ticket-pagination-premium">
        <div class="pagination-summary">
            <p class="pagination-showing" id="ticket-range-mobile">Showing <?php echo e($rangeStart); ?>&ndash;<?php echo e($rangeEnd); ?> of <?php echo e($totalTickets); ?> tickets</p>
            <span class="pagination-info">Page <span id="current-page"><?php echo e($currentPageNumber); ?></span> of <span id="total-pages"><?php echo e($totalPages); ?></span></span>
            <label class="ticket-page-size">
                <span>Per page</span>
                <select id="page-limit" aria-label="Tickets per page">
                    <?php foreach ([10, 20, 50, 100] as $limitOption): ?>
                        <option value="<?php echo e((string) $limitOption); ?>" <?php echo (int) ($filters['limit'] ?? 20) === $limitOption ? 'selected' : ''; ?>><?php echo e((string) $limitOption); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <div class="pagination-controls">
            <button type="button" class="pagination-btn" id="first-page" <?php echo $currentPageNumber <= 1 ? 'disabled' : ''; ?>>First</button>
            <button type="button" class="pagination-btn" id="prev-page" <?php echo $currentPageNumber <= 1 ? 'disabled' : ''; ?> aria-label="Previous page">Prev</button>
            <div class="pagination-pages" id="pagination-pages"></div>
            <button type="button" class="pagination-btn" id="next-page" <?php echo $currentPageNumber >= $totalPages ? 'disabled' : ''; ?>>Next</button>
            <button type="button" class="pagination-btn" id="last-page" <?php echo $currentPageNumber >= $totalPages ? 'disabled' : ''; ?>>Last</button>
        </div>
    </div>
</div>

<div class="modal-backdrop" id="archive-modal" style="display: none;">
    <div class="modal danger-modal" style="max-width: 480px;">
        <div class="modal-header">
            <h3 class="modal-title">Archive Tickets</h3>
            <button type="button" class="modal-close" onclick="closeModal('archive-modal')">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="archive-ticket-id">
            <p>Are you sure you want to archive <strong id="archive-count">0</strong> ticket(s)?</p>
            <p class="text-muted" style="font-size: 12px;">This will hide the tickets from the active view. They can be restored later.</p>
            <div style="margin-top: 12px;">
                <label style="display: block; margin-bottom: 6px; font-size: 13px; font-weight: 500;">Reason for archiving:</label>
                <textarea id="archive-reason" class="form-control" rows="3" placeholder="Enter reason (optional)"></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal('archive-modal')">Cancel</button>
            <button type="button" class="btn btn-danger" id="confirm-archive-btn">Archive Tickets</button>
        </div>
    </div>
</div>

    <style>
    .modal-backdrop {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 10000;
    }
    .modal {
        background: var(--bg);
        border-radius: 8px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        max-width: 90vw;
        width: 100%;
        border: 1px solid var(--border);
    }
    .modal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 16px 20px;
        border-bottom: 1px solid var(--border);
    }
    .modal-title {
        margin: 0;
        font-size: 18px;
        font-weight: 600;
    }
    .modal-close {
        background: none;
        border: none;
        font-size: 24px;
        cursor: pointer;
        padding: 0;
    }
    .modal-body {
        padding: 20px;
    }
    .modal-footer {
        padding: 16px 20px;
        border-top: 1px solid var(--border);
        display: flex;
        justify-content: flex-end;
        gap: 8px;
    }
    .modal.danger-modal {
        border: 2px solid #dc2626;
    }
    .modal.danger-modal .modal-header {
        border-bottom-color: #dc2626;
    }
    .modal.danger-modal .modal-title {
        color: #dc2626;
    }

    .text-muted {
        color: var(--muted);
    }
    </style>

<?php include __DIR__ . '/../views/tickets/create_modal.php'; ?>

<script src="<?php echo e(url('assets/js/tickets-list.js')); ?>"></script>
<script src="<?php echo e(url('assets/js/tickets_ui_party_country.js')); ?>" defer></script>
<script>
(function () {
    var state = {
        page: <?php echo (int) $filters['page']; ?>,
        limit: <?php echo (int) $filters['limit']; ?>,
        sort_by: '<?php echo e($filters['sort_by']); ?>',
        sort_dir: '<?php echo e($filters['sort_dir']); ?>',
        status: '<?php echo e($filters['status']); ?>',
        search: '<?php echo e($filters['search']); ?>',
        external_ticket_id: '<?php echo e($filters['external_ticket_id']); ?>',
        from_date: '<?php echo e($filters['from_date']); ?>',
        to_date: '<?php echo e($filters['to_date']); ?>',
        priority: '<?php echo e($filters['priority'] ?? ''); ?>',
        country: '<?php echo e($filters['country'] ?? ''); ?>',
        customer: '<?php echo e($filters['customer'] ?? ''); ?>',
        assign_to: '<?php echo e($filters['assign_to'] ?? ''); ?>',
        assigned_vendor_id: '<?php echo e($filters['assigned_vendor_id'] ?? ''); ?>'
    };

    var searchInput = document.getElementById('search');
    var statusInput = document.getElementById('status');
    var priorityInput = document.getElementById('priority');
    var assignToInput = document.getElementById('assign-to');
    var vendorInput = document.getElementById('vendor-id');
    var customerInput = document.getElementById('customer');
    var countryInput = document.getElementById('country');
    var externalInput = document.getElementById('external-ticket-id');
    var fromDateInput = document.getElementById('from-date');
    var toDateInput = document.getElementById('to-date');
    var limitInput = document.getElementById('page-limit');
    var toggleFiltersBtn = document.getElementById('toggle-filters');
    var filterFields = document.getElementById('filter-fields');
    var ticketBody = document.getElementById('tickets-tbody');
    var ticketCount = document.getElementById('ticket-count');
    var currentPage = document.getElementById('current-page');
    var totalPages = document.getElementById('total-pages');
    var ticketRange = document.getElementById('ticket-range');
    var ticketRangeMobile = document.getElementById('ticket-range-mobile');
    var firstButton = document.getElementById('first-page');
    var prevButton = document.getElementById('prev-page');
    var nextButton = document.getElementById('next-page');
    var lastButton = document.getElementById('last-page');
    var pageButtons = document.getElementById('pagination-pages');
    var clearButton = document.getElementById('clear-filters');
    var searchDelay = null;

    function syncStateFromInputs() {
        state.search = searchInput ? searchInput.value.trim() : '';
        state.status = statusInput ? statusInput.value : '';
        state.priority = priorityInput ? priorityInput.value : '';
        state.assign_to = assignToInput ? assignToInput.value : '';
        state.assigned_vendor_id = vendorInput ? vendorInput.value : '';
        state.customer = customerInput ? customerInput.value.trim() : '';
        state.country = countryInput ? countryInput.value.trim() : '';
        state.external_ticket_id = externalInput ? externalInput.value.trim() : '';
        state.from_date = fromDateInput ? fromDateInput.value : '';
        state.to_date = toDateInput ? toDateInput.value : '';
        state.limit = limitInput ? limitInput.value : '20';
    }

    function buildQuery() {
        var params = new URLSearchParams();
        Object.keys(state).forEach(function (key) {
            if (state[key] !== '') {
                params.set(key, state[key]);
            }
        });
        params.set('ajax', '1');
        return params.toString();
    }

    function updateSortIndicators() {
        document.querySelectorAll('th.sortable').forEach(function (header) {
            var column = header.getAttribute('data-sort');
            header.classList.remove('sort-asc', 'sort-desc');
            if (column === state.sort_by) {
                header.classList.add(state.sort_dir === 'ASC' ? 'sort-asc' : 'sort-desc');
            }
        });
    }

    function updateFilterCount() {
        var countEl = document.getElementById('filter-active-count');
        if (!countEl) {
            return;
        }
        var count = 0;
        [
            statusInput, priorityInput, assignToInput, vendorInput,
            customerInput, countryInput, externalInput, fromDateInput, toDateInput
        ].forEach(function (input) {
            if (input && String(input.value || '').trim() !== '') {
                count += 1;
            }
        });
        countEl.textContent = String(count);
        countEl.classList.toggle('is-hidden', count === 0);
    }

    function renderPageButtons() {
        var total = Number(totalPages.textContent || '1');
        var current = Number(currentPage.textContent || '1');
        var start = Math.max(1, current - 2);
        var end = Math.min(total, current + 2);
        var fragment = document.createDocumentFragment();

        function addPageButton(label, pageNumber, active) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'pagination-btn pagination-number' + (active ? ' active' : '');
            button.textContent = label;
            button.disabled = active;
            button.addEventListener('click', function () {
                state.page = pageNumber;
                fetchTickets();
            });
            fragment.appendChild(button);
        }

        pageButtons.innerHTML = '';

        if (start > 1) {
            addPageButton('1', 1, current === 1);
            if (start > 2) {
                var gapStart = document.createElement('span');
                gapStart.className = 'pagination-gap';
                gapStart.textContent = '...';
                fragment.appendChild(gapStart);
            }
        }

        for (var page = start; page <= end; page += 1) {
            addPageButton(String(page), page, page === current);
        }

        if (end < total) {
            if (end < total - 1) {
                var gapEnd = document.createElement('span');
                gapEnd.className = 'pagination-gap';
                gapEnd.textContent = '...';
                fragment.appendChild(gapEnd);
            }
            addPageButton(String(total), total, current === total);
        }

        pageButtons.appendChild(fragment);
    }

    function showLoading() {
        if (!ticketBody) {
            return;
        }
        ticketBody.innerHTML = '<tr><td colspan="5"><div class="ticket-table-skeleton" aria-hidden="true"><span></span><span></span><span></span><span></span><span></span></div></td></tr>';
    }

    if (toggleFiltersBtn && filterFields) {
        toggleFiltersBtn.addEventListener('click', function () {
            var hidden = filterFields.hasAttribute('hidden');
            if (hidden) {
                filterFields.removeAttribute('hidden');
                toggleFiltersBtn.setAttribute('aria-expanded', 'true');
                toggleFiltersBtn.textContent = 'Hide Filters';
            } else {
                filterFields.setAttribute('hidden', 'hidden');
                toggleFiltersBtn.setAttribute('aria-expanded', 'false');
                toggleFiltersBtn.textContent = 'Show Filters';
            }
        });
    }

    function fetchTickets() {
        showLoading();
        var qs = buildQuery();
        qs += (qs !== '' ? '&' : '') + '_ts=' + Date.now();
        fetch(window.location.pathname + '?' + qs, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Bad response');
                }
                return response.json();
            })
            .then(function (payload) {
                if (!payload || !payload.success) {
                    return;
                }

                ticketBody.innerHTML = payload.html;
                ticketCount.textContent = payload.total;
                currentPage.textContent = payload.page;
                totalPages.textContent = payload.pages;
                if (ticketRange) {
                    ticketRange.textContent = payload.range_start + '-' + payload.range_end;
                }
                ticketRangeMobile.textContent = 'Showing ' + payload.range_start + '\u2013' + payload.range_end + ' of ' + payload.total + ' tickets';
                updateFilterCount();
                firstButton.disabled = payload.page <= 1;
                prevButton.disabled = payload.page <= 1;
                nextButton.disabled = payload.page >= payload.pages;
                lastButton.disabled = payload.page >= payload.pages;

                var params = new URLSearchParams();
                Object.keys(state).forEach(function (key) {
                    if (state[key] !== '') {
                        params.set(key, state[key]);
                    }
                });
                history.replaceState(null, '', window.location.pathname + (params.toString() ? '?' + params.toString() : ''));

                if (window.TicketListUI) {
                    var lr = document.getElementById('ticket-list-root');
                    if (lr) {
                        window.TicketListUI.init(lr, typeof window.__ticketListRefresh === 'function' ? window.__ticketListRefresh : undefined);
                    }
                }
                updateSortIndicators();
                renderPageButtons();
            })
            .catch(function () {
                if (window.TicketListUI && typeof window.TicketListUI.showToast === 'function') {
                    window.TicketListUI.showToast('Could not refresh ticket list.', true);
                }
            });
    }

    function applyFilters() {
        state.page = 1;
        syncStateFromInputs();
        updateFilterCount();
        fetchTickets();
    }

    [statusInput, priorityInput, assignToInput, vendorInput, fromDateInput, toDateInput, limitInput].forEach(function (input) {
        if (input) {
            input.addEventListener('change', applyFilters);
        }
    });

    [searchInput, externalInput, customerInput, countryInput].forEach(function (input) {
        if (!input) {
            return;
        }
        input.addEventListener('input', function () {
            window.clearTimeout(searchDelay);
            searchDelay = window.setTimeout(applyFilters, 250);
        });
    });

    document.querySelectorAll('th.sortable').forEach(function (header) {
        header.addEventListener('click', function () {
            var column = header.getAttribute('data-sort');
            if (state.sort_by === column) {
                state.sort_dir = state.sort_dir === 'ASC' ? 'DESC' : 'ASC';
            } else {
                state.sort_by = column;
                state.sort_dir = 'DESC';
            }
            fetchTickets();
        });
    });

    firstButton.addEventListener('click', function () {
        if (state.page > 1) {
            state.page = 1;
            fetchTickets();
        }
    });

    prevButton.addEventListener('click', function () {
        if (state.page > 1) {
            state.page -= 1;
            fetchTickets();
        }
    });

    nextButton.addEventListener('click', function () {
        state.page += 1;
        fetchTickets();
    });

    lastButton.addEventListener('click', function () {
        state.page = Number(totalPages.textContent || state.page);
        fetchTickets();
    });

    clearButton.addEventListener('click', function () {
        state.page = 1;
        state.sort_by = 'ticket_id';
        state.sort_dir = 'DESC';
        if (searchInput) searchInput.value = '';
        if (statusInput) statusInput.value = '';
        if (priorityInput) priorityInput.value = '';
        if (assignToInput) assignToInput.value = '';
        if (vendorInput) vendorInput.value = '';
        if (customerInput) customerInput.value = '';
        if (countryInput) countryInput.value = '';
        if (externalInput) externalInput.value = '';
        if (fromDateInput) fromDateInput.value = '';
        if (toDateInput) toDateInput.value = '';
        if (limitInput) limitInput.value = '20';
        syncStateFromInputs();
        fetchTickets();
    });

    window.__ticketListRefresh = fetchTickets;

    (function initTicketCreateModal() {
        var modal = document.getElementById('ticket-create-modal');
        var form = document.getElementById('ticket-create-modal-form');
        if (!modal || !form) {
            return;
        }
        var errEl = document.getElementById('ticket-create-modal-error');
        var submitBtn = document.getElementById('ticket-create-modal-submit');
        var openSelectors = '#ticket-create-modal-open, .ticket-create-modal-open-trigger';
        var descEditor = document.getElementById('ticket-create-description-editor');
        var descSync = document.getElementById('ticket-create-description-sync');
        var descWrap = document.getElementById('ticket-create-description-editor-wrap');
        var descSizeStorageKey = 'ticketCreateModalDescSize';

        function applyDescSize(size) {
            var sizes = { compact: true, comfortable: true, expanded: true };
            if (!sizes[size]) {
                size = 'comfortable';
            }
            if (descWrap) {
                descWrap.setAttribute('data-desc-size', size);
            }
            if (descEditor) {
                descEditor.style.height = '';
            }
            try {
                sessionStorage.setItem(descSizeStorageKey, size);
            } catch (ignore1) {
                /* ignore */
            }
            document.querySelectorAll('.ticket-desc-size-btn').forEach(function (btn) {
                var active = btn.getAttribute('data-desc-size') === size;
                btn.classList.toggle('ticket-desc-size-btn--active', active);
                btn.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
        }

        function loadStoredDescSize() {
            var size = 'comfortable';
            try {
                var stored = sessionStorage.getItem(descSizeStorageKey);
                if (stored === 'compact' || stored === 'comfortable' || stored === 'expanded') {
                    size = stored;
                }
            } catch (ignore2) {
                /* ignore */
            }
            applyDescSize(size);
        }

        document.querySelectorAll('.ticket-desc-size-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                applyDescSize(btn.getAttribute('data-desc-size') || 'comfortable');
                if (descEditor && typeof descEditor.focus === 'function') {
                    descEditor.focus();
                }
            });
        });

        loadStoredDescSize();

        function descEditorLooksEmpty() {
            if (!descEditor) {
                return true;
            }
            var html = descEditor.innerHTML || '';
            if (/<\s*img\b/i.test(html)) {
                return false;
            }
            return (descEditor.innerText || '').replace(/\u00a0/g, ' ').trim() === '';
        }

        function syncDescriptionField() {
            if (descEditor && descSync) {
                descSync.value = descEditor.innerHTML || '';
            }
        }

        function toggleDescPlaceholderClass() {
            if (!descEditor) {
                return;
            }
            if (descEditorLooksEmpty()) {
                descEditor.classList.add('is-empty');
            } else {
                descEditor.classList.remove('is-empty');
            }
        }

        if (descEditor) {
            descEditor.classList.add('is-empty');
            ['input', 'paste', 'blur'].forEach(function (ev) {
                descEditor.addEventListener(ev, function () {
                    window.setTimeout(toggleDescPlaceholderClass, 0);
                });
            });
        }

        function showErr(msg) {
            if (!errEl) {
                return;
            }
            errEl.textContent = msg || '';
            if (msg) {
                errEl.removeAttribute('hidden');
            } else {
                errEl.setAttribute('hidden', 'hidden');
            }
        }

        function openModal() {
            modal.removeAttribute('hidden');
            modal.setAttribute('aria-hidden', 'false');
            showErr('');
            loadStoredDescSize();
            toggleDescPlaceholderClass();
            document.body.style.overflow = 'hidden';
            var first = form.querySelector('input:not([type=hidden]), select, textarea');
            if (first) {
                window.setTimeout(function () {
                    first.focus();
                }, 0);
            }
        }

        function closeModal() {
            modal.setAttribute('hidden', 'hidden');
            modal.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
            showErr('');
        }

        document.querySelectorAll(openSelectors).forEach(function (btn) {
            btn.addEventListener('click', openModal);
        });

        modal.querySelectorAll('[data-ticket-create-modal-close]').forEach(function (el) {
            el.addEventListener('click', function (e) {
                if (e.target === el) {
                    closeModal();
                }
            });
        });

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }
            showErr('');
            syncDescriptionField();
            if (descEditorLooksEmpty()) {
                showErr('Please add a description: type text, paste an Excel table, or paste an image.');

                return;
            }
            submitBtn.disabled = true;
            var fd = new FormData(form);
            fetch(form.action, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json'
                }
            })
                .then(function (res) {
                    return res.text().then(function (raw) {
                        var data = null;
                        try {
                            data = raw ? JSON.parse(raw) : null;
                        } catch (ignore) {
                            data = null;
                        }
                        if (!res.ok || !data || data.success !== true) {
                            var msg = (data && data.message)
                                ? data.message
                                : raw && raw.trim()
                                  ? raw.trim().slice(0, 400)
                                  : 'Could not create ticket.';
                            throw new Error(msg);
                        }
                        form.reset();
                        if (descEditor) {
                            descEditor.innerHTML = '';
                            descEditor.style.height = '';
                            toggleDescPlaceholderClass();
                        }
                        closeModal();
                        if (window.TicketListUI && typeof window.TicketListUI.showToast === 'function') {
                            var toastLine = data.message || 'Ticket created.';
                            if (data.ticket_serial) {
                                toastLine += ' · ' + data.ticket_serial;
                            }
                            window.TicketListUI.showToast(toastLine);
                        }
                        state.page = 1;
                        if (typeof window.__ticketListRefresh === 'function') {
                            window.__ticketListRefresh();
                        }
                    });
                })
                .catch(function (err) {
                    showErr(err.message || 'Request failed.');
                })
                .finally(function () {
                    submitBtn.disabled = false;
                });
        });
    })();

    if (window.TicketListUI) {
        var listRootBootstrap = document.getElementById('ticket-list-root');
        if (listRootBootstrap) {
            window.__ticketListInitWithRefresh = true;
            window.TicketListUI.init(listRootBootstrap, fetchTickets);
        }
    }
    updateSortIndicators();
    updateFilterCount();
    renderPageButtons();
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

