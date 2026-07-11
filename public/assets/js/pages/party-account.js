(function () {
    var B = window.PartyAccountBootstrap;
    var R = document.getElementById('pa-app');
    if (!B || !R || !B.endpoints) return;

    var T = B.csrf;
    var SORT = {
        key: (B.defaults && B.defaults.sort && B.defaults.sort.key) || 'updated_at',
        dir: (B.defaults && B.defaults.sort && B.defaults.sort.dir) || 'desc',
    };
    var CAN_MANAGE = !!(B.permissions && B.permissions.can_manage);
    var TABLE_COLS = CAN_MANAGE ? 8 : 7;
    var page = 1;
    var perPg = +(document.getElementById('pa-page-size') || {}).value || 25;
    var editId = null;
    var viewRecordId = null;
    var viewMode = false;
    var sel = new Map();
    var searchTimer = null;

    function qs(s, e) { return (e || document).querySelector(s); }
    function qsa(s, e) { return [].slice.call((e || document).querySelectorAll(s)); }

    function requireManage() {
        if (CAN_MANAGE) return true;
        toast('View-only access. Changes are not allowed.', 'e');
        return false;
    }

    function toast(m, k) {
        var h = document.getElementById('pa-toasts');
        if (!h) return;
        var d = document.createElement('div');
        d.className = 'pa-toast' + (k === 'e' ? ' pa-toast--error' : '') + (k === 'ok' ? ' pa-toast--success' : '');
        d.textContent = m;
        h.appendChild(d);
        setTimeout(function () { d.remove(); }, 3400);
    }

    function paConfirm(cfg) {
        cfg = cfg || {};
        var root = qs('#pa-confirm-dialog');
        var titleEl = qs('#pa-confirm-title');
        var msgEl = qs('#pa-confirm-message');
        var okBtn = qs('#pa-confirm-ok');
        var cancelBtn = qs('#pa-confirm-cancel');
        var backdrop = qs('#pa-confirm-backdrop');
        var fallback = (cfg.title ? cfg.title + '\n\n' : '') + (cfg.message || 'Are you sure?');
        if (!root || !okBtn || !cancelBtn) {
            return Promise.resolve(window.confirm(fallback));
        }
        return new Promise(function (resolve) {
            if (titleEl) titleEl.textContent = cfg.title || 'Confirm';
            if (msgEl) msgEl.textContent = cfg.message || 'Are you sure?';
            okBtn.textContent = cfg.confirmLabel || 'Confirm';
            okBtn.className = 'btn ' + (cfg.danger ? 'btn-danger' : 'btn-primary');
            root.hidden = false;
            root.setAttribute('aria-hidden', 'false');
            function finish(ok) {
                root.hidden = true;
                root.setAttribute('aria-hidden', 'true');
                document.removeEventListener('keydown', onKey);
                okBtn.onclick = null;
                cancelBtn.onclick = null;
                if (backdrop) backdrop.onclick = null;
                resolve(!!ok);
            }
            function onKey(ev) {
                if (ev.key === 'Escape') finish(false);
            }
            cancelBtn.onclick = function () { finish(false); };
            okBtn.onclick = function () { finish(true); };
            if (backdrop) backdrop.onclick = function () { finish(false); };
            document.addEventListener('keydown', onKey);
            cancelBtn.focus();
        });
    }

    function esc(t) {
        return String(t ?? '').replace(/[&<>'"]/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]);
        });
    }

    function fm(n, c) {
        var x = +n;
        if (!isFinite(x)) return '-';
        var u = c && String(c).length === 3 ? String(c) : 'USD';
        try {
            return new Intl.NumberFormat(void 0, { style: 'currency', currency: u, minimumFractionDigits: 2 }).format(x);
        } catch (e) {
            return x.toFixed(2) + ' ' + u;
        }
    }

    function mask(v) {
        var d = String(v || '').replace(/\D+/g, '');
        return d ? '\u2022\u2022\u2022\u2022 ' + d.slice(-4) : '-';
    }

    var PHONE_BY_NAME = {};
    var PHONE_BY_DIAL = [];
    (B.phone_countries || []).forEach(function (c) {
        PHONE_BY_NAME[c.name] = c;
        PHONE_BY_DIAL.push(c);
    });
    PHONE_BY_DIAL.sort(function (a, b) { return String(b.dial).length - String(a.dial).length; });

    function phoneMeta(country) {
        return PHONE_BY_NAME[String(country || '').trim()] || null;
    }

    function flagUrl(isoOrCountry, width) {
        var meta = phoneMeta(isoOrCountry);
        var code = meta ? meta.iso : String(isoOrCountry || '');
        code = code.toLowerCase().replace(/[^a-z]/g, '');
        if (code.length !== 2) return '';
        return 'https://flagcdn.com/w' + (width || 40) + '/' + code + '.png';
    }

    function flagImgHtml(iso, w, h) {
        var url = flagUrl(iso, w || 40);
        if (!url) return '';
        return '<img class="pa-flag-img" src="' + esc(url) + '" alt="" width="' + (w ? Math.round(w * 0.6) : 24) +
            '" height="' + (h || 18) + '" loading="lazy" decoding="async"/>';
    }

    function setFlagImg(el, iso) {
        if (!el) return;
        var url = flagUrl(iso, 40);
        if (!url) {
            el.hidden = true;
            el.removeAttribute('src');
            return;
        }
        el.src = url;
        el.alt = String(iso || '') + ' flag';
        el.hidden = false;
    }

    function resolveCountryValue(name) {
        var n = String(name || '').trim();
        if (!n) return '';
        if (PHONE_BY_NAME[n]) return n;
        var lower = n.toLowerCase();
        if (lower === 'uae') return 'United Arab Emirates';
        if (lower === 'usa' || lower === 'us') return 'United States';
        if (lower === 'uk' || lower === 'gb') return 'United Kingdom';
        var keys = Object.keys(PHONE_BY_NAME);
        for (var i = 0; i < keys.length; i++) {
            if (keys[i].toLowerCase() === lower) return keys[i];
        }
        return n;
    }

    function syncCountryFlags() {
        var sel = qs('#pa-form-country');
        var meta = sel && sel.value ? phoneMeta(sel.value) : null;
        setFlagImg(qs('#pa-country-flag-preview'), meta ? meta.iso : '');
        syncPhonePrefix();
    }

    function syncPhonePrefix() {
        var sel = qs('#pa-form-country');
        var meta = sel && sel.value ? phoneMeta(sel.value) : null;
        var flagEl = qs('#pa-phone-flag');
        var dialEl = qs('#pa-phone-dial');
        var hint = qs('#pa-phone-hint');
        var phone = qs('#pa-form-phone');
        if (!dialEl) return;
        if (!meta) {
            setFlagImg(flagEl, '');
            dialEl.textContent = '+';
            if (hint) hint.textContent = 'Select country first, then enter digits without country code.';
            if (phone) phone.disabled = true;
            return;
        }
        setFlagImg(flagEl, meta.iso);
        dialEl.textContent = '+' + meta.dial;
        if (hint) {
            hint.textContent = 'Enter ' + meta.min + (meta.min !== meta.max ? '\u2013' + meta.max : '') +
                ' digits for ' + meta.name + ' (without +' + meta.dial + ').';
        }
        if (phone) phone.disabled = viewMode;
    }

    function syncOpeningBalancePreview() {
        var amountEl = qs('#pa-form-opening-balance');
        var typeEl = qs('#pa-form-opening-balance-type');
        var currencyEl = qs('#pa-form-currency');
        var row = qs('.pa-opening-balance-row');
        if (!amountEl || !typeEl || !row) return;
        var hint = qs('#pa-opening-balance-preview');
        if (!hint) {
            hint = document.createElement('small');
            hint.id = 'pa-opening-balance-preview';
            hint.className = 'pa-opening-balance-preview pa-field__hint';
            row.insertAdjacentElement('afterend', hint);
        }
        var raw = amountEl.value.replace(/,/g, '').trim();
        if (!raw || isNaN(Number(raw))) {
            hint.hidden = true;
            hint.textContent = '';
            return;
        }
        var amount = Math.abs(Number(raw));
        var signed = typeEl.value === 'payable' ? -amount : amount;
        hint.hidden = false;
        hint.textContent = 'Ledger opening balance: ' + fm(signed, (currencyEl && currencyEl.value) || 'INR');
        hint.classList.toggle('pa-opening-balance-preview--payable', signed < 0);
    }

    function setModalMode(mode) {
        var modal = qs('#pa-profile-modal');
        var form = qs('#pa-account-form');
        var view = qs('#pa-profile-view');
        var submit = qs('#pa-form-submit');
        var editBtn = qs('#pa-view-edit');
        var cancel = qs('#pa-modal-cancel');
        var sub = qs('#pa-modal-subtitle');
        var isView = mode === 'view';
        if (modal) modal.classList.toggle('pa-modal--view', isView);
        if (form) form.hidden = isView;
        if (view) view.hidden = !isView;
        if (submit) submit.hidden = isView;
        if (editBtn) editBtn.hidden = !isView || !viewRecordId || !CAN_MANAGE;
        if (cancel) cancel.textContent = isView ? 'Close' : 'Cancel';
        if (sub) sub.hidden = isView;
        var leAdd = qs('#pa-loop-entity-add');
        var leBtn = qs('#pa-btn-add-loop-entity');
        if (isView) {
            toggleLoopEntityAdd(false);
            if (leAdd) leAdd.hidden = true;
            if (leBtn) leBtn.hidden = true;
        } else {
            if (leBtn) leBtn.hidden = false;
        }
    }

    function loopEntityOptionLabel(le) {
        return le.name + (le.code ? ' / ' + le.code : '');
    }

    function populateLoopEntitySelects(rows, selectFormId) {
        ['#pa-filter-entity', '#pa-form-loop_entity'].forEach(function (sid) {
            var s = qs(sid);
            if (!s) return;
            var prev = s.value;
            qsa('option[data-z]', s).forEach(function (o) { o.remove(); });
            (rows || []).forEach(function (le) {
                var o = document.createElement('option');
                o.value = le.id;
                o.setAttribute('data-z', '1');
                o.textContent = loopEntityOptionLabel(le);
                s.appendChild(o);
            });
            if (selectFormId && sid === '#pa-form-loop_entity') {
                s.value = String(selectFormId);
            } else if (prev) {
                s.value = prev;
            }
        });
    }

    function showLoopEntityError(msg) {
        var el = qs('#pa-loop-entity-error');
        if (!el) return;
        if (!msg) {
            el.hidden = true;
            el.textContent = '';
            return;
        }
        el.textContent = msg;
        el.hidden = false;
    }

    function toggleLoopEntityAdd(show) {
        var panel = qs('#pa-loop-entity-add');
        var btn = qs('#pa-btn-add-loop-entity');
        if (!panel) return;
        if (show) {
            panel.removeAttribute('hidden');
            panel.hidden = false;
            if (btn) btn.setAttribute('aria-expanded', 'true');
            var nameIn = qs('#pa-loop-entity-name');
            if (nameIn) nameIn.focus();
        } else {
            panel.setAttribute('hidden', 'hidden');
            panel.hidden = true;
            if (btn) btn.setAttribute('aria-expanded', 'false');
            var n = qs('#pa-loop-entity-name');
            var c = qs('#pa-loop-entity-code');
            if (n) n.value = '';
            if (c) c.value = '';
            showLoopEntityError('');
        }
    }

    async function loadLoopEntities(selectFormId) {
        try {
            var r = await fetch(B.endpoints.entities, {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            var j = JSON.parse(await r.text());
            if (j.csrf_token) T = j.csrf_token;
            populateLoopEntitySelects(j.rows || [], selectFormId);
            return j.rows || [];
        } catch (e) {
            toast('Branch list load failed', 'e');
            return [];
        }
    }

    async function saveLoopEntityBranch() {
        if (!requireManage()) return;
        var nameIn = qs('#pa-loop-entity-name');
        var codeIn = qs('#pa-loop-entity-code');
        var name = nameIn ? nameIn.value.trim() : '';
        if (!name) {
            showLoopEntityError('Enter a branch name.');
            return;
        }
        showLoopEntityError('');
        var res = await api(B.endpoints.entities, {
            action: 'create',
            payload: { name: name, code: codeIn && codeIn.value.trim() ? codeIn.value.trim() : null, status: 'active' },
        });
        if (!res) return;
        var newId = res.id || (res.row && res.row.id);
        await loadLoopEntities(newId);
        toggleLoopEntityAdd(false);
        toast('Branch added and selected', 'ok');
    }

    function setModalCopy(mode, partyName) {
        var title = qs('#pa-modal-title');
        var sub = qs('#pa-modal-subtitle');
        if (mode === 'create') {
            if (title) title.textContent = 'New party account';
            if (sub) sub.textContent = 'Required: party name, country. Pick or add a company branch below.';
        } else if (mode === 'edit') {
            if (title) title.textContent = partyName ? 'Edit: ' + partyName : 'Edit party account';
            if (sub) sub.textContent = 'Changes are saved to the activity timeline.';
        } else if (mode === 'view') {
            if (title) title.textContent = partyName || 'Party account';
        }
    }

    function parsePhoneForForm(phone, country) {
        var meta = phoneMeta(country);
        var digits = String(phone || '').replace(/\D+/g, '');
        if (!meta) return { national: digits, full: phone ? String(phone).trim() : '' };
        if (!digits) return { national: '', full: '' };
        var national = digits;
        if (digits.indexOf(meta.dial) === 0) national = digits.slice(meta.dial.length);
        national = national.replace(/^0+/, '');
        return { national: national, full: national ? '+' + meta.dial + national : '' };
    }

    function guessCountryFromPhone(phone) {
        var digits = String(phone || '').replace(/\D+/g, '');
        if (!digits) return '';
        var i;
        for (i = 0; i < PHONE_BY_DIAL.length; i++) {
            var c = PHONE_BY_DIAL[i];
            if (digits.indexOf(c.dial) === 0) return c.name;
        }
        return '';
    }

    function showPhoneError(msg) {
        var errEl = qs('#pa-phone-error');
        var input = qs('#pa-form-phone');
        if (errEl) {
            errEl.textContent = msg;
            errEl.hidden = !msg;
        }
        if (input) input.classList.toggle('pa-input-invalid', !!msg);
        return !msg;
    }

    function validatePhoneClient() {
        var country = qs('#pa-form-country') && qs('#pa-form-country').value;
        var input = qs('#pa-form-phone');
        if (!input) return true;
        var phone = input.value.trim();
        if (!phone) return showPhoneError('');
        if (!country) return showPhoneError('Select a country before entering a phone number.');
        var meta = phoneMeta(country);
        if (!meta) return showPhoneError('Unsupported country for phone validation.');
        var parsed = parsePhoneForForm(phone, country);
        var national = parsed.national;
        if (!national || !/^\d+$/.test(national)) return showPhoneError('Enter a valid phone number (digits only).');
        if (national.length < meta.min || national.length > meta.max) {
            return showPhoneError('For ' + meta.name + ', enter ' + meta.min + '\u2013' + meta.max + ' digits after +' + meta.dial + '.');
        }
        if (meta.iso === 'IN' && national.charAt(0) < '6') {
            return showPhoneError('Indian mobile numbers must start with 6\u20139.');
        }
        return showPhoneError('');
    }

    async function api(u, p) {
        var r = await fetch(u, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json;charset=UTF-8',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(Object.assign({}, p || {}, { csrf_token: T })),
        });
        var j = {};
        try { j = JSON.parse(await r.text()); } catch (e) { toast('Bad JSON', 'e'); return null; }
        if (j.csrf_token) T = j.csrf_token;
        if (!r.ok || j.ok === false) {
            if (j.validation && j.errors) {
                var k = Object.keys(j.errors);
                if (k.length) toast(k[0] + ': ' + j.errors[k[0]], 'e');
            } else if (r.status === 419 || j.error === 'csrf') {
                toast('Reload page (csrf)', 'e');
            } else if (j.error === 'party_account_db' || String(j.message || j.error || '').indexOf('party_accounts') >= 0) {
                toast(String(j.hint || j.message || 'DB mismatch ? check .env DB_NAME.'), 'e');
            } else {
                toast(String(j.message || j.error || 'Request failed'), 'e');
            }
            return null;
        }
        return j;
    }

    function fl() {
        return {
            scope: (qs('#pa-scope') && qs('#pa-scope').value) || 'live',
            search: (qs('#pa-search') && qs('#pa-search').value.trim()) || '',
            status: (qs('#pa-filter-status') && qs('#pa-filter-status').value) || '',
            loop_entity_id: (qs('#pa-filter-entity') && qs('#pa-filter-entity').value) || '',
            country: (qs('#pa-filter-country') && qs('#pa-filter-country').value.trim()) || '',
            currency: (qs('#pa-filter-currency') && qs('#pa-filter-currency').value) || '',
            created_from: (qs('#pa-filter-created-from') && qs('#pa-filter-created-from').value) || '',
            created_to: (qs('#pa-filter-created-to') && qs('#pa-filter-created-to').value) || '',
        };
    }

    function sp(on) {
        var o = qs('#pa-table-loading-overlay');
        if (o) o.hidden = !on;
    }

    function kpi(S, P) {
        var by = (S && S.by_status) || {};
        var fx = fl().currency || 'USD';
        function W(slot, val) {
            var n = R.querySelector('.pa-stat-card[data-kpi-slot="' + slot + '"] .pa-kpi-value');
            if (n) n.textContent = val;
        }
        try {
            W('credit', fm(S && S.company_net_total, fx.length === 3 ? fx : 'USD'));
        } catch (e) {
            W('credit', String((S && S.company_net_total) || ''));
        }
        var t = P ? P.total : (S && S.total_rows);
        W('rows', t != null ? String(t) : '-');
        W('status_active', String(by.active || 0));
        var hint = qs('#pa-stat-filtered-hint');
        if (hint) {
            var f = fl();
            var filtered = !!(f.search || f.status || f.loop_entity_id || f.country || f.currency || f.created_from || f.created_to);
            hint.hidden = !filtered;
            if (filtered) hint.textContent = 'filters on';
        }
        R.setAttribute('data-loaded', 'true');
    }

    function paging(p) {
        var h = qs('#pa-page-buttons');
        var inf = qs('#pa-page-range');
        if (!h || !p) return;
        h.innerHTML = '';
        var tot = p.total || 0;
        var pp = p.per_page || perPg;
        var pgs = Math.max(1, p.pages || Math.ceil(tot / Math.max(pp, 1)));
        var lo = tot ? (p.page - 1) * pp + 1 : 0;
        var upt = Math.min(p.page * pp, tot);
        if (inf) inf.textContent = tot ? 'Showing ' + lo + '\u2013' + upt + ' of ' + tot : 'No rows';

        function btn(tx, pg, dis) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'pagination-btn';
            b.textContent = tx;
            b.disabled = !!dis;
            if (!dis && pg) b.addEventListener('click', function () { page = pg; refresh(0); });
            h.appendChild(b);
        }
        btn('Prev', Math.max(1, p.page - 1), p.page <= 1);
        btn('Next', Math.min(pgs, p.page + 1), p.page >= pgs);
        var spn = document.createElement('span');
        spn.className = 'pagination-gap';
        spn.textContent = 'Page ' + p.page + ' of ' + pgs;
        h.appendChild(spn);
    }

    function currentScope() {
        return (qs('#pa-scope') && qs('#pa-scope').value) || 'live';
    }

    /** IDs from checked rows (Map.keys() is an iterator — slice.call does not work). */
    function selectedIds() {
        var ids = [];
        qsa('#pa-table-body .pa-row-check:checked').forEach(function (c) {
            var tr = c.closest('tr');
            var id = tr && parseInt(tr.dataset.pid, 10);
            if (id > 0) ids.push(id);
        });
        if (!ids.length) {
            sel.forEach(function (_row, key) {
                var id = parseInt(String(key), 10);
                if (id > 0) ids.push(id);
            });
        }
        return ids;
    }

    function bsync() {
        var domCount = qsa('#pa-table-body .pa-row-check:checked').length;
        var n = Math.max(sel.size, domCount);
        var t = document.querySelector('[data-bulk-visible]');
        if (t) {
            t.hidden = n === 0;
            if (n === 0) t.setAttribute('hidden', 'hidden');
            else t.removeAttribute('hidden');
        }
        if (qs('#pa-bulk-count')) qs('#pa-bulk-count').textContent = String(n);
        var scope = currentScope();
        var archBtn = qs('#pa-bulk-archive');
        var restBtn = qs('#pa-bulk-restore');
        if (archBtn) {
            archBtn.hidden = scope === 'deleted';
            archBtn.disabled = n === 0 || scope === 'deleted';
        }
        if (restBtn) {
            restBtn.hidden = scope === 'live';
            restBtn.disabled = n === 0 || scope === 'live';
        }
    }

    function hideModal() {
        var ov = qs('#pa-modal-overlay');
        var m = qs('#pa-profile-modal');
        if (ov) { ov.setAttribute('hidden', 'hidden'); ov.hidden = true; ov.onclick = null; }
        if (m) { m.setAttribute('hidden', 'hidden'); m.hidden = true; }
        document.body.classList.remove('pa-modal-open');
        viewMode = false;
        viewRecordId = null;
        setModalMode('edit');
        var view = qs('#pa-profile-view');
        if (view) view.innerHTML = '';
    }

    function showModal() {
        var ov = qs('#pa-modal-overlay');
        var m = qs('#pa-profile-modal');
        if (ov) { ov.removeAttribute('hidden'); ov.hidden = false; ov.onclick = hideModal; }
        if (m) { m.removeAttribute('hidden'); m.hidden = false; }
        document.body.classList.add('pa-modal-open');
    }

    function partyInitials(name) {
        var parts = String(name || '').trim().split(/\s+/).filter(Boolean);
        if (!parts.length) return '?';
        if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
        return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
    }

    function avatarHtml(name, status) {
        var st = String(status || 'draft').toLowerCase().replace(/[^a-z]/g, '') || 'draft';
        return '<span class="pa-avatar pa-avatar--' + st + '" aria-hidden="true">' + esc(partyInitials(name)) + '</span>';
    }

    function cellLine(html, muted) {
        return '<div class="pa-cell-line' + (muted ? ' pa-cell-line--muted' : '') + '">' + html + '</div>';
    }

    function cellStack(lines) {
        return '<div class="pa-cell-stack">' + lines.join('') + '</div>';
    }

    function formatTableDate(iso) {
        if (!iso) return '-';
        var s = String(iso);
        var d = s.slice(0, 10);
        try {
            var dt = new Date(s.replace(' ', 'T'));
            if (!isNaN(dt.getTime())) {
                return dt.toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' });
            }
        } catch (e) { /* ignore */ }
        return d;
    }

    function truncateText(t, max) {
        var s = String(t || '');
        if (s.length <= max) return esc(s);
        return esc(s.slice(0, max - 1)) + '\u2026';
    }

    function currencyPill(cur) {
        return '<span class="pa-pill pa-pill-currency">' + esc(cur || 'INR') + '</span>';
    }
    function isMultiCurrency(x) {
        return x.is_multi_currency === true || x.is_multi_currency === 1 || String(x.is_multi_currency) === '1';
    }

    function entityPill(name) {
        if (!name) return '';
        return '<span class="pa-pill pa-pill-entity" title="Loop entity">' + esc(name) + '</span>';
    }


    function statusChip(status) {
        var s = esc(status || 'draft');
        return '<span class="pa-chip pa-chip-' + s + '">' + s + '</span>';
    }
    function wire(tr, w) {
        tr.dataset.pid = String(w.id);
        var chk = qs('input.pa-row-check', tr);
        if (chk) {
            chk.addEventListener('change', function () {
                var id = String(w.id);
                if (chk.checked) sel.set(id, w);
                else sel.delete(id);
                bsync();
            });
            chk.addEventListener('click', function (ev) {
                ev.stopPropagation();
            });
        }
        qsa('[data-pa-action]', tr).forEach(function (btn) {
            btn.onclick = async function (ev) {
                ev.preventDefault();
                var a = btn.getAttribute('data-pa-action');
                if (a === 'view') openD(w.id);
                else if (a === 'edit') openU(w.id);
                else if (a === 'archive') {
                    if (!requireManage()) return;
                    var label = w.party_name || ('Account #' + w.id);
                    if (!(await paConfirm({
                        title: 'Archive party account?',
                        message: 'Archive "' + label + '"? You can restore it later from the Archived scope.',
                        confirmLabel: 'Archive',
                        danger: true,
                    }))) return;
                    if (await api(B.endpoints.account, { action: 'delete', id: w.id })) {
                        toast('Archived', 'ok');
                        refresh(0);
                    }
                } else if (a === 'restore') {
                    if (!requireManage()) return;
                    var rlabel = w.party_name || ('Account #' + w.id);
                    if (!(await paConfirm({
                        title: 'Restore party account?',
                        message: 'Restore "' + rlabel + '" to live accounts?',
                        confirmLabel: 'Restore',
                        danger: false,
                    }))) return;
                    if (await api(B.endpoints.account, { action: 'restore', id: w.id })) {
                        toast('Restored', 'ok');
                        refresh(0);
                    }
                }
            };
        });
    }

    function row(tb, x) {
        var tr = document.createElement('tr');
        var dz = !!x.deleted_at;
        if (dz) tr.classList.add('pa-row-archived');
        var meta = phoneMeta(x.country);
        var countryLabel = esc(x.country || '-');
        var countryHtml = meta ? flagImgHtml(meta.iso, 40) + '<span>' + countryLabel + '</span>' : countryLabel;
        var addressLine = x.address ? cellLine(truncateText(x.address, 42), true) : '';
        var currencyCount = isMultiCurrency(x) ? (x.currency_ledgers ? x.currency_ledgers.length : 0) : 1;
        var currencyCountHtml = isMultiCurrency(x) ? cellLine('Currencies: ' + esc(String(currencyCount)), true) : '';
        var partyCell = cellStack([
            '<div class="pa-party-cell">' + avatarHtml(x.party_name, x.status) +
            '<div class="pa-party-cell__body">' +
            cellLine('<strong class="pa-party-name">' + esc(x.party_name) + '</strong>') +
            cellLine('<span class="pa-party-id">#' + esc(String(x.id || '')) + '</span> ' +
                statusChip(x.status) + (dz ? ' <span class="pa-chip pa-chip-deleted">archived</span>' : ''), true) +
            currencyCountHtml +
            (x.loop_entity_name ? cellLine(entityPill(x.loop_entity_name), true) : '') +
            '</div></div>',
        ]);
        var emailHtml = x.party_email
            ? '<a class="pa-cell-link" href="mailto:' + esc(x.party_email) + '">' + esc(x.party_email) + '</a>'
            : '-';
        if (x.extra_email_count > 0) {
            emailHtml += ' <span class="pa-muted">+' + esc(String(x.extra_email_count)) + '</span>';
        }
        var phoneHtml = x.party_phone
            ? (meta ? flagImgHtml(meta.iso, 40) : '') + '<span class="pa-mono">' + esc(x.party_phone) + '</span>'
            : '-';
        var contactCell = cellStack([
            cellLine('<span class="pa-cell-icon" aria-hidden="true">@</span>' + emailHtml),
            cellLine('<span class="pa-cell-icon" aria-hidden="true">Tel</span> ' + phoneHtml, true),
        ]);
        var locationCell = cellStack([cellLine('<span class="pa-country-cell">' + countryHtml + '</span>'), addressLine]);
        var creditTxt = fm(x.credit_limit, x.currency);
        var financeCell = cellStack([
            cellLine('<strong>' + esc(x.bank_name || '-') + '</strong>'),
            cellLine('<span class="pa-mono">' + esc(mask(x.account_number)) + '</span>' +
                (x.account_holder_name ? ' <span class="pa-muted">\u00b7 ' + truncateText(x.account_holder_name, 24) + '</span>' : ''), true),
            cellLine('<span class="pa-credit-val">' + esc(creditTxt) + '</span> ' + currencyPill(x.currency)),
        ]);
        var termsCell = x.payment_terms ? cellStack([cellLine(truncateText(x.payment_terms, 36))]) : cellStack([cellLine('-', true)]);
        var updatedCell = cellStack([
            cellLine('<strong>' + esc(formatTableDate(x.updated_at)) + '</strong>'),
            cellLine('Created ' + esc(formatTableDate(x.created_at)), true),
        ]);
        var actions = '<button type="button" class="pa-link-btn" data-pa-action="view">View</button>';
        if (CAN_MANAGE) {
            if (isMultiCurrency(x) && x.currency_ledgers && x.currency_ledgers.length > 0) {
                x.currency_ledgers.forEach(function (cl) {
                    actions += '<a class="pa-link-btn" href="' + esc(B.endpoints.ledger_page) + '?party_id=' + esc(x.id) + '&currency=' + esc(cl.currency) + '">' + esc(cl.currency) + ' Ledger</a>';
                });
            } else if (!isMultiCurrency(x)) {
                actions += (B.endpoints.ledger_page ? '<a class="pa-link-btn" href="' + esc(B.endpoints.ledger_page) + '?party_id=' + esc(x.id) + '">[Ledger]</a>' : '');
            }
            actions +=
                '<button type="button" class="pa-icon-btn" data-pa-action="edit" title="Edit" aria-label="Edit">Edit</button>' +
                (dz
                    ? '<button type="button" class="pa-icon-btn pa-icon-btn--restore" data-pa-action="restore" title="Restore" aria-label="Restore">R</button>'
                    : '<button type="button" class="pa-icon-btn pa-icon-btn--danger" data-pa-action="archive" title="Archive" aria-label="Archive">X</button>');
        }

        tr.innerHTML =
            (CAN_MANAGE ? '<td class="pa-col-check pa-col-sticky-left"><input type="checkbox" class="pa-row-check" aria-label="Select row"></td>' : '') +
            '<td class="pa-col-party">' + partyCell + '</td>' +
            '<td class="pa-col-contact">' + contactCell + '</td>' +
            '<td class="pa-col-location">' + locationCell + '</td>' +
            '<td class="pa-col-finance">' + financeCell + '</td>' +
            '<td class="pa-col-terms">' + termsCell + '</td>' +
            '<td class="pa-col-date">' + updatedCell + '</td>' +
            '<td class="pa-col-actions pa-col-sticky-right"><div class="pa-row-actions">' + actions + '</div></td>';

        tb.appendChild(tr);
        wire(tr, x);
    }

    async function hydrate() {
        await loadLoopEntities();
    }

    async function refresh(st) {
        sp(!!st);
        var res = await api(B.endpoints.datatable, { filters: fl(), sort: SORT, page: page, per_page: perPg });
        sp(false);
        if (!res) return;
        var tb = qs('#pa-table-body');
        tb.innerHTML = '';
        (res.rows || []).forEach(function (x) { row(tb, x); });
        if (!res.rows || !res.rows.length) {
            tb.innerHTML =
                '<tr><td colspan="' + TABLE_COLS + '" class="pa-empty muted">' +
                '<p class="pa-empty__title">No party accounts found</p>' +
                '<p class="pa-empty__text">Adjust your search or filters, or create the first finance profile.</p>' +
                '<button type="button" class="btn btn-primary btn-sm" id="pa-empty-create">+ Add party account</button></td></tr>';
        }
        kpi(res.summary, res.pagination || {});
        paging(res.pagination || { total: 0, page: 1, pages: 1, per_page: perPg });
        var sa = qs('#pa-select-all');
        if (sa) { sa.checked = false; sa.indeterminate = false; }
        sel.clear();
        bsync();
    }

    function applyPhoneFields(country, phone) {
        var countrySel = qs('#pa-form-country');
        var phoneInput = qs('#pa-form-phone');
        var resolved = resolveCountryValue(country);
        if (!resolved && phone) resolved = guessCountryFromPhone(phone);
        if (countrySel) countrySel.value = resolved && phoneMeta(resolved) ? resolved : (resolved || '');
        var parsed = parsePhoneForForm(phone || '', countrySel && countrySel.value ? countrySel.value : resolved);
        if (phoneInput) phoneInput.value = parsed.national;
        syncCountryFlags();
        showPhoneError('');
    }


    function viewRow(label, value, raw) {
        if (!raw && (value === '' || value === '-' || value == null)) return '';
        return '<div class="pa-view-row"><dt>' + esc(label) + '</dt><dd>' + (raw || esc(value)) + '</dd></div>';
    }

    function formatPhoneDisplay(phone, country) {
        if (!phone) return '-';
        var meta = phoneMeta(country);
        var parsed = parsePhoneForForm(phone, country);
        var flag = meta ? flagImgHtml(meta.iso, 40) : '';
        var display = parsed.full || phone;
        return flag + '<span class="pa-view-phone">' + esc(display) + '</span>';
    }

    function renderViewProfile(x, timeline) {
        var meta = phoneMeta(x.country);
        var lg = '';
        var tl = timeline || [];
        for (var i = 0; i < Math.min(tl.length, 30); i++) {
            var g = tl[i];
            lg += '<li><time>' + esc(String(g.created_at || '').slice(0, 16).replace('T', ' ')) + '</time>' +
                '<div><strong>' + esc(g.action || '') + '</strong> <span class="pa-muted">' + esc(g.actor_name || '') + '</span></div>' +
                '<p>' + esc(g.summary || '') + '</p></li>';
        }
        var countryHtml = meta
            ? flagImgHtml(meta.iso, 40) + '<span>' + esc(meta.name) + '</span>'
            : esc(x.country || '-');

var isMulti = isMultiCurrency(x);
        var currencySection = '';
        if (isMulti && x.currency_ledgers && x.currency_ledgers.length > 0) {
            var ledgersHtml = x.currency_ledgers.map(function (cl) {
                var ob = cl.opening_balance != null ? fm(cl.opening_balance, cl.currency) : '—';
                var typeLabel = cl.opening_balance_type === 'receivable' ? ' (Receivable)' : cl.opening_balance_type === 'payable' ? ' (Payable)' : '';
                return '<div class="pa-view-currency-item">' +
                    '<div class="pa-view-currency-header">' +
                    '<strong>' + esc(cl.currency) + '</strong>' +
                    '<a class="pa-view-ledger-link" href="' + esc(B.endpoints.ledger_page) + '?party_id=' + esc(x.id) + '&currency=' + esc(cl.currency) + '">Open Ledger</a>' +
                    '</div>' +
                    '<div class="pa-view-currency-balance">' + ob + typeLabel + '</div>' +
                    '</div>';
            }).join('');
            currencySection = '<section class="pa-view-card"><h4>Currency Ledgers</h4><div class="pa-view-currencies">' + ledgersHtml + '</div></section>';
        }

        return (
            '<div class="pa-view-hero">' +
            '<div class="pa-view-hero__top">' +
            (meta ? flagImgHtml(meta.iso, 80) : '') +
            '<div class="pa-view-hero__titles">' +
            '<h3 class="pa-view-name">' + esc(x.party_name || '') + '</h3>' +
            '<p class="pa-view-sub">' + esc(x.loop_entity_name || 'No loop entity') + '</p>' +
            '</div>' +
            statusChip(x.status) +
            (x.deleted_at ? '<span class="pa-chip pa-chip-deleted">archived</span>' : '') +
            '</div>' +
            '<p class="pa-view-meta pa-muted">Updated ' + esc(String(x.updated_at || x.created_at || '').slice(0, 16).replace('T', ' ')) + '</p>' +
            '</div>' +
            '<div class="pa-view-sections">' +
            '<section class="pa-view-card"><h4>Contact</h4><dl class="pa-view-dl">' +
            viewRow('Email', '-', formatEmailsView(x)) +
            viewRow('Phone', '', formatPhoneDisplay(x.party_phone, x.country)) +
            viewRow('Country', '', countryHtml) +
            viewRow('Address', x.address || '-') +
            viewRow('Payment terms', x.payment_terms || '-') +
            viewRow('Assistant manager', x.assistant_manager_name || '-') +
            viewRow('Business manager', x.business_manager_name || '-') +
            '</dl></section>' +
            '<section class="pa-view-card"><h4>Bank &amp; limits</h4><dl class="pa-view-dl">' +
            viewRow('Bank', x.bank_name || '-') +
            viewRow('Account holder', x.account_holder_name || '-') +
            viewRow('Account no.', mask(x.account_number)) +
            viewRow('IFSC / SWIFT', x.ifsc_swift_code || '-') +
            viewRow('IBAN', x.iban_number || '-') +
            (x.bank_branch_address ? viewRow('Bank Branch Address', x.bank_branch_address) : '') +
            viewRow('Credit limit', fm(x.credit_limit, x.currency)) +
            (isMulti ? '' : viewRow('Currency', x.currency || '-')) +
            (isMulti ? '' : (x.opening_balance != null && x.opening_balance !== ''
                ? viewRow('Opening balance', fm(x.opening_balance, x.currency) +
                    (x.opening_balance_type === 'receivable'
                        ? ' (Receivable)'
                        : x.opening_balance_type === 'payable' ? ' (Payable)' : ''))
                : '')) +
            '</dl></section>' +
            currencySection +
            '</div>' +
            (x.notes ? '<section class="pa-view-card pa-view-card--full"><h4>Notes</h4><p class="pa-view-notes">' + esc(x.notes) + '</p></section>' : '') +
            '<section class="pa-view-card pa-view-card--full"><h4>Activity</h4><ul class="pa-view-timeline">' +
            (lg || '<li class="pa-muted">No activity logged yet.</li>') +
            '</ul></section>'
        );
    }

    function clearExtraEmails() {
        var list = qs('#pa-extra-emails-list');
        if (list) list.innerHTML = '';
    }

    function addExtraEmailRow(value) {
        var list = qs('#pa-extra-emails-list');
        if (!list || !CAN_MANAGE) return;
        var row = document.createElement('div');
        row.className = 'pa-extra-email-row';
        var input = document.createElement('input');
        input.type = 'email';
        input.className = 'pa-extra-email-input';
        input.setAttribute('autocomplete', 'email');
        input.placeholder = 'Additional email address';
        input.value = value == null ? '' : String(value);
        var removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'btn btn-ghost btn-sm pa-extra-email-remove';
        removeBtn.setAttribute('aria-label', 'Remove email');
        removeBtn.textContent = 'Remove';
        row.appendChild(input);
        row.appendChild(removeBtn);
        list.appendChild(row);
    }

    function formatEmailsView(x) {
        var parts = [];
        if (x.party_email) {
            parts.push(
                '<a class="pa-cell-link" href="mailto:' + esc(x.party_email) + '">' + esc(x.party_email) + '</a>' +
                ' <span class="pa-muted">(primary)</span>'
            );
        }
        (x.additional_emails || []).forEach(function (em) {
            if (!em) return;
            parts.push('<a class="pa-cell-link" href="mailto:' + esc(em) + '">' + esc(em) + '</a>');
        });
        if (!parts.length && !x.party_email) return '-';

        return parts.join('<br>');
    }

function fill(x) {
        editId = x.id;
        var f = qs('#pa-account-form');
        clearExtraEmails();
        (x.additional_emails || []).forEach(function (em) { addExtraEmailRow(em); });
        ['party_name', 'party_email', 'address', 'bank_name', 'account_holder_name',
            'account_number', 'ifsc_swift_code', 'iban_number', 'bank_branch_address', 'credit_limit', 'opening_balance',
            'payment_terms', 'notes', 'assistant_manager_name', 'business_manager_name'].forEach(function (nm) {
            var el = f.elements.namedItem(nm);
            if (el) el.value = x[nm] == null ? '' : String(x[nm]);
        });
        applyPhoneFields(x.country, x.party_phone);
        qs('#pa-form-loop_entity').value = x.loop_entity_id ? String(x.loop_entity_id) : '';
        qs('#pa-form-currency').value = x.currency || 'INR';
        var obType = qs('#pa-form-opening-balance-type');
        if (obType) obType.value = x.opening_balance_type || '';
        var obAmount = qs('#pa-form-opening-balance');
        var obTypeEl = qs('#pa-form-opening-balance-type');
        if (obAmount) obAmount.disabled = true;
        if (obTypeEl) obTypeEl.disabled = true;
        syncOpeningBalancePreview();
        qs('#pa-form-status').value = x.status || 'draft';

        var multiCurrency = qs('#pa-form-multi-currency');
        if (multiCurrency) {
            multiCurrency.checked = isMultiCurrency(x);
            var panel = qs('#pa-multi-currency-panel');
            if (panel) {
                if (isMultiCurrency(x)) {
                    var ledgers = x.currency_ledgers || [];
                    currencyLedgers = ledgers.map(function (cl) {
                        return {
                            currency: cl.currency,
                            opening_balance: cl.opening_balance || '',
                            opening_balance_type: cl.opening_balance_type || ''
                        };
                    });
                    renderCurrencyLedgers();
                    panel.hidden = false;
                } else {
                    panel.hidden = true;
                    currencyLedgers = [];
                    renderCurrencyLedgers();
                }
            }
        }

        setModalCopy('edit', x.party_name);
    }

    function blank() {
        editId = null;
        clearExtraEmails();
        qs('#pa-account-form').reset();
        var def = B.default_country || 'India';
        if (qs('#pa-form-country') && phoneMeta(def)) qs('#pa-form-country').value = def;
        var obAmount = qs('#pa-form-opening-balance');
        var obType = qs('#pa-form-opening-balance-type');
        if (obAmount) obAmount.disabled = false;
        if (obType) obType.disabled = false;
        syncOpeningBalancePreview();
        applyPhoneFields(def, '');
        toggleLoopEntityAdd(false);
        currencyLedgers = [];
        renderCurrencyLedgers();
        var multiCurrency = qs('#pa-form-multi-currency');
        if (multiCurrency) {
            multiCurrency.checked = false;
            var panel = qs('#pa-multi-currency-panel');
            if (panel) panel.hidden = true;
        }
        setModalCopy('create');
    }


    async function openD(id) {
        var r = await api(B.endpoints.account, { action: 'detail', id: id, include_deleted: true });
        if (!r || !r.record) return;
        var x = r.record;
        x.currency_ledgers = r.currency_ledgers || [];
        currencyLedgers = x.currency_ledgers;
        viewMode = true;
        viewRecordId = x.deleted_at ? null : x.id;
        var view = qs('#pa-profile-view');
        if (view) view.innerHTML = renderViewProfile(x, r.timeline);
        setModalCopy('view', x.party_name);
        setModalMode('view');
        showModal();
    }

    async function openU(pid) {
        if (!pid) {
            if (!requireManage()) return;
        } else if (!CAN_MANAGE) {
            return openD(pid);
        }
        viewMode = false;
        viewRecordId = null;
        setModalMode('edit');
        if (!pid) {
            blank();
            showModal();
            return;
        }
        var rr = await api(B.endpoints.account, { action: 'detail', id: pid, include_deleted: false });
        if (!rr || !rr.record || rr.record.deleted_at) {
            toast('Cannot edit archived row', 'e');
            return;
        }
        rr.record.currency_ledgers = rr.currency_ledgers || [];
        currencyLedgers = rr.record.currency_ledgers;
        fill(rr.record);
        renderCurrencyLedgers();
        showModal();
    }

    var currencySymbols = B.currency_symbols || {};

    var currencyLedgers = [];

    function formatCurrencySymbol(cur) {
        return currencySymbols[cur] || cur;
    }

    function isMultiCurrency(x) {
        return !!(x.is_multi_currency == 1 || x.is_multi_currency === true);
    }

    function renderCurrencyLedgers() {
        var tbody = qs('#pa-currency-ledgers-body');
        if (!tbody) return;
        tbody.innerHTML = '';
        currencyLedgers.forEach(function (ledger, idx) {
            var tr = document.createElement('tr');
            var currencyOptions = (B.currencies || []).map(function (c) {
                var sel = c === ledger.currency ? ' selected' : '';
                return '<option value="' + esc(c) + '"' + sel + '>' + esc(c) + '</option>';
            }).join('');
            tr.innerHTML =
                '<td><select class="pa-currency-select" data-idx="' + idx + '">' + currencyOptions + '</select></td>' +
                '<td><input type="decimal" class="pa-currency-opening" data-idx="' + idx + '" value="' + esc(String(ledger.opening_balance || '')) + '" placeholder="0.00"></td>' +
                '<td><select class="pa-currency-type" data-idx="' + idx + '"><option value="">—</option><option value="receivable" ' + (ledger.opening_balance_type === 'receivable' ? 'selected' : '') + '>Receivable</option><option value="payable" ' + (ledger.opening_balance_type === 'payable' ? 'selected' : '') + '>Payable</option></select></td>' +
                '<td><button type="button" class="btn btn-ghost btn-sm pa-remove-currency" data-idx="' + idx + '">Remove</button></td>';
            tbody.appendChild(tr);
        });
    }

    function addCurrencyLedger() {
        var available = (B.currencies || []).filter(function (c) {
            return !currencyLedgers.some(function (l) { return l.currency === c; });
        });
        if (available.length === 0) {
            toast('No more currencies available', 'e');
            return;
        }
        currencyLedgers.push({ currency: available[0], opening_balance: '', opening_balance_type: '' });
        renderCurrencyLedgers();
    }

    function syncCurrencyLedgersForm() {
        var multiCurrency = qs('#pa-form-multi-currency');
        var panel = qs('#pa-multi-currency-panel');
        if (multiCurrency && panel) {
            multiCurrency.addEventListener('change', function () {
                panel.hidden = !this.checked;
                if (this.checked && currencyLedgers.length === 0) {
                    addCurrencyLedger();
                }
            });
            var addBtn = qs('#pa-add-currency');
            if (addBtn) {
                addBtn.addEventListener('click', function () {
                    addCurrencyLedger();
                });
            }
            var tbody = qs('#pa-currency-ledgers-body');
            if (tbody) {
                tbody.addEventListener('input', function (e) {
                    var idx = e.target.getAttribute('data-idx');
                    if (e.target.classList.contains('pa-currency-opening')) {
                        currencyLedgers[idx].opening_balance = e.target.value;
                    }
                });
                tbody.addEventListener('change', function (e) {
                    var idx = e.target.getAttribute('data-idx');
                    if (e.target.classList.contains('pa-currency-type')) {
                        currencyLedgers[idx].opening_balance_type = e.target.value;
                    }
                    if (e.target.classList.contains('pa-currency-select')) {
                        currencyLedgers[idx].currency = e.target.value;
                    }
                });
            }
            var removeBtn = qs('#pa-currency-ledgers-body');
            if (removeBtn) {
                removeBtn.addEventListener('click', function (e) {
                    var btn = e.target.closest('.pa-remove-currency');
                    if (!btn) return;
                    var idx = parseInt(btn.getAttribute('data-idx'), 10);
                    currencyLedgers.splice(idx, 1);
                    renderCurrencyLedgers();
                });
            }
        }
    }

    function collectCurrencyLedgers() {
        var out = [];
        currencyLedgers.forEach(function (ledger) {
            if (ledger.currency) {
                out.push({
                    currency: ledger.currency,
                    opening_balance: ledger.opening_balance || null,
                    opening_balance_type: ledger.opening_balance_type || null,
                });
            }
        });
        return out;
    }

    function rdForm() {
        var f = qs('#pa-account-form');
        var out = {};
        ['party_name', 'party_email', 'address', 'bank_name', 'account_holder_name',
            'account_number', 'ifsc_swift_code', 'iban_number', 'bank_branch_address', 'credit_limit', 'opening_balance',
            'payment_terms', 'notes', 'assistant_manager_name', 'business_manager_name'].forEach(function (nm) {
            var el = f.elements.namedItem(nm);
            if (el && el.value.trim()) out[nm] = el.value.trim();
        });
        var obTypeEl = f.elements.namedItem('opening_balance_type');
        if (obTypeEl && obTypeEl.value) out.opening_balance_type = obTypeEl.value;
        var countryEl = f.elements.namedItem('country');
        var country = countryEl && countryEl.value ? countryEl.value.trim() : '';
        out.country = country;
        var phoneRaw = f.elements.namedItem('party_phone') && f.elements.namedItem('party_phone').value.trim();
        if (phoneRaw) {
            out.party_phone = parsePhoneForForm(phoneRaw, country).full || phoneRaw;
        }
        var le = f.elements.namedItem('loop_entity_id');
        var cr = f.elements.namedItem('currency');
        var st = f.elements.namedItem('status');
        out.loop_entity_id = le && le.value ? le.value : null;
        out.currency = (cr && cr.value) || 'INR';
        out.status = (st && st.value) || 'draft';
        out.party_name = f.elements.namedItem('party_name').value.trim();
        if (out.credit_limit) out.credit_limit = String(out.credit_limit).replace(/,/g, '');
        if (out.opening_balance) out.opening_balance = String(out.opening_balance).replace(/,/g, '');
        var extras = [];
        qsa('.pa-extra-email-input', f).forEach(function (el) {
            var v = el.value.trim();
            if (v) extras.push(v);
        });
        if (extras.length) out.additional_emails = extras;
        var multiCurrency = qs('#pa-form-multi-currency');
        if (multiCurrency && multiCurrency.checked) {
            out.is_multi_currency = true;
            out.currencies = collectCurrencyLedgers();
        }
        return out;
    }

    async function sv() {
        if (viewMode) { hideModal(); return; }
        if (!validatePhoneClient()) {
            toast(qs('#pa-phone-error').textContent || 'Fix phone number', 'e');
            return;
        }
        var p = rdForm();
        if (!p.party_name) { toast('Party name required', 'e'); return; }
        if (!p.country) { toast('Country is required', 'e'); return; }
        if (editId) {
            if (await api(B.endpoints.account, { action: 'update', id: editId, payload: p })) {
                toast('Saved', 'ok');
                hideModal();
                refresh(0);
            }
        } else if (await api(B.endpoints.account, { action: 'create', payload: p })) {
            toast('Created', 'ok');
            hideModal();
            page = 1;
            refresh(0);
        }
    }

    var importPreviewData = null;

    function hideImportModal() {
        var m = qs('#pa-import-modal');
        if (m) {
            m.hidden = true;
            m.setAttribute('aria-hidden', 'true');
        }
        importPreviewData = null;
        var runBtn = qs('#pa-import-run');
        if (runBtn) runBtn.disabled = true;
    }

    function showImportModal() {
        if (!requireManage()) return;
        var m = qs('#pa-import-modal');
        if (!m) return;
        m.hidden = false;
        m.setAttribute('aria-hidden', 'false');
        var prev = qs('#pa-import-preview');
        if (prev) prev.hidden = true;
        var file = qs('#pa-import-file');
        if (file) file.value = '';
        loadImportHistory();
    }

    async function loadImportHistory() {
        if (!B.endpoints.import) return;
        var list = qs('#pa-import-history-list');
        if (!list) return;
        try {
            var fd = new FormData();
            fd.append('action', 'history');
            fd.append('csrf_token', T);
            var r = await fetch(B.endpoints.import, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: fd,
            });
            var j = await r.json();
            if (!j.ok || !j.logs || !j.logs.length) {
                list.innerHTML = '<li>No imports yet.</li>';
                if (j.csrf_token) T = j.csrf_token;
                return;
            }
            if (j.csrf_token) T = j.csrf_token;
            list.innerHTML = j.logs.map(function (lg) {
                return '<li>' + esc(String(lg.created_at || '').slice(0, 16).replace('T', ' ')) +
                    ' — ' + esc(lg.filename || '') + ': ' +
                    esc(String(lg.success_count || 0)) + ' ok, ' +
                    esc(String(lg.skipped_count || 0)) + ' skipped, ' +
                    esc(String(lg.failed_count || 0)) + ' failed (' +
                    esc(lg.actor_name || '') + ')</li>';
            }).join('');
        } catch (e) {
            list.innerHTML = '<li>Could not load import history.</li>';
        }
    }

    function renderImportPreview(preview) {
        var box = qs('#pa-import-preview');
        var runBtn = qs('#pa-import-run');
        if (!box) return;
        importPreviewData = preview;
        var readyN = (preview.ready || []).length;
        var errN = (preview.errors || []).length;
        var dupN = (preview.duplicates || []).length;
        var html = '<div class="pa-import-stat">' +
            '<span><strong>' + esc(String(preview.total_rows || 0)) + '</strong> data rows</span>' +
            '<span><strong>' + readyN + '</strong> ready</span>' +
            '<span><strong>' + errN + '</strong> errors</span>' +
            '<span><strong>' + dupN + '</strong> duplicates</span></div>';
        if (errN) {
            html += '<p class="pa-muted">Fix errors in your file (row numbers below), then preview again.</p><ul>';
            (preview.errors || []).slice(0, 30).forEach(function (e) {
                html += '<li>Row ' + esc(String(e.row_number)) + ': ' + esc(e.message) + '</li>';
            });
            html += '</ul>';
        }
        if (dupN) {
            html += '<p><strong>Duplicates</strong> (skipped on import if option enabled):</p><ul>';
            (preview.duplicates || []).slice(0, 30).forEach(function (d) {
                html += '<li>Row ' + esc(String(d.row_number)) + ': ' + esc(d.message) + '</li>';
            });
            html += '</ul>';
        }
        box.innerHTML = html;
        box.hidden = false;
        if (runBtn) runBtn.disabled = readyN === 0;
    }

    function downloadImportTemplate() {
        if (!B.endpoints.import) return;
        var f = document.createElement('form');
        f.method = 'POST';
        f.action = B.endpoints.import;
        f.target = '_blank';
        var a = document.createElement('input');
        a.type = 'hidden';
        a.name = 'action';
        a.value = 'template';
        f.appendChild(a);
        var c = document.createElement('input');
        c.type = 'hidden';
        c.name = 'csrf_token';
        c.value = T;
        f.appendChild(c);
        document.body.appendChild(f);
        f.submit();
        f.remove();
    }

    async function importApiForm(action) {
        var fileInput = qs('#pa-import-file');
        if (!fileInput || !fileInput.files || !fileInput.files[0]) {
            toast('Choose a .csv or .xlsx file', 'e');
            return null;
        }
        var fd = new FormData();
        fd.append('action', action);
        fd.append('csrf_token', T);
        fd.append('file', fileInput.files[0]);
        if (action === 'import') {
            var skip = qs('#pa-import-skip-dup');
            fd.append('skip_duplicates', skip && skip.checked ? '1' : '0');
        }
        var r = await fetch(B.endpoints.import, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd,
        });
        var j = await r.json();
        if (j.csrf_token) T = j.csrf_token;
        if (!j.ok) {
            toast(j.message || j.error || 'Import failed', 'e');
            return null;
        }
        return j;
    }

    async function previewImport() {
        if (!requireManage()) return;
        var j = await importApiForm('preview');
        if (!j || !j.preview) return;
        renderImportPreview(j.preview);
    }

    async function runImport() {
        if (!requireManage()) return;
        if (!importPreviewData || !(importPreviewData.ready || []).length) {
            toast('Preview the file first', 'e');
            return;
        }
        var ok = await paConfirm({
            title: 'Import party accounts?',
            message: 'Import ' + (importPreviewData.ready || []).length + ' row(s)? Duplicates may be skipped per your setting.',
            confirmLabel: 'Import',
        });
        if (!ok) return;
        var j = await importApiForm('import');
        if (!j || !j.result) return;
        var res = j.result;
        toast(
            'Import done: ' + res.success_count + ' created, ' + res.skipped_count + ' skipped, ' + res.failed_count + ' failed',
            res.success_count > 0 ? 'ok' : 'e'
        );
        hideImportModal();
        page = 1;
        refresh(0);
    }

    async function expCsv() {
        var r = await fetch(B.endpoints.export, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ csrf_token: T, filters: fl() }),
        });
        var b = await r.blob();
        var disp = r.headers.get('Content-Disposition') || '';
        var nm = (disp.match(/filename="([^"]+)"/) || [])[1] || 'party-accounts.csv';
        var a = document.createElement('a');
        a.href = URL.createObjectURL(b);
        a.download = nm;
        a.click();
        URL.revokeObjectURL(a.href);
        toast('Exported', 'ok');
    }

    function toggleFilters() {
        var panel = qs('#pa-filters-panel');
        var btn = qs('#pa-btn-toggle-filter');
        if (!panel || !btn) return;
        var open = panel.hidden;
        if (open) panel.removeAttribute('hidden');
        else panel.setAttribute('hidden', 'hidden');
        panel.hidden = !open;
        btn.setAttribute('aria-expanded', String(open));
        btn.classList.toggle('is-open', open);
    }

    document.addEventListener('DOMContentLoaded', async function () {
        hideModal();

        (B.statuses || []).forEach(function (code) {
            var o = document.createElement('option');
            o.value = o.textContent = code;
            qs('#pa-form-status').appendChild(o);
        });
        (B.currencies || []).forEach(function (cv) {
            var o = document.createElement('option');
            o.value = o.textContent = cv;
            qs('#pa-form-currency').appendChild(o);
        });
        qs('#pa-page-size').addEventListener('change', async function () {
            perPg = +qs('#pa-page-size').value;
            page = 1;
            await refresh(0);
        });

        qsa('.pa-sort').forEach(function (b) {
            b.addEventListener('click', async function () {
                var th = b.closest('th');
                var ky = th && th.getAttribute('data-sort');
                if (!ky) return;
                if (SORT.key === ky) SORT.dir = SORT.dir === 'desc' ? 'asc' : 'desc';
                else { SORT.key = ky; SORT.dir = 'desc'; }
                page = 1;
                qsa('.pa-sort').forEach(function (x) { x.classList.remove('pa-sort-active'); });
                b.classList.add('pa-sort-active');
                await refresh(0);
            });
        });

        var filterBtn = qs('#pa-btn-toggle-filter');
        if (filterBtn) filterBtn.addEventListener('click', toggleFilters);

        var newBtn = qs('#pa-btn-new-account');
        if (newBtn) newBtn.onclick = function () { openU(0); };
        var exportBtn = qs('#pa-btn-export');
        if (exportBtn) exportBtn.onclick = function () { expCsv(); };
        var importBtn = qs('#pa-btn-import');
        if (importBtn) importBtn.onclick = function () { showImportModal(); };
        var importClose = qs('#pa-import-close');
        if (importClose) importClose.onclick = hideImportModal;
        var importCancel = qs('#pa-import-cancel');
        if (importCancel) importCancel.onclick = hideImportModal;
        var importBackdrop = qs('#pa-import-backdrop');
        if (importBackdrop) importBackdrop.onclick = hideImportModal;
        var importTpl = qs('#pa-import-download-template');
        if (importTpl) importTpl.onclick = downloadImportTemplate;
        var importPreviewBtn = qs('#pa-import-preview-btn');
        if (importPreviewBtn) importPreviewBtn.onclick = previewImport;
        var importRun = qs('#pa-import-run');
        if (importRun) importRun.onclick = runImport;
        qs('#pa-modal-close').onclick = hideModal;
        qs('#pa-modal-cancel').onclick = hideModal;
        qs('#pa-apply-filter').onclick = async function () { page = 1; await refresh(true); };
        qs('#pa-reset-filter').onclick = async function () {
            qs('#pa-search').value = '';
            qs('#pa-filter-status').value = '';
            qs('#pa-filter-country').value = '';
            qs('#pa-filter-currency').value = '';
            qs('#pa-filter-created-from').value = '';
            qs('#pa-filter-created-to').value = '';
            qs('#pa-filter-entity').value = '';
            qs('#pa-scope').value = 'live';
            page = 1;
            await refresh(true);
        };
        qs('#pa-form-submit').onclick = function () { if (requireManage()) sv(); };

        ['#pa-form-opening-balance', '#pa-form-opening-balance-type', '#pa-form-currency'].forEach(function (sid) {
            var el = qs(sid);
            if (!el) return;
            el.addEventListener('input', syncOpeningBalancePreview);
            el.addEventListener('change', syncOpeningBalancePreview);
        });

        var countrySel = qs('#pa-form-country');
        if (countrySel) {
            countrySel.addEventListener('change', function () {
                syncCountryFlags();
                validatePhoneClient();
            });
        }
        var viewEditBtn = qs('#pa-view-edit');
        if (viewEditBtn) {
            viewEditBtn.addEventListener('click', function () {
                if (viewRecordId) openU(viewRecordId);
            });
        }
        var phoneInput = qs('#pa-form-phone');
        if (phoneInput) {
            phoneInput.addEventListener('input', function () {
                phoneInput.value = phoneInput.value.replace(/[^\d\s\-()]/g, '');
            });
            phoneInput.addEventListener('blur', validatePhoneClient);
        }
        syncCountryFlags();
        syncCurrencyLedgersForm();

        var leToggle = qs('#pa-btn-add-loop-entity');
        if (leToggle) {
            leToggle.addEventListener('click', function () {
                var panel = qs('#pa-loop-entity-add');
                toggleLoopEntityAdd(panel && panel.hidden);
            });
        }
        var leSave = qs('#pa-loop-entity-save');
        if (leSave) leSave.addEventListener('click', saveLoopEntityBranch);
        var leCancel = qs('#pa-loop-entity-cancel');
        if (leCancel) leCancel.addEventListener('click', function () { toggleLoopEntityAdd(false); });

        var addEmailBtn = qs('#pa-btn-add-email');
        if (addEmailBtn) addEmailBtn.addEventListener('click', function () { addExtraEmailRow(''); });
        var emailList = qs('#pa-extra-emails-list');
        if (emailList) {
            emailList.addEventListener('click', function (ev) {
                var rm = ev.target.closest('.pa-extra-email-remove');
                if (!rm) return;
                var row = rm.closest('.pa-extra-email-row');
                if (row) row.remove();
            });
        }
        var leName = qs('#pa-loop-entity-name');
        if (leName) {
            leName.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter') {
                    ev.preventDefault();
                    saveLoopEntityBranch();
                }
            });
        }

        var searchEl = qs('#pa-search');
        if (searchEl) {
            searchEl.addEventListener('input', function () {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(async function () {
                    page = 1;
                    await refresh(0);
                }, 380);
            });
        }

        qs('#pa-scope').addEventListener('change', async function () {
            sel.clear();
            qsa('#pa-table-body .pa-row-check').forEach(function (c) { c.checked = false; });
            var sa0 = qs('#pa-select-all');
            if (sa0) { sa0.checked = false; sa0.indeterminate = false; }
            bsync();
            page = 1;
            await refresh(0);
        });

        var bulkArchiveBtn = qs('#pa-bulk-archive');
        if (bulkArchiveBtn) {
            bulkArchiveBtn.onclick = async function () {
                var ids = selectedIds();
                if (!ids.length) return toast('Select at least one row', 'e');
                if (currentScope() === 'deleted') return toast('Switch to Live accounts to archive', 'e');
                var n = ids.length;
                if (!(await paConfirm({
                    title: 'Archive selected accounts?',
                    message: 'Archive ' + n + ' party account' + (n === 1 ? '' : 's') + '? You can restore them from the Archived scope.',
                    confirmLabel: 'Archive ' + n,
                    danger: true,
                }))) return;
                var res = await api(B.endpoints.bulk, { action: 'bulk_delete', ids: ids });
                if (!res) return;
                var affected = res.affected != null ? Number(res.affected) : n;
                if (!affected) return toast('No rows archived (already archived or not found)', 'e');
                sel.clear();
                bsync();
                await refresh(0);
                toast(affected === 1 ? '1 account archived' : affected + ' accounts archived', 'ok');
            };
        }
        var bulkRestoreBtn = qs('#pa-bulk-restore');
        if (bulkRestoreBtn) {
            bulkRestoreBtn.onclick = async function () {
                var ids = selectedIds();
                if (!ids.length) return toast('Select at least one row', 'e');
                if (currentScope() === 'live') return toast('Switch to Archived only to restore', 'e');
                var n = ids.length;
                if (!(await paConfirm({
                    title: 'Restore selected accounts?',
                    message: 'Restore ' + n + ' party account' + (n === 1 ? '' : 's') + ' to live?',
                    confirmLabel: 'Restore ' + n,
                    danger: false,
                }))) return;
                var res = await api(B.endpoints.bulk, { action: 'bulk_restore', ids: ids });
                if (!res) return;
                var affected = res.affected != null ? Number(res.affected) : n;
                if (!affected) return toast('No rows restored (already live or not found)', 'e');
                sel.clear();
                bsync();
                await refresh(0);
                toast(affected === 1 ? '1 account restored' : affected + ' accounts restored', 'ok');
            };
        }
        var dismissBtn = qs('#pa-bulk-dismiss');
        if (dismissBtn) {
            dismissBtn.onclick = function () {
                sel.clear();
                qsa('#pa-table-body .pa-row-check').forEach(function (c) { c.checked = false; });
                var sa = qs('#pa-select-all');
                if (sa) { sa.checked = false; sa.indeterminate = false; }
                bsync();
            };
        }

        var selAll = qs('#pa-select-all');
        if (selAll && !CAN_MANAGE) {
            var th = selAll.closest('th');
            if (th) th.style.display = 'none';
        }

        if (selAll && CAN_MANAGE) selAll.addEventListener('change', function () {
            var on = selAll.checked;
            qsa('#pa-table-body .pa-row-check').forEach(function (c) {
                c.checked = on;
                var tr = c.closest('tr');
                var id = tr && tr.dataset.pid;
                if (id) {
                    if (on) sel.set(id, { id: Number(id) });
                    else sel.delete(id);
                }
            });
            bsync();
        });

        if (!CAN_MANAGE) bsync();

        document.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape') hideModal();
        });

        document.body.addEventListener('click', function (ev) {
            if (ev.target && ev.target.id === 'pa-empty-create') {
                ev.preventDefault();
                openU(0);
            }
        });

        bsync();
        await hydrate();
        await refresh(true);
    });
})();
