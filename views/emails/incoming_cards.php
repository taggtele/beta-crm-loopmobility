<?php
if (!function_exists('rbac_can_manage_email_logs')) {
    require_once __DIR__ . '/../../includes/rbac.php';
}
$canManageEmailFromUi = rbac_can_manage_email_logs($currentUser ?? null);
?>
<?php if (!empty($incomingEmails)): ?>
    <div class="mail-card-list">
        <?php foreach ($incomingEmails as $email): ?>
            <?php
            $isIgnored = ($email['mail_status'] ?? '') === 'ignored';
            $isUnmapped = ($email['mail_status'] ?? '') === 'unmapped';
            $isUnknown = ($email['mail_status'] ?? '') === 'unknown';
            $statusLabel = $isIgnored ? 'Ignored' : ($isUnmapped ? 'Unmapped' : ($isUnknown ? 'Unknown' : 'Incoming'));
            $statusClass = $isIgnored || $isUnmapped || $isUnknown ? 'badge-medium' : 'badge-open';
            $displayTime = format_date($email['received_at'] ?: $email['created_at']);
            ?>
            <article class="mail-card mail-card-incoming">
                <div class="mail-card-header">
                    <div class="mail-card-main">
                        <h3><?php echo e($email['subject'] ?: 'Incoming Email'); ?></h3>
                        <div class="mail-visible-meta">
                            <span class="badge <?php echo e($statusClass); ?>"><?php echo e($statusLabel); ?></span>
                            <span class="mail-time"><?php echo e($displayTime); ?></span>
                        </div>
                        <p class="mail-meta-line">
                            From <?php echo e($email['from_email'] ?: '-'); ?>
                            |                             Ticket 
                            <?php if (!empty($email['ticket_id'])): ?>
                                <a href="<?php echo e(url('tickets/view.php?id=' . (int) $email['ticket_id'])); ?>">
                                    <?php 
                                    $ticketSerial = (!empty($email['ticket_created_at']))
                                        ? format_ticket_serial($pdo, ['ticket_id' => $email['ticket_id'], 'created_at' => $email['ticket_created_at']])
                                        : ($email['ticket_id'] ?? '-');
                                    echo e($ticketSerial); 
                                    ?>
                                </a>
                            <?php else: ?>
                                <?php 
                                $ticketSerial = (!empty($email['ticket_created_at']))
                                    ? format_ticket_serial($pdo, ['ticket_id' => $email['ticket_id'], 'created_at' => $email['ticket_created_at']])
                                    : ($email['ticket_id'] ?? '-');
                                echo e($ticketSerial); 
                                ?>
                            <?php endif; ?>
                            | External <?php echo e($email['external_ticket_id'] ?: '-'); ?>
                        </p>
                    </div>
                    <div class="mail-card-status">
                        <span class="badge <?php echo e($statusClass); ?>"><?php echo e($statusLabel); ?></span>
                        <small class="mail-time"><?php echo e($displayTime); ?></small>
                        <?php if ($canManageEmailFromUi): ?>
                        <button type="button" class="btn btn-outline btn-sm reply-email-btn"
                                data-to="<?php echo e($email['from_email']); ?>"
                                data-subject="<?php echo e('Re: ' . ($email['subject'] ?? 'Your inquiry')); ?>"
                                data-ticket-id="<?php echo e((int) ($email['ticket_id'] ?? 0)); ?>"
                                style="margin-left:8px;">
                            Reply
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="mail-meta-grid">
                    <div><strong>Customer:</strong> <?php echo e($email['customer'] ?: '-'); ?></div>
                    <div><strong>Ticket Status:</strong> <?php echo e($email['ticket_status'] ?: '-'); ?></div>
                    <div><strong>Source:</strong> <?php echo e(ucfirst((string) ($email['source'] ?: 'email'))); ?></div>
                    <div><strong>Assigned To:</strong> <?php echo e($email['assignee_name'] ?: 'Unassigned'); ?></div>
                </div>

                <div class="mail-body"><?php echo $email['body'] !== '' ? format_email_body($email['body']) : 'No incoming email body available.'; ?></div>

                <?php if (($isIgnored || $isUnmapped || $isUnknown) && !empty($email['ignored_reason'])): ?>
                    <div class="mail-error"><?php echo e($email['ignored_reason']); ?></div>
                <?php endif; ?>

                <?php if ($isUnmapped && empty($email['ticket_id']) && $canManageEmailFromUi): ?>
                    <form method="POST" action="<?php echo e(url('emails/logs.php?direction=incoming&status=unmapped')); ?>" class="inline-form" style="margin-top:12px;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="map_unmapped_email">
                        <input type="hidden" name="inbox_log_id" value="<?php echo e((int) ($email['log_id'] ?? 0)); ?>">
                        <div class="input-group">
                            <label for="map-ticket-<?php echo e((int) ($email['log_id'] ?? 0)); ?>">Manual Map Ticket ID</label>
                            <input type="number" id="map-ticket-<?php echo e((int) ($email['log_id'] ?? 0)); ?>" name="map_ticket_id" min="1" placeholder="Internal ticket number" required>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary btn-sm">Map Email</button>
                        </div>
                    </form>
                <?php endif; ?>

                <div class="table-actions">
                    <?php if (!empty($email['ticket_id'])): ?>
                        <a href="<?php echo e(url('tickets/view.php?id=' . (int) $email['ticket_id'])); ?>" class="btn btn-outline btn-sm">Open Ticket</a>
                    <?php endif; ?>
                    <a href="<?php echo e(url('emails/logs.php?ticket_id=' . (int) ($email['ticket_id'] ?? 0) . '&direction=incoming')); ?>" class="btn btn-secondary btn-sm">More Mail</a>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="empty-state">No incoming emails found for this filter.</div>
<?php endif; ?>
