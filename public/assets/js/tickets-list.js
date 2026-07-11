(function () {
    'use strict';

    var dropdownCloseBound = false;

    function getCsrfToken(root) {
        return root ? (root.getAttribute('data-csrf') || '') : '';
    }

    function postTicketAction(root, payload) {
        var body = new FormData();
        Object.keys(payload).forEach(function (key) {
            body.append(key, payload[key]);
        });
        body.append('csrf_token', getCsrfToken(root));

        return fetch(window.location.pathname, {
            method: 'POST',
            body: body,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        }).then(function (response) {
            return response.json().then(function (data) {
                if (!response.ok || !data.success) {
                    var message = (data && data.message) ? data.message : 'Action failed.';
                    throw new Error(message);
                }
                return data;
            });
        });
    }

    function showToast(message, isError) {
        var existing = document.querySelector('.ticket-inline-toast');
        if (existing) {
            existing.remove();
        }

        var toast = document.createElement('div');
        toast.className = 'ticket-inline-toast' + (isError ? ' is-error' : '');
        toast.textContent = message;
        document.body.appendChild(toast);

        window.setTimeout(function () {
            toast.classList.add('is-visible');
        }, 10);

        window.setTimeout(function () {
            toast.classList.remove('is-visible');
            window.setTimeout(function () {
                toast.remove();
            }, 220);
        }, 2800);
    }

    function positionDropdownMenu(dropdown) {
        var menu = dropdown.querySelector('[data-dropdown-menu]');
        var trigger = dropdown.querySelector('[data-dropdown-trigger]');
        if (!menu || !trigger) {
            return;
        }

        var rect = trigger.getBoundingClientRect();
        menu.style.position = 'fixed';
        menu.style.top = Math.round(rect.bottom + 6) + 'px';
        menu.style.left = 'auto';
        menu.style.right = Math.round(window.innerWidth - rect.right) + 'px';
        menu.style.minWidth = '220px';
        menu.style.zIndex = '10050';
    }

    function resetDropdownMenu(dropdown) {
        var menu = dropdown.querySelector('[data-dropdown-menu]');
        if (!menu) {
            return;
        }

        menu.style.position = '';
        menu.style.top = '';
        menu.style.left = '';
        menu.style.right = '';
        menu.style.minWidth = '';
        menu.style.zIndex = '';
    }

    function closeDropdown(dropdown) {
        if (!dropdown) {
            return;
        }
        dropdown.classList.remove('open');
        resetDropdownMenu(dropdown);
        var trigger = dropdown.querySelector('[data-dropdown-trigger]');
        if (trigger) {
            trigger.setAttribute('aria-expanded', 'false');
        }
    }

    function closeAllTicketDropdowns(root, exceptDropdown) {
        if (!root) {
            return;
        }

        root.querySelectorAll('.table-menu.open').forEach(function (dropdown) {
            if (exceptDropdown && dropdown === exceptDropdown) {
                return;
            }
            closeDropdown(dropdown);
        });
    }

    function bindDropdownCloseHandler() {
        if (dropdownCloseBound) {
            return;
        }
        dropdownCloseBound = true;

        document.addEventListener('click', function (event) {
            var root = document.getElementById('ticket-list-root');
            if (!root || !root.contains(event.target)) {
                return;
            }

            var openDropdown = event.target.closest('.table-menu.open');
            if (openDropdown && openDropdown.contains(event.target)) {
                return;
            }

            closeAllTicketDropdowns(root);
        });

        window.addEventListener('resize', function () {
            var root = document.getElementById('ticket-list-root');
            if (!root) {
                return;
            }
            root.querySelectorAll('.table-menu.open').forEach(positionDropdownMenu);
        });

        var scrollWrap = document.querySelector('#ticket-list-root .ticket-list-scroll')
            || document.querySelector('#ticket-list-root .table-wrap');
        if (scrollWrap) {
            scrollWrap.addEventListener('scroll', function () {
                var root = document.getElementById('ticket-list-root');
                closeAllTicketDropdowns(root);
            });
        }
    }

    function findRow(el) {
        if (!el) {
            return null;
        }
        return el.closest('.ticket-card') || el.closest('tr');
    }

    function syncStatusBadge(badge, status) {
        if (!badge) {
            return;
        }
        var chipMap = {
            Open: 'status-open',
            'In-Progress': 'status-progress',
            Closed: 'status-closed'
        };
        var dotMap = {
            Open: 'dot-open',
            'In-Progress': 'dot-progress',
            Closed: 'dot-closed'
        };
        badge.className = 'ticket-status-chip ticket-status-chip-sm ' + (chipMap[status] || '');
        badge.setAttribute('data-ticket-status-badge', '');
        badge.innerHTML = '<span class="ticket-status-dot ' + (dotMap[status] || '') + '" aria-hidden="true"></span>' + status;
    }

    function formatRelativeTime(isoString) {
        if (!isoString) {
            return '';
        }
        var then = new Date(isoString.replace(' ', 'T'));
        if (Number.isNaN(then.getTime())) {
            return '';
        }
        var diffMs = Date.now() - then.getTime();
        if (diffMs < 0) {
            diffMs = 0;
        }
        var mins = Math.floor(diffMs / 60000);
        if (mins < 1) {
            return 'just now';
        }
        if (mins < 60) {
            return mins + 'm ago';
        }
        var hours = Math.floor(mins / 60);
        if (hours < 24) {
            return hours + 'h ago';
        }
        var days = Math.floor(hours / 24);
        if (days < 7) {
            return days + 'd ago';
        }
        return '';
    }

    function bindRelativeTimes(root) {
        root.querySelectorAll('[data-relative-at]').forEach(function (el) {
            if (el.dataset.relativeBound === '1') {
                return;
            }
            el.dataset.relativeBound = '1';
            var at = el.getAttribute('data-relative-at');
            var display = el.querySelector('[data-relative-display]');
            if (!display && el.hasAttribute('data-relative-display')) {
                display = el;
            }
            if (!display) {
                return;
            }
            var relative = formatRelativeTime(at);
            if (relative) {
                display.textContent = relative;
            }
        });
    }

    function bindCopyButtons(root) {
        root.querySelectorAll('[data-copy]').forEach(function (button) {
            if (button.dataset.copyBound === '1') {
                return;
            }
            button.dataset.copyBound = '1';
            button.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                var value = button.getAttribute('data-copy') || '';
                if (!value) {
                    return;
                }
                function onCopied() {
                    button.classList.add('is-copied');
                    showToast('Copied ' + value);
                    window.setTimeout(function () {
                        button.classList.remove('is-copied');
                    }, 1200);
                }
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(value).then(onCopied).catch(function () {
                        showToast('Could not copy.', true);
                    });
                } else {
                    var area = document.createElement('textarea');
                    area.value = value;
                    document.body.appendChild(area);
                    area.select();
                    try {
                        document.execCommand('copy');
                        onCopied();
                    } catch (err) {
                        showToast('Could not copy.', true);
                    }
                    area.remove();
                }
            });
        });
    }

    function bindDropdowns(root) {
        bindDropdownCloseHandler();

        root.querySelectorAll('[data-dropdown]').forEach(function (dropdown) {
            if (dropdown.dataset.ticketMenuBound === '1') {
                return;
            }
            dropdown.dataset.ticketMenuBound = '1';

            var trigger = dropdown.querySelector('[data-dropdown-trigger]');
            if (!trigger) {
                return;
            }

            trigger.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();

                var willOpen = !dropdown.classList.contains('open');
                closeAllTicketDropdowns(root);

                if (willOpen) {
                    dropdown.classList.add('open');
                    trigger.setAttribute('aria-expanded', 'true');
                    positionDropdownMenu(dropdown);
                } else {
                    closeDropdown(dropdown);
                }
            });
        });
    }

    function bindRowNavigation(root) {
        root.querySelectorAll('.ticket-row-clickable').forEach(function (row) {
            if (row.dataset.rowBound === '1') {
                return;
            }
            row.dataset.rowBound = '1';

            var href = row.getAttribute('data-href');
            if (!href) {
                return;
            }

            function navigate() {
                window.location.href = href;
            }

            function shouldNavigate(event) {
                var target = event.target;
                if (target.closest('[data-ticket-actions]')) {
                    return false;
                }
                if (target.closest('a')) {
                    return false;
                }
                if (target.closest('.ticket-inline-select')) {
                    return false;
                }
                if (target.closest('.ticket-select-row')) {
                    return false;
                }
                if (target.closest('.ticket-delete-btn')) {
                    return false;
                }
                if (target.closest('[data-dropdown-trigger]')) {
                    return false;
                }
                return true;
            }

            row.addEventListener('click', function (event) {
                if (!shouldNavigate(event)) {
                    return;
                }
                navigate();
            });

            row.addEventListener('keydown', function (event) {
                if (!shouldNavigate(event)) {
                    return;
                }
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    navigate();
                }
            });
        });
    }

    function setButtonLoading(button, loading) {
        if (!button) {
            return;
        }
        button.disabled = loading;
        button.classList.toggle('is-loading', loading);
    }

    function setSelectLoading(select, loading) {
        if (!select) {
            return;
        }
        select.disabled = loading;
        select.classList.toggle('is-loading', loading);
    }

    function statusSelectClass(status) {
        if (status === 'Open') {
            return 'is-status-open';
        }
        if (status === 'In-Progress') {
            return 'is-status-progress';
        }
        if (status === 'Closed') {
            return 'is-status-closed';
        }
        return '';
    }

    function syncStatusSelectClass(select, status) {
        if (!select) {
            return;
        }
        select.classList.remove('is-status-open', 'is-status-progress', 'is-status-closed');
        var cls = statusSelectClass(status);
        if (cls) {
            select.classList.add(cls);
        }
    }

    function updateClosedCell(row, status, payload) {
        var cell = row ? row.querySelector('[data-ticket-closed-cell]') : null;
        if (!cell) {
            return;
        }
        if (status === 'Closed') {
            var label = payload && payload.closed_at_label ? payload.closed_at_label : '';
            cell.textContent = label || '—';
            cell.classList.toggle('is-muted', !label);
            if (payload && payload.closed_at) {
                cell.setAttribute('datetime', payload.closed_at);
            } else {
                cell.removeAttribute('datetime');
            }
        } else {
            cell.textContent = '—';
            cell.classList.add('is-muted');
            cell.removeAttribute('datetime');
        }
    }

    function updateUpdatedCell(row, payload) {
        if (!payload) {
            return;
        }
        var block = row ? row.querySelector('[data-ticket-updated-cell]') : null;
        if (!block) {
            return;
        }
        var atEl = block.querySelector('[data-ticket-updated-at]');
        var byEl = block.querySelector('[data-ticket-updated-by]');
        if (atEl && payload.updated_at_label) {
            atEl.textContent = payload.updated_at_label;
        }
        if (atEl && payload.updated_at) {
            atEl.setAttribute('datetime', payload.updated_at);
        }
        if (byEl && payload.updater_name) {
            byEl.textContent = payload.updater_name;
        }
        if (payload.updated_at) {
            block.querySelectorAll('[data-relative-at]').forEach(function (relEl) {
                relEl.setAttribute('data-relative-at', payload.updated_at);
                var display = relEl.querySelector('[data-relative-display]');
                if (!display && relEl.hasAttribute('data-relative-display')) {
                    display = relEl;
                }
                if (display) {
                    var relative = formatRelativeTime(payload.updated_at);
                    if (relative) {
                        display.textContent = relative;
                    }
                }
            });
        }
    }

    function applyActionResponse(row, payload) {
        if (!row || !payload) {
            return;
        }
        updateUpdatedCell(row, payload);
    }

    function updateAssigneeCell(row, name) {
        var cell = row ? row.querySelector('[data-ticket-assignee-cell]') : null;
        if (!cell) {
            return;
        }
        var label = name || 'Unassigned';
        var isUnassigned = !name || label === 'Unassigned';
        cell.textContent = label;
        cell.classList.toggle('is-muted', isUnassigned);
    }

    function stopRowNav(event) {
        event.stopPropagation();
    }

    var confirmBtn = document.getElementById('confirm-archive-btn');
    var reasonInput = document.getElementById('archive-reason');
    var archiveTicketId = document.getElementById('archive-ticket-id');

    function setupConfirmHandler(root, refreshCallback) {
        reasonInput.value = '';
        var handler = function () {
            var reason = reasonInput.value.trim();
            var singleId = archiveTicketId.value;
            if (singleId) {
                setButtonLoading(confirmBtn, true);
                postTicketAction(root, {
                    action: 'soft_delete',
                    ticket_id: singleId,
                    reason: reason
                }).then(function (data) {
                    showToast('Ticket archived successfully.');
                    closeModal('archive-modal');
                    if (typeof refreshCallback === 'function') {
                        refreshCallback();
                    } else if (typeof window.__ticketListRefresh === 'function') {
                        window.__ticketListRefresh();
                    } else {
                        window.location.reload();
                    }
                }).catch(function (error) {
                    showToast(error.message || 'Action failed.', true);
                }).finally(function () {
                    setButtonLoading(confirmBtn, false);
                });
            } else {
                var ids = [];
                root.querySelectorAll('.ticket-select-row').forEach(function (cb) {
                    if (cb.checked) {
                        ids.push(cb.getAttribute('data-ticket-id'));
                    }
                });
                if (!ids.length) return;

                setButtonLoading(confirmBtn, true);
                var body = new FormData();
                body.append('bulk_action', 'bulk_archive');
                ids.forEach(function (id) {
                    body.append('ticket_ids[]', id);
                });
                body.append('reason', reason);
                body.append('csrf_token', root.getAttribute('data-csrf') || '');

                fetch(window.location.pathname, {
                    method: 'POST',
                    body: body,
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                }).then(function (response) {
                    return response.json().then(function (data) {
                        if (!response.ok || !data.success) {
                            throw new Error(data.message || 'Action failed.');
                        }
                        return data;
                    });
                }).then(function () {
                    showToast(ids.length + ' ticket(s) archived successfully.');
                    closeModal('archive-modal');
                    if (typeof refreshCallback === 'function') {
                        refreshCallback();
                    } else if (typeof window.__ticketListRefresh === 'function') {
                        window.__ticketListRefresh();
                    } else {
                        window.location.reload();
                    }
                }).catch(function (error) {
                    showToast(error.message || 'Action failed.', true);
                }).finally(function () {
                    setButtonLoading(confirmBtn, false);
                });
            }
        };
        confirmBtn.onclick = handler;
    }

