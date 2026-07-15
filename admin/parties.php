<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../services/party_service.php';

$currentUser = require_login($pdo);
rbac_require_party_read();

$canManageParties = rbac_can_manage_parties($currentUser);

$pageTitle = 'Party Management';
$pageHeading = 'Parties';
$pageDescription = $canManageParties
    ? 'Register organizations and emails allowed for ticket creation and routing.'
    : 'View organizations and registered emails (read-only).';
$message = null;
$extraStylesheets = ['assets/css/pages/admin-party-suite.css'];

party_service_ensure_schema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rbac_require_party_manage();
    verify_csrf();

    try {
        $action = trim((string) ($_POST['action'] ?? ''));

        if ($action === 'create_party') {
            $manageTransaction = !$pdo->inTransaction();
            if ($manageTransaction) {
                $pdo->beginTransaction();
            }
            try {
                $partyId = party_service_create($pdo, (string) ($_POST['party_name'] ?? ''), (string) ($_POST['party_status'] ?? 'active'));
                $email = trim((string) ($_POST['party_email'] ?? ''));
                if ($email === '') {
                    throw new RuntimeException('Primary email is required.');
                }
                party_service_add_email($pdo, $partyId, $email, true);

                $ccEmails = $_POST['cc_emails'] ?? [];
                if (!is_array($ccEmails)) {
                    $ccEmails = [];
                }
                foreach ($ccEmails as $cc) {
                    $cc = trim((string) $cc);
                    if ($cc === '' || strcasecmp($cc, $email) === 0) {
                        continue;
                    }
                    try {
                        party_service_add_email($pdo, $partyId, $cc, false);
                    } catch (RuntimeException $e) {
                        if (stripos($e->getMessage(), 'already registered') === false) {
                            throw $e;
                        }
                    }
                }

                if ($manageTransaction && $pdo->inTransaction()) {
                    $pdo->commit();
                }
                set_flash('success', 'Party saved.');
                redirect('admin/parties.php');
            } catch (Throwable $throwable) {
                if ($manageTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $throwable;
            }
        }

        if ($action === 'add_email') {
            $partyId = (int) ($_POST['party_id'] ?? 0);
            $email = trim((string) ($_POST['email'] ?? ''));
            $isPrimary = isset($_POST['is_primary']);

            if ($email !== '') {
                party_service_add_email($pdo, $partyId, $email, $isPrimary);
            }

            $ccRaw = trim((string) ($_POST['cc_emails'] ?? ''));
            if ($ccRaw !== '' && $partyId > 0) {
                foreach (preg_split('/[;,]+/', $ccRaw) ?: [] as $part) {
                    $cc = trim($part);
                    if ($cc === '' || strcasecmp($cc, $email) === 0) {
                        continue;
                    }
                    try {
                        party_service_add_email($pdo, $partyId, $cc, false);
                    } catch (RuntimeException $e) {
                        if (stripos($e->getMessage(), 'already registered') === false) {
                            throw $e;
                        }
                    }
                }
            }

            set_flash('success', 'Party email added.');
            redirect('admin/parties.php');
        }

        if ($action === 'update_party') {
            party_service_update(
                $pdo,
                (int) ($_POST['party_id'] ?? 0),
                (string) ($_POST['party_name'] ?? ''),
                (string) ($_POST['party_status'] ?? 'active')
            );
            set_flash('success', 'Party updated.');
            redirect('admin/parties.php');
        }

        $message = ['type' => 'error', 'text' => 'Invalid party request.'];
    } catch (Throwable $throwable) {
        $message = ['type' => 'error', 'text' => $throwable->getMessage()];
    }
}

$search = trim((string) ($_GET['search'] ?? ''));
$partyStatusFilter = strtolower(trim((string) ($_GET['status'] ?? 'all')));
if (!in_array($partyStatusFilter, ['all', 'active', 'inactive'], true)) {
    $partyStatusFilter = 'all';
}

