<?php if (!empty($thread)): ?>
    <?php foreach ($thread as $entry): ?>
        <div class="meta-item" style="margin-bottom:10px;">
            <?php if (isset($entry['direction'])): ?>
                <span><?php echo e($entry['label'] ?? ucwords((string) $entry['direction'])); ?></span>
                <strong><?php echo e(format_date($entry['occurred_at'] ?? '')); ?></strong>
                <p class="mail-meta-line" style="margin:8px 0 0;">
                    <?php if (($entry['direction'] ?? '') === 'outgoing'): ?>
                        From <?php echo e($entry['from_email'] ?: '-'); ?> | To <?php echo e($entry['to_email'] ?: '-'); ?>
                        <?php if (!empty($entry['cc_email'])): ?> | CC <?php echo e($entry['cc_email']); ?><?php endif; ?>
                    <?php else: ?>
                        From <?php echo e($entry['from_email'] ?: '-'); ?>
                    <?php endif; ?>
                    | Status <?php echo e($entry['mail_status'] ?: '-'); ?>
                </p>
                <strong style="display:block;margin-top:8px;"><?php echo e($entry['subject'] ?: 'No subject'); ?></strong>
                <div class="text-muted" style="margin:10px 0 0; white-space:pre-wrap;"><?php echo format_email_body((string) ($entry['body'] ?? '')); ?></div>
            <?php else: ?>
                <span><?php echo e(ucwords(str_replace('_', ' ', $entry['action']))); ?></span>
                <strong><?php echo e(format_date($entry['created_at'])); ?></strong>
                <div class="text-muted" style="margin:10px 0 0; white-space:pre-wrap;"><?php echo format_email_body($entry['message']); ?></div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php else: ?>
    <div class="empty-state">No email thread available for this ticket.</div>
<?php endif; ?>
