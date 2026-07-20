(function () {
    var B = window.PartyLedgerBootstrap;
    var root = document.getElementById('pl-app');
    if (!B || !root) return;

    var csrf = B.csrf;
    var currentPartyId = 0;
    var currentCurrency = '';
    var partyRows = [];
    var ledgerRows = [];

    function qs(s, e) { return (e || document).querySelector(s); }
    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>'"]/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]);
        });
    }
    function money(v, c) {
        var n = Number(v || 0);
        try {
            return new Intl.NumberFormat(void 0, { style: 'currency', currency: c || 'INR' }).format(n);
        } catch (e) {
            return n.toFixed(2) + ' ' + (c || '');
        }
    }
    function toast(m, k) {
        var h = document.getElementById('pa-toasts');
        if (!h) return;
        var d = document.createElement('div');
        d.className = 'pa-toast' + (k === 'e' ? ' pa-toast--error' : ' pa-toast--success');
        d.textContent = m;
        h.appendChild(d);
        setTimeout(function () { d.remove(); }, 3000);
    }
    async function api(payload) {
        var r = await fetch(B.endpoints.ledger, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json;charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
            },
            body: JSON.stringify(Object.assign({}, payload || {}, { csrf_token: csrf })),
        });
        var j = {};
        try { j = await r.json(); } catch (e) { toast('Bad JSON response', 'e'); return null; }
        if (j.csrf_token) csrf = j.csrf_token;
        if (!r.ok || j.ok === false) {
            toast(j.message || j.error || 'Request failed', 'e');
            return null;
        }
        return j;
    }
    function filters() {
        return {
            party_id: qs('#pl-party-filter').value,
            currency: qs('#pl-currency-filter').value,
            from: qs('#pl-from').value,
            to: qs('#pl-to').value,
            balance_type: qs('#pl-balance-type').value,
        };
    }
    function hydratePartySelect(rows) {
        var sel = qs('#pl-party-filter');
        var prev = sel.value;
        var seen = {};
        sel.innerHTML = '<option value="">All parties</option>';
        rows.forEach(function (r) {
            if (!r.id || seen[r.id]) return;
            seen[r.id] = true;
            var o = document.createElement('option');
            o.value = r.id;
            o.textContent = r.party_name;
            sel.appendChild(o);
        });
        sel.value = prev;
    }
    function hydrateCurrencySelect(rows) {
        var sel = qs('#pl-currency-filter');
        var prev = sel.value;
        var seen = {};
        sel.innerHTML = '<option value="">All currencies</option>';
        rows.forEach(function (r) {
            if (!r.currency || seen[r.currency]) return;
            seen[r.currency] = true;
            var o = document.createElement('option');
            o.value = r.currency;
            o.textContent = r.currency;
            sel.appendChild(o);
        });
        sel.value = prev;
    }
    function renderParties(rows) {
        var tb = qs('#pl-party-rows');
        if (!rows.length) {
            tb.innerHTML = '<tr><td colspan="7">No ledger records found.</td></tr>';
            return;
        }
        tb.innerHTML = rows.map(function (r) {
            var multi = r.is_multi_currency === true || r.is_multi_currency === 1 || String(r.is_multi_currency) === '1';
            var currency = r.currency || '';
            var dataCurrency = currency ? ' data-ledger-currency="' + esc(currency) + '"' : '';
            var buttonLabel = multi ? esc(currency) + ' Ledger' : 'Open Ledger';
            return '<tr>' +
                '<td><strong>' + esc(r.party_name) + '</strong><div class="pl-muted">' + esc(r.party_email || '') + '</div></td>' +
                '<td><span class="pl-chip">' + esc(currency) + '</span></td>' +
                '<td><span class="pl-money">' + esc(money(r.opening_balance, currency)) + '</span></td>' +
                '<td><span class="pl-money">' + esc(money(r.current_balance, currency)) + '</span><div class="pl-muted">' + esc(r.balance_type) + '</div></td>' +
                '<td>' + esc(r.transaction_count || 0) + '</td>' +
                '<td>' + esc(r.last_activity || '-') + '</td>' +
                '<td><button type="button" class="btn btn-primary btn-sm" data-view-ledger="' + esc(r.party_id) + '"' + dataCurrency + '>' + buttonLabel + '</button></td>' +
            '</tr>';
        }).join('');
    }
    async function loadParties() {
        var j = await api({ action: 'list', filters: filters() });
        if (!j) return;
        partyRows = j.rows || [];
        if (!qs('#pl-party-filter').dataset.ready) {
            hydratePartySelect(partyRows);
            hydrateCurrencySelect(partyRows);
            if (qs('#pl-currency-filter').dataset.initial) {
                qs('#pl-currency-filter').value = qs('#pl-currency-filter').dataset.initial;
                delete qs('#pl-currency-filter').dataset.initial;
            }
            if (qs('#pl-party-filter').dataset.initial) {
                qs('#pl-party-filter').value = qs('#pl-party-filter').dataset.initial;
                delete qs('#pl-party-filter').dataset.initial;
            }
            qs('#pl-party-filter').dataset.ready = '1';
            if (qs('#pl-party-filter').value) {
                await loadParties();
                openLedger(qs('#pl-party-filter').value, qs('#pl-currency-filter').value);
                return;
            }
        }
        renderParties(partyRows);
    }
    function summaryHtml(summary, currency) {
        var c = currency || summary.currency;
        return [
            ['Total Customer Invoice', summary.total_customer_invoice],
            ['Total Vendor Invoice', summary.total_vendor_invoice],
            ['Total Payment In', summary.total_payment_in],
            ['Total Payment Out', summary.total_payment_out],
            ['Net Balance', summary.net_balance],
        ].map(function (x) {
            return '<div class="pl-kv"><span>' + esc(x[0]) + '</span><strong>' + esc(money(x[1], c)) + '</strong></div>';
        }).join('');
    }
    function partySummaryHtml(party, summary) {
        var c = summary.currency || party.currency;
        return [
            ['Party Name', party.party_name],
            ['Contact', party.party_phone || '-'],
            ['Email', party.party_email || '-'],
            ['Opening Balance', money(summary.opening_balance, c)],
            ['Current Balance', money(summary.closing_balance, c)],
        ].map(function (x) {
            return '<div class="pl-kv"><span>' + esc(x[0]) + '</span><strong>' + esc(x[1]) + '</strong></div>';
        }).join('');
    }
    function renderRows(rows, party, currency) {
        ledgerRows = rows || [];
        var c = currency || (party && party.currency) || currentCurrency;
        var tb = qs('#pl-ledger-rows');
        if (!rows.length) {
            tb.innerHTML = '<tr><td colspan="9">No ledger transactions yet.</td></tr>';
            return;
        }
        tb.innerHTML = rows.map(function (r) {
            var locked = !!r.is_locked;
            return '<tr>' +
                '<td>' + esc(r.invoice_period) + (locked ? ' <span class="pl-chip pl-chip--closed">closed</span>' : '') + '</td>' +
                '<td>' + esc(r.customer_invoice_no || '') + '</td>' +
                '<td>' + esc(money(r.customer_invoice_value, c)) + '</td>' +
                '<td>' + esc(r.vendor_invoice_no || '') + '</td>' +
                '<td>' + esc(money(r.vendor_invoice_value, c)) + '</td>' +
                '<td>' + esc(money(r.payment_in, c)) + '</td>' +
                '<td>' + esc(r.payment_in_date || '') + '</td>' +
                '<td>' + esc(money(r.payment_out, c)) + '</td>' +
                '<td>' + esc(r.payment_out_date || '') + '</td>' +
                '<td><strong>' + esc(money(r.running_balance, c)) + '</strong></td>' +
                '<td><div class="pl-row-actions">' +
                (locked ? '<span class="pl-muted">Read only</span>' :
                    '<button type="button" class="btn btn-secondary btn-sm" data-edit="' + esc(r.id) + '">Edit</button>' +
                    '<button type="button" class="btn btn-danger btn-sm" data-del="' + esc(r.id) + '">Delete</button>') +
                '</div></td>' +
            '</tr>';
        }).join('');
    }
    function renderMonths(months, currency) {
        var box = qs('#pl-months');
        var arr = months || [];
        if (!box) return;
        box.innerHTML = arr.map(function (m) {
            var closed = m.status === 'closed';
            var badge = '<span class="pl-chip ' + (closed ? 'pl-chip--closed' : 'pl-chip--open') + '">' + esc(closed ? 'closed' : 'open') + '</span>';
            var actions = '';
            if (closed && B.is_admin) {
                actions = ' <button type="button" class="btn btn-secondary btn-sm" data-reopen="' + esc(m.period_month) + '">Reopen</button>';
            } else if (!closed) {
                actions = ' <button type="button" class="btn btn-secondary btn-sm" data-close-month="' + esc(m.period_month) + '">Close</button>';
            }
            return '<div role="tab" tabindex="0" aria-selected="false" class="pl-period-card" data-period="' + esc(m.period_month) + '">' +
                '<strong>' + esc(m.period_month) + '</strong>' +
                '<div class="pl-period-meta">' + esc(money(m.opening_balance, currency)) + ' → ' + esc(money(m.closing_balance, currency)) + '</div>' +
                badge + actions +
            '</div>';
        }).join('') || '<span class="pl-muted">No months yet.</span>';

        // wire up click handlers (client-side presentation) without changing backend
        box.querySelectorAll('.pl-period-card').forEach(function (el) {
            el.addEventListener('click', function () {
                box.querySelectorAll('.pl-period-card.active').forEach(function (a) { a.classList.remove('active'); a.setAttribute('aria-selected','false'); });
                el.classList.add('active'); el.setAttribute('aria-selected','true');
                var period = el.getAttribute('data-period');
                var sel = qs('#pl-selected-period'); if (sel) { sel.style.display = 'block'; sel.innerHTML = '<strong>Selected: ' + esc(period) + '</strong><div class="pl-muted">' + esc(el.querySelector('.pl-period-meta').textContent) + '</div>'; }
                // client-side filter of ledger rows to show only selected period
                Array.from(qs('#pl-ledger-rows').children).forEach(function (r) {
                    var ip = r.cells && r.cells[0] ? r.cells[0].textContent.trim() : '';
                    r.style.display = (ip === period) ? '' : 'none';
                });
            });
            el.addEventListener('keydown', function (ev) { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); el.click(); } });
        });
    }
    async function openLedger(partyId, currency) {
        currentPartyId = Number(partyId || 0);
        currentCurrency = currency || '';
        var j = await api({ action: 'detail', party_id: currentPartyId, currency: currentCurrency, from: qs('#pl-st-from').value, to: qs('#pl-st-to').value });
        if (!j) return;
        var ledgerCurrency = j.currency || currentCurrency || j.party.currency;
        qs('#pl-drawer').hidden = false;
        qs('#pl-ledger-title').textContent = j.party.party_name + (currentCurrency ? ' - ' + currentCurrency + ' Ledger' : '');
        qs('#pl-ledger-contact').textContent = (j.party.party_email || '-') + ' | ' + (j.party.party_phone || '-');
        var branchAddr = qs('#pl-bank-branch-address');
        if (branchAddr) {
            branchAddr.textContent = j.party.bank_branch_address ? 'Branch: ' + esc(j.party.bank_branch_address) : '';
            branchAddr.style.display = j.party.bank_branch_address ? '' : 'none';
        }
        // populate compact header items; keep totals in lower summary
        qs('#pl-summary').innerHTML = summaryHtml(j.summary, ledgerCurrency);
        var hc = qs('#pl-header-compact');
        if (hc) {
            hc.innerHTML = '';
            function addItem(label, value) {
                var d = document.createElement('div'); d.className = 'ph-item';
                d.innerHTML = '<div class="label">' + esc(label) + '</div><div class="value">' + esc(value) + '</div>';
                hc.appendChild(d);
            }
            addItem('Party', j.party.party_name || '-');
            addItem('Contact', j.party.party_phone || '-');
            addItem('Email', j.party.party_email || '-');
            addItem('Opening', money(j.summary.opening_balance, ledgerCurrency));
            addItem('Current', money(j.summary.closing_balance, ledgerCurrency));
        }
        renderRows(j.rows || [], j.party, ledgerCurrency);
        renderMonths(j.months || [], ledgerCurrency);
        resetForm();
        qs('#pl-form').elements.party_account_id.value = String(currentPartyId);
    }
    function populateCurrencyDisplay() {
        var currencyDisplay = qs('#pl-form-currency-display');
        var currencyText = qs('#pl-form-currency-text');
        var currencyInput = qs('#pl-form-currency-value');
        if (!currencyDisplay || !currencyText || !currencyInput) return;

        if (currentCurrency) {
            currencyDisplay.hidden = false;
            currencyText.textContent = currentCurrency;
            currencyInput.value = currentCurrency;
        } else {
            currencyDisplay.hidden = true;
            currencyInput.value = '';
        }
    }
    function resetForm() {
        var f = qs('#pl-form');
        f.reset();
        f.elements.id.value = '';
        f.elements.party_account_id.value = currentPartyId ? String(currentPartyId) : '';
        var today = new Date();
        var ym = today.toISOString().slice(0, 7);
        f.elements.invoice_period.value = ym;
        populateCurrencyDisplay();
    }
    function readForm() {
        var f = qs('#pl-form');
        return {
            id: f.elements.id.value,
            party_account_id: f.elements.party_account_id.value,
            currency: qs('#pl-form-currency-value').value || currentCurrency,
            invoice_period: f.elements.invoice_period.value,
            customer_invoice_no: f.elements.customer_invoice_no.value.trim(),
            customer_invoice_value: f.elements.customer_invoice_value.value.trim(),
            vendor_invoice_no: f.elements.vendor_invoice_no.value.trim(),
            vendor_invoice_value: f.elements.vendor_invoice_value.value.trim(),
            payment_in: f.elements.payment_in.value.trim(),
            payment_in_date: f.elements.payment_in_date.value.trim(),
            payment_out: f.elements.payment_out.value.trim(),
            payment_out_date: f.elements.payment_out_date.value.trim(),
            notes: f.elements.notes.value.trim(),
            ledger_currency: currentCurrency || '',
        };
    }
    function exportUrl(format) {
        var p = new URLSearchParams({
            party_id: String(currentPartyId),
            from: qs('#pl-st-from').value,
            to: qs('#pl-st-to').value,
            format: format,
        });
        if (currentCurrency) p.set('currency', currentCurrency);
        return B.endpoints.export + '?' + p.toString();
    }

    document.addEventListener('DOMContentLoaded', function () {
        var initialParty = new URLSearchParams(window.location.search).get('party_id') || '';
        var initialCurrency = new URLSearchParams(window.location.search).get('currency') || '';
        if (initialParty) qs('#pl-party-filter').dataset.initial = initialParty;
        if (initialCurrency) qs('#pl-currency-filter').dataset.initial = initialCurrency;
        loadParties();
        qs('#pl-apply').onclick = loadParties;
        qs('#pl-reset').onclick = function () {
            qs('#pl-party-filter').value = '';
            qs('#pl-currency-filter').value = '';
            qs('#pl-from').value = '';
            qs('#pl-to').value = '';
            qs('#pl-balance-type').value = '';
            loadParties();
        };
        qs('#pl-close').onclick = function () { qs('#pl-drawer').hidden = true; currentCurrency = ''; loadParties(); };
        qs('#pl-form-reset').onclick = resetForm;
        qs('#pl-export-excel').onclick = function () { window.open(exportUrl('excel'), '_blank'); };
        qs('#pl-export-pdf').onclick = function () { window.open(exportUrl('pdf'), '_blank'); };
        qs('#pl-st-from').onchange = function () { if (currentPartyId) openLedger(currentPartyId, currentCurrency); };
        qs('#pl-st-to').onchange = function () { if (currentPartyId) openLedger(currentPartyId, currentCurrency); };
        document.body.addEventListener('click', async function (ev) {
            var view = ev.target.closest('[data-view-ledger]');
            if (view) openLedger(view.getAttribute('data-view-ledger'), view.getAttribute('data-ledger-currency') || '');
            var edit = ev.target.closest('[data-edit]');
            if (edit) {
                var id = edit.getAttribute('data-edit');
                var data = ledgerRows.find(function (row) { return String(row.id) === String(id); });
                if (!data) return;
                var f = qs('#pl-form');
                ['id', 'party_account_id', 'invoice_period', 'customer_invoice_no',
                    'customer_invoice_value', 'vendor_invoice_no', 'vendor_invoice_value', 'payment_in',
                    'payment_in_date', 'payment_out', 'payment_out_date', 'notes'].forEach(function (name) {
                    if (f.elements[name]) f.elements[name].value = data[name] == null ? '' : String(data[name]);
                });
                if (f.elements['currency']) f.elements['currency'].value = data.currency;
                f.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            var del = ev.target.closest('[data-del]');
            if (del && window.confirm('Delete this ledger transaction?')) {
                var j = await api({ action: 'delete', id: del.getAttribute('data-del'), currency: currentCurrency, expected_currency: currentCurrency });
                if (j) { toast('Deleted', 'ok'); openLedger(currentPartyId, currentCurrency); }
            }
            var close = ev.target.closest('[data-close-month]');
            if (close && window.confirm('Close this month? Transactions will become read-only.')) {
                var jc = await api({ action: 'close_month', party_id: currentPartyId, currency: currentCurrency, period: close.getAttribute('data-close-month') });
                if (jc) { toast('Month closed', 'ok'); openLedger(currentPartyId, currentCurrency); }
            }
            var reopen = ev.target.closest('[data-reopen]');
            if (reopen && window.confirm('Reopen this month?')) {
                var jr = await api({ action: 'reopen_month', party_id: currentPartyId, currency: currentCurrency, period: reopen.getAttribute('data-reopen') });
                if (jr) { toast('Month reopened', 'ok'); openLedger(currentPartyId, currentCurrency); }
            }
        });
        qs('#pl-form').addEventListener('submit', async function (ev) {
            ev.preventDefault();
            var btn = qs('#pl-form').querySelector('button[type="submit"]');
            if (btn && btn.disabled) return;

            var payload = readForm();

            if (!payload.invoice_period) {
                toast('Invoice Period is required.', 'e');
                return;
            }
            var hasValue = payload.customer_invoice_value || payload.vendor_invoice_value ||
                payload.payment_in || payload.payment_out;
            if (!hasValue) {
                toast('Enter at least one amount (Customer/Vendor Invoice Value, Payment In, or Payment Out).', 'e');
                return;
            }

            if (btn) {
                btn.disabled = true;
                btn.dataset.label = btn.textContent;
                btn.textContent = 'Saving…';
            }
            try {
                var j = await api({ action: 'save', payload: payload, expected_currency: payload.ledger_currency || '' });
                if (!j) {
                    if (btn) { btn.disabled = false; btn.textContent = btn.dataset.label; }
                    return;
                }
                toast('Transaction saved', 'ok');
                await openLedger(currentPartyId, currentCurrency);
                if (btn) { btn.disabled = false; btn.textContent = btn.dataset.label; }
            } catch (e) {
                if (btn) { btn.disabled = false; btn.textContent = btn.dataset.label; }
            }
        });

        // Arrow controls for period navigator
        (function () {
            var left = qs('.pl-period-left');
            var right = qs('.pl-period-right');
            var box = qs('#pl-months');
            if (!left || !right || !box) return;
            var amt = 220;
            left.addEventListener('click', function () { box.scrollBy({ left: -amt, behavior: 'smooth' }); });
            right.addEventListener('click', function () { box.scrollBy({ left: amt, behavior: 'smooth' }); });
            function update() {
                if (box.scrollWidth > box.clientWidth) { left.style.display = 'inline-flex'; right.style.display = 'inline-flex'; } else { left.style.display = 'none'; right.style.display = 'none'; }
            }
            update(); window.addEventListener('resize', update);
        })();
    });
})();
