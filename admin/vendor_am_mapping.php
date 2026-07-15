<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../services/vendor_am_service.php';
require_once __DIR__ . '/../services/party_service.php';

$currentUser = require_login($pdo);
rbac_require_party_mapping_read();

$canManagePartyMapping = rbac_can_manage_party_mapping($currentUser);

$pageTitle = 'Party AM Mapping';
$pageHeading = 'Party AM Mapping';
$pageDescription = $canManagePartyMapping
    ? 'Route party inboxes to Assistant Managers and optional Business Managers for automatic CC.'
    : 'View party routing mappings and assistant managers (read-only).';
$message = null;
$extraStylesheets = ['assets/css/pages/admin-party-suite.css'];

vendor_am_service_ensure_schema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rbac_require_party_mapping_manage();
    verify_csrf();

    $action = trim((string) ($_POST['action'] ?? ''));

    try {
        if ($action === 'save_am') {
            vendor_am_service_save_assistant_manager(
                $pdo,
                (string) ($_POST['am_name'] ?? ''),
                (string) ($_POST['am_email'] ?? ''),
                isset($_POST['am_is_active']),
                (int) ($_POST['am_id'] ?? 0)
            );
            set_flash('success', 'Assistant Manager saved.');
            redirect('admin/vendor_am_mapping.php');
        }

        if ($action === 'save_mapping') {
            $assistantManagerId = (int) ($_POST['assistant_manager_id'] ?? 0);
            vendor_am_service_save_mapping(
                $pdo,
                (string) ($_POST['vendor_name'] ?? ''),
                (string) ($_POST['vendor_email'] ?? ''),
                $assistantManagerId > 0 ? $assistantManagerId : null,
                isset($_POST['mapping_is_active']),
                (int) ($_POST['mapping_id'] ?? 0),
                (string) ($_POST['business_manager_email'] ?? ''),
                (int) ($_POST['mapping_party_id'] ?? 0) > 0 ? (int) $_POST['mapping_party_id'] : null
            );
            set_flash('success', 'Party mapping saved.');
            redirect('admin/vendor_am_mapping.php');
        }

        if ($action === 'delete_mapping') {
            vendor_am_service_delete_mapping($pdo, (int) ($_POST['mapping_id'] ?? 0));
            set_flash('success', 'Party mapping deleted.');
            redirect('admin/vendor_am_mapping.php');
        }

        if ($action === 'delete_am') {
            vendor_am_service_delete_assistant_manager($pdo, (int) ($_POST['am_id'] ?? 0));
            set_flash('success', 'Assistant Manager deleted.');
            redirect('admin/vendor_am_mapping.php');
        }

        $message = ['type' => 'error', 'text' => 'Invalid party mapping request.'];
    } catch (Throwable $throwable) {
        $message = ['type' => 'error', 'text' => $throwable->getMessage()];
    }
}

