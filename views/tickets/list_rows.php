<?php
$statusOptions = ticket_statuses();
$listAssignees = $listAssignees ?? active_users($pdo);
$currentUserId = trim((string) ($currentUser['user_id'] ?? ''));

$ticketStatusUi = static function (string $status): array {
    if ($status === 'Open') {
        return ['chip' => 'status-open', 'dot' => 'dot-open'];
    }
    if ($status === 'In-Progress') {
        return ['chip' => 'status-progress', 'dot' => 'dot-progress'];
    }
    if ($status === 'Closed') {
        return ['chip' => 'status-closed', 'dot' => 'dot-closed'];
    }
    return ['chip' => '', 'dot' => ''];
};

$ticketStatusSelectClass = static function (string $status): string {
    if ($status === 'Open') {
        return 'is-status-open';
    }
    if ($status === 'In-Progress') {
        return 'is-status-progress';
    }
    if ($status === 'Closed') {
        return 'is-status-closed';
    }
    return '';
};

$ticketPriorityUi = static function (string $priority): string {
    if ($priority === 'High') {
        return 'priority-high';
    }
    if ($priority === 'Medium') {
        return 'priority-medium';
    }
    return 'priority-low';
};

$ticketUpdatedMeta = static function (array $ticket): array {
    $updatedAt = trim((string) ($ticket['updated_at'] ?? ''));
    if ($updatedAt === '') {
        $updatedAt = trim((string) ($ticket['created_at'] ?? ''));
    }
    $updaterName = trim((string) ($ticket['updater_name'] ?? ''));
    if ($updaterName === '') {
        $updaterName = trim((string) ($ticket['creator_name'] ?? ''));
    }
    if ($updaterName === '') {
        $updaterName = trim((string) ($ticket['updated_by'] ?? ''));
    }
    return [
        'at' => $updatedAt,
        'label' => $updatedAt !== '' ? format_date($updatedAt, 'd M, H:i') : '-',
        'by' => $updaterName !== '' ? $updaterName : 'System',
    ];
};

$ticketDescriptionSnippet = static function (array $ticket): string {
    $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($ticket['description'] ?? ''))));
    if ($text === '') {
        return '';
    }
    if (function_exists('mb_strlen') && mb_strlen($text) > 90) {
        return mb_substr($text, 0, 87) . '...';
    }
    if (strlen($text) > 90) {
        return substr($text, 0, 87) . '...';
    }
    return $text;
};
?>
<?php if (!empty($tickets)): ?>
    <?php foreach ($tickets as $ticket): ?>
        <?php
        $ticketId = (int) $ticket['ticket_id'];
        $serial = format_ticket_serial($pdo, $ticket);
        $viewUrl = url('tickets/view.php?id=' . $ticketId);
        $isAssigned = ticket_is_assigned($ticket['assign_to'] ?? null);
        $canChangeStatus = ticket_user_can_change_status($currentUser, $ticket);
        $canAssignOthers = ticket_user_can_assign_others($currentUser);
        $canSelfAssign = ticket_user_can_self_assign($currentUser, $ticket);
        $canSoftDelete = ticket_user_can_soft_delete($currentUser);
        $isDeleted = (int) ($ticket['is_deleted'] ?? 0) === 1;
        $hasActions = $canChangeStatus || $canAssignOthers || $canSelfAssign || $canSoftDelete;
        $canRestore = $canSoftDelete && $isDeleted;
        $currentAssignTo = (string) ($ticket['assign_to'] ?? '');
        $status = (string) ($ticket['status'] ?? '');
        $statusUi = $ticketStatusUi($status);
        $priorityUi = $ticketPriorityUi((string) ($ticket['priority'] ?? 'Low'));
        $assigneeLabel = $ticket['assignee_name'] ?: ($isAssigned ? (string) $ticket['assign_to'] : 'Unassigned');
        $vendorName = trim((string) ($ticket['assigned_vendor_name'] ?? ''));
        $updatedMeta = $ticketUpdatedMeta($ticket);
        $descriptionSnippet = $ticketDescriptionSnippet($ticket);
        $createdAt = trim((string) ($ticket['created_at'] ?? ''));
        $closedAt = trim((string) ($ticket['closed_at'] ?? ''));
        $closedDisplayAt = $closedAt;
        if ($closedDisplayAt === '' && $status === 'Closed') {
            $closedDisplayAt = trim((string) ($ticket['updated_at'] ?? ''));
        }
        $closedRowMuted = ($status !== 'Closed' || $closedDisplayAt === '');
        $customerName = trim((string) ($ticket['customer'] ?? ''));
        ?>
        <tr
            class="ticket-row-clickable<?php echo $isDeleted ? ' is-archived' : ''; ?>"
            data-href="<?php echo e($viewUrl); ?>"
            tabindex="0"
            aria-label="Open ticket <?php echo e($serial); ?>"
        >
            <td class="ticket-col-checkbox">
                <input type="checkbox" class="ticket-select-row" data-ticket-id="<?php echo e((string) $ticketId); ?>">
            </td>
            <td class="ticket-col-ticket">
                <div class="ticket-stack ticket-stack-compact">
                    <a href="<?php echo e($viewUrl); ?>" class="ticket-row-link ticket-row-link-lg"><?php echo e($serial); ?></a>
                    <div class="ticket-stack-meta ticket-stack-meta-tight">
                        <span class="ticket-pill <?php echo e($priorityUi); ?>"><?php echo e($ticket['priority']); ?></span>
                        <span class="ticket-status-chip ticket-status-chip-sm <?php echo e($statusUi['chip']); ?>" data-ticket-status-badge>
                            <span class="ticket-status-dot <?php echo e($statusUi['dot']); ?>" aria-hidden="true"></span>
                            <?php echo e($status); ?>
                        </span>
                        <?php if (!empty($ticket['external_ticket_id'])): ?>
                            <span class="ticket-meta-tag"><?php echo e($ticket['external_ticket_id']); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </td>
            <td class="ticket-col-details">
                <div class="ticket-details-cell">
                    <div class="ticket-details-subject ticket-cell-clip-1" title="<?php echo e($ticket['issue']); ?>"><?php echo e($ticket['issue']); ?></div>
                    <?php if ($descriptionSnippet !== ''): ?>
                        <p class="ticket-details-desc ticket-cell-clip-1" title="<?php echo e($descriptionSnippet); ?>"><?php echo e($descriptionSnippet); ?></p>
                    <?php endif; ?>
                    <div class="ticket-details-meta">
                        <span class="ticket-details-name"><?php echo e($customerName !== '' ? $customerName : 'No customer'); ?></span>
                        <?php if (!empty($ticket['customer_email'])): ?>
                            <span class="ticket-details-email" title="<?php echo e($ticket['customer_email']); ?>">
                                <svg class="ticket-details-icon" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 6h16v12H4z"/><path d="m4 7 8 6 8-6"/></svg>
                                <span class="ticket-details-email-text"><?php echo e($ticket['customer_email']); ?></span>
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($ticket['country'])): ?>
                            <span class="ticket-details-country" title="<?php echo e($ticket['country']); ?>">
                                <svg class="ticket-details-icon" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/></svg>
                                <?php echo e($ticket['country']); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </td>
            <td class="ticket-col-team ticket-cell-hide-md">
                <div class="ticket-kv-list ticket-kv-compact">
                    <div class="ticket-kv">
                        <span class="ticket-kv-label">Assigned</span>
                        <span class="ticket-kv-value<?php echo !$isAssigned ? ' is-muted' : ''; ?>" data-ticket-assignee-cell><?php echo e($assigneeLabel); ?></span>
                    </div>
                    <div class="ticket-kv">
                        <span class="ticket-kv-label">Created by</span>
                        <span class="ticket-kv-value"><?php echo e($ticket['creator_name'] ?: ($ticket['created_by'] ?: '-')); ?></span>
                    </div>
                    <?php if ($vendorName !== ''): ?>
                        <div class="ticket-kv">
                            <span class="ticket-kv-label">Vendor</span>
                            <span class="ticket-kv-value"><?php echo e($vendorName); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </td>
            <td class="ticket-col-dates">
                <div class="ticket-kv-list ticket-kv-compact">
                    <div class="ticket-kv" data-relative-at="<?php echo e($createdAt); ?>">
                        <span class="ticket-kv-label">Created</span>
                        <span class="ticket-kv-value">
                            <?php echo e(format_date($createdAt ?: null, 'd M, H:i')); ?>
                            <span class="ticket-kv-meta ticket-time-relative" data-relative-display></span>
                        </span>
                    </div>
                    <div class="ticket-kv" data-ticket-updated-cell>
                        <span class="ticket-kv-label">Updated</span>
                        <span class="ticket-kv-value">
                            <span data-ticket-updated-at><?php echo e($updatedMeta['label']); ?></span>
                            <span class="ticket-kv-meta">by <span data-ticket-updated-by><?php echo e($updatedMeta['by']); ?></span></span>
                        </span>
                    </div>
                    <div class="ticket-kv">
                        <span class="ticket-kv-label">Closed</span>
                        <span class="ticket-kv-value<?php echo $closedRowMuted ? ' is-muted' : ''; ?>" data-ticket-closed-cell><?php echo !$closedRowMuted ? e(format_date($closedDisplayAt, 'd M, H:i')) : '—'; ?></span>
                    </div>
                </div>
            </td>
            <td class="ticket-col-actions actions-cell" data-ticket-actions>
                <?php if ($hasActions): ?>
                    <div class="ticket-action-panel ticket-action-panel-compact ticket-inline-actions">
                        <?php if ($canChangeStatus): ?>
                            <div class="ticket-action-row ticket-action-row-inline">
                                <span class="ticket-action-label">Status</span>
                                <select
                                    class="ticket-inline-select ticket-status-badge-select <?php echo e($ticketStatusSelectClass($status)); ?>"
                                    data-inline-status
                                    data-ticket-id="<?php echo e((string) $ticketId); ?>"
                                    data-prev-status="<?php echo e($status); ?>"
                                    aria-label="Change status"
                                >
                                    <?php foreach ($statusOptions as $statusOption): ?>
                                        <option value="<?php echo e($statusOption); ?>" <?php echo $status === $statusOption ? 'selected' : ''; ?>>
                                            <?php echo e($statusOption); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                        <?php if ($canAssignOthers): ?>
                            <div class="ticket-action-row ticket-action-row-inline">
                                <span class="ticket-action-label">Assign</span>
                                <select
                                    class="ticket-inline-select ticket-assign-select"
                                    data-inline-assign
                                    data-ticket-id="<?php echo e((string) $ticketId); ?>"
                                    data-prev-assign="<?php echo e($currentAssignTo); ?>"
                                    aria-label="Assign ticket"
                                >
                                    <option value="" <?php echo $currentAssignTo === '' ? 'selected' : ''; ?>>Unassigned</option>
                                    <?php foreach ($listAssignees as $assignee): ?>
                                        <option
                                            value="<?php echo e($assignee['user_id']); ?>"
                                            <?php echo $currentAssignTo === $assignee['user_id'] ? 'selected' : ''; ?>
                                        ><?php echo e($assignee['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php elseif ($canSelfAssign): ?>
                            <button
                                type="button"
                                class="btn btn-sm ticket-inline-btn"
                                data-quick-assign-self
                                data-ticket-id="<?php echo e((string) $ticketId); ?>"
                            >Assign to me</button>
                        <?php endif; ?>
                        <?php if ($canSoftDelete): ?>
                            <button
                                type="button"
                                class="ticket-delete-btn ticket-inline-btn"
                                data-ticket-id="<?php echo e((string) $ticketId); ?>"
                                data-action="<?php echo $isDeleted ? 'restore' : 'archive'; ?>"
                                title="<?php echo $isDeleted ? 'Restore ticket' : 'Archive ticket'; ?>"
                                aria-label="<?php echo $isDeleted ? 'Restore this ticket' : 'Archive this ticket'; ?>"
                            >
                                <?php if ($isDeleted): ?>
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M22 12H2"/><path d="M18 8l4 4l-4 4"/></svg>
                                <?php else: ?>
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                <?php endif; ?>
                            </button>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <span class="ticket-actions-muted">—</span>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
<?php else: ?>
    <tr>
        <td colspan="5" class="ticket-empty-state-table">
            <strong>No tickets found</strong>
            <span>Try different filters or</span>
            <button type="button" class="btn btn-primary btn-sm ticket-create-modal-open-trigger">Create ticket</button>
            <span>from here.</span>
        </td>
    </tr>
<?php endif; ?>
