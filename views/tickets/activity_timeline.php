<?php
/** @var array $logs */
$logs = $logs ?? [];
?>
<?php if (!empty($logs)): ?>
    <ol class="ticket-activity-timeline">
        <?php foreach ($logs as $entry): ?>
            <?php
            $action = (string) ($entry['action'] ?? 'updated');
            $iconClass = match ($action) {
                'created' => 'timeline-icon-created',
                'updated' => 'timeline-icon-updated',
                'closed' => 'timeline-icon-closed',
                'email_received', 'incoming_reply' => 'timeline-icon-incoming',
                'email_sent', 'email_reply' => 'timeline-icon-outgoing',
                'email_failed' => 'timeline-icon-failed',
                default => 'timeline-icon-default',
            };
            $title = ucwords(str_replace('_', ' ', $action));
            ?>
            <li class="ticket-activity-item">
                <span class="ticket-activity-icon <?php echo e($iconClass); ?>" aria-hidden="true"></span>
                <div class="ticket-activity-body">
                    <div class="ticket-activity-head">
                        <strong><?php echo e($title); ?></strong>
                        <time datetime="<?php echo e($entry['created_at'] ?? ''); ?>"><?php echo e(format_date($entry['created_at'] ?? null)); ?></time>
                    </div>
                    <?php if (!empty($entry['sender_email'])): ?>
                        <p class="ticket-activity-meta">From: <?php echo e($entry['sender_email']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($entry['subject'])): ?>
                        <p class="ticket-activity-meta">Subject: <?php echo e($entry['subject']); ?></p>
                    <?php endif; ?>
                    <p class="ticket-activity-message"><?php echo e($entry['message'] ?? ''); ?></p>
                </div>
            </li>
        <?php endforeach; ?>
    </ol>
<?php else: ?>
    <div class="ticket-empty-state">
        <p>No activity recorded for this ticket yet.</p>
    </div>
<?php endif; ?>
