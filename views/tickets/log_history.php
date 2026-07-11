<?php if (!empty($logs)): ?>
    <?php foreach ($logs as $entry): ?>
        <div class="meta-item" style="margin-bottom:10px;">
            <span><?php echo e(ucwords(str_replace('_', ' ', $entry['action']))); ?></span>
            <strong><?php echo e(format_date($entry['created_at'])); ?></strong>
            <p class="text-muted" style="margin:10px 0 0; white-space:pre-wrap;"><?php echo e($entry['message']); ?></p>
        </div>
    <?php endforeach; ?>
<?php else: ?>
    <div class="empty-state">No log history available for this ticket.</div>
<?php endif; ?>
