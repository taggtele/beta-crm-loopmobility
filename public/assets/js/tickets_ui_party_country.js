(function () {
    'use strict';

    function debounce(fn, wait) {
        var t = null;
        return function () {
            var ctx = this;
            var args = arguments;
            window.clearTimeout(t);
            t = window.setTimeout(function () {
                fn.apply(ctx, args);
            }, wait);
        };
    }

    function findVendorSelect(form) {
        if (!form) {
            return null;
        }
        return form.querySelector('select[name="assigned_vendor_id"]');
    }

    function findCustomerEmailInput(form) {
        if (!form) {
            return null;
        }
        return form.querySelector('[data-ticket-customer-email-input]');
    }

    function findCountryInput(wrap) {
        if (!wrap) {
            return null;
        }
        var form = wrap.closest('form');
        if (!form) {
            return null;
        }
        return form.querySelector('[data-ticket-country-input]');
    }

    function applyVendorPartyConflict(vendorSel, partyHidden) {
        if (!vendorSel || !partyHidden) {
            return;
        }
        var pid = parseInt(partyHidden.value, 10) || 0;
        var vid = parseInt(vendorSel.value, 10) || 0;
        if (pid > 0 && vid > 0 && pid === vid) {
            vendorSel.setCustomValidity(
                'Customer party and assigned vendor cannot be the same organisation. Pick a different vendor.'
            );
        } else {
            vendorSel.setCustomValidity('');
        }
    }

    function bindVendorWatch(form, partyHidden) {
        var vendorSel = findVendorSelect(form);
        if (!vendorSel || !partyHidden) {
            return;
        }
        vendorSel.addEventListener('change', function () {
            applyVendorPartyConflict(vendorSel, partyHidden);
        });
        applyVendorPartyConflict(vendorSel, partyHidden);
    }

    function renderResults(listEl, items, onPick) {
        listEl.innerHTML = '';
        items.forEach(function (row) {
            var li = document.createElement('li');
            li.setAttribute('role', 'option');
            li.tabIndex = -1;
            var name = document.createElement('div');
            name.textContent = row.name || '';
            li.appendChild(name);
            var metaParts = [];
            if (row.primary_email) {
                metaParts.push(row.primary_email);
            }
            if (row.country) {
                metaParts.push(row.country);
            }
            if (metaParts.length) {
                var meta = document.createElement('small');
                meta.className = 'party-search-meta';
                meta.textContent = metaParts.join(' · ');
                li.appendChild(meta);
            }
            li.addEventListener('mousedown', function (e) {
                e.preventDefault();
                onPick(row);
            });
            listEl.appendChild(li);
        });
    }

    function hideResults(listEl, input) {
        listEl.setAttribute('hidden', 'hidden');
        if (input) {
            input.setAttribute('aria-expanded', 'false');
        }
    }

    function showResults(listEl, input) {
        listEl.removeAttribute('hidden');
        if (input) {
            input.setAttribute('aria-expanded', 'true');
        }
    }

    function initCombo(wrap) {
        var searchUrl = wrap.getAttribute('data-party-search-url');
        if (!searchUrl) {
            return;
        }

        var input = wrap.querySelector('[data-party-combo-input]');
        var hidden = wrap.querySelector('[data-party-combo-id]');
        var listEl = wrap.querySelector('[data-party-combo-results]');
        var form = wrap.closest('form');
        if (!input || !hidden || !listEl) {
            return;
        }

        var countryInput = findCountryInput(wrap);
        var customerEmailInput = findCustomerEmailInput(form);
        var vendorSel = findVendorSelect(form);

        bindVendorWatch(form, hidden);

        if (form) {
            form.addEventListener('submit', function (e) {
                if (vendorSel && hidden) {
                    applyVendorPartyConflict(vendorSel, hidden);
                }
                if (!hidden.value || parseInt(hidden.value, 10) <= 0) {
                    input.setCustomValidity('Choose a customer from the party search results.');
                } else {
                    input.setCustomValidity('');
                }
                if (form.id === 'ticket-create-modal-form') {
                    return;
                }
                if (!form.checkValidity()) {
                    e.preventDefault();
                    form.reportValidity();
                }
            });
        }

        var lastController = null;

        function pickRow(row) {
            hidden.value = String(row.id);
            input.value = row.name || '';
            input.setAttribute('data-selected-party-label', input.value);
            input.setCustomValidity('');
            hideResults(listEl, input);
            if (countryInput && row.country && String(countryInput.value).trim() === '') {
                countryInput.value = row.country;
            }
            if (customerEmailInput && row.primary_email) {
                customerEmailInput.value = String(row.primary_email).trim();
            }
            if (vendorSel) {
                applyVendorPartyConflict(vendorSel, hidden);
            }
        }

        function clearSelectionIfEdited() {
            var selected = input.getAttribute('data-selected-party-label') || '';
            if (input.value.trim() !== selected.trim()) {
                hidden.value = '';
                input.setAttribute('data-selected-party-label', '');
            }
        }

        var runSearch = debounce(function () {
            clearSelectionIfEdited();
            var q = input.value.trim();
            if (q.length < 1) {
                hideResults(listEl, input);
                listEl.innerHTML = '';
                return;
            }
            if (lastController) {
                lastController.abort();
            }
            lastController = new AbortController();
            var u = searchUrl + (searchUrl.indexOf('?') >= 0 ? '&' : '?') + 'q=' + encodeURIComponent(q);
            fetch(u, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal: lastController.signal
            })
                .then(function (res) {
                    return res.json();
                })
                .then(function (data) {
                    var rows = (data && data.results) || [];
                    if (!rows.length) {
                        listEl.innerHTML = '';
                        hideResults(listEl, input);
                        return;
                    }
                    renderResults(listEl, rows, pickRow);
                    showResults(listEl, input);
                })
                .catch(function () {
                    /* aborted or network */
                });
        }, 200);

        input.addEventListener('input', function () {
            runSearch();
        });

        input.addEventListener('focus', function () {
            if (listEl.children.length) {
                showResults(listEl, input);
            }
        });

        input.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                hideResults(listEl, input);
            }
        });

        document.addEventListener('click', function (e) {
            if (!wrap.contains(e.target)) {
                hideResults(listEl, input);
            }
        });

        if (hidden.value && parseInt(hidden.value, 10) > 0) {
            input.setAttribute('data-selected-party-label', input.value);
        }
    }

    function countryListValues(datalist) {
        var out = [];
        var seen = {};
        if (!datalist) {
            return out;
        }
        datalist.querySelectorAll('option').forEach(function (opt) {
            if (opt.getAttribute('data-country-custom-suggestion') === '1') {
                return;
            }
            var v = String(opt.value || '').trim();
            if (!v) {
                return;
            }
            var key = v.toLowerCase();
            if (!seen[key]) {
                seen[key] = true;
                out.push(v);
            }
        });
        return out;
    }

    function countryMatchesList(value, datalist) {
        var needle = String(value || '').trim().toLowerCase();
        if (!needle) {
            return true;
        }
        return countryListValues(datalist).some(function (opt) {
            return opt.toLowerCase() === needle;
        });
    }

    function initCountryInput(input) {
        var listId = input.getAttribute('list');
        if (!listId) {
            return;
        }
        var datalist = document.getElementById(listId);
        if (!datalist) {
            return;
        }

        var customOpt = null;

        function syncCustomSuggestion() {
            var val = String(input.value || '').trim();
            if (!val || countryMatchesList(val, datalist)) {
                if (customOpt) {
                    customOpt.remove();
                    customOpt = null;
                }
                input.removeAttribute('data-country-custom-active');
                return;
            }
            if (!customOpt) {
                customOpt = document.createElement('option');
                customOpt.setAttribute('data-country-custom-suggestion', '1');
                datalist.appendChild(customOpt);
            }
            customOpt.value = val;
            customOpt.label = 'Use "' + val + '"';
            input.setAttribute('data-country-custom-active', '1');
        }

        input.addEventListener('input', syncCustomSuggestion);
        input.addEventListener('blur', syncCustomSuggestion);
    }

    function boot() {
        document.querySelectorAll('[data-party-combo-wrap]').forEach(initCombo);
        document.querySelectorAll('[data-ticket-country-input]').forEach(initCountryInput);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