$parties = party_service_list($pdo, $search, 150, $partyStatusFilter);
$partyOptions = party_service_active_options($pdo);
$flash = get_flash();

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($isAjax) {
    header('Content-Type: text/html; charset=UTF-8');
    $canManagePartiesHere = rbac_can_manage_parties($currentUser);
    ?>
    <section class="apd-table-card">
        <div class="apd-table-card__head">
            <h2>Parties</h2>
            <p><?php echo e((string) count($parties)); ?> row(s) · filters update the list</p>
        </div>
        <div class="apd-table-scroll">
            <table class="apd-table">
                <thead>
                    <tr>
                        <th>Party</th>
                        <th>Emails</th>
                        <th>Status</th>
                        <?php if ($canManagePartiesHere): ?>
                        <th style="width: 38%;">Quick update</th>
                        <?php endif; ?>
                        <th style="width: 120px; text-align: right;">Links</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($parties): ?>
                        <?php foreach ($parties as $party): ?>
                            <?php
                            $pid = (int) ($party['id'] ?? 0);
                            $mapSearch = rawurlencode((string) ($party['name'] ?? ''));
                            ?>
                            <tr>
                                <td>
                                    <div class="apd-title"><?php echo e($party['name']); ?></div>
                                    <div class="apd-td-muted apd-mono">ID <?php echo e((string) $pid); ?></div>
                                </td>
                                <td>
                                    <div class="apd-email-preview"><?php echo e($party['emails'] ?: '—'); ?></div>
                                    <?php if (!empty($party['primary_email'])): ?>
                                        <div class="apd-td-muted">Primary: <span class="apd-mono"><?php echo e((string) $party['primary_email']); ?></span></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge <?php echo strtolower((string) $party['status']) === 'active' ? 'badge-open' : 'badge-medium'; ?>">
                                        <?php echo e(ucfirst((string) $party['status'])); ?>
                                    </span>
                                </td>
                                <?php if ($canManagePartiesHere): ?>
                                <td>
                                    <form method="POST" class="apd-party-update">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="update_party">
                                        <input type="hidden" name="party_id" value="<?php echo e((string) $pid); ?>">
                                        <input type="text" name="party_name" value="<?php echo e($party['name']); ?>" required aria-label="Party name">
                                        <select name="party_status" aria-label="Party status">
                                            <option value="active" <?php echo strtolower((string) $party['status']) === 'active' ? 'selected' : ''; ?>>Active</option>
                                            <option value="inactive" <?php echo strtolower((string) $party['status']) === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                        </select>
                                        <button type="submit" class="btn btn-secondary btn-sm">Save</button>
                                    </form>
                                </td>
                                <?php endif; ?>
                                <td>
                                    <div class="apd-actions">
                                        <?php if (rbac_can_read_party_mapping($currentUser)): ?>
                                        <a href="<?php echo e(url('admin/vendor_am_mapping.php?search=' . $mapSearch)); ?>" class="btn btn-outline btn-sm">View mappings</a>
                                        <?php else: ?>
                                        <span class="apd-td-muted">—</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?php echo $canManagePartiesHere ? 5 : 4; ?>" class="apd-empty">No matching records found</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php
    exit;
}

include __DIR__ . '/../includes/header.php';
?>

<?php if ($flash): ?>
    <div class="flash flash-<?php echo e($flash['type']); ?>"><?php echo e($flash['message']); ?></div>
<?php endif; ?>

<?php if ($message): ?>
    <div class="flash flash-<?php echo e($message['type']); ?>"><?php echo e($message['text']); ?></div>
<?php endif; ?>

