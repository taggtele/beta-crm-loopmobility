<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../services/ticket_query_service.php';
require_once __DIR__ . '/../services/email_log_service.php';
require_once __DIR__ . '/../services/external_ticket_history_service.php';
require_once __DIR__ . '/../services/party_service.php';
require_once __DIR__ . '/../modules/tickets/ticket_service.php';
require_once __DIR__ . '/../modules/tickets/ticket_description_sanitize.php';
require_once __DIR__ . '/../includes/rbac.php';

$currentUser = require_login($pdo);
$ticketId = (int) ($_GET['id'] ?? $_POST['ticket_id'] ?? 0);

if ($ticketId <= 0) {
    set_flash('error', 'Ticket not found.');
    redirect('tickets/list.php');
}

[$scopeSql, $scopeParams] = ticket_scope($currentUser, 't', true, true);
$stmt = $pdo->prepare(
    'SELECT t.ticket_id
     FROM tickets t
     WHERE t.ticket_id = :ticket_id
     AND t.deleted_at IS NULL
     AND t.is_deleted = 0
     AND ' . $scopeSql . '
     LIMIT 1'
);
$stmt->execute(array_merge([':ticket_id' => $ticketId], $scopeParams));

if (!$stmt->fetchColumn()) {
    set_flash('error', 'Ticket not found or access denied.');
    redirect('tickets/list.php');
}

$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if (isset($_POST['auto_acknowledgement_toggle'])) {
        $detailTicket = ticket_query_service_detail($pdo, $ticketId);
        if (!$detailTicket) {
            set_flash('error', 'Ticket not found.');
            redirect('tickets/list.php');
        }
        if (!ticket_user_can_change_status($currentUser, $detailTicket)) {
            $message = ['type' => 'error', 'text' => 'You do not have permission to change this setting.'];
        } else {
            $newAutoAck = trim((string) ($_POST['auto_acknowledgement'] ?? '1'));
            $newAutoAck = ($newAutoAck === '1');
            $beforeTicket = ticket_service_get_ticket_with_user_context($pdo, $ticketId);
            $pdo->prepare(
                'UPDATE tickets SET send_auto_acknowledgement = :val, updated_by = :updated_by WHERE ticket_id = :ticket_id'
            )->execute([
                ':val' => $newAutoAck ? 1 : 0,
                ':updated_by' => ticket_service_current_user_id($currentUser),
                ':ticket_id' => $ticketId,
            ]);
            $afterTicket = ticket_service_get_ticket_with_user_context($pdo, $ticketId);
            if ($beforeTicket && $afterTicket) {
                ticket_service_handle_ticket_updated($pdo, $beforeTicket, $afterTicket, $newAutoAck);
            }
            $currentAutoAck = $newAutoAck;
            set_flash('success', 'Auto Acknowledge ' . ($newAutoAck ? 'enabled' : 'disabled') . '.');
            redirect('tickets/view.php?id=' . $ticketId);
        }
    } elseif (isset($_POST['status'])) {
        $detailTicket = ticket_query_service_detail($pdo, $ticketId);
        if (!$detailTicket) {
            set_flash('error', 'Ticket not found.');
            redirect('tickets/list.php');
        }

        $newStatus = trim((string) ($_POST['status'] ?? ''));
        if (!in_array($newStatus, ticket_statuses(), true)) {
            $message = ['type' => 'error', 'text' => 'Invalid status selected.'];
        } elseif (!ticket_user_can_change_status($currentUser, $detailTicket)) {
            $message = ['type' => 'error', 'text' => 'You can only change status for tickets assigned to you.'];
        } elseif ($newStatus === 'Closed' && !ticket_is_assigned($detailTicket['assign_to'] ?? null)) {
            $message = ['type' => 'error', 'text' => 'Assign the ticket before closing it.'];
        } else {
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

            set_flash('success', 'Ticket status updated.');
            redirect('tickets/view.php?id=' . $ticketId);
        }
    }
}

