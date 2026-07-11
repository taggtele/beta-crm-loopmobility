<?php
/**
 * Create-ticket UI: customer email + auto-acknowledgement mail toggle.
 *
 * @var string $customerEmailInputId
 * @var string $customerEmailGroupExtraClass e.g. ticket-create-modal-span-2 or full
 * @var string $customerEmailValue
 * @var bool $sendAutoAckEnabled default true when omitted
 * @var string|null $ackTooltip optional hover text for the info button
 */
$groupClass = 'input-group ticket-customer-email-group';
if (!empty($customerEmailGroupExtraClass)) {
    $groupClass .= ' ' . trim((string) $customerEmailGroupExtraClass);
}
$sendAutoAckEnabled = !isset($sendAutoAckEnabled) || $sendAutoAckEnabled;
if (!isset($ackTooltip) || trim((string) $ackTooltip) === '') {
    $ackTooltip = 'Customer will receive an acknowledgement email after ticket creation. Disable if not required.';
}
?>
        <div class="<?php echo e($groupClass); ?>">
            <label for="<?php echo e($customerEmailInputId); ?>">Customer email</label>
            <input
                type="email"
                id="<?php echo e($customerEmailInputId); ?>"
                name="customer_email"
                value="<?php echo e((string) ($customerEmailValue ?? '')); ?>"
                autocomplete="email"
                placeholder="Optional — used for notifications"
                data-ticket-customer-email-input
            >
            <div class="ticket-ack-mail-control">
                <div class="ticket-ack-mail-control-head">
                    <span class="ticket-ack-mail-control-label" id="<?php echo e($customerEmailInputId); ?>_ack_label">Auto acknowledgement</span>
                    <button
                        type="button"
                        class="ticket-ack-info-btn"
                        data-tooltip="<?php echo e($ackTooltip); ?>"
                        aria-label="<?php echo e($ackTooltip); ?>"
                    >i</button>
                    <div
                        class="ticket-ack-mail-toggle"
                        role="group"
                        aria-labelledby="<?php echo e($customerEmailInputId); ?>_ack_label"
                    >
                        <label class="ticket-ack-mail-toggle-option">
                            <input
                                type="radio"
                                name="send_auto_acknowledgement"
                                value="1"
                                <?php echo $sendAutoAckEnabled ? 'checked' : ''; ?>
                            >
                            <span>On</span>
                        </label>
                        <label class="ticket-ack-mail-toggle-option">
                            <input
                                type="radio"
                                name="send_auto_acknowledgement"
                                value="0"
                                <?php echo !$sendAutoAckEnabled ? 'checked' : ''; ?>
                            >
                            <span>Off</span>
                        </label>
                    </div>
                </div>
            </div>
        </div>