<div class="apd apd--parties<?php echo $canManageParties ? '' : ' apd--readonly'; ?>">
    <div class="apd-page-head">
        <div>
            <h2>party list</h2>
            <p>Parties define trusted senders. Link emails here before mapping AM/BM routing.</p>
        </div>
        <div class="apd-page-head__actions">
            <?php if (rbac_can_read_party_mapping($currentUser)): ?>
                <a href="<?php echo e(url('admin/vendor_am_mapping.php')); ?>" class="btn btn-outline btn-sm">Party AM mapping</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$canManageParties): ?>
        <div class="flash flash-info apd-readonly-banner" role="status">Read-only access — you can browse and search parties but cannot create or change records.</div>
    <?php endif; ?>

    <div class="apd-stats">
        <div class="apd-stat">
            <span class="apd-stat__label">Listed</span>
            <span class="apd-stat__value"><?php echo e((string) count($parties)); ?></span>
            <div class="apd-stat__hint">Up to 150 matches</div>
        </div>
        <div class="apd-stat">
            <span class="apd-stat__label">Active (picker)</span>
            <span class="apd-stat__value"><?php echo e((string) count($partyOptions)); ?></span>
            <div class="apd-stat__hint">Available in forms</div>
        </div>
    </div>

    <?php if ($canManageParties): ?>
    <div class="apd-forms">
        <div class="form-card" id="apd-add-party">
            <div class="info-strip">
                <div>
                    <strong>New party</strong>
                    <p>Registered emails gate ticket creation from inbound mail.</p>
                </div>
            </div>

            <form method="POST" novalidate>
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="create_party">

                <div class="form-grid">
                    <div class="input-group">
                        <label for="party_name">Name</label>
                        <input type="text" id="party_name" name="party_name" required placeholder="Organization name">
                    </div>
                    <div class="input-group">
                        <label for="party_email">Primary email</label>
                        <input type="email" id="party_email" name="party_email" placeholder="Required" required>
                    </div>
                    <div class="input-group" id="apd-cc-emails-group">
                        <label>Additional emails(optional)</label>
                        <div id="apd-cc-emails-list" class="apd-cc-emails-list"></div>
                        <?php if ($canManageParties): ?>
                        <button type="button" class="btn btn-secondary btn-sm" id="apd-add-cc-email">+ Add Email</button>
                        <?php endif; ?>
                    </div>
                    <div class="input-group">
                        <label for="party_status">Status</label>
                        <select id="party_status" name="party_status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Create party</button>
                </div>
            </form>
        </div>

        <div class="form-card" id="apd-add-email">
            <div class="info-strip">
                <div>
                    <strong>Add email</strong>
                    <p>Each address is unique across the party list.</p>
                </div>
            </div>

            <form method="POST" novalidate>
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="add_email">

                <div class="form-grid">
                    <div class="input-group">
                        <label for="party_id">Party</label>
                        <select id="party_id" name="party_id" required>
                            <option value="">Select party</option>
                            <?php foreach ($partyOptions as $party): ?>
                                <option value="<?php echo e((string) $party['id']); ?>"><?php echo e($party['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="input-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" required placeholder="alias@company.com">
                    </div>
                    <div class="input-group">
                        <label for="is_primary">Primary</label>
                        <label class="checkbox-line">
                            <input type="checkbox" id="is_primary" name="is_primary">
                            Set as primary
                        </label>
                    </div>
                    <div class="input-group">
                        <label for="cc_emails">Additional Email Addresses (CC)</label>
                        <textarea id="cc_emails" name="cc_emails" rows="3" placeholder="alias@company.com, billing@company.com"></textarea>
                        <small class="apd-field-hint">Comma or semicolon separated. Each address must be unique across the party list.</small>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Add email</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <form method="GET" class="apd-filter-bar" id="apd-party-filters" autocomplete="off">
        <div class="input-group">
            <label for="apd-party-search">Search</label>
            <input type="search" id="apd-party-search" name="search" value="<?php echo e($search); ?>" placeholder="Name or email…" data-apd-live-search="1">
            <span class="apd-search-loading" id="apd-search-loading" style="display: none;">●</span>
        </div>
        <div class="input-group" style="flex:0 1 140px;">
            <label for="apd-party-status">Status</label>
            <select id="apd-party-status" name="status">
                <option value="all" <?php echo $partyStatusFilter === 'all' ? 'selected' : ''; ?>>All</option>
                <option value="active" <?php echo $partyStatusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                <option value="inactive" <?php echo $partyStatusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
            </select>
        </div>
        <div class="apd-filter-bar__actions">
            <button type="submit" class="btn btn-primary btn-sm">Apply</button>
            <a href="<?php echo e(url('admin/parties.php')); ?>" class="btn btn-outline btn-sm">Reset</a>
        </div>
    </form>

    <section class="apd-table-card">
        <div class="apd-table-card__head">
            <h2>Parties</h2>
            <p><?php echo e((string) count($parties)); ?> row(s) · filters update the list</p>
        </div>
        <div class="apd-table-scroll">
            <table class="apd-table">
                <thead>
                    <tr>
                        <th>Party</th>
                        <th>Emails</th>
                        <th>Status</th>
                        <?php if ($canManageParties): ?>
                        <th style="width: 38%;">Quick update</th>
                        <?php endif; ?>
                        <th style="width: 120px; text-align: right;">Links</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($parties): ?>
                        <?php foreach ($parties as $party): ?>
                            <?php
                            $pid = (int) ($party['id'] ?? 0);
                            $mapSearch = rawurlencode((string) ($party['name'] ?? ''));
                            ?>
                            <tr>
                                <td>
                                    <div class="apd-title"><?php echo e($party['name']); ?></div>
                                    <div class="apd-td-muted apd-mono">ID <?php echo e((string) $pid); ?></div>
                                </td>
                                <td>
                                    <div class="apd-email-preview"><?php echo e($party['emails'] ?: '—'); ?></div>
                                    <?php if (!empty($party['primary_email'])): ?>
                                        <div class="apd-td-muted">Primary: <span class="apd-mono"><?php echo e((string) $party['primary_email']); ?></span></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge <?php echo strtolower((string) $party['status']) === 'active' ? 'badge-open' : 'badge-medium'; ?>">
                                        <?php echo e(ucfirst((string) $party['status'])); ?>
                                    </span>
                                </td>
                                <?php if ($canManageParties): ?>
                                <td>
                                    <form method="POST" class="apd-party-update">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="update_party">
                                        <input type="hidden" name="party_id" value="<?php echo e((string) $pid); ?>">
                                        <input type="text" name="party_name" value="<?php echo e($party['name']); ?>" required aria-label="Party name">
                                        <select name="party_status" aria-label="Party status">
                                            <option value="active" <?php echo strtolower((string) $party['status']) === 'active' ? 'selected' : ''; ?>>Active</option>
                                            <option value="inactive" <?php echo strtolower((string) $party['status']) === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                        </select>
                                        <button type="submit" class="btn btn-secondary btn-sm">Save</button>
                                    </form>
                                </td>
                                <?php endif; ?>
                                <td>
                                    <div class="apd-actions">
                                        <?php if (rbac_can_read_party_mapping($currentUser)): ?>
                                        <a href="<?php echo e(url('admin/vendor_am_mapping.php?search=' . $mapSearch)); ?>" class="btn btn-outline btn-sm">View mappings</a>
                                        <?php else: ?>
                                        <span class="apd-td-muted">—</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?php echo $canManageParties ? 5 : 4; ?>" class="apd-empty">No parties match these filters.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<script>
(function () {
    var form = document.getElementById('apd-party-filters');
    if (!form) return;
    var search = document.getElementById('apd-party-search');
    var status = document.getElementById('apd-party-status');
    var loadingEl = document.getElementById('apd-search-loading');
    var t = null;

    function submitSearch() {
        var query = search ? search.value.trim() : '';
        var statusVal = status ? status.value : 'all';

        if (query === '' && statusVal === 'all') {
            if (window.history.replaceState) {
                var baseUrl = '<?php echo e(url('admin/parties.php')); ?>';
                window.history.replaceState(null, '', baseUrl);
                var xhr = new XMLHttpRequest();
                xhr.open('GET', baseUrl, true);
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                xhr.onreadystatechange = function () {
                    if (xhr.readyState === 4 && xhr.status === 200) {
                        var doc = new DOMParser().parseFromString(xhr.responseText, 'text/html');
                        var newTable = doc.querySelector('.apd-table-card');
                        var oldTable = document.querySelector('.apd-table-card');
                        if (newTable && oldTable) oldTable.innerHTML = newTable.innerHTML;
                    }
                };
                xhr.send();
                return;
            }
            window.location.href = '<?php echo e(url('admin/parties.php')); ?>';
            return;
        }

        if (window.XMLHttpRequest) {
            if (loadingEl) loadingEl.style.display = 'inline-block';
            var xhr = new XMLHttpRequest();
            var params = new URLSearchParams();
            if (query) params.append('search', query);
            if (statusVal && statusVal !== 'all') params.append('status', statusVal);

            xhr.open('GET', '<?php echo e(url('admin/parties.php')); ?>?' + params.toString(), true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

            xhr.onreadystatechange = function () {
                if (xhr.readyState === 4) {
                    if (loadingEl) loadingEl.style.display = 'none';
                    if (xhr.status === 200) {
                        var doc = new DOMParser().parseFromString(xhr.responseText, 'text/html');
                        var newTable = doc.querySelector('.apd-table-card');
                        var oldTable = document.querySelector('.apd-table-card');
                        if (newTable && oldTable) {
                            oldTable.innerHTML = newTable.innerHTML;
                            history.replaceState(null, '', '?' + params.toString());
                            if (search) search.focus();
                        }
                    }
                }
            };
            xhr.send();
        } else {
            if (form.requestSubmit) form.requestSubmit();
            else form.submit();
        }
    }

    function debouncedSubmit() {
        clearTimeout(t);
        t = setTimeout(submitSearch, 420);
    }

    if (search && search.getAttribute('data-apd-live-search') === '1') {
        search.addEventListener('input', debouncedSubmit);
        search.addEventListener('search', function () {
            clearTimeout(t);
            if (form.requestSubmit) form.requestSubmit();
            else form.submit();
        });
    }

    if (status) {
        status.addEventListener('change', function () {
            clearTimeout(t);
            if (form.requestSubmit) form.requestSubmit();
            else form.submit();
        });
    }

    function addCcEmailRow(value) {
        var list = document.getElementById('apd-cc-emails-list');
        if (!list) return;
        var row = document.createElement('div');
        row.className = 'apd-cc-email-row';
        var input = document.createElement('input');
        input.type = 'email';
        input.name = 'cc_emails[]';
        input.className = 'apd-cc-email-input';
        input.placeholder = 'CC email address';
        input.value = value == null ? '' : String(value);
        var removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'btn btn-ghost btn-sm apd-cc-email-remove';
        removeBtn.textContent = 'Remove';
        row.appendChild(input);
        row.appendChild(removeBtn);
        list.appendChild(row);
    }

    function clearCcEmails() {
        var list = document.getElementById('apd-cc-emails-list');
        if (list) list.innerHTML = '';
    }

    var addCcBtn = document.getElementById('apd-add-cc-email');
    if (addCcBtn) {
        addCcBtn.addEventListener('click', function () {
            addCcEmailRow('');
        });
    }

    var ccEmailList = document.getElementById('apd-cc-emails-list');
    if (ccEmailList) {
        ccEmailList.addEventListener('click', function (ev) {
            var rm = ev.target.closest('.apd-cc-email-remove');
            if (!rm) return;
            var row = rm.closest('.apd-cc-email-row');
            if (row) row.remove();
        });
    }
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
