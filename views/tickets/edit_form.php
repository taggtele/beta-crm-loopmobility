<?php
/**
 * Shared ticket edit form — used by update.php and view.php inline panel.
 *
 * Expected variables: $currentUser, $assignees, $partyOptions, $formAction,
 * $selectedCustomer, $selectedCustomerEmail, $selectedCountry, $selectedIssue,
 * $selectedDescription, $selectedStatus, $selectedPriority, $selectedAssignTo,
 * $selectedVendorId, $selectedExternalTicketId, $ticket, $agentCanClaim, $ticketId,
 * plus for ticket form UI: $ticketCountryDropdownOptions, $ticketPartySearchUrl,
 * $ticketCountryFieldNs, $selectedCustomerPartyId, $selectedCustomerDisplay, $ticketCountryShowRequired.
 * Partial: views/tickets/ticket_ui_party_country_fields.php
 */
$editTicketId = (int) ($ticketId ?? ($ticket['ticket_id'] ?? 0));
$canAssignOthers = ticket_user_can_assign_others($currentUser);
?>
<form method="POST" action="<?php echo e($formAction); ?>" class="ticket-edit-form" novalidate>
    <?php echo csrf_field(); ?>
    <input type="hidden" name="ticket_id" value="<?php echo e((string) $editTicketId); ?>">

    <div class="form-grid ticket-edit-grid">
        <?php include __DIR__ . '/ticket_ui_party_country_fields.php'; ?>

        <div class="input-group full">
            <label for="edit-issue">Subject</label>
            <input type="text" id="edit-issue" name="issue" value="<?php echo e($selectedIssue); ?>" required>
        </div>

        <?php
        if (!isset($sendAutoAckEnabled)) {
            $sendAutoAckEnabled = true;
        }
        if (!isset($ackTooltip)) {
            $ackTooltip = 'When On, customer receives acknowledgement and status-update emails on save. When Off, no customer auto-mails are sent from this update.';
        }
        $customerEmailInputId = 'edit-customer_email';
        $customerEmailGroupExtraClass = 'full';
        $customerEmailValue = $selectedCustomerEmail;
        include __DIR__ . '/ticket_ui_customer_email_ack_fields.php';
?>

        <div class="input-group">
            <label for="edit-external_ticket_id">External Ticket ID</label>
            <input type="text" id="edit-external_ticket_id" name="external_ticket_id" value="<?php echo e($selectedExternalTicketId); ?>" placeholder="Vendor / client reference">
        </div>

        <div class="input-group">
            <label>Status</label>
            <div class="status-chips" data-status-chips>
                <?php foreach (ticket_statuses() as $statusOption): ?>
                    <?php
                    $isSelected = $selectedStatus === $statusOption;
                    $statusClass = match ($statusOption) {
                        'Open' => 'status-open',
                        'In-Progress' => 'status-progress',
                        'Closed' => 'status-closed',
                        default => '',
                    };
                    ?>
                    <label class="chip <?php echo e($statusClass); ?><?php echo $isSelected ? ' selected' : ''; ?>">
                        <input type="radio" name="status" value="<?php echo e($statusOption); ?>" <?php echo $isSelected ? 'checked' : ''; ?> hidden>
                        <span><?php echo e($statusOption); ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="input-group">
            <label>Priority</label>
            <div class="status-chips" data-priority-chips>
                <?php foreach (ticket_priorities() as $priorityOption): ?>
                    <?php
                    $isSelected = $selectedPriority === $priorityOption;
                    $priorityClass = match ($priorityOption) {
                        'Low' => 'status-open',
                        'Medium' => 'status-progress',
                        'High' => 'status-closed',
                        default => '',
                    };
                    ?>
                    <label class="chip <?php echo e($priorityClass); ?><?php echo $isSelected ? ' selected' : ''; ?>">
                        <input type="radio" name="priority" value="<?php echo e($priorityOption); ?>" <?php echo $isSelected ? 'checked' : ''; ?> hidden>
                        <span><?php echo e($priorityOption); ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="input-group full" id="assignment">
            <label for="edit-assign_to">Assign</label>
            <?php if ($canAssignOthers): ?>
                <select id="edit-assign_to" name="assign_to" class="ticket-assign-select">
                    <option value="">Unassigned</option>
                    <?php foreach ($assignees as $assignee): ?>
                        <option value="<?php echo e($assignee['user_id']); ?>" <?php echo $selectedAssignTo === $assignee['user_id'] ? 'selected' : ''; ?>>
                            <?php echo e($assignee['name'] . ' (' . $assignee['role'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php elseif ($agentCanClaim): ?>
                <select id="edit-assign_to" name="assign_to">
                    <option value="">Keep Unassigned</option>
                    <option value="<?php echo e($currentUser['user_id']); ?>" <?php echo $selectedAssignTo === $currentUser['user_id'] ? 'selected' : ''; ?>>
                        Assign to me
                    </option>
                </select>
            <?php else: ?>
                <input type="hidden" name="assign_to" value="<?php echo e($ticket['assign_to'] ?? ''); ?>">
                <input type="text" value="<?php echo e($ticket['assignee_name'] ?? ($ticket['assign_to'] ?? 'Unassigned')); ?>" readonly>
            <?php endif; ?>
        </div>

        <div class="input-group full">
            <label for="edit-assigned_vendor_id">Vendor</label>
            <select id="edit-assigned_vendor_id" name="assigned_vendor_id">
                <option value="">No vendor assigned</option>
                <?php foreach ($partyOptions as $party): ?>
                    <option value="<?php echo e((string) $party['id']); ?>" <?php echo (string) $selectedVendorId === (string) $party['id'] ? 'selected' : ''; ?>>
                        <?php echo e($party['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="input-group full">
            <label for="edit-description">Description</label>
            <textarea id="edit-description" name="description" rows="5" required><?php echo e($selectedDescription); ?></textarea>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save Changes</button>
        <?php if (!empty($showCancelButton)): ?>
            <button type="button" class="btn btn-outline" data-edit-cancel>Cancel</button>
        <?php endif; ?>
    </div>
</form>