$ticket = ticket_query_service_detail($pdo, $ticketId);
if (!$ticket) {
    set_flash('error', 'Ticket not found.');
    redirect('tickets/list.php');
}
$ticketMail = email_log_service_for_ticket($pdo, $ticketId, $currentUser);
$incomingEmails = $ticketMail['incoming'];
$outgoingEmails = $ticketMail['outgoing'];
$emailThread = $ticketMail['thread'] ?? [];
$externalTicketHistory = external_ticket_history_for_ticket($pdo, $ticketId);
$canEdit = ticket_user_can_edit($currentUser, $ticket);
$canChangeStatus = ticket_user_can_change_status($currentUser, $ticket);
$showEditPanel = $canEdit && (isset($_GET['edit']) && $_GET['edit'] === '1');
$assignees = $canEdit ? active_users($pdo) : [];
$partyOptions = $canEdit ? party_service_active_options($pdo) : [];
$agentCanClaim = ($currentUser['role'] ?? '') === 'Agent' && !ticket_is_assigned($ticket['assign_to'] ?? null);
$selectedAssignTo = $ticket['assign_to'] ?? '';
$selectedVendorId = $ticket['assigned_vendor_id'] ?? '';
$vendorEmailInitiated = (bool) ($ticket['vendor_email_initiated'] ?? 0);
$selectedStatus = $ticket['status'];
$selectedPriority = $ticket['priority'];
$selectedCustomer = $ticket['customer'] ?? '';
$selectedCustomerEmail = $ticket['customer_email'] ?? '';
$sendAutoAckEnabled = (bool) ($ticket['send_auto_acknowledgement'] ?? true);
$ackTooltip = 'When On, customer receives acknowledgement and status-update emails on save. When Off, no customer auto-mails are sent from this update.';
$selectedCountry = $ticket['country'] ?? '';
$selectedIssue = $ticket['issue'] ?? '';
$selectedDescription = $ticket['description'] ?? '';
$selectedExternalTicketId = $ticket['external_ticket_id'] ?? '';
$formAction = url('tickets/update.php?id=' . (int) $ticket['ticket_id']);
$showCancelButton = true;
$ticketId = (int) $ticket['ticket_id'];

$extraStylesheets = [];
if ($canEdit) {
    $ticketCountryDropdownOptions = party_service_ticket_country_options($pdo);
    $ticketPartySearchUrl = url('tickets/party_search.php');
    $ticketCountryFieldNs = 'edit';
    $selectedCustomerPartyId = (int) ($ticket['initiator_party_id'] ?? 0);
    $selectedCustomerDisplay = $selectedCustomer;
    $ticketCountryShowRequired = false;
    $extraStylesheets[] = 'assets/css/tickets_ui_party_country.css';
}

$assignedVendorEmail = '';
if (!empty($ticket['assigned_vendor_id'])) {
    $vendorEmailStmt = $pdo->prepare(
        'SELECT email
         FROM party_emails
         WHERE party_id = :party_id
         ORDER BY is_primary DESC, id ASC
         LIMIT 1'
    );
    $vendorEmailStmt->execute([':party_id' => (int) $ticket['assigned_vendor_id']]);
    $assignedVendorEmail = (string) ($vendorEmailStmt->fetchColumn() ?: '');
}

$canManageEmailLogs = rbac_can_manage_email_logs($currentUser);
$hasCustomerReplyTarget = trim((string) ($ticket['customer_email'] ?? '')) !== ''
    || (int) ($ticket['initiator_party_id'] ?? 0) > 0;
$quickReplyCustomerUrl = '';
$quickReplyVendorUrl = '';
if ($canManageEmailLogs && $ticketId > 0) {
    if ($hasCustomerReplyTarget) {
        $quickReplyCustomerUrl = url(
            'emails/logs.php?' . http_build_query([
                'ticket_id' => $ticketId,
                'open_compose' => '1',
                'quick_reply' => 'customer',
            ])
        );
    }
    if ($assignedVendorEmail !== '' || (int) ($ticket['assigned_vendor_id'] ?? 0) > 0) {
        $quickReplyVendorUrl = url(
            'emails/logs.php?' . http_build_query([
                'ticket_id' => $ticketId,
                'open_compose' => '1',
                'quick_reply' => 'vendor',
            ])
        );
    }
}

$pageTitle = 'Ticket ' . format_ticket_serial($pdo, $ticket);
$pageHeading = 'Ticket Detail';
$pageDescription = 'Full email thread, audit trail, and quick status updates.';
$flash = get_flash();

include __DIR__ . '/../includes/header.php';
?>

