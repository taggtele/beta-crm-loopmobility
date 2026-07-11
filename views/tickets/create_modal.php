<?php
/**
 * @var array $currentUser
 * @var array $listAssignees
 * @var array $listPartyOptions
 * @var string $csrfToken
 * @var array<int|string, string> $modalTicketCountryOptions
 * @var string $modalTicketPartySearchUrl
 */
?>
<div id="ticket-create-modal" class="ticket-modal" hidden aria-hidden="true">
    <div class="ticket-modal-backdrop" aria-hidden="true"></div>
    <div
        class="ticket-modal-panel form-card ticket-modal-panel--create"
        role="dialog"
        aria-modal="true"
        aria-labelledby="ticket-create-modal-title"
        aria-describedby="ticket-create-modal-instructions"
        tabindex="-1"
    >
        <div class="ticket-modal-header">
            <h2 id="ticket-create-modal-title" class="ticket-modal-title">Create ticket</h2>
            <button type="button" class="ticket-modal-close" data-ticket-create-modal-close aria-label="Close">&times;</button>
        </div>
        <p class="ticket-modal-lead">New tickets are saved in <strong>Open</strong> status. You can assign an owner or vendor later if needed.</p>

        <div id="ticket-create-modal-instructions" class="ticket-modal-instructions" role="note">
            <div class="ticket-modal-instructions-head">
                <span class="ticket-modal-instructions-badge" aria-hidden="true">i</span>
                <div>
                    <p class="ticket-modal-instructions-title">Quick tips</p>
                    <p class="ticket-modal-instructions-lede">Use the fields this way so agents and customers get the right context fast.</p>
                </div>
            </div>
            <ul class="ticket-modal-instructions-list">
                <li>
                    <strong>Issue</strong> — One clear line (what is wrong / what is requested). Think of it as the email subject.
                </li>
                <li>
                    <strong>Description</strong> — Full story: steps to reproduce, IDs, times, error text. Paste from <strong>Excel</strong> here; row/column layout stays as a table.
                </li>
                <li>
                    <strong>Pictures</strong> — Paste with <kbd>Ctrl</kbd>+<kbd>V</kbd>. Prefer smaller screenshots so the ticket saves reliably.
                </li>
            </ul>
        </div>

        <form id="ticket-create-modal-form" method="post" action="<?php echo e(url('tickets/create.php')); ?>" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">

            <div class="ticket-create-modal-grid">
                <?php
                $ticketCountryFieldNs = 'modal';
                $ticketPartySearchUrl = $modalTicketPartySearchUrl;
                $ticketCountryDropdownOptions = $modalTicketCountryOptions;
                $selectedCustomerPartyId = 0;
                $selectedCustomerDisplay = '';
                $selectedCountry = '';
                $ticketCountryShowRequired = true;
                include __DIR__ . '/ticket_ui_party_country_fields.php';
?>
                <?php
                $customerEmailInputId = 'modal_customer_email';
                $customerEmailGroupExtraClass = 'ticket-create-modal-span-2';
                $customerEmailValue = '';
                $sendAutoAckEnabled = true;
                include __DIR__ . '/ticket_ui_customer_email_ack_fields.php';
?>
                <div class="input-group ticket-create-modal-span-2">
                    <label for="modal_issue">Issue <span class="req">*</span></label>
                    <input type="text" id="modal_issue" name="issue" required>
                </div>
                <div class="input-group ticket-create-modal-span-2 ticket-create-description-field">
                    <label for="ticket-create-description-editor">Description <span class="req">*</span></label>
                    <div class="ticket-create-description-head">
                        <p class="field-help ticket-modal-field-hint">Bullets, Excel tables, and pasted images are supported. Use <strong>Small / Medium / Large</strong> for a quick height, or <strong>drag the bottom-right corner</strong> of the box to resize freely (within limits).</p>
                        <div class="ticket-create-description-toolbar" role="toolbar" aria-label="Description editor height">
                            <span id="ticket-desc-size-label" class="ticket-create-description-toolbar-label">Height</span>
                            <div class="ticket-desc-size-toggle" role="group" aria-labelledby="ticket-desc-size-label">
                                <button type="button" class="ticket-desc-size-btn" data-desc-size="compact" aria-pressed="false" title="Smaller editor">Small</button>
                                <button type="button" class="ticket-desc-size-btn ticket-desc-size-btn--active" data-desc-size="comfortable" aria-pressed="true" title="Default height">Medium</button>
                                <button type="button" class="ticket-desc-size-btn" data-desc-size="expanded" aria-pressed="false" title="Taller editor">Large</button>
                            </div>
                        </div>
                    </div>
                    <div id="ticket-create-description-editor-wrap" class="ticket-create-description-editor-wrap" data-desc-size="comfortable">
                        <div
                            id="ticket-create-description-editor"
                            class="ticket-create-description-editor"
                            contenteditable="true"
                            spellcheck="true"
                            tabindex="0"
                            role="textbox"
                            aria-multiline="true"
                            aria-required="true"
                            data-placeholder="Type here, paste an Excel table, or paste an image (Ctrl+V)…"
                        ></div>
                    </div>
                    <textarea id="ticket-create-description-sync" name="description" hidden aria-hidden="true"></textarea>
                </div>
                <div class="input-group">
                    <label for="modal_priority">Priority <span class="req">*</span></label>
                    <select id="modal_priority" name="priority" required>
                        <option value="">Select priority</option>
                        <?php foreach (ticket_priorities() as $priority): ?>
                            <option value="<?php echo e($priority); ?>"><?php echo e($priority); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="input-group">
                    <label for="modal_assign_to">Assign to</label>
                    <select id="modal_assign_to" name="assign_to">
                        <option value="">Unassigned</option>
                        <?php if (($currentUser['role'] ?? '') === 'Admin'): ?>
                            <?php foreach ($listAssignees as $assignUser): ?>
                                <option value="<?php echo e($assignUser['user_id']); ?>">
                                    <?php echo e($assignUser['name'] . ' (' . $assignUser['role'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <option value="<?php echo e($currentUser['user_id']); ?>">
                                <?php echo e($currentUser['name'] . ' (Assign to me)'); ?>
                            </option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="input-group ticket-create-modal-span-2">
                    <label for="modal_assigned_vendor_id">Assigned vendor</label>
                    <select id="modal_assigned_vendor_id" name="assigned_vendor_id">
                        <option value="">No vendor assigned</option>
                        <?php foreach ($listPartyOptions as $party): ?>
                            <option value="<?php echo e((string) $party['id']); ?>"><?php echo e($party['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <p class="ticket-modal-error" id="ticket-create-modal-error" hidden role="alert"></p>

            <div class="ticket-modal-actions form-actions">
                <button type="submit" class="btn btn-primary" id="ticket-create-modal-submit">Create ticket</button>
                <button type="button" class="btn btn-secondary" data-ticket-create-modal-close>Cancel</button>
            </div>
        </form>
    </div>
</div>
