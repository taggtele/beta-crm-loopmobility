<div id="pl-app" class="pl-app">
    <section class="pl-head">
        <div>
            <h2>Party Ledger</h2>
            <p>All parties with opening balance, current balance, last activity, and ledger access.</p>
        </div>
        <a class="btn btn-secondary" href="<?php echo e(url('modules/party_account/index.php')); ?>">Party Accounts</a>
        <a class="btn btn-outline-primary ms-2" href="<?php echo e(url('modules/party_account/party_transactions.php')); ?>">View Transactions</a>
    </section>

    <section class="pl-filters">
        <label>
            <span>Party</span>
            <select id="pl-party-filter"><option value="">All parties</option></select>
        </label>
        <label>
            <span>Currency</span>
            <select id="pl-currency-filter"><option value="">All currencies</option></select>
        </label>
        <label>
            <span>From</span>
            <input type="date" id="pl-from">
        </label>
        <label>
            <span>To</span>
            <input type="date" id="pl-to">
        </label>
        <label>
            <span>Balance Type</span>
            <select id="pl-balance-type">
                <option value="">All</option>
                <option value="receivable">Receivable</option>
                <option value="payable">Payable</option>
                <option value="zero">Zero</option>
            </select>
        </label>
        <button type="button" class="btn btn-primary" id="pl-apply">Apply</button>
        <button type="button" class="btn btn-secondary" id="pl-reset">Reset</button>
    </section>

    <section class="pl-table-card">
        <div class="pl-scroll">
            <table class="pl-table">
                <thead>
                    <tr>
                        <th>Party Name</th>
                        <th>Currency</th>
                        <th>Opening Balance</th>
                        <th>Current Balance</th>
                        <th>Transactions</th>
                        <th>Last Transaction Date</th>
                        <th>Open Ledger</th>
                    </tr>
                </thead>
                <tbody id="pl-party-rows">
                    <tr><td colspan="6">Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </section>
</div>

<div class="pl-drawer" id="pl-drawer" hidden>
    <div class="pl-drawer__panel">
        <header class="pl-drawer__head">
            <div>
                <h2 id="pl-ledger-title">Ledger</h2>
                <p id="pl-ledger-contact"></p>
                <div id="pl-header-compact" style="display:flex;gap:12px;align-items:center;margin-top:6px;flex-wrap:nowrap;overflow:auto">
                    <!-- compact header items populated by JS -->
                </div>
                <div id="pl-bank-branch-address" style="margin-top:6px;font-size:13px;color:#475569"></div>
            </div>
            <button type="button" class="pl-close" id="pl-close">Close</button>
        </header>

        <!-- lower summary: keep totals only (party basic details moved to header) -->
        <section class="pl-summary" id="pl-summary"></section>

        <section class="pl-actions">
            <label><span>Statement From</span><input type="date" id="pl-st-from"></label>
            <label><span>To</span><input type="date" id="pl-st-to"></label>
            <button type="button" class="btn btn-secondary" id="pl-export-excel">Excel</button>
            <button type="button" class="btn btn-secondary" id="pl-export-pdf">PDF</button>
        </section>

        <form id="pl-form" class="pl-form">
            <input type="hidden" name="id">
            <input type="hidden" name="party_account_id">
            <input type="hidden" name="currency" id="pl-form-currency-value">
            <div class="pl-form-currency-display" id="pl-form-currency-display">
                <label><span>Currency</span><span id="pl-form-currency-text"></span></label>
            </div>
            <label><span>Invoice Period</span><input type="month" name="invoice_period" required></label>
            <label><span>Customer Invoice No</span><input name="customer_invoice_no" maxlength="120"></label>
            <label><span>Customer Invoice Value</span><input name="customer_invoice_value" inputmode="decimal" placeholder="0.00"></label>
            <label><span>Vendor Invoice No</span><input name="vendor_invoice_no" maxlength="120"></label>
            <label><span>Vendor Invoice Value</span><input name="vendor_invoice_value" inputmode="decimal" placeholder="0.00"></label>
            <label><span>Payment In</span><input name="payment_in" inputmode="decimal" placeholder="0.00"></label>
            <label><span>Payment In Date</span><input type="date" name="payment_in_date"></label>
            <label><span>Payment Out</span><input name="payment_out" inputmode="decimal" placeholder="0.00"></label>
            <label><span>Payment Out Date</span><input type="date" name="payment_out_date"></label>
            <label class="pl-form-wide"><span>Notes</span><input name="notes" maxlength="500"></label>
            <div class="pl-form-actions">
                <button type="submit" class="btn btn-primary">Save Transaction</button>
                <button type="button" class="btn btn-secondary" id="pl-form-reset">Clear</button>
            </div>
        </form>

        <section class="pl-periods-wrap">
            <div class="pl-periods-sticky">
                <button type="button" class="btn btn-sm btn-light pl-period-arrow pl-period-left" aria-label="Scroll left">&larr;</button>
                <div class="pl-periods" id="pl-months" role="tablist" aria-label="Ledger periods"></div>
                <button type="button" class="btn btn-sm btn-light pl-period-arrow pl-period-right" aria-label="Scroll right">&rarr;</button>
            </div>
        </section>

        <section id="pl-selected-period" class="pl-selected-period" aria-live="polite"></section>

        <div style="display:flex;justify-content:flex-end;margin-bottom:6px;">
            <button type="button" class="btn btn-outline btn-sm" id="pl-full-table-btn">Full table</button>
        </div>

        <div class="pl-scroll">
            <table class="pl-table pl-ledger-table">
                <thead>
                    <tr>
                        <th>Invoice Period</th>
                        <th>Customer Invoice No</th>
                        <th>Customer Invoice Value</th>
                        <th>Vendor Invoice No</th>
                        <th>Vendor Invoice Value</th>
                        <th>Payment In</th>
                        <th>Payment In Date</th>
                        <th>Payment Out</th>
                        <th>Payment Out Date</th>
                        <th>Net Balance</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="pl-ledger-rows"></tbody>
            </table>
        </div>
    </div>
</div>