<?php
$recentTickets = $recentTickets ?? [];
$currentUser = $currentUser ?? [];

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
<div class="table-card ticket-list-card ticket-list-compact dashboard-recent-card" id="dashboard-recent-root">
    <div class="table-header">
        <div>
            <h2 class="section-title">Latest tickets</h2>
            <p class="section-subtitle dashboard-recent-subtitle">
                <?php if (($currentUser['role'] ?? '') === 'Admin'): ?>
                    Newest 10 by ticket ID (org-wide). Row click opens the ticket; charts above still follow the selected period.
                <?php else: ?>
                    Newest 10 you can work on (same scope as ticket list). Row click opens the ticket.
                <?php endif; ?>
            </p>
        </div>
        <a href="<?php echo e(url('tickets/list.php')); ?>" class="btn btn-outline btn-sm">Open Ticket List</a>
    </div>

    <div class="table-wrap ticket-table-wrap">
        <table class="ticket-data-table ticket-table-dense">
            <thead>
                <tr>
                    <th>Ticket</th>
                    <th>Details</th>
                    <th class="ticket-col-team ticket-cell-hide-md">Team</th>
                    <th>Timeline</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($recentTickets)): ?>
                    <?php foreach ($recentTickets as $ticket): ?>
                        <?php
                        $ticketId = (int) $ticket['ticket_id'];
                        $serial = format_ticket_serial($pdo, $ticket);
                        $viewUrl = url('tickets/view.php?id=' . $ticketId);
                        $isAssigned = ticket_is_assigned($ticket['assign_to'] ?? null);
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
                            class="ticket-row-clickable"
                            data-href="<?php echo e($viewUrl); ?>"
                            tabindex="0"
                            aria-label="Open ticket <?php echo e($serial); ?>"
                        >
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
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" class="empty-state">No tickets in your visibility yet.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