<?php if ($flash): ?>
    <div class="flash flash-<?php echo e($flash['type']); ?>"><?php echo e($flash['message']); ?></div>
<?php endif; ?>

<?php if ($message): ?>
    <div class="flash flash-<?php echo e($message['type']); ?>"><?php echo e($message['text']); ?></div>
<?php endif; ?>

<nav class="ticket-breadcrumb ticket-breadcrumb-detail" aria-label="Breadcrumb">
    <a href="<?php echo e(url('dashboard/index.php')); ?>">Dashboard</a>
    <span aria-hidden="true">/</span>
    <a href="<?php echo e(url('tickets/list.php')); ?>">Tickets</a>
    <span aria-hidden="true">/</span>
    <span aria-current="page"><?php echo e(format_ticket_serial($pdo, $ticket)); ?></span>
</nav>

<div class="page-actions ticket-detail-toolbar">
    <div>
        <h2 class="section-title ticket-detail-title"><?php echo e(format_ticket_serial($pdo, $ticket)); ?></h2>
        <p class="section-subtitle"><?php echo e($ticket['issue']); ?></p>
        <div class="ticket-detail-badges">
            <span class="badge <?php echo $ticket['status'] === 'Open' ? 'badge-open' : ($ticket['status'] === 'In-Progress' ? 'badge-progress' : 'badge-closed'); ?>"><?php echo e($ticket['status']); ?></span>
            <span class="badge <?php echo $ticket['priority'] === 'High' ? 'badge-high' : ($ticket['priority'] === 'Medium' ? 'badge-medium' : 'badge-low'); ?>"><?php echo e($ticket['priority']); ?></span>
            <?php if (!ticket_is_assigned($ticket['assign_to'] ?? null)): ?>
                <span class="badge badge-unassigned">Unassigned</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="toolbar">
        <a href="<?php echo e(url('tickets/list.php')); ?>" class="btn btn-outline">Back to Tickets</a>
        <?php if ($canEdit): ?>
            <button type="button" class="btn btn-primary" data-edit-toggle aria-expanded="<?php echo $showEditPanel ? 'true' : 'false'; ?>">
                Update / Edit
            </button>
        <?php endif; ?>
        <?php if ($quickReplyCustomerUrl !== ''): ?>
            <a href="<?php echo e($quickReplyCustomerUrl); ?>" class="btn btn-outline">Reply to Customer</a>
        <?php endif; ?>
        <?php if ($vendorEmailInitiated && $quickReplyVendorUrl !== ''): ?>
            <a href="<?php echo e($quickReplyVendorUrl); ?>" class="btn btn-outline">Reply to Vendor</a>
        <?php endif; ?>
        <?php if (!$vendorEmailInitiated && $assignedVendorEmail !== ''): ?>
            <?php
            $raiseToVendorQuery = http_build_query([
                'open_compose' => '1',
                'compose_ticket_id' => (int) $ticket['ticket_id'],
                'compose_to' => $assignedVendorEmail,
                'compose_party_id' => (int) ($ticket['assigned_vendor_id'] ?? 0),
            ]);
            ?>
            <a href="<?php echo e(url('emails/logs.php?' . $raiseToVendorQuery)); ?>" class="btn btn-primary">Raise To Vendor</a>
        <?php endif; ?>
        <a href="<?php echo e(url('emails/logs.php?ticket_id=' . (int) $ticket['ticket_id'])); ?>" class="btn btn-outline">Open Email Logs</a>
    </div>
</div>

<?php if ($canEdit): ?>
<div class="form-card ticket-edit-panel-card" id="ticket-edit-panel"<?php echo $showEditPanel ? '' : ' hidden'; ?>>
    <div class="info-strip">
        <div>
            <strong>Update ticket</strong>
            <p>Edit customer, assignment, status, vendor, and description.</p>
        </div>
        <span class="badge <?php echo $ticket['status'] === 'Open' ? 'badge-open' : ($ticket['status'] === 'In-Progress' ? 'badge-progress' : 'badge-closed'); ?>">
            <?php echo e($ticket['status']); ?>
        </span>
    </div>
    <?php include __DIR__ . '/../views/tickets/edit_form.php'; ?>
</div>
<?php endif; ?>

<div class="panel-grid">
    <div class="form-card ticket-detail-summary">
        <div class="info-strip">
            <div>
                <strong>Ticket summary</strong>
                <p>Quick overview of the current ticket state.</p>
            </div>
            <span class="badge <?php echo $ticket['status'] === 'Open' ? 'badge-open' : ($ticket['status'] === 'In-Progress' ? 'badge-progress' : 'badge-closed'); ?>">
                <?php echo e($ticket['status']); ?>
            </span>
        </div>

        <div class="ticket-summary-grid">
                <div class="ticket-summary-item">
                    <span>Customer</span>
                    <strong><?php echo e($ticket['customer']); ?></strong>
                </div>
                <div class="ticket-summary-item">
                    <span>Mail</span>
                    <strong><?php echo e($ticket['customer_email'] ?: '-'); ?></strong>
                </div>
                <div class="ticket-summary-item">
                    <span>Country</span>
                    <strong><?php echo e($ticket['country'] ?: '-'); ?></strong>
                </div>
                <div class="ticket-summary-item">
                    <span>Priority</span>
                    <strong><?php echo e($ticket['priority'] ?: '-'); ?></strong>
                </div>
                <div class="ticket-summary-item">
                    <span>Assigned To</span>
                    <strong><?php echo e($ticket['assignee_name'] ?: ($ticket['assign_to'] ?: 'Unassigned')); ?></strong>
                </div>
                <div class="ticket-summary-item full">
                    <span>Subject</span>
                    <strong><?php echo e($ticket['issue']); ?></strong>
                </div>
                <div class="ticket-summary-item full">
                    <span>Description</span>
                    <div class="ticket-summary-description ticket-summary-body"><?php ticket_description_render_html($ticket['description'] ?? ''); ?></div>
                </div>
            </div>

        <?php if ($canChangeStatus): ?>
        <form method="POST" class="ticket-quick-status-form" id="quick-status-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="ticket_id" value="<?php echo e($ticket['ticket_id']); ?>">
            <div class="form-grid ticket-quick-status-grid">
                <div class="input-group">
                    <label for="quick-status">Quick status</label>
                    <select id="quick-status" name="status">
                        <?php foreach (ticket_statuses() as $statusOption): ?>
                            <option value="<?php echo e($statusOption); ?>" <?php echo $ticket['status'] === $statusOption ? 'selected' : ''; ?>>
                                <?php echo e($statusOption); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-secondary btn-sm">Apply Status</button>
            </div>
        </form>
        <?php endif; ?>

        <?php if (rbac_can_change_ticket_status($currentUser)): ?>
        <form method="POST" class="ticket-quick-ack-form" id="quick-ack-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="ticket_id" value="<?php echo e($ticket['ticket_id']); ?>">
            <input type="hidden" name="auto_acknowledgement_toggle" value="1">
            <div class="ticket-ack-mail-control" style="margin-top:10px;">
                <div class="ticket-ack-mail-control-head">
                    <span class="ticket-ack-mail-control-label">Auto acknowledgement</span>
                    <div class="ticket-ack-mail-toggle" role="group" aria-label="Auto acknowledgement">
                        <label class="ticket-ack-mail-toggle-option">
                            <input type="radio" name="auto_acknowledgement" value="1" <?php echo $sendAutoAckEnabled ? 'checked' : ''; ?>>
                            <span>On</span>
                        </label>
                        <label class="ticket-ack-mail-toggle-option">
                            <input type="radio" name="auto_acknowledgement" value="0" <?php echo !$sendAutoAckEnabled ? 'checked' : ''; ?>>
                            <span>Off</span>
                        </label>
                    </div>
                </div>
                <div class="form-actions" style="margin-top:8px;">
                    <button type="submit" class="btn btn-secondary btn-sm">Save Auto Acknowledge</button>
                </div>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <div class="hero-card">
        <h2 class="section-title">Identifiers</h2>
        <div class="meta-list">
            <div class="meta-item">
                <span>Internal Ticket ID</span>
                <strong><?php echo e(format_ticket_serial($pdo, $ticket)); ?></strong>
            </div>
            <div class="meta-item">
                <span>System Ticket Number</span>
                <strong>#<?php echo e((int) $ticket['ticket_id']); ?></strong>
            </div>
            <div class="meta-item">
                <span>External Ticket ID</span>
                <strong><?php echo e($ticket['external_ticket_id'] ?: '-'); ?></strong>
            </div>
            <div class="meta-item">
                <span>Initiator</span>
                <strong><?php echo e($ticket['initiator_party_name'] ?: '-'); ?></strong>
            </div>
            <div class="meta-item">
                <span>Assigned Vendor</span>
                <strong><?php echo e($ticket['assigned_vendor_name'] ?: '-'); ?></strong>
            </div>
            <div class="meta-item">
                <span>Customer</span>
                <strong><?php echo e($ticket['customer']); ?></strong>
            </div>
            <div class="meta-item">
                <span>Customer Email</span>
                <strong><?php echo e($ticket['customer_email'] ?: '-'); ?></strong>
            </div>
            <div class="meta-item">
                <span>Country</span>
                <strong><?php echo e($ticket['country'] ?: '-'); ?></strong>
            </div>
            <div class="meta-item">
                <span>Priority</span>
                <strong><?php echo e($ticket['priority'] ?: '-'); ?></strong>
            </div>
            <div class="meta-item">
                <span>Assigned To</span>
                <strong><?php echo e($ticket['assignee_name'] ?: ($ticket['assign_to'] ?: 'Unassigned')); ?></strong>
            </div>
            <div class="meta-item">
                <span>Created By</span>
                <strong><?php echo e($ticket['creator_name'] ?: ($ticket['created_by'] ?: '-')); ?></strong>
            </div>
            <div class="meta-item">
                <span>Source</span>
                <strong><?php echo e(ucfirst((string) ($ticket['source'] ?: '-'))); ?></strong>
            </div>
            <div class="meta-item">
                <span>Created Date</span>
                <strong><?php echo e(format_date($ticket['created_at'])); ?></strong>
            </div>
            <div class="meta-item">
                <span>Closed Date</span>
                <strong><?php echo e(format_date($ticket['closed_at'])); ?></strong>
            </div>
        </div>
    </div>