function bindInlineActions(root, refreshCallback) {
        root.querySelectorAll('[data-inline-status]').forEach(function (select) {
            if (select.dataset.bound === '1') {
                return;
            }
            select.dataset.bound = '1';
            select.addEventListener('click', stopRowNav);
            select.addEventListener('mousedown', stopRowNav);

            select.addEventListener('change', function (event) {
                event.stopPropagation();
                var ticketId = select.getAttribute('data-ticket-id');
                var status = select.value;
                var prev = select.getAttribute('data-prev-status') || '';
                var row = findRow(select);

                if (status === 'Closed' && prev !== 'Closed') {
                    if (!window.confirm('Close this ticket? The customer may receive a closure email.')) {
                        select.value = prev;
                        return;
                    }
                }

                setSelectLoading(select, true);
                postTicketAction(root, {
                    action: 'quick_status',
                    ticket_id: ticketId,
                    status: status
                }).then(function (data) {
                    select.setAttribute('data-prev-status', status);
                    syncStatusSelectClass(select, status);
                    syncStatusBadge(row ? row.querySelector('[data-ticket-status-badge]') : null, status);
                    updateClosedCell(row, status, data);
                    applyActionResponse(row, data);
                    showToast('Status updated to ' + status + '.');
                    if (typeof refreshCallback === 'function') {
                        refreshCallback();
                    } else if (typeof window.__ticketListRefresh === 'function') {
                        window.__ticketListRefresh();
                    } else {
                        window.location.reload();
                    }
                }).catch(function (error) {
                    select.value = prev;
                    showToast(error.message || 'Could not update status.', true);
                }).finally(function () {
                    setSelectLoading(select, false);
                });
            });
        });

        root.querySelectorAll('[data-inline-assign]').forEach(function (select) {
            if (select.dataset.bound === '1') {
                return;
            }
            select.dataset.bound = '1';
            select.addEventListener('click', stopRowNav);
            select.addEventListener('mousedown', stopRowNav);

            select.addEventListener('change', function (event) {
                event.stopPropagation();
                var ticketId = select.getAttribute('data-ticket-id');
                var assignTo = select.value;
                var prev = select.getAttribute('data-prev-assign') || '';
                var row = findRow(select);

                setSelectLoading(select, true);
                postTicketAction(root, {
                    action: 'quick_assign',
                    ticket_id: ticketId,
                    assign_to: assignTo
                }).then(function (data) {
                    select.setAttribute('data-prev-assign', assignTo);
                    updateAssigneeCell(row, data.assignee_name || (assignTo ? 'Assigned' : 'Unassigned'));
                    applyActionResponse(row, data);
                    showToast('Assignment updated.');
                    if (typeof refreshCallback === 'function') {
                        refreshCallback();
                    } else if (typeof window.__ticketListRefresh === 'function') {
                        window.__ticketListRefresh();
                    } else {
                        window.location.reload();
                    }
                }).catch(function (error) {
                    select.value = prev;
                    showToast(error.message || 'Could not assign ticket.', true);
                }).finally(function () {
                    setSelectLoading(select, false);
                });
            });
        });

        root.querySelectorAll('[data-action="archive"], [data-action="restore"]').forEach(function (button) {
            if (button.dataset.bound === '1') {
                return;
            }
            button.dataset.bound = '1';
            button.addEventListener('click', stopRowNav);
            button.addEventListener('mousedown', stopRowNav);

            button.addEventListener('click', function (event) {
                event.stopPropagation();
                var action = button.getAttribute('data-action');
                var ticketId = button.getAttribute('data-ticket-id');
                var row = findRow(button);

                if (action === 'archive') {
                    archiveTicketId.value = ticketId;
                    document.getElementById('archive-count').textContent = '1';
                    document.getElementById('archive-reason').value = '';
                    openModal('archive-modal');
                    setupConfirmHandler(root, refreshCallback);
                } else if (action === 'restore') {
                    var body = new FormData();
                    body.append('bulk_action', 'bulk_restore');
                    body.append('ticket_ids[]', ticketId);
                    body.append('csrf_token', root.getAttribute('data-csrf') || '');

                    fetch(window.location.pathname, {
                        method: 'POST',
                        body: body,
                        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                    }).then(function (response) {
                        return response.json().then(function (data) {
                            if (!response.ok || !data.success) {
                                throw new Error(data.message || 'Action failed.');
                            }
                            return data;
                        });
                    }).then(function () {
                        showToast('Ticket restored successfully.');
                        if (typeof refreshCallback === 'function') {
                            refreshCallback();
                        } else if (typeof window.__ticketListRefresh === 'function') {
                            window.__ticketListRefresh();
                        } else {
                            window.location.reload();
                        }
                    }).catch(function (error) {
                        showToast(error.message || 'Action failed.', true);
                    });
                }
            });
        });
    }

    function bindQuickActions(root, refreshCallback) {
        root.querySelectorAll('[data-quick-status]').forEach(function (button) {
            if (button.dataset.bound === '1') {
                return;
            }
            button.dataset.bound = '1';

            button.addEventListener('click', function (event) {
                event.stopPropagation();
                var ticketId = button.getAttribute('data-ticket-id');
                var status = button.getAttribute('data-status');
                var row = findRow(button);

                if (button.getAttribute('data-confirm-close') === '1') {
                    if (!window.confirm('Close this ticket? The customer may receive a closure email.')) {
                        return;
                    }
                }

                setButtonLoading(button, true);
                postTicketAction(root, {
                    action: 'quick_status',
                    ticket_id: ticketId,
                    status: status
                }).then(function (data) {
                    syncStatusBadge(row ? row.querySelector('[data-ticket-status-badge]') : null, status);
                    updateClosedCell(row, status, data);
                    applyActionResponse(row, data);
                    closeDropdown(button.closest('[data-dropdown]'));
                    showToast('Status updated to ' + status + '.');
                    bindRelativeTimes(root);
                    if (typeof refreshCallback === 'function') {
                        refreshCallback();
                    } else if (typeof window.__ticketListRefresh === 'function') {
                        window.__ticketListRefresh();
                    } else {
                        window.location.reload();
                    }
                }).catch(function (error) {
                    showToast(error.message || 'Could not update status.', true);
                }).finally(function () {
                    setButtonLoading(button, false);
                });
            });
        });

        root.querySelectorAll('[data-quick-assign]').forEach(function (button) {
            if (button.dataset.bound === '1') {
                return;
            }
            button.dataset.bound = '1';

            button.addEventListener('click', function (event) {
                event.stopPropagation();
                var ticketId = button.getAttribute('data-ticket-id');
                var assignTo = button.getAttribute('data-assign-to') || '';
                var row = findRow(button);

                setButtonLoading(button, true);
                postTicketAction(root, {
                    action: 'quick_assign',
                    ticket_id: ticketId,
                    assign_to: assignTo
                }).then(function (data) {
                    updateAssigneeCell(row, data.assignee_name || (assignTo ? 'Assigned' : 'Unassigned'));
                    applyActionResponse(row, data);
                    closeDropdown(button.closest('[data-dropdown]'));
                    showToast('Assignment updated.');
                    bindRelativeTimes(root);
                    if (typeof refreshCallback === 'function') {
                        refreshCallback();
                    } else if (typeof window.__ticketListRefresh === 'function') {
                        window.__ticketListRefresh();
                    } else {
                        window.location.reload();
                    }
                }).catch(function (error) {
                    showToast(error.message || 'Could not assign ticket.', true);
                }).finally(function () {
                    setButtonLoading(button, false);
                });
            });
        });

        root.querySelectorAll('[data-quick-assign-self]').forEach(function (button) {
            if (button.dataset.bound === '1') {
                return;
            }
            button.dataset.bound = '1';

            button.addEventListener('click', function (event) {
                event.stopPropagation();
                stopRowNav(event);
                var ticketId = button.getAttribute('data-ticket-id');
                var row = findRow(button);

                setButtonLoading(button, true);
                postTicketAction(root, {
                    action: 'assign_self',
                    ticket_id: ticketId
                }).then(function (data) {
                    updateAssigneeCell(row, data.assignee_name || 'You');
                    applyActionResponse(row, data);
                    closeDropdown(button.closest('[data-dropdown]'));
                    showToast('Ticket assigned to you.');
                    if (typeof refreshCallback === 'function') {
                        refreshCallback();
                    } else if (typeof window.__ticketListRefresh === 'function') {
                        window.__ticketListRefresh();
                    } else {
                        window.location.reload();
                    }
                }).catch(function (error) {
                    showToast(error.message || 'Could not assign ticket.', true);
                }).finally(function () {
                    setButtonLoading(button, false);
                });
            });
        });

        root.querySelectorAll('[data-assign-search]').forEach(function (input) {
            if (input.dataset.bound === '1') {
                return;
            }
            input.dataset.bound = '1';

            input.addEventListener('input', function () {
                var query = input.value.trim().toLowerCase();
                var list = input.closest('.menu-assign-section');
                if (!list) {
                    return;
                }
                list.querySelectorAll('[data-quick-assign]').forEach(function (assignButton) {
                    var label = (assignButton.getAttribute('data-assign-label') || assignButton.textContent || '').toLowerCase();
                    assignButton.hidden = query !== '' && label.indexOf(query) === -1;
                });
            });

            input.addEventListener('click', function (event) {
                event.stopPropagation();
            });
        });
    }

    function bindBulkActions(root, refreshCallback) {
        var topArchiveBtn = root.querySelector('#top-bulk-action-btn');
        var topRestoreBtn = root.querySelector('#top-bulk-restore-btn');
        var topDeleteDiv = root.querySelector('.bulk-actions-delete');

        function updateToolbar() {
            var checkedBoxes = [];
            root.querySelectorAll('.ticket-select-row').forEach(function (cb) {
                if (cb.checked) {
                    checkedBoxes.push(cb);
                }
            });
            var count = checkedBoxes.length;

            var hasArchived = false;
            var hasActive = false;
            checkedBoxes.forEach(function (cb) {
                var row = cb.closest('tr');
                if (row && row.classList.contains('is-archived')) {
                    hasArchived = true;
                } else {
                    hasActive = true;
                }
            });

            var selectedCount = root.querySelector('#selected-count');
            if (selectedCount) {
                selectedCount.textContent = count;
            }

            if (topDeleteDiv) {
                topDeleteDiv.style.display = count > 0 ? 'flex' : 'none';
            }
            if (topArchiveBtn) {
                topArchiveBtn.style.display = hasActive ? 'flex' : 'none';
            }
            if (topRestoreBtn) {
                topRestoreBtn.style.display = hasArchived ? 'flex' : 'none';
            }
        }

        root.addEventListener('change', function (e) {
            if (e.target.classList.contains('ticket-select-row')) {
                updateToolbar();
            }
        });

        root.addEventListener('change', function (e) {
            if (e.target.id === 'select-all-tickets') {
                var checked = e.target.checked;
                root.querySelectorAll('.ticket-select-row').forEach(function (cb) {
                    cb.checked = checked;
                });
                window.setTimeout(function () {
                    updateToolbar();
                }, 0);
            }
        });

        if (topArchiveBtn) {
            topArchiveBtn.addEventListener('click', function () {
                var ids = [];
                root.querySelectorAll('.ticket-select-row').forEach(function (cb) {
                    if (cb.checked) {
                        ids.push(cb.getAttribute('data-ticket-id'));
                    }
                });
                if (!ids.length) return;

                archiveTicketId.value = '';
                document.getElementById('archive-count').textContent = ids.length;
                openModal('archive-modal');
                setupConfirmHandler(root, refreshCallback);
            });
        }

        if (topRestoreBtn) {
            topRestoreBtn.addEventListener('click', function () {
                var ids = [];
                root.querySelectorAll('.ticket-select-row:checked').forEach(function (cb) {
                    if (cb.checked) {
                        ids.push(cb.getAttribute('data-ticket-id'));
                    }
                });
                if (!ids.length) return;

                var body = new FormData();
                body.append('bulk_action', 'bulk_restore');
                ids.forEach(function (id) {
                    body.append('ticket_ids[]', id);
                });
                body.append('csrf_token', root.getAttribute('data-csrf') || '');

                fetch(window.location.pathname, {
                    method: 'POST',
                    body: body,
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                }).then(function (response) {
                    return response.json().then(function (data) {
                        if (!response.ok || !data.success) {
                            throw new Error(data.message || 'Action failed.');
                        }
                        return data;
                    });
                }).then(function () {
                    showToast(ids.length + ' ticket(s) restored successfully.');
                    if (typeof refreshCallback === 'function') {
                        refreshCallback();
                    } else if (typeof window.__ticketListRefresh === 'function') {
                        window.__ticketListRefresh();
                    } else {
                        window.location.reload();
                    }
                }).catch(function (error) {
                    showToast(error.message || 'Action failed.', true);
                });
            });
        }
    }

    function openModal(id) {
        var modal = document.getElementById(id);
        if (modal) {
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }
    }

    function closeModal(id) {
        var modal = document.getElementById(id);
        if (modal) {
            modal.style.display = 'none';
        }
        document.body.style.overflow = '';
    }

    window.closeModal = closeModal;
    window.openModal = openModal;

    function bindStatusChips(root) {
        root.querySelectorAll('[data-status-chips], [data-priority-chips]').forEach(function (group) {
            if (group.dataset.bound === '1') {
                return;
            }
            group.dataset.bound = '1';

            group.querySelectorAll('.chip input').forEach(function (input) {
                input.addEventListener('change', function () {
                    group.querySelectorAll('.chip').forEach(function (chip) {
                        chip.classList.remove('selected');
                    });
                    var parent = input.closest('.chip');
                    if (parent) {
                        parent.classList.add('selected');
                    }
                });
            });
        });
    }

    function initMonitoring(root) {
        if (!root) {
            return;
        }
        bindRowNavigation(root);
        bindRelativeTimes(root);
    }

    function init(root, refreshCallback) {
        if (!root) {
            return;
        }
        bindDropdowns(root);
        bindRowNavigation(root);
        bindCopyButtons(root);
        bindRelativeTimes(root);
        root.querySelectorAll('[data-inline-status]').forEach(function (select) {
            syncStatusSelectClass(select, select.value);
        });
        bindInlineActions(root, refreshCallback);
        bindQuickActions(root, refreshCallback);
        bindStatusChips(root);
        bindBulkActions(root, refreshCallback);
    }

    window.TicketListUI = {
        init: init,
        initMonitoring: initMonitoring,
        closeAll: function () {
            closeAllTicketDropdowns(document.getElementById('ticket-list-root'));
        },
        showToast: showToast
    };

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.getElementById('ticket-list-root');
        if (root && !window.__ticketListInitWithRefresh) {
            init(root);
        }

        var dashRecent = document.getElementById('dashboard-recent-root');
        if (dashRecent) {
            initMonitoring(dashRecent);
        }

        var editPanel = document.getElementById('ticket-edit-panel');
        if (editPanel) {
            init(editPanel);
        }

        var editToggle = document.querySelector('[data-edit-toggle]');
        var editCancel = document.querySelector('[data-edit-cancel]');
        if (editToggle && editPanel) {
            editToggle.addEventListener('click', function () {
                var isHidden = editPanel.hasAttribute('hidden');
                if (isHidden) {
                    editPanel.removeAttribute('hidden');
                    editToggle.setAttribute('aria-expanded', 'true');
                    editPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                } else {
                    editPanel.setAttribute('hidden', 'hidden');
                    editToggle.setAttribute('aria-expanded', 'false');
                }
            });
        }
        if (editCancel && editPanel) {
            editCancel.addEventListener('click', function () {
                editPanel.setAttribute('hidden', 'hidden');
                if (editToggle) {
                    editToggle.setAttribute('aria-expanded', 'false');
                }
            });
        }
    });
})();