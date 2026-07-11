<?php if (!empty($notifications)): ?>
    <?php foreach ($notifications as $notification): ?>
        <?php
        $display = notification_ui_service_display($notification);
        $isUnread = (int) ($notification['is_read'] ?? 0) === 0;
        $href = notification_ui_service_link($notification);
        ?>
        <a
            href="<?php echo e($href); ?>"
            data-notification-link
            data-notification-id="<?php echo e((string) $notification['id']); ?>"
            class="notification-item notification-item--<?php echo e($display['icon']); ?><?php echo $isUnread ? ' notification-unread' : ' notification-read'; ?>"
            title="<?php echo e($display['time_full'] !== '' ? $display['time_full'] : $display['subject']); ?>"
        >
            <span class="notification-item__row">
                <span class="notification-item__icon" aria-hidden="true"></span>
                <span class="notification-item__body">
                    <span class="notification-item__head">
                        <span class="notification-item__sender-wrap">
                            <?php if ($isUnread): ?>
                                <span class="notification-item__dot" aria-hidden="true"></span>
                            <?php endif; ?>
                            <strong class="notification-item__sender"><?php echo e($display['sender_name']); ?></strong>
                        </span>
                        <?php if ($display['time_label'] !== ''): ?>
                            <time class="notification-item__time" datetime="<?php echo e((string) ($notification['created_at'] ?? '')); ?>"><?php echo e($display['time_label']); ?></time>
                        <?php endif; ?>
                    </span>
                    <?php if ($display['sender_email'] !== ''): ?>
                        <span class="notification-item__email"><?php echo e($display['sender_email']); ?></span>
                    <?php endif; ?>
                    <?php if ($display['subject'] !== ''): ?>
                        <span class="notification-item__subject"><?php echo e($display['subject']); ?></span>
                    <?php endif; ?>
                    <?php if ($display['snippet'] !== ''): ?>
                        <span class="notification-item__snippet"><?php echo e($display['snippet']); ?></span>
                    <?php endif; ?>
                    <span class="notification-item__meta">
                        <span class="notification-item__type"><?php echo e($display['type_label']); ?></span>
                        <?php if (!empty($display['has_attachment'])): ?>
                            <span class="notification-item__attach" title="Has attachment" aria-label="Has attachment"></span>
                        <?php endif; ?>
                    </span>
                </span>
            </span>
        </a>
    <?php endforeach; ?>
<?php else: ?>
    <div class="notification-empty">No notifications yet.</div>
<?php endif; ?>