</div>

<?php if ($externalTicketHistory): ?>
<div class="table-card" style="margin-top:16px;">
    <div class="table-header">
        <div>
            <h2 class="section-title">External Ticket History</h2>
            <p class="section-subtitle">Vendor/client references captured from email without overwriting the current ticket value.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>External Ticket ID</th>
                    <th>Source Email</th>
                    <th>Seen Count</th>
                    <th>Last Seen</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($externalTicketHistory as $history): ?>
                    <tr>
                        <td><?php echo e($history['external_ticket_id']); ?></td>
                        <td><?php echo e($history['source_email'] ?: '-'); ?></td>
                        <td><?php echo e((int) $history['seen_count']); ?></td>
                        <td><?php echo e(format_date($history['last_seen_at'] ?: $history['first_seen_at'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="panel-grid" style="margin-top:16px;">
    <div class="table-card">
        <div class="table-header">
            <div>
                <h2 class="section-title">Incoming Customer Emails</h2>
                <p class="section-subtitle">Full incoming email content received from the customer for this ticket.</p>
            </div>
        </div>
        <div style="padding:0 18px 18px;">
            <?php include __DIR__ . '/../views/emails/incoming_cards.php'; ?>
        </div>
    </div>

    <div class="table-card">
        <div class="table-header">
            <div>
                <h2 class="section-title">Outgoing Emails</h2>
                <p class="section-subtitle">SMTP delivery history, including the close-mail confirmation sent to the customer.</p>
            </div>
        </div>
        <div style="padding:0 18px 18px;">
            <?php include __DIR__ . '/../views/emails/outgoing_cards.php'; ?>
        </div>
    </div>
</div>

<div class="table-card" style="margin-top:16px;">
    <div class="table-header">
        <div>
            <h2 class="section-title">Logs History</h2>
            <p class="section-subtitle">Created, updated, email received, closed, notification, and mail delivery events.</p>
        </div>
    </div>
    <div style="padding:0 18px 18px;">
        <?php $logs = $ticket['logs']; include __DIR__ . '/../views/tickets/activity_timeline.php'; ?>
    </div>
</div>

<script src="<?php echo e(url('assets/js/tickets-list.js')); ?>"></script>
<?php if ($canEdit): ?>
<script src="<?php echo e(url('assets/js/tickets_ui_party_country.js')); ?>" defer></script>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
