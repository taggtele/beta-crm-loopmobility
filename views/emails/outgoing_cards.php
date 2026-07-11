<?php if (!empty($outgoingEmails)): ?>
    <div class="mail-card-list">
        <?php foreach ($outgoingEmails as $email): ?>
            <?php
            $statusClass = 'badge-medium';
            if (($email['mail_status'] ?? '') === 'sent') {
                $statusClass = 'badge-closed';
            } elseif (($email['mail_status'] ?? '') === 'failed') {
                $statusClass = 'badge-high';
            }
            $statusLabel = strtoupper((string) ($email['mail_status'] ?: 'pending'));
            $displayTime = format_date($email['sent_at'] ?: $email['created_at']);
            ?>
            <article class="mail-card mail-card-outgoing">
                <div class="mail-card-header">
                    <div class="mail-card-main">
                        <h3><?php echo e($email['subject'] ?: 'Outgoing Email'); ?></h3>
                        <div class="mail-visible-meta">
                            <span class="badge <?php echo e($statusClass); ?>"><?php echo e($statusLabel); ?></span>
                            <span class="mail-time"><?php echo e($displayTime); ?></span>
                        </div>
                        <p class="mail-meta-line">
                            From <?php echo e($email['from_email'] ?: '-'); ?>
                            |
                            To <?php echo e($email['to_email'] ?: '-'); ?>
                            <?php if (!empty($email['cc_email'])): ?>
                                | CC <?php echo e($email['cc_email']); ?>
                            <?php endif; ?>
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
                    </div>
                </div>

                <div class="mail-meta-grid">
                    <div><strong>Customer:</strong> <?php echo e($email['customer'] ?: '-'); ?></div>
                    <div><strong>Ticket Status:</strong> <?php echo e($email['ticket_status'] ?: '-'); ?></div>
                    <div><strong>Source:</strong> <?php echo e(ucfirst((string) ($email['source'] ?: 'manual'))); ?></div>
                    <div><strong>Created By:</strong> <?php echo e($email['creator_name'] ?: '-'); ?></div>
                </div>

                <div class="mail-body"><?php echo $email['body'] !== '' ? format_email_body($email['body']) : 'No outgoing email body available.'; ?></div>

                <?php if (!empty($email['error_message'])): ?>
                    <div class="mail-error"><?php echo e($email['error_message']); ?></div>
                <?php endif; ?>

                <div class="table-actions">
                    <?php if (!empty($email['ticket_id'])): ?>
                        <a href="<?php echo e(url('tickets/view.php?id=' . (int) $email['ticket_id'])); ?>" class="btn btn-outline btn-sm">Open Ticket</a>
                    <?php endif; ?>
                    <a href="<?php echo e(url('emails/logs.php?ticket_id=' . (int) ($email['ticket_id'] ?? 0) . '&direction=outgoing')); ?>" class="btn btn-secondary btn-sm">More Mail</a>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="empty-state">No outgoing emails found for this filter.</div>
<?php endif; ?>
