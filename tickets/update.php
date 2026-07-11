<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../modules/notifications/notification_service.php';
require_once __DIR__ . '/../modules/tickets/ticket_service.php';
require_once __DIR__ . '/../services/ticket_query_service.php';
require_once __DIR__ . '/../services/party_service.php';
require_once __DIR__ . '/../modules/tickets/ticket_description_sanitize.php';

$currentUser = require_login($pdo);
$extraStylesheets = ['assets/css/tickets_ui_party_country.css'];
$ticketCountryDropdownOptions = party_service_ticket_country_options($pdo);
$ticketId = (int) ($_GET['id'] ?? $_POST['ticket_id'] ?? 0);

if ($ticketId <= 0) {
    set_flash('error', 'Ticket not found.');
    redirect('tickets/list.php');
}

[$scopeSql, $scopeParams] = ticket_scope($currentUser, 't', true);
$ticketStmt = $pdo->prepare(
    'SELECT
        t.ticket_id,
        t.external_ticket_id,
        t.customer,
        t.country,
        t.customer_email,
        t.issue,
        t.description,
        t.status,
        t.priority,
        t.assign_to,
        t.created_by,
        t.created_at,
        t.closed_at,
        t.mail_message_id,
        t.mail_thread_id,
        t.source,
        ' . party_service_ticket_select_columns($pdo) . ',
        initiator.name AS initiator_party_name,
        vendor.name AS assigned_vendor_name,
        assignee.name AS assignee_name,
        creator.name AS creator_name
     FROM tickets t
     LEFT JOIN parties initiator ON initiator.id = t.initiator_party_id
     LEFT JOIN parties vendor ON vendor.id = t.assigned_vendor_id
     LEFT JOIN users assignee ON assignee.user_id = t.assign_to
     LEFT JOIN users creator ON creator.user_id = t.created_by
WHERE t.ticket_id = :ticket_id
      AND t.deleted_at IS NULL
      AND t.is_deleted = 0
      AND ' . $scopeSql . '
      LIMIT 1'
);
$ticketStmt->execute(array_merge([':ticket_id' => $ticketId], $scopeParams));
$ticket = $ticketStmt->fetch();

if (!$ticket) {
    set_flash('error', 'Ticket not found or access denied.');
    redirect('tickets/list.php');
}

$assignees = active_users($pdo);
$partyOptions = party_service_active_options($pdo);
$assigneeIds = [];
foreach ($assignees as $assignee) {
    $assigneeIds[] = $assignee['user_id'];
}

