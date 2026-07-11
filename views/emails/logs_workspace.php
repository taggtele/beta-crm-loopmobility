<?php
/**
 * Outlook-style split workspace for Email Logs only.
 * Expects in scope: $emailLogsThreadItems (list of row arrays from emails/logs.php).
 */
$emailLogsThreadItems = $emailLogsThreadItems ?? [];
$emailLogsListIndices = $emailLogsListIndices ?? array_keys($emailLogsThreadItems);
?>
<div
    class="email-logs-workspace"
    id="email-logs-workspace"
    role="region"
    aria-label="Email message log and reading pane"
    data-url-tickets-view="<?php echo e(url('tickets/view.php')); ?>"
    data-url-logs="<?php echo e(url('emails/logs.php')); ?>"
>
    <div class="email-logs-pane email-logs-pane--list" id="email-logs-pane-list">
        <div class="email-logs-list-toolbar">
            <div class="email-logs-list-toolbar__text">
                <span class="email-logs-count" id="email-logs-count"><?php echo e((string) count($emailLogsListIndices)); ?> messages</span>
                <span class="email-logs-list-toolbar__hint" aria-hidden="true">Newest first</span>
            </div>
        </div>
        <div
            class="email-logs-list-scroll"
            id="email-logs-list-scroll"
            role="listbox"
            aria-label="Email messages"
            aria-activedescendant=""
            tabindex="0"
        >
            <?php if ($emailLogsListIndices === []): ?>
                <div class="email-logs-empty email-logs-empty--list">
                    <p class="email-logs-empty__title">No messages in this view</p>
                    <p class="email-logs-empty__hint">Adjust filters or widen the date range, then click <strong>Apply filters</strong>.</p>
                </div>
            <?php else: ?>
                <?php foreach ($emailLogsListIndices as $poolIdx): ?>
                    <?php $row = $emailLogsThreadItems[$poolIdx] ?? null; ?>
                    <?php if ($row === null) {
                        continue;
                    } ?>
                    <?php
                    $rowExtraClass = '';
                    if (!empty($row['is_failed'])) {
                        $rowExtraClass .= ' email-logs-row--failed';
                    } elseif (!empty($row['is_pending'])) {
                        $rowExtraClass .= ' email-logs-row--pending';
                    } elseif (!empty($row['needs_attention'])) {
                        $rowExtraClass .= ' email-logs-row--attention';
                    }
                    $btnTitle = (string) ($row['row_title'] ?? $row['subject']);
                    if (!empty($row['is_flagged'])) {
                        $rowExtraClass .= ' email-logs-row--flagged';
                    }
                    $isFlagged = !empty($row['is_flagged']);
                    ?>
                    <div
                        class="email-logs-row<?php echo e($rowExtraClass); ?>"
                        role="option"
                        tabindex="0"
                        id="email-logs-row-<?php echo e((string) $poolIdx); ?>"
                        data-email-logs-index="<?php echo e((string) $poolIdx); ?>"
                        aria-selected="false"
                        title="<?php echo e($btnTitle); ?>"
                    >
                        <div class="email-logs-row__top">
                            <span class="email-logs-row__sender-wrap">
                                <span class="email-logs-row__unread-dot" aria-hidden="true"></span>
                                <span class="email-logs-row__sender"><?php echo e($row['list_sender']); ?></span>
                            </span>
                            <span class="email-logs-row__top-end">
                                <button
                                    type="button"
                                    class="email-logs-flag-btn<?php echo $isFlagged ? ' is-flagged' : ''; ?>"
                                    data-email-logs-flag="1"
                                    data-mail-direction="<?php echo e($row['direction']); ?>"
                                    data-log-id="<?php echo e((string) (int) ($row['log_id'] ?? 0)); ?>"
                                    aria-pressed="<?php echo $isFlagged ? 'true' : 'false'; ?>"
                                    aria-label="<?php echo $isFlagged ? 'Remove important flag' : 'Mark as important'; ?>"
                                    title="<?php echo $isFlagged ? 'Remove flag' : 'Mark as important'; ?>"
                                ><span class="email-logs-flag-icon" aria-hidden="true"></span></button>
                                <time class="email-logs-row__time" datetime="<?php echo e($row['sort_at'] ?? ''); ?>" title="<?php echo e($row['time_tooltip'] ?? $row['time_full'] ?? ''); ?>"><?php echo e($row['time_short']); ?></time>
                            </span>
                        </div>
                        <?php if (!empty($row['list_secondary'])): ?>
                            <div class="email-logs-row__secondary"><?php echo e($row['list_secondary']); ?></div>
                        <?php endif; ?>
                        <div class="email-logs-row__subject-line">
                            <span class="email-logs-row__subject" title="<?php echo e($row['subject']); ?>"><?php echo e($row['subject']); ?></span>
                            <?php if (!empty($row['has_attachment'])): ?>
                                <span class="email-logs-attach-badge" title="Message includes attachments" aria-label="Has attachments"></span>
                            <?php endif; ?>
                        </div>
                        <div class="email-logs-row__snippet"><?php echo e($row['snippet']); ?></div>
                        <div class="email-logs-row__badges">
                            <span class="email-logs-status-pill badge <?php echo e($row['status_class']); ?>"><?php echo e($row['status_label']); ?></span>
                            <?php if (!empty($row['ticket_serial']) && $row['ticket_serial'] !== '-'): ?>
                                <span class="email-logs-ticket-pill"><?php echo e($row['ticket_serial']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($row['thread_count']) && (int) $row['thread_count'] > 1): ?>
                                <span class="email-logs-thread-pill" title="Messages in this conversation"><?php echo e((string) (int) $row['thread_count']); ?></span>
                            <?php endif; ?>
                            <?php if ($row['direction'] === 'outgoing'): ?>
                                <span class="email-logs-dir-pill email-logs-dir-pill--out">Out</span>
                            <?php else: ?>
                                <span class="email-logs-dir-pill email-logs-dir-pill--in">In</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div
        class="email-logs-resizer"
        id="email-logs-resizer"
        role="separator"
        aria-orientation="vertical"
        aria-label="Resize message list"
        title="Drag to resize list. When focused, use Left or Right arrow keys."
        tabindex="0"
    ></div>

    <div class="email-logs-pane email-logs-pane--preview" id="email-logs-pane-preview" aria-label="Reading pane">
        <div class="email-logs-preview-toolbar" id="email-logs-preview-toolbar">
            <div class="email-logs-preview-toolbar__title">
                <span class="email-logs-reading-label">Reading</span>
            </div>
            <?php if ($canManageEmailLogs ?? true): ?>
            <div class="email-logs-preview-actions">
                <button type="button" class="btn btn-outline btn-sm email-logs-action-btn" id="email-logs-btn-reply" disabled>Reply</button>
                <button type="button" class="btn btn-outline btn-sm email-logs-action-btn" id="email-logs-btn-forward" disabled>Forward</button>
            </div>
            <?php endif; ?>
            <div class="email-logs-preview-toolbar__meta" id="email-logs-preview-toolbar-meta"></div>
        </div>

        <div class="email-logs-preview-body" id="email-logs-preview-body" aria-busy="false">
            <div class="email-logs-empty email-logs-empty--preview" id="email-logs-preview-placeholder">
                <div class="email-logs-empty__icon" aria-hidden="true"></div>
                <p class="email-logs-empty__title">Select an item to view</p>
                <p class="email-logs-empty__hint">Click a message in the list or use ↑ ↓ when the list is focused.</p>
            </div>
            <div class="email-logs-preview-content is-hidden" id="email-logs-preview-content" aria-busy="false">
                <div class="email-logs-preview-chrome" id="email-logs-preview-chrome">
                    <div class="email-logs-preview-chrome-bar">
                        <button type="button" class="elw-details-toggle is-expanded" id="email-logs-toggle-details" aria-expanded="true" aria-controls="email-logs-preview-details" title="Hide message details">
                            <span class="elw-chevron" aria-hidden="true"></span>
                            <span class="elw-details-toggle-text">Details</span>
                        </button>
                        <p class="email-logs-preview-collapsed-line is-hidden" id="email-logs-preview-collapsed-line"></p>
                    </div>
                    <div class="email-logs-preview-details" id="email-logs-preview-details">
                        <header class="email-logs-preview-header">
                            <div class="elw-subject-row">
                                <h3 class="email-logs-preview-title" id="email-logs-preview-subject"></h3>
                                <time class="elw-preview-time" id="email-logs-preview-time"></time>
                            </div>
                            <div class="email-logs-preview-participants" id="email-logs-preview-participants"></div>
                        </header>
                        <div class="email-logs-preview-meta-strip" id="email-logs-preview-meta-strip"></div>
                    </div>
                </div>
                <div class="email-logs-preview-message" id="email-logs-preview-message">
                    <div class="email-logs-conversation is-hidden" id="email-logs-conversation" aria-label="Conversation thread"></div>
                    <div class="email-logs-preview-single" id="email-logs-preview-single">
                        <div class="email-logs-preview-frame-wrap" id="email-logs-preview-frame-wrap">
                            <iframe class="email-logs-preview-iframe" id="email-logs-preview-iframe" title="Email message body" sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox allow-forms allow-scripts"></iframe>
                        </div>
                        <div class="email-logs-preview-plain is-hidden" id="email-logs-preview-plain"></div>
                    </div>
                    <div class="email-logs-preview-attachments is-hidden" id="email-logs-preview-attachments" aria-label="Email attachments"></div>
                </div>
                <div class="email-logs-preview-error is-hidden" id="email-logs-preview-error"></div>
                <div class="email-logs-preview-actions-bottom" id="email-logs-preview-actions-bottom"></div>
                <div class="email-logs-map-wrap is-hidden" id="email-logs-map-wrap"></div>
            </div>
        </div>
    </div>
</div>