$search = trim((string) ($_GET['search'] ?? ''));
$statusFilter = strtolower(trim((string) ($_GET['status'] ?? 'all')));
if (!in_array($statusFilter, ['all', 'active', 'inactive', 'no_am'], true)) {
    $statusFilter = 'all';
}
$assistantManagers = vendor_am_service_all_assistant_managers($pdo);
$activeAssistantManagers = vendor_am_service_active_assistant_managers($pdo);
$mappings = vendor_am_service_mappings($pdo, $search, 150, $statusFilter);
$vendorCandidates = vendor_am_service_recent_vendor_candidates($pdo, 80);
if ($canManagePartyMapping) {
    $editAm = vendor_am_service_assistant_manager_by_id($pdo, (int) ($_GET['edit_am'] ?? 0));
    $editMapping = vendor_am_service_mapping_by_id($pdo, (int) ($_GET['edit_mapping'] ?? 0));
} else {
    $editAm = null;
    $editMapping = null;
}
$flash = get_flash();

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($isAjax) {
    header('Content-Type: text/html; charset=UTF-8');
    ?>
    <section class="apd-table-card">
        <div class="apd-table-card__head">
            <h2>Mappings</h2>
            <p><?php echo e((string) count($mappings)); ?> match(es)</p>
        </div>
        <div class="apd-table-scroll">
            <table class="apd-table admin-mappings">
                <thead>
                    <tr>
                        <th>Party</th>
                        <th>Key email</th>
                        <th>CC routing</th>
                        <th>Map</th>
                        <th>Updated</th>
                        <?php if ($canManagePartyMapping): ?>
                        <th style="text-align: right;">Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($mappings): ?>
                        <?php foreach ($mappings as $mapping): ?>
                            <?php
                            $mappingActive = (int) ($mapping['mapping_active'] ?? 0) === 1;
                            $amActive = (int) ($mapping['am_active'] ?? 0) === 1;
                            $crmPartyId = (int) ($mapping['party_id'] ?? 0);
                            ?>
                            <tr>
                                <td>
                                    <div class="apd-title"><?php echo e($mapping['vendor_name'] ?: 'Unnamed'); ?></div>
                                    <div class="apd-td-muted apd-mono">
                                        Map #<?php echo e((string) (int) $mapping['id']); ?><?php echo $crmPartyId > 0 ? ' · Party ' . e((string) $crmPartyId) : ''; ?>
                                    </div>
                                </td>
                                <td><a class="apd-mono" href="mailto:<?php echo e($mapping['vendor_email']); ?>"><?php echo e($mapping['vendor_email']); ?></a></td>
                                <td>
                                    <div class="apd-route-stack">
                                        <div class="apd-route-line">
                                            <span class="apd-route-tag apd-route-tag--am">AM</span>
                                            <div class="apd-route-line__body">
                                                <?php if (!empty($mapping['am_email'])): ?>
                                                    <span class="apd-title"><?php echo e($mapping['am_name'] ?: 'AM'); ?></span>
                                                    <div class="apd-mono apd-td-muted"><?php echo e($mapping['am_email']); ?></div>
                                                    <?php if (!$amActive): ?>
                                                        <span class="badge badge-medium" style="margin-top:4px;">Inactive AM</span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="apd-td-muted">—</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="apd-route-line">
                                            <span class="apd-route-tag apd-route-tag--bm">BM</span>
                                            <div class="apd-route-line__body">
                                                <?php if (!empty($mapping['business_manager_email'])): ?>
                                                    <a class="apd-mono" href="mailto:<?php echo e($mapping['business_manager_email']); ?>"><?php echo e($mapping['business_manager_email']); ?></a>
                                                <?php else: ?>
                                                    <span class="apd-td-muted">—</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge <?php echo $mappingActive ? 'badge-open' : 'badge-medium'; ?>">
                                        <?php echo e($mappingActive ? 'Active' : 'Inactive'); ?>
                                    </span>
                                </td>
                                <td class="apd-td-muted"><?php echo e(format_date($mapping['updated_at'] ?: $mapping['created_at'])); ?></td>
                                <?php if ($canManagePartyMapping): ?>
                                <td>
                                    <div class="apd-actions">
                                        <a href="<?php echo e(url('admin/vendor_am_mapping.php?edit_mapping=' . (int) $mapping['id'] . '#mapping-form')); ?>" class="btn btn-outline btn-sm">Edit</a>
                                        <form method="POST" class="inline-form" onsubmit="return confirm('Delete this mapping?');">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete_mapping">
                                            <input type="hidden" name="mapping_id" value="<?php echo e((int) $mapping['id']); ?>">
                                            <button type="submit" class="btn btn-secondary btn-sm">Delete</button>
                                        </form>
                                    </div>
                                </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?php echo $canManagePartyMapping ? 6 : 5; ?>" class="apd-empty">No matching records found</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php
    include __DIR__ . '/../includes/footer.php';
    exit;
}

party_service_ensure_schema($pdo);
$editMappingPartyId = $editMapping ? (int) ($editMapping['party_id'] ?? 0) : 0;
$editPartyEmailRows = [];
$editPartyTypeaheadLabel = '';
if ($editMapping && $editMappingPartyId > 0) {
    $activePartyRow = party_service_get_active_party($pdo, $editMappingPartyId);
    if ($activePartyRow) {
        $editPartyTypeaheadLabel = (string) ($activePartyRow['name'] ?? '');
        $editPartyEmailRows = party_service_party_emails_ordered($pdo, $editMappingPartyId);
    }
}
$currentMappingVendorEmail = $editMapping ? (string) ($editMapping['vendor_email'] ?? '') : '';
$isLegacyMappingWithoutParty = (bool) ($editMapping && $editMappingPartyId <= 0);

include __DIR__ . '/../includes/header.php';
?>

<?php if ($flash): ?>
    <div class="flash flash-<?php echo e($flash['type']); ?>"><?php echo e($flash['message']); ?></div>
<?php endif; ?>

<?php if ($message): ?>
    <div class="flash flash-<?php echo e($message['type']); ?>"><?php echo e($message['text']); ?></div>
<?php endif; ?>

<div class="apd apd--mapping<?php echo $canManagePartyMapping ? '' : ' apd--readonly'; ?>">
    <div class="apd-page-head">
        <div>
            <h2>Routing</h2>
            <p>When mail targets a mapped party email, active AMs (and BM when set) merge into CC. Inactive AMs are ignored.</p>
        </div>
        <div class="apd-page-head__actions">
            <?php if (rbac_can_read_parties($currentUser)): ?>
                <a href="<?php echo e(url('admin/parties.php')); ?>" class="btn btn-outline btn-sm">Parties</a>
            <?php endif; ?>
            <a href="<?php echo e(url('emails/logs.php')); ?>" class="btn btn-outline btn-sm">Email logs</a>
        </div>
    </div>

    <?php if (!$canManagePartyMapping): ?>
        <div class="flash flash-info apd-readonly-banner" role="status">Read-only access — browse mappings and filters only; changes are not permitted.</div>
    <?php endif; ?>

    <div class="apd-stats">
        <div class="apd-stat">
            <span class="apd-stat__label">Mappings (filtered)</span>
            <span class="apd-stat__value"><?php echo e((string) count($mappings)); ?></span>
        </div>
        <div class="apd-stat">
            <span class="apd-stat__label">Assistant managers</span>
            <span class="apd-stat__value"><?php echo e((string) count($assistantManagers)); ?></span>
            <div class="apd-stat__hint"><?php echo e((string) count($activeAssistantManagers)); ?> active</div>
        </div>
    </div>

    <?php if ($canManagePartyMapping): ?>
    <div class="apd-forms">
        <div class="form-card" id="am-form">
            <div class="info-strip">
                <div>
                    <strong><?php echo $editAm ? 'Edit assistant manager' : 'New assistant manager'; ?></strong>
                    <p>Email must be valid and unique system-wide.</p>
                </div>
            </div>

            <form method="POST" novalidate>
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save_am">
                <input type="hidden" name="am_id" value="<?php echo e((string) ($editAm['id'] ?? 0)); ?>">

                <div class="form-grid">
                    <div class="input-group">
                        <label for="am_name">Name</label>
                        <input type="text" id="am_name" name="am_name" value="<?php echo e($_POST['am_name'] ?? ($editAm['name'] ?? '')); ?>" required>
                    </div>
                    <div class="input-group">
                        <label for="am_email">Email</label>
                        <input type="email" id="am_email" name="am_email" value="<?php echo e($_POST['am_email'] ?? ($editAm['email'] ?? '')); ?>" required>
                    </div>
                    <div class="input-group">
                        <label for="am_is_active">Status</label>
                        <label class="apd-checkbox">
                            <input type="checkbox" id="am_is_active" name="am_is_active" <?php echo (int) ($_POST ? isset($_POST['am_is_active']) : ($editAm['is_active'] ?? 1)) === 1 ? 'checked' : ''; ?>>
                            <span class="apd-checkbox__box" aria-hidden="true"></span>
                            <span class="apd-checkbox__label">Active</span>
                        </label>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><?php echo $editAm ? 'Update AM' : 'Save AM'; ?></button>
                    <?php if ($editAm): ?>
                        <a href="<?php echo e(url('admin/vendor_am_mapping.php#am-form')); ?>" class="btn btn-outline">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="form-card" id="mapping-form">
            <div class="info-strip">
                <div>
                    <strong><?php echo $editMapping ? 'Edit mapping' : 'New mapping'; ?></strong>
                    <p>Pick an active party and one of its emails as the key. Optional AM/BM for CC expansion.</p>
                </div>
            </div>

            <form method="POST" novalidate>
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save_mapping">
                <input type="hidden" name="mapping_id" value="<?php echo e((string) ($editMapping['id'] ?? 0)); ?>">
                <input type="hidden" name="mapping_party_id" id="mapping_party_id" value="<?php echo e((string) (int) ($_POST['mapping_party_id'] ?? $editMappingPartyId)); ?>">
                <input type="hidden" name="vendor_name" id="mapping_vendor_name_legacy" value="<?php echo e($_POST['vendor_name'] ?? ($editMapping['vendor_name'] ?? '')); ?>">

                <div class="form-grid apd-mapping-split">
                    <fieldset class="apd-fieldset">
                        <legend>Party</legend>
                        <div class="input-group mapping-party-typeahead">
                            <label for="mapping_party_search">Lookup <?php echo $isLegacyMappingWithoutParty ? '' : '<span class="apd-req">*</span>'; ?></label>
                            <input type="text" id="mapping_party_search" name="mapping_party_search" autocomplete="off" placeholder="Name, email, or ID…" value="<?php echo e($_POST['mapping_party_search'] ?? $editPartyTypeaheadLabel); ?>">
                            <div id="mapping_party_dropdown" class="mapping-party-dropdown" hidden></div>
                            <small class="apd-field-hint"><?php echo $isLegacyMappingWithoutParty ? 'Optional: attach a CRM party.' : 'Required for new rows.'; ?></small>
                        </div>

                        <div class="input-group">
                            <label for="vendor_email">Key email <span class="apd-req" aria-hidden="true">*</span></label>
                            <?php if ($isLegacyMappingWithoutParty): ?>
                                <input type="email" name="vendor_email" id="vendor_email" list="vendor-email-suggestions" required value="<?php echo e($_POST['vendor_email'] ?? $currentMappingVendorEmail); ?>">
                                <small class="apd-field-hint">Legacy row — attach a party above or keep this address.</small>
                            <?php else: ?>
                                <select name="vendor_email" id="vendor_email" required>
                                    <?php if ($editPartyEmailRows): ?>
                                        <?php $selEmail = (string) ($_POST['vendor_email'] ?? $currentMappingVendorEmail); ?>
                                        <?php
                                        $foundSel = false;
                                        foreach ($editPartyEmailRows as $er) {
                                            if (strcasecmp(trim((string) $er['email']), trim($selEmail)) === 0) {
                                                $foundSel = true;
                                                break;
                                            }
                                        }
                                        ?>
                                        <?php foreach ($editPartyEmailRows as $er): ?>
                                            <?php
                                            $em = trim((string) ($er['email'] ?? ''));
                                            $isPrim = (int) ($er['is_primary'] ?? 0) === 1;
                                            ?>
                                            <option value="<?php echo e($em); ?>" <?php echo strcasecmp($em, trim($selEmail)) === 0 ? 'selected' : ''; ?>>
                                                <?php echo e($em . ($isPrim ? ' (primary)' : '')); ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <?php if (!$foundSel && $selEmail !== ''): ?>
                                            <option value="<?php echo e($selEmail); ?>" selected><?php echo e($selEmail); ?> (current)</option>
                                        <?php endif; ?>
                                    <?php elseif ($editMapping): ?>
                                        <option value="<?php echo e($currentMappingVendorEmail); ?>" selected><?php echo e($currentMappingVendorEmail); ?></option>
                                    <?php else: ?>
                                        <option value="" disabled selected>Select a party first</option>
                                    <?php endif; ?>
                                </select>
                                <small class="apd-field-hint">Must match a party email.</small>
                            <?php endif; ?>
                        </div>

                        <div class="input-group">
                            <label for="mapping_is_active">Mapping</label>
                            <label class="apd-checkbox">
                                <input type="checkbox" id="mapping_is_active" name="mapping_is_active" <?php echo (int) ($_POST ? isset($_POST['mapping_is_active']) : ($editMapping['mapping_active'] ?? 1)) === 1 ? 'checked' : ''; ?>>
                                <span class="apd-checkbox__box" aria-hidden="true"></span>
                                <span class="apd-checkbox__label">Active</span>
                            </label>
                        </div>
                    </fieldset>

                    <fieldset class="apd-fieldset">
                        <legend>CC routing</legend>
                        <div class="input-group">
                            <label for="assistant_manager_id">Assistant manager</label>
                            <select id="assistant_manager_id" name="assistant_manager_id">
                                <option value="">No AM</option>
                                <?php $selectedAmId = (int) ($_POST['assistant_manager_id'] ?? ($editMapping['assistant_manager_id'] ?? 0)); ?>
                                <?php foreach ($assistantManagers as $am): ?>
                                    <option value="<?php echo e((string) $am['id']); ?>" <?php echo $selectedAmId === (int) $am['id'] ? 'selected' : ''; ?>>
                                        <?php echo e($am['name'] . ' <' . $am['email'] . '>' . ((int) $am['is_active'] === 1 ? '' : ' — inactive')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="input-group">
                            <label for="business_manager_email">Business manager</label>
                            <input type="email" id="business_manager_email" name="business_manager_email" value="<?php echo e($_POST['business_manager_email'] ?? ($editMapping['business_manager_email'] ?? '')); ?>" placeholder="BM email (optional)" autocomplete="email">
                        </div>
                    </fieldset>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><?php echo $editMapping ? 'Save changes' : 'Create mapping'; ?></button>
                    <?php if ($editMapping): ?>
                        <a href="<?php echo e(url('admin/vendor_am_mapping.php#mapping-form')); ?>" class="btn btn-outline">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>

            <datalist id="vendor-email-suggestions">
                <?php foreach ($vendorCandidates as $candidate): ?>
                    <option value="<?php echo e($candidate['vendor_email']); ?>"></option>
                <?php endforeach; ?>
            </datalist>
        </div>
    </div>
    <?php endif; ?>

    <form method="GET" class="apd-filter-bar" id="apd-mapping-filters" autocomplete="off">
        <div class="input-group">
            <label for="apd-mapping-search">Search</label>
            <input type="search" id="apd-mapping-search" name="search" value="<?php echo e($search); ?>" placeholder="Party, email, AM, BM…" data-apd-live-search="1">
            <span class="apd-search-loading" id="apd-mapping-search-loading" style="display: none;">●</span>
        </div>
        <div class="input-group" style="flex:0 1 160px;">
            <label for="apd-mapping-status">Status</label>
            <select id="apd-mapping-status" name="status">
                <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All</option>
                <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                <option value="no_am" <?php echo $statusFilter === 'no_am' ? 'selected' : ''; ?>>No AM</option>
            </select>
        </div>
        <div class="apd-filter-bar__actions">
            <button type="submit" class="btn btn-primary btn-sm">Apply</button>
            <a href="<?php echo e(url('admin/vendor_am_mapping.php')); ?>" class="btn btn-outline btn-sm">Reset</a>
        </div>
    </form>

    <section class="apd-table-card">
        <div class="apd-table-card__head">
            <h2>Mappings</h2>
            <p><?php echo e((string) count($mappings)); ?> match(es)</p>
        </div>
        <div class="apd-table-scroll">
            <table class="apd-table admin-mappings">
                <thead>
                    <tr>
                        <th>Party</th>
                        <th>Key email</th>
                        <th>CC routing</th>
                        <th>Map</th>
                        <th>Updated</th>
                        <?php if ($canManagePartyMapping): ?>
                        <th style="text-align: right;">Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($mappings): ?>
                        <?php foreach ($mappings as $mapping): ?>
                            <?php
                            $mappingActive = (int) ($mapping['mapping_active'] ?? 0) === 1;
                            $amActive = (int) ($mapping['am_active'] ?? 0) === 1;
                            $crmPartyId = (int) ($mapping['party_id'] ?? 0);
                            ?>
                            <tr>
                                <td>
                                    <div class="apd-title"><?php echo e($mapping['vendor_name'] ?: 'Unnamed'); ?></div>
                                    <div class="apd-td-muted apd-mono">
                                        Map #<?php echo e((string) (int) $mapping['id']); ?><?php echo $crmPartyId > 0 ? ' · Party ' . e((string) $crmPartyId) : ''; ?>
                                    </div>
                                </td>
                                <td><a class="apd-mono" href="mailto:<?php echo e($mapping['vendor_email']); ?>"><?php echo e($mapping['vendor_email']); ?></a></td>
                                <td>
                                    <div class="apd-route-stack">
                                        <div class="apd-route-line">
                                            <span class="apd-route-tag apd-route-tag--am">AM</span>
                                            <div class="apd-route-line__body">
                                                <?php if (!empty($mapping['am_email'])): ?>
                                                    <span class="apd-title"><?php echo e($mapping['am_name'] ?: 'AM'); ?></span>
                                                    <div class="apd-mono apd-td-muted"><?php echo e($mapping['am_email']); ?></div>
                                                    <?php if (!$amActive): ?>
                                                        <span class="badge badge-medium" style="margin-top:4px;">Inactive AM</span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="apd-td-muted">—</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="apd-route-line">
                                            <span class="apd-route-tag apd-route-tag--bm">BM</span>
                                            <div class="apd-route-line__body">
                                                <?php if (!empty($mapping['business_manager_email'])): ?>
                                                    <a class="apd-mono" href="mailto:<?php echo e($mapping['business_manager_email']); ?>"><?php echo e($mapping['business_manager_email']); ?></a>
                                                <?php else: ?>
                                                    <span class="apd-td-muted">—</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge <?php echo $mappingActive ? 'badge-open' : 'badge-medium'; ?>">
                                        <?php echo e($mappingActive ? 'Active' : 'Inactive'); ?>
                                    </span>
                                </td>
                                <td class="apd-td-muted"><?php echo e(format_date($mapping['updated_at'] ?: $mapping['created_at'])); ?></td>
                                <?php if ($canManagePartyMapping): ?>
                                <td>
                                    <div class="apd-actions">
                                        <a href="<?php echo e(url('admin/vendor_am_mapping.php?edit_mapping=' . (int) $mapping['id'] . '#mapping-form')); ?>" class="btn btn-outline btn-sm">Edit</a>
                                        <form method="POST" class="inline-form" onsubmit="return confirm('Delete this mapping?');">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete_mapping">
                                            <input type="hidden" name="mapping_id" value="<?php echo e((int) $mapping['id']); ?>">
                                            <button type="submit" class="btn btn-secondary btn-sm">Delete</button>
                                        </form>
                                    </div>
                                </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?php echo $canManagePartyMapping ? 6 : 5; ?>" class="apd-empty">No mappings match.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="apd-table-card">
        <div class="apd-table-card__head">
            <h2>Assistant managers</h2>
            <p><?php echo e((string) count($assistantManagers)); ?> records</p>
        </div>
        <div class="apd-table-scroll">
            <table class="apd-table admin-assistants">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Status</th>
                        <th>Updated</th>
                        <?php if ($canManagePartyMapping): ?>
                        <th style="text-align: right;">Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($assistantManagers): ?>
                        <?php foreach ($assistantManagers as $am): ?>
                            <tr>
                                <td>
                                    <div class="apd-title"><?php echo e($am['name']); ?></div>
                                    <div class="apd-td-muted apd-mono">ID <?php echo e((string) (int) $am['id']); ?></div>
                                </td>
                                <td><a class="apd-mono" href="mailto:<?php echo e($am['email']); ?>"><?php echo e($am['email']); ?></a></td>
                                <td>
                                    <span class="badge <?php echo (int) $am['is_active'] === 1 ? 'badge-open' : 'badge-medium'; ?>">
                                        <?php echo e((int) $am['is_active'] === 1 ? 'Active' : 'Inactive'); ?>
                                    </span>
                                </td>
                                <td class="apd-td-muted"><?php echo e(format_date($am['updated_at'] ?: $am['created_at'])); ?></td>
                                <?php if ($canManagePartyMapping): ?>
                                <td>
                                    <div class="apd-actions">
                                        <a href="<?php echo e(url('admin/vendor_am_mapping.php?edit_am=' . (int) $am['id'] . '#am-form')); ?>" class="btn btn-outline btn-sm">Edit</a>
                                        <form method="POST" class="inline-form" onsubmit="return confirm('Delete this AM? Mappings keep the party and clear AM.');">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete_am">
                                            <input type="hidden" name="am_id" value="<?php echo e((int) $am['id']); ?>">
                                            <button type="submit" class="btn btn-secondary btn-sm">Delete</button>
                                        </form>
                                    </div>
                                </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?php echo $canManagePartyMapping ? 5 : 4; ?>" class="apd-empty">No assistant managers.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<?php if ($canManagePartyMapping): ?>
<script>
(function () {
    var partyApi = <?php echo json_encode(url('admin/party_api.php'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES); ?>;
    var searchInput = document.getElementById('mapping_party_search');
    var dropdown = document.getElementById('mapping_party_dropdown');
    var partyHidden = document.getElementById('mapping_party_id');
    var emailField = document.getElementById('vendor_email');
    if (!partyApi || !searchInput || !dropdown || !partyHidden || !emailField) {
        return;
    }

    var isLegacyEmailInput = emailField.tagName === 'INPUT';
    var debounceTimer = null;

    function hideDropdown() {
        dropdown.hidden = true;
        dropdown.innerHTML = '';
    }

    function fillEmailSelect(emails) {
        if (isLegacyEmailInput) {
            if (emails.length) {
                emailField.value = emails[0].email || '';
            }
            return;
        }
        var sel = emailField;
        sel.innerHTML = '';
        emails.forEach(function (row) {
            var o = document.createElement('option');
            o.value = row.email;
            o.textContent = row.email + (parseInt(row.is_primary, 10) === 1 ? ' (primary)' : '');
            sel.appendChild(o);
        });
        if (emails.length) {
            sel.selectedIndex = 0;
        }
    }

    function loadPartyDetail(id) {
        fetch(partyApi + '?action=detail&id=' + encodeURIComponent(String(id)), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok || !d.party) {
                    return;
                }
                partyHidden.value = String(d.party.id);
                searchInput.value = d.party.name || '';
                fillEmailSelect(d.emails || []);
            })
            .catch(function () {});
    }

    searchInput.addEventListener('input', function () {
        clearTimeout(debounceTimer);
        var q = searchInput.value.trim();
        debounceTimer = setTimeout(function () {
            if (q.length < 1) {
                hideDropdown();
                return;
            }
            fetch(partyApi + '?action=search&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    dropdown.innerHTML = '';
                    if (!d || !d.results || !d.results.length) {
                        dropdown.hidden = true;
                        return;
                    }
                    d.results.forEach(function (row) {
                        var btn = document.createElement('button');
                        btn.type = 'button';
                        var strong = document.createElement('strong');
                        strong.textContent = row.name || '';
                        btn.appendChild(strong);
                        var meta = document.createElement('span');
                        meta.className = 'mapping-party-dropdown__meta';
                        meta.textContent = 'ID ' + row.id + (row.primary_email ? ' · ' + row.primary_email : '');
                        btn.appendChild(meta);
                        btn.addEventListener('click', function () {
                            loadPartyDetail(row.id);
                            hideDropdown();
                        });
                        dropdown.appendChild(btn);
                    });
                    dropdown.hidden = false;
                })
                .catch(function () {
                    hideDropdown();
                });
        }, 220);
    });

    document.addEventListener('click', function (e) {
        if (dropdown.hidden) {
            return;
        }
        if (e.target === searchInput || dropdown.contains(e.target)) {
            return;
        }
        hideDropdown();
    });
})();
</script>
<?php endif; ?>

<script>
(function () {
    var form = document.getElementById('apd-mapping-filters');
    if (!form) return;
    var search = document.getElementById('apd-mapping-search');
    var status = document.getElementById('apd-mapping-status');
    var loadingEl = document.getElementById('apd-mapping-search-loading');
    var t = null;

    function submitSearch() {
        var query = search ? search.value.trim() : '';
        var statusVal = status ? status.value : 'all';

        if (query === '' && statusVal === 'all') {
            if (window.history.replaceState) {
                var baseUrl = '<?php echo e(url('admin/vendor_am_mapping.php')); ?>';
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
            window.location.href = '<?php echo e(url('admin/vendor_am_mapping.php')); ?>';
            return;
        }

        if (window.XMLHttpRequest) {
            if (loadingEl) loadingEl.style.display = 'inline-block';
            var xhr = new XMLHttpRequest();
            var params = new URLSearchParams();
            if (query) params.append('search', query);
            if (statusVal && statusVal !== 'all') params.append('status', statusVal);

            xhr.open('GET', '<?php echo e(url('admin/vendor_am_mapping.php')); ?>?' + params.toString(), true);
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
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