$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $customerPartyId = (int) ($_POST['customer_party_id'] ?? 0);
    $partyRow = $customerPartyId > 0 ? party_service_get_active_party($pdo, $customerPartyId) : null;
    $customer = trim((string) ($_POST['customer'] ?? $ticket['customer']));
    $customerEmail = trim((string) ($_POST['customer_email'] ?? $ticket['customer_email']));
    $countryInput = trim((string) ($_POST['country'] ?? $ticket['country']));
    $countryCanonical = party_service_ticket_country_canonical($pdo, $countryInput);
    $issue = trim((string) ($_POST['issue'] ?? $ticket['issue']));
    $statusInput = trim($_POST['status'] ?? '');
    $priorityInput = trim($_POST['priority'] ?? '');
    $assignTo = normalize_assignee($_POST['assign_to'] ?? $ticket['assign_to']);
    $assignedVendorId = (int) ($_POST['assigned_vendor_id'] ?? ($ticket['assigned_vendor_id'] ?? 0));
    $assignedVendorId = $assignedVendorId > 0 ? $assignedVendorId : null;
    $description = ticket_description_normalize_for_storage((string) ($_POST['description'] ?? $ticket['description']));
    $externalTicketId = trim((string) ($_POST['external_ticket_id'] ?? ($ticket['external_ticket_id'] ?? '')));
    $externalTicketId = $externalTicketId !== '' ? $externalTicketId : null;

    $status = match (strtolower($statusInput)) {
        'open' => 'Open',
        'in-progress', 'inprogress' => 'In-Progress',
        'closed' => 'Closed',
        default => $statusInput
    };
    $priority = match (strtolower($priorityInput)) {
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
        default => $priorityInput
    };

    if ($customerPartyId <= 0 || !$partyRow) {
        $message = ['type' => 'error', 'text' => 'Select an active customer party from the search list — free text alone is not allowed.'];
    } elseif ($countryCanonical === null) {
        $message = ['type' => 'error', 'text' => 'Choose a valid country from the suggestions (existing ticket/party countries are also accepted).'];
    } elseif ($issue === '' || !ticket_description_has_meaningful_content($description)) {
        $message = ['type' => 'error', 'text' => 'Issue and description are required.'];
    } elseif (strlen($description) > TICKET_DESCRIPTION_MAX_BYTES) {
        $message = ['type' => 'error', 'text' => 'Description is too long. Shorten the text or reduce pasted content (storage limit).'];
    } elseif ($customerEmail !== '' && !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
        $message = ['type' => 'error', 'text' => 'Customer email must be a valid email address.'];
    } elseif (!in_array($status, ticket_statuses(), false) || !in_array($priority, ticket_priorities(), false)) {
        $message = ['type' => 'error', 'text' => 'Invalid status or priority selected.'];
    } else {
        if ($currentUser['role'] === 'Admin') {
            if ($assignTo !== null && !in_array($assignTo, $assigneeIds, true)) {
                $message = ['type' => 'error', 'text' => 'Assigned user must be active.'];
            }
        } elseif (!ticket_user_can_edit($currentUser, $ticket)) {
            $message = ['type' => 'error', 'text' => 'You cannot update tickets assigned to another user.'];
        } elseif ($assignTo !== null && $assignTo !== $currentUser['user_id']) {
            $message = ['type' => 'error', 'text' => 'Agents can only assign tickets to themselves.'];
        }

        if (!$message && $status === 'Closed' && !ticket_is_assigned($assignTo)) {
            $message = ['type' => 'error', 'text' => 'Ticket must be assigned before closing.'];
        }

        if (!$message && $assignedVendorId !== null) {
            $validVendorIds = array_map(static fn(array $party): int => (int) $party['id'], $partyOptions);
            if (!in_array($assignedVendorId, $validVendorIds, true)) {
                $message = ['type' => 'error', 'text' => 'Assigned vendor must be an active party.'];
            }
        }

        if (!$message && $assignedVendorId !== null && $assignedVendorId === $customerPartyId) {
            $message = ['type' => 'error', 'text' => party_service_ticket_customer_vendor_conflict_message()];
        }

        if (!$message) {
            $customer = trim((string) $partyRow['name']);
            $country = $countryCanonical;
            $closedAt = $status === 'Closed' ? ($ticket['closed_at'] ?: date('Y-m-d H:i:s')) : null;
            $beforeTicket = $ticket;

            $updateStmt = $pdo->prepare(
                'UPDATE tickets
                 SET customer = :customer,
                     customer_email = :customer_email,
                     country = :country,
                     issue = :issue,
                     status = :status,
                     priority = :priority,
                     assign_to = :assign_to,
                     assigned_vendor_id = :assigned_vendor_id,
                     initiator_party_id = :initiator_party_id,
                     description = :description,
                     external_ticket_id = :external_ticket_id,
                     closed_at = :closed_at,
                     updated_by = :updated_by
                 WHERE ticket_id = :ticket_id'
            );
            $updateStmt->execute([
                ':customer' => $customer,
                ':customer_email' => $customerEmail !== '' ? $customerEmail : null,
                ':country' => $country,
                ':issue' => $issue,
                ':status' => $status,
                ':priority' => $priority,
                ':assign_to' => $assignTo,
                ':assigned_vendor_id' => $assignedVendorId,
                ':initiator_party_id' => $customerPartyId,
                ':description' => $description,
                ':external_ticket_id' => $externalTicketId,
                ':closed_at' => $closedAt,
                ':updated_by' => ticket_service_current_user_id($currentUser),
                ':ticket_id' => $ticket['ticket_id'],
            ]);
            $afterTicket = ticket_service_get_ticket_with_user_context($pdo, (int) $ticket['ticket_id']);
            if ($afterTicket) {
                $sendCustomerEmailsOnUpdate = (string) ($_POST['send_auto_acknowledgement'] ?? '1') !== '0';
                ticket_service_handle_ticket_updated($pdo, $beforeTicket, $afterTicket, $sendCustomerEmailsOnUpdate);
            }

            set_flash('success', 'Ticket updated successfully.');
            redirect('tickets/view.php?id=' . (int) $ticket['ticket_id']);
        }
    }
}

