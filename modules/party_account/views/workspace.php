<?php
$paCanManage = !empty($paPermissions['can_manage']);
$paViewOnly = !$paCanManage;
?>
<div id="pa-app" class="pa-app<?php echo $paViewOnly ? ' pa-app--view-only' : ''; ?>">

    <section class="pa-intro pa-intro--compact" aria-labelledby="pa-intro-title">
        <div class="pa-intro__content">
            <h2 id="pa-intro-title" class="pa-intro__title">Party Account</h2>
            <p class="pa-intro__summary"><?php echo $paViewOnly
                ? 'View-only: browse party profiles, search and filters. No edits or export.'
                : 'Finance profiles with branch (loop entity). Search, filter, export. Archive restores from <em>Archived</em> scope.'; ?></p>
        </div>
        <div class="pa-intro__toolbar">
            <?php if ($paCanManage): ?>
            <button type="button" class="btn btn-primary" id="pa-btn-new-account">+ Add party account</button>
            <button type="button" class="btn btn-secondary" id="pa-btn-import">Import party accounts</button>
            <button type="button" class="btn btn-secondary" id="pa-btn-export">Export CSV</button>
            <?php endif; ?>
            <?php if (in_array($paPermissions['role'] ?? '', ['Admin', 'Finance'], true)): ?>
            <a class="btn btn-secondary" href="<?php echo e(url('modules/party_account/ledger.php')); ?>">Party Ledger</a>
            <?php endif; ?>
            <button type="button" class="btn btn-secondary pa-btn-filter" id="pa-btn-toggle-filter" aria-expanded="false">
                <span>Advanced filters</span>
                <span class="pa-filter-chevron" aria-hidden="true">v</span>
            </button>
        </div>
    </section>

    <div class="pa-toolbar-row">
        <div class="pa-search-input-wrap">
            <span class="pa-search-icon" aria-hidden="true"></span>
            <input type="search" id="pa-search" class="pa-search-input" placeholder="Search parties, email, phone, bank..." autocomplete="off"/>
        </div>
        <label class="pa-scope-label">
            <span class="pa-scope-label__text">Show</span>
            <select id="pa-scope" class="pa-scope-select" title="Ledger scope">
                <option value="live">Live accounts</option>
                <option value="deleted">Archived only</option>
                <option value="all">All records</option>
            </select>
        </label>
    </div>

    <section class="pa-stats-row" id="pa-kpi" aria-label="Summary metrics">
        <article class="pa-stat-card" data-kpi-slot="rows">
            <div class="pa-stat-card__icon pa-stat-card__icon--blue pa-stat-card__icon--glyph-list" aria-hidden="true"></div>
            <div>
                <span class="pa-stat-label">Matching parties</span>
                <strong class="pa-stat-value pa-kpi-value">-</strong>
            </div>
            <span class="pa-stat-badge pa-stat-badge--muted" id="pa-stat-filtered-hint" hidden>filters on</span>
        </article>
        <article class="pa-stat-card" data-kpi-slot="status_active">
            <div class="pa-stat-card__icon pa-stat-card__icon--green pa-stat-card__icon--glyph-active" aria-hidden="true"></div>
            <div>
                <span class="pa-stat-label">Active accounts</span>
                <strong class="pa-stat-value pa-kpi-value">-</strong>
            </div>
            <span class="pa-stat-badge pa-stat-badge--success">live</span>
        </article>
        <article class="pa-stat-card" data-kpi-slot="credit">
            <div class="pa-stat-card__icon pa-stat-card__icon--violet pa-stat-card__icon--glyph-credit" aria-hidden="true"></div>
            <div>
                <span class="pa-stat-label">Company net amount</span>
                <strong class="pa-stat-value pa-kpi-value">-</strong>
            </div>
        </article>
    </section>

    <section class="pa-filters-panel" id="pa-filters-panel" hidden>
        <div class="pa-filters-panel__inner">
            <p class="pa-filters-panel__hint">Combines with search and scope.</p>
            <div class="pa-filter-grid">
                <label class="pa-field">
                    <span>Status</span>
                    <select id="pa-filter-status">
                        <option value="">Any status</option>
                        <?php foreach (party_account_statuses() as $st): ?>
                            <option value="<?php echo e($st); ?>"><?php echo ucfirst(str_replace('_', ' ', $st)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="pa-field">
                    <span>Loop entity</span>
                    <select id="pa-filter-entity"><option value="">Any entity</option></select>
                </label>
                <label class="pa-field">
                    <span>Country</span>
                    <input type="text" id="pa-filter-country" maxlength="120" placeholder="e.g. India"/>
                </label>
                <label class="pa-field">
                    <span>Currency</span>
                    <select id="pa-filter-currency">
                        <option value="">Any currency</option>
                        <?php foreach (party_account_currencies() as $cur): ?>
                            <option><?php echo e($cur); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="pa-field">
                    <span>Created from</span>
                    <input type="date" id="pa-filter-created-from"/>
                </label>
                <label class="pa-field">
                    <span>Created to</span>
                    <input type="date" id="pa-filter-created-to"/>
                </label>
            </div>
            <div class="pa-filter-actions">
                <button type="button" class="btn btn-primary btn-sm" id="pa-apply-filter">Apply filters</button>
                <button type="button" class="btn btn-secondary btn-sm" id="pa-reset-filter">Clear all</button>
            </div>
        </div>
    </section>

    <?php if ($paCanManage): ?>
    <div class="pa-bulk-slot" hidden data-bulk-visible>
        <span class="pa-bulk-label"><span id="pa-bulk-count">0</span> selected</span>
        <button type="button" class="btn btn-warning btn-sm" id="pa-bulk-archive">Archive selected</button>
        <button type="button" class="btn btn-success btn-sm" id="pa-bulk-restore">Restore selected</button>
        <button type="button" class="btn btn-ghost btn-sm" id="pa-bulk-dismiss">Clear selection</button>
    </div>
    <?php endif; ?>

    <section class="pa-finance-card table-card">
        <div class="pa-finance-card__head">
            <div class="pa-finance-card__titles">
                <h2 class="pa-finance-title">Party Account list</h2>
            </div>
            <div class="pa-finance-card__tools">
                <label class="pa-per-page">
                    <span class="pa-muted">Rows per page</span>
                    <select id="pa-page-size">
                        <option value="10">10</option>
                        <option value="25" selected>25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </label>
            </div>
        </div>

        <div class="pa-table-shell">
            <div class="table-wrap pa-scroll">
                <table class="pa-data-table pa-data-table--rich">
                    <thead>
                    <tr>
                        <th class="pa-col-check pa-col-sticky-left">
                            <input type="checkbox" id="pa-select-all" aria-label="Select all rows"/>
                        </th>
                        <th class="pa-col-party" data-sort="party_name"><button type="button" class="pa-sort">Party</button></th>
                        <th class="pa-col-contact" data-sort="party_email"><button type="button" class="pa-sort">Contact</button></th>
                        <th class="pa-col-location" data-sort="country"><button type="button" class="pa-sort">Location</button></th>
                        <th class="pa-col-finance" data-sort="credit_limit"><button type="button" class="pa-sort">Finance</button></th>
                        <th class="pa-col-terms">Terms</th>
                        <th class="pa-col-date" data-sort="updated_at"><button type="button" class="pa-sort pa-sort-active">Updated</button></th>
                        <th class="pa-col-actions pa-col-sticky-right">Actions</th>
                    </tr>
                    </thead>
                    <tbody id="pa-table-body">
                        <tr><td colspan="8" class="pa-empty muted">Loading party accounts...</td></tr>
                    </tbody>
                </table>
                <div class="pa-loading shimmer" hidden id="pa-table-loading-overlay"></div>
            </div>
            <footer class="pagination pa-pagination" id="pa-pagination">
                <div class="pagination-summary">
                    <span id="pa-page-range">-</span>
                </div>
                <div class="pagination-controls" id="pa-page-buttons"></div>
            </footer>
        </div>
    </section>

</div><!-- /pa-app -->

<div class="pa-modal-overlay" hidden id="pa-modal-overlay" aria-hidden="true"></div>

<div class="pa-modal" hidden id="pa-profile-modal" role="dialog" aria-modal="true" aria-labelledby="pa-modal-title">
    <header class="pa-modal__header">
        <div class="pa-modal__heading">
            <h2 id="pa-modal-title">Party account profile</h2>
            <p id="pa-modal-subtitle" class="pa-modal__subtitle">Required fields are marked *.</p>
        </div>
        <button type="button" class="pa-modal__close" id="pa-modal-close" aria-label="Close">&times;</button>
    </header>
    <div class="pa-modal__body">
        <div id="pa-profile-view" class="pa-profile-view" hidden aria-live="polite"></div>
        <form id="pa-account-form" class="pa-profile-form" novalidate>
            <?php echo csrf_field(); ?>

            <p class="pa-form-notice" role="note">Required: party name and country. Loop entity = company branch for billing. Banking optional.</p>

            <div class="pa-profile-form__cols">
                <div class="pa-profile-form__col">
                    <div class="pa-form-section">
                        <h3 class="pa-form-section-title">Contact details</h3>
                        <div class="pa-form-grid pa-form-grid--2">
                            <label class="pa-field pa-field--span-2">
                                <span class="pa-field__label">Party name <em class="pa-req">*</em></span>
                                <input name="party_name" required autocomplete="organization" placeholder="e.g. Acme Logistics Pvt Ltd"/>
                            </label>
                            <label class="pa-field">
                                <span class="pa-field__label">Primary email</span>
                                <input type="email" name="party_email" id="pa-form-primary-email" autocomplete="email" placeholder="finance@example.com"/>
                            </label>
                            <label class="pa-field pa-field-country">
                                <span class="pa-field__label">Country <em class="pa-req">*</em></span>
                                <div class="pa-country-picker">
                                    <img id="pa-country-flag-preview" class="pa-flag-img" src="" alt="" width="24" height="18" hidden/>
                                    <select name="country" id="pa-form-country" required>
                                        <option value="">Select country...</option>
                                        <?php foreach (party_account_country_phone_catalog() as $c): ?>
                                            <option value="<?php echo e($c['name']); ?>"
                                                data-iso="<?php echo e($c['iso']); ?>"
                                                data-dial="<?php echo e($c['dial']); ?>"
                                                data-min="<?php echo (int) $c['min']; ?>"
                                                data-max="<?php echo (int) $c['max']; ?>"
                                                data-flag-url="<?php echo e($c['flag_url']); ?>">
                                                <?php echo e($c['name'] . ' (+' . $c['dial'] . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </label>
                            <label class="pa-field pa-field-phone">
                                <span class="pa-field__label">Primary phone</span>
                                <div class="pa-phone-input" id="pa-phone-wrap">
                                    <span class="pa-phone-prefix" id="pa-phone-prefix" aria-hidden="true">
                                        <img id="pa-phone-flag" class="pa-flag-img" src="" alt="" width="24" height="18" hidden/>
                                        <span class="pa-phone-dial" id="pa-phone-dial">+</span>
                                    </span>
                                    <input type="tel"
                                           name="party_phone"
                                           id="pa-form-phone"
                                           inputmode="tel"
                                           autocomplete="tel"
                                           placeholder="National number only"
                                           aria-describedby="pa-phone-hint pa-phone-error"/>
                                </div>
                                <small class="pa-phone-hint pa-field__hint" id="pa-phone-hint">National number only (no country code).</small>
                                <small class="pa-field-error" id="pa-phone-error" hidden role="alert"></small>
                            </label>
                            <div class="pa-field pa-field-extra-emails pa-field--span-2">
                                <span class="pa-field__label">Additional emails
(optional)</span>
                                <div id="pa-extra-emails-list" class="pa-extra-emails-list"></div>
                                <?php if ($paCanManage): ?>
                                <button type="button" class="btn btn-secondary btn-sm" id="pa-btn-add-email">+ Add Email</button>
                                <?php endif; ?>
                            </div>
                            <label class="pa-field pa-field--span-2">
                                <span class="pa-field__label">Registered address</span>
                                <textarea name="address" rows="2" placeholder="Street, city, postal code"></textarea>
                            </label>
                        </div>
                    </div>

                    <div class="pa-form-section">
                        <h3 class="pa-form-section-title">Assignment</h3>
                        <div class="pa-form-grid pa-form-grid--2">
                            <div class="pa-field pa-field-loop-entity pa-field--span-2">
                                <span class="pa-field__label">Loop entity <span class="pa-muted">(company branch)</span></span>
                                <small class="pa-field__hint">Which Loop legal entity / branch owns this party relationship.</small>
                                <div class="pa-loop-entity-row">
                                    <select id="pa-form-loop_entity" name="loop_entity_id" class="pa-loop-entity-select">
                                        <option value="">Not assigned</option>
                                    </select>
                                    <?php if ($paCanManage): ?>
                                    <button type="button" class="btn btn-secondary btn-sm" id="pa-btn-add-loop-entity" aria-expanded="false" aria-controls="pa-loop-entity-add">+ Add branch</button>
                                    <?php endif; ?>
                                </div>
                                <?php if ($paCanManage): ?>
                                <div id="pa-loop-entity-add" class="pa-loop-entity-add" hidden>
                                    <p class="pa-loop-entity-add__title">New company branch</p>
                                    <label class="pa-field pa-field--compact">
                                        <span class="pa-field__label">Branch name <em class="pa-req">*</em></span>
                                        <input type="text" id="pa-loop-entity-name" maxlength="180" placeholder="e.g. Loop Mobility Mumbai" autocomplete="organization"/>
                                    </label>
                                    <label class="pa-field pa-field--compact">
                                        <span class="pa-field__label">Short code <span class="pa-muted">(optional)</span></span>
                                        <input type="text" id="pa-loop-entity-code" maxlength="32" placeholder="e.g. LMMH" autocomplete="off"/>
                                    </label>
                                    <div class="pa-loop-entity-add__actions">
                                        <button type="button" class="btn btn-primary btn-sm" id="pa-loop-entity-save">Save branch</button>
                                        <button type="button" class="btn btn-ghost btn-sm" id="pa-loop-entity-cancel">Cancel</button>
                                    </div>
                                    <small class="pa-field-error" id="pa-loop-entity-error" hidden role="alert"></small>
                                </div>
                                <?php endif; ?>
                            </div>
                            <label class="pa-field">
                                <span class="pa-field__label">AM <span class="pa-muted">(Assistant Manager)</span></span>
                                <input type="text"
                                       id="pa-form-assistant_manager_name"
                                       name="assistant_manager_name"
                                       maxlength="180"
                                       placeholder="Assistant manager name (optional)"
                                       autocomplete="name"/>
                            </label>
                            <label class="pa-field">
                                <span class="pa-field__label">BM <span class="pa-muted">(Business Manager)</span></span>
                                <input type="text"
                                       id="pa-form-business_manager_name"
                                       name="business_manager_name"
                                       maxlength="180"
                                       placeholder="Business manager name (optional)"
                                       autocomplete="name"/>
                            </label>
                            <label class="pa-field">
                                <span class="pa-field__label">Payment terms</span>
                                <input name="payment_terms" placeholder="e.g. Net 30, Net 45, Due on receipt"/>
                            </label>
                            <label class="pa-field">
                                <span class="pa-field__label">Account status</span>
                                <select name="status" id="pa-form-status"></select>
                            </label>
                        </div>
                    </div>
                </div>

<div class="pa-profile-form__col">
                    <div class="pa-form-section">
                        <h3 class="pa-form-section-title">Banking details</h3>
                        <div class="pa-form-grid pa-form-grid--2">
                            <label class="pa-field">
                                <span class="pa-field__label">Bank name</span>
                                <input name="bank_name" placeholder="e.g. HDFC Bank"/>
                            </label>
                            <label class="pa-field">
                                <span class="pa-field__label">Account holder name</span>
                                <input name="account_holder_name" placeholder="Name on bank account"/>
                            </label>
                            <label class="pa-field">
                                <span class="pa-field__label">Account number</span>
                                <input name="account_number" autocomplete="off" placeholder="Account / IBAN reference"/>
                            </label>
                            <label class="pa-field">
                                <span class="pa-field__label">IFSC / SWIFT / routing code</span>
                                <input name="ifsc_swift_code" placeholder="e.g. HDFC0001234"/>
                            </label>
                            <label class="pa-field pa-field--span-2">
                                <span class="pa-field__label">IBAN</span>
                                <input name="iban_number" placeholder="International bank account number"/>
                            </label>
                            <label class="pa-field pa-field--span-2">
                                <span class="pa-field__label">Bank Branch Address</span>
                                <textarea name="bank_branch_address" rows="2" placeholder="e.g. HDFC Bank Ltd., Subhash Nagar Branch, B-12, Main Najafgarh Road, New Delhi"></textarea>
                            </label>
                        </div>
                    </div>

                    <div class="pa-form-section">
                        <h3 class="pa-form-section-title">Credit &amp; currency</h3>
                        <div class="pa-form-grid pa-form-grid--2">
                            <label class="pa-field">
                                <span class="pa-field__label">Credit limit</span>
                                <input inputmode="decimal" name="credit_limit" id="pa-form-credit" placeholder="0.00"/>
                            </label>
                            <label class="pa-field">
                                <span class="pa-field__label">Currency</span>
                                <select name="currency" id="pa-form-currency"></select>
                            </label>
                            <label class="pa-field pa-field--span-2">
                                <span class="pa-field__label">Opening balance</span>
                                <div class="pa-opening-balance-row">
                                    <input inputmode="decimal"
                                           name="opening_balance"
                                           id="pa-form-opening-balance"
                                           placeholder="0.00"
                                           autocomplete="off"/>
                                    <select name="opening_balance_type" id="pa-form-opening-balance-type" aria-label="Opening balance type">
                                        <option value="">—</option>
                                        <option value="receivable">Receivable (money we will receive)</option>
                                        <option value="payable">Payable (money we need to pay)</option>
                                    </select>
                                </div>
                            </label>
                        </div>
                    </div>

                    <div class="pa-form-section pa-form-section--multi">
                        <div class="pa-form-section__head">
                            <h3 class="pa-form-section-title">Multi-Currency</h3>
                            <label class="pa-form-switch">
                                <input type="checkbox" id="pa-form-multi-currency" name="is_multi_currency">
                                <span>Enable multi-currency support</span>
                            </label>
                        </div>
                        <p class="pa-form-section-desc pa-form-section-desc--tight">Use this when the same party needs separate balances in multiple currencies.</p>
                        <div id="pa-multi-currency-panel" class="pa-multi-currency-panel" hidden>
                            <div class="pa-multi-currency-toolbar">
                                <small class="pa-field__hint pa-multi-currency-hint">Keep each ledger row lean. Duplicate currencies are blocked automatically.</small>
                                <button type="button" class="btn btn-secondary btn-sm pa-add-currency" id="pa-add-currency">+ Add Currency</button>
                            </div>
                            <div class="pa-multi-currency-table-wrap">
                                <table class="pa-multi-currency-table">
                                    <thead>
                                        <tr>
                                            <th>Currency</th>
                                            <th>Opening Balance</th>
                                            <th>Balance Type</th>
                                            <th class="pa-multi-currency-actions-col">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="pa-currency-ledgers-body">
                                    </tbody>
                                </table>
                            </div>
                            <small class="pa-field-error" id="pa-currencies-error" hidden role="alert"></small>
                        </div>
                    </div>
                </div>
            </div>

            <label class="pa-field pa-field--full">
                <span class="pa-field__label">Internal notes</span>
                <textarea name="notes" id="pa-form-notes" rows="3" placeholder="Finance-only notes (not visible to the party)"></textarea>
            </label>
        </form>
    </div>
    <footer class="pa-modal__footer" id="pa-modal-footer">
        <button type="button" class="btn btn-secondary" id="pa-modal-cancel">Cancel</button>
        <button type="button" class="btn btn-primary" id="pa-view-edit" hidden>Edit profile</button>
        <button type="button" class="btn btn-primary" id="pa-form-submit">Save party account</button>
    </footer>
</div>

<div class="pa-modal pa-modal--import" id="pa-import-modal" hidden role="dialog" aria-modal="true" aria-labelledby="pa-import-title">
    <div class="pa-modal__backdrop" id="pa-import-backdrop"></div>
    <div class="pa-modal__panel pa-import-panel">
        <header class="pa-modal__header">
            <div class="pa-modal__heading">
                <h2 id="pa-import-title">Import party accounts</h2>
                <p class="pa-modal__subtitle">Upload .csv or .xlsx (max 500 rows). Required columns: <strong>party_name</strong>, <strong>country</strong>.</p>
            </div>
            <button type="button" class="pa-modal__close" id="pa-import-close" aria-label="Close">&times;</button>
        </header>
        <div class="pa-modal__body">
            <div class="pa-import-actions">
                <button type="button" class="btn btn-secondary btn-sm" id="pa-import-download-template">Download sample template</button>
            </div>
            <label class="pa-field">
                <span class="pa-field__label">Excel / CSV file</span>
                <input type="file" id="pa-import-file" accept=".csv,.xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv"/>
            </label>
            <label class="pa-field pa-import-option">
                <input type="checkbox" id="pa-import-skip-dup" checked/>
                <span>Skip duplicate rows (same email in file or already in system)</span>
            </label>
            <div id="pa-import-preview" class="pa-import-preview" hidden></div>
            <div class="pa-import-history">
                <h3 class="pa-import-history__title">Recent imports</h3>
                <ul id="pa-import-history-list" class="pa-import-history__list pa-muted">
                    <li>Loading…</li>
                </ul>
            </div>
        </div>
        <footer class="pa-modal__footer">
            <button type="button" class="btn btn-secondary" id="pa-import-cancel">Cancel</button>
            <button type="button" class="btn btn-secondary" id="pa-import-preview-btn">Preview</button>
            <button type="button" class="btn btn-primary" id="pa-import-run" disabled>Import rows</button>
        </footer>
    </div>
</div>

<div class="pa-confirm" id="pa-confirm-dialog" hidden role="alertdialog" aria-modal="true" aria-labelledby="pa-confirm-title" aria-describedby="pa-confirm-message">
    <div class="pa-confirm__backdrop" id="pa-confirm-backdrop"></div>
    <div class="pa-confirm__panel">
        <h3 id="pa-confirm-title" class="pa-confirm__title">Confirm</h3>
        <p id="pa-confirm-message" class="pa-confirm__message">Are you sure?</p>
        <footer class="pa-confirm__actions">
            <button type="button" class="btn btn-secondary" id="pa-confirm-cancel">Cancel</button>
            <button type="button" class="btn btn-danger" id="pa-confirm-ok">Confirm</button>
        </footer>
    </div>
</div>