$pageTitle = 'Update Ticket';
$pageHeading = 'Ticket Workspace';
$pageDescription = $currentUser['role'] === 'Admin'
    ? 'Assign, reassign, and update ticket status in one compact view.'
    : 'Assign available tickets to yourself and update status safely.';

$selectedAssignTo = $_POST['assign_to'] ?? ($ticket['assign_to'] ?? '');
$selectedVendorId = $_POST['assigned_vendor_id'] ?? ($ticket['assigned_vendor_id'] ?? '');
$selectedStatus = $_POST['status'] ?? $ticket['status'];
$selectedPriority = $_POST['priority'] ?? $ticket['priority'];
$ticketPartySearchUrl = url('tickets/party_search.php');
$ticketCountryFieldNs = 'update';
$ticketCountryShowRequired = false;
$selectedCustomerPartyId = (int) ($_POST['customer_party_id'] ?? ($ticket['initiator_party_id'] ?? 0));
$selectedCustomer = $_POST['customer'] ?? ($ticket['customer'] ?? '');
$selectedCustomerDisplay = $selectedCustomer;
$selectedCustomerEmail = $_POST['customer_email'] ?? ($ticket['customer_email'] ?? '');
$sendAutoAckEnabled = (string) ($_POST['send_auto_acknowledgement'] ?? '1') !== '0';
$ackTooltip = 'When On, customer receives acknowledgement and status-update emails on save. When Off, no customer auto-mails are sent from this update.';
$selectedCountry = $_POST['country'] ?? ($ticket['country'] ?? '');
$selectedIssue = $_POST['issue'] ?? ($ticket['issue'] ?? '');
$selectedDescription = $_POST['description'] ?? ($ticket['description'] ?? '');
$selectedExternalTicketId = $_POST['external_ticket_id'] ?? ($ticket['external_ticket_id'] ?? '');
$agentCanClaim = $currentUser['role'] === 'Agent' && !ticket_is_assigned($ticket['assign_to']);
$formAction = url('tickets/update.php?id=' . (int) $ticket['ticket_id']);
$showCancelButton = true;

include __DIR__ . '/../includes/header.php';
?>

<?php if ($message): ?>
    <div class="flash flash-<?php echo e($message['type']); ?>"><?php echo e($message['text']); ?></div>
<?php endif; ?>

<div class="panel-grid">
    <div class="form-card">
        <div class="info-strip">
            <div>
                <strong>Closing validation</strong>
                <p>Tickets cannot be closed until someone is assigned.</p>
            </div>
            <?php if (!ticket_is_assigned($ticket['assign_to'])): ?>
                <span class="badge badge-unassigned">Currently unassigned</span>
            <?php endif; ?>
        </div>

        <form method="POST" novalidate>
            <?php echo csrf_field(); ?>
            <input type="hidden" name="ticket_id" value="<?php echo e($ticket['ticket_id']); ?>">

            <div class="form-grid">
                <?php include __DIR__ . '/../views/tickets/ticket_ui_party_country_fields.php'; ?>

                <?php
                $customerEmailInputId = 'customer_email';
                $customerEmailGroupExtraClass = 'full';
                $customerEmailValue = $selectedCustomerEmail;
                include __DIR__ . '/../views/tickets/ticket_ui_customer_email_ack_fields.php';
?>

                <div class="input-group full">
                    <label for="issue">Subject</label>
                    <input type="text" id="issue" name="issue" value="<?php echo e($selectedIssue); ?>" required>
                </div>

                <div class="input-group">
                    <label for="external_ticket_id">External Ticket ID</label>
                    <input type="text" id="external_ticket_id" name="external_ticket_id" value="<?php echo e($selectedExternalTicketId); ?>" placeholder="Vendor / client reference">
                </div>

                <div class="input-group">
                    <label for="status">Status</label>
                    <div class="status-chips">
                        <?php 
                        $statuses = ['Open', 'In-Progress', 'Closed'];
                        foreach ($statuses as $s): 
                            $isSelected = $selectedStatus === $s;
                            $class = match($s) {
                                'Open' => 'status-open',
                                'In-Progress' => 'status-progress', 
                                'Closed' => 'status-closed',
                                default => ''
                            };
                        ?>
                            <label class="chip <?php echo $class; ?><?php echo $isSelected ? ' selected' : ''; ?>">
                                <input type="radio" name="status" value="<?php echo e($s); ?>" <?php echo $isSelected ? 'checked' : ''; ?> style="display:none;">
                                <span><?php echo e($s); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="input-group">
                    <label>Priority</label>
                    <div class="status-chips">
                        <?php 
                        $priorities = ['Low', 'Medium', 'High'];
                        foreach ($priorities as $p): 
                            $isSelected = $selectedPriority === $p;
                            $class = match($p) {
                                'Low' => 'status-open',
                                'Medium' => 'status-progress', 
                                'High' => 'status-closed',
                                default => ''
                            };
                        ?>
                            <label class="chip <?php echo $class; ?><?php echo $isSelected ? ' selected' : ''; ?>">
                                <input type="radio" name="priority" value="<?php echo e($p); ?>" <?php echo $isSelected ? 'checked' : ''; ?> style="display:none;">
                                <span><?php echo e($p); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                 <div class="input-group full" id="assignment">
                     <label for="assign_to">Assignment</label>
                     <?php if ($currentUser['role'] === 'Admin'): ?>
                         <select id="assign_to" name="assign_to">
                             <option value="">Unassigned</option>
                             <?php foreach ($assignees as $assignee): ?>
                                 <option value="<?php echo e($assignee['user_id']); ?>" <?php echo $selectedAssignTo === $assignee['user_id'] ? 'selected' : ''; ?>>
                                     <?php echo e($assignee['name'] . ' (' . $assignee['role'] . ')'); ?>
                                 </option>
                             <?php endforeach; ?>
                         </select>
                         <div class="field-help">Admins can assign, reassign, or clear the assignment anytime.</div>
                     <?php elseif ($agentCanClaim): ?>
                         <select id="assign_to" name="assign_to">
                             <option value="">Keep Unassigned</option>
                             <option value="<?php echo e($currentUser['user_id']); ?>" <?php echo $selectedAssignTo === $currentUser['user_id'] ? 'selected' : ''; ?>>
                                 Assign to me
                             </option>
                         </select>
                         <div class="field-help">As an agent, you can claim this available ticket for yourself.</div>
                     <?php else: ?>
                         <input type="hidden" name="assign_to" value="<?php echo e($ticket['assign_to']); ?>">
                         <input type="text" value="<?php echo e($ticket['assignee_name'] ?: $ticket['assign_to']); ?>" readonly>
                         <div class="field-help">This ticket is already assigned to you.</div>
                     <?php endif; ?>
                 </div>

                 <div class="input-group full">
                     <label for="assigned_vendor_id">Assigned Vendor</label>
                     <select id="assigned_vendor_id" name="assigned_vendor_id">
                         <option value="">No vendor assigned</option>
                         <?php foreach ($partyOptions as $party): ?>
                             <option value="<?php echo e((string) $party['id']); ?>" <?php echo (string) $selectedVendorId === (string) $party['id'] ? 'selected' : ''; ?>>
                                 <?php echo e($party['name']); ?>
                             </option>
                         <?php endforeach; ?>
                     </select>
                     <div class="field-help">Use registered parties for vendor assignment. This does not send email by itself.</div>
                 </div>

                 <div class="input-group full">
                     <label for="description">Description</label>
                     <textarea id="description" name="description" rows="6" required><?php echo e($selectedDescription); ?></textarea>
                     <div class="field-help">Detailed explanation of the issue. This will be included in customer-facing emails.</div>
                 </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save Ticket</button>
                <a href="<?php echo e(url('tickets/view.php?id=' . (int) $ticket['ticket_id'])); ?>" class="btn btn-secondary">Back to Details</a>
                <a href="<?php echo e(url('tickets/list.php')); ?>" class="btn btn-outline">Ticket List</a>
            </div>
        </form>
    </div>

    <div class="hero-card">
        <h2 class="section-title">Ticket Details</h2>
        <div class="meta-list">
            <div class="meta-item">
                <span>Ticket ID</span>
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
                <span>Country</span>
                <strong><?php echo e($ticket['country']); ?></strong>
            </div>
            <div class="meta-item">
                <span>Created</span>
                <strong><?php echo e(format_date($ticket['created_at'])); ?></strong>
            </div>
            <div class="meta-item">
                <span>Created By</span>
                <strong><?php echo e($ticket['creator_name'] ?: $ticket['created_by']); ?></strong>
            </div>
            <div class="meta-item">
                <span>Source</span>
                <strong><?php echo e($ticket['source'] ?: '-'); ?></strong>
            </div>
            <div class="meta-item">
                <span>Closed At</span>
                <strong><?php echo e(format_date($ticket['closed_at'])); ?></strong>
            </div>
        </div>

            <div class="meta-item" style="margin-top:12px;">
            <span>Issue</span>
            <strong><?php echo e($ticket['issue']); ?></strong>
            <div class="text-muted ticket-summary-description ticket-summary-body" style="margin-bottom:0;margin-top:8px;"><?php ticket_description_render_html($ticket['description'] ?? ''); ?></div>
        </div>

        <div class="meta-item" style="margin-top:12px;">
            <span>Email Metadata</span>
            <strong>Customer Email: <?php echo e($ticket['customer_email'] ?: '-'); ?></strong>
            <p class="text-muted" style="margin:8px 0 0;">Message ID: <?php echo e($ticket['mail_message_id'] ?: '-'); ?></p>
            <p class="text-muted" style="margin:8px 0 0;">Thread ID: <?php echo e($ticket['mail_thread_id'] ?: '-'); ?></p>
        </div>
    </div>
</div>

<?php $detailTicket = ticket_query_service_detail($pdo, (int) $ticket['ticket_id']); ?>

<div class="panel-grid" style="margin-top:16px;">
    <div class="table-card">
        <div class="table-header">
            <div>
                <h2 class="section-title">Email Thread</h2>
                <p class="section-subtitle">Incoming email history linked to this ticket.</p>
            </div>
        </div>
        <div style="padding:0 18px 18px;">
            <?php $thread = $detailTicket['thread'] ?? []; include __DIR__ . '/../views/tickets/email_thread.php'; ?>
        </div>
    </div>

    <div class="table-card">
        <div class="table-header">
            <div>
                <h2 class="section-title">Logs History</h2>
                <p class="section-subtitle">Audit trail for ticket changes, mapping, emails, and notifications.</p>
            </div>
        </div>
        <div style="padding:0 18px 18px;">
            <?php $logs = $detailTicket['logs'] ?? []; include __DIR__ . '/../views/tickets/log_history.php'; ?>
        </div>
    </div>
</div>

<script src="<?php echo e(url('assets/js/tickets_ui_party_country.js')); ?>" defer></script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
