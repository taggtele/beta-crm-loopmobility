<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../services/release_version_service.php';

$currentUser = require_login($pdo);
rbac_require_release_management_read($currentUser);

$canManageRelease = rbac_can_manage_release_management($currentUser);

$message = null;
$activeTab = 'publish';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rbac_require_release_management_manage($currentUser);
    verify_csrf();

    try {
        $action = trim((string) ($_POST['action'] ?? ''));
        $version = (string) ($_POST['version'] ?? '');
        $build = (string) ($_POST['build'] ?? '');
        $releaseDate = (string) ($_POST['release_date'] ?? '');
        $featuresRaw = (string) ($_POST['features'] ?? '');
        $featureLines = preg_split('/\R+/', $featuresRaw) ?: [];

        if ($action === 'save_current') {
            release_version_save_current($currentUser, $version, $build, $releaseDate, $featureLines);
            set_flash('success', 'Current release updated (same version).');
            redirect('admin/release_management.php?tab=edit');
        }

        if ($action === 'publish_new') {
            release_version_publish_new($currentUser, $version, $build, $releaseDate, $featureLines);
            set_flash('success', 'New release published. Previous version moved to history.');
            redirect('admin/release_management.php?tab=publish');
        }

        throw new InvalidArgumentException('Invalid request.');
    } catch (Throwable $throwable) {
        $message = ['type' => 'error', 'text' => $throwable->getMessage()];
        $activeTab = ($_POST['action'] ?? '') === 'save_current' ? 'edit' : 'publish';
    }
}

if ($canManageRelease) {
    $tabParam = trim((string) ($_GET['tab'] ?? ''));
    if (in_array($tabParam, ['publish', 'edit'], true)) {
        $activeTab = $tabParam;
    }
}

release_version_invalidate_cache();
$release = release_version_get();
$flash = get_flash();

$suggestedPatch = release_version_suggest_next($release['version'], 'patch');
$suggestedMinor = release_version_suggest_next($release['version'], 'minor');
$suggestedMajor = release_version_suggest_next($release['version'], 'major');
$todayBuild = release_version_today_build();
$todayDate = release_version_today_date();

$pageTitle = $canManageRelease ? 'Release Management' : 'Release Notes';
$pageHeading = $canManageRelease ? 'Release Management' : 'Release Notes';
$pageDescription = $canManageRelease
    ? 'Publish a new version or edit the live release shown in the sidebar footer.'
    : 'Current application version, features, and previous release history (read-only).';
$extraStylesheets = ['assets/css/pages/release-management.css'];

include __DIR__ . '/../includes/header.php';
?>

<?php if ($flash): ?>
    <div class="flash flash-<?php echo e($flash['type']); ?>"><?php echo e($flash['message']); ?></div>
<?php endif; ?>

<?php if ($message): ?>
    <div class="flash flash-<?php echo e($message['type']); ?>"><?php echo e($message['text']); ?></div>
<?php endif; ?>

<div class="rel-mgmt<?php echo $canManageRelease ? '' : ' rel-mgmt--readonly'; ?>">
    <?php if (!$canManageRelease): ?>
        <div class="flash flash-info rel-mgmt__readonly-banner" role="status">Read-only — contact an Admin to publish or edit releases.</div>
    <?php endif; ?>

    <div class="rel-mgmt__preview card-panel">
        <h2><?php echo $canManageRelease ? 'Live release (sidebar footer)' : 'Current release'; ?></h2>
        <div class="rel-mgmt__live-badge">v<?php echo e($release['version']); ?></div>
        <div class="rel-mgmt__footer-mock">
            <div class="rel-mgmt__footer-mock-top">
                <strong><?php echo e(defined('APP_COMPANY') ? APP_COMPANY : APP_NAME); ?></strong>
                <span class="sidebar-env-badge sidebar-env-badge--<?php echo e((app_environment_display())['slug']); ?>">
                    <?php echo e((app_environment_display())['label']); ?>
                </span>
            </div>
            <div class="rel-mgmt__footer-mock-metrics">
                v<?php echo e($release['version']); ?>
                <?php if ($release['build'] !== ''): ?> · <?php echo e($release['build']); ?><?php endif; ?>
                <?php if ($release['release_date'] !== ''): ?> · <?php echo e(format_date($release['release_date'], 'd M Y')); ?><?php endif; ?>
            </div>
            <?php if ($release['features'] !== []): ?>
                <ul class="rel-mgmt__feature-list<?php echo $canManageRelease ? ' rel-mgmt__feature-list--compact' : ''; ?>">
                    <?php foreach ($release['features'] as $feature): ?>
                        <li><?php echo e($feature); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php if ($release['updated_at'] !== ''): ?>
            <p class="rel-mgmt__meta">Last updated <?php echo e($release['updated_at']); ?>
                <?php if ($release['updated_by'] !== ''): ?> by <?php echo e($release['updated_by']); ?><?php endif; ?>
            </p>
        <?php endif; ?>
    </div>

    <?php if ($canManageRelease): ?>
    <div class="rel-mgmt__tabs" role="tablist">
        <a href="<?php echo e(url('admin/release_management.php?tab=publish')); ?>"
           class="rel-mgmt__tab <?php echo $activeTab === 'publish' ? 'is-active' : ''; ?>"
           role="tab"
           aria-selected="<?php echo $activeTab === 'publish' ? 'true' : 'false'; ?>">
            Publish new release
        </a>
        <a href="<?php echo e(url('admin/release_management.php?tab=edit')); ?>"
           class="rel-mgmt__tab <?php echo $activeTab === 'edit' ? 'is-active' : ''; ?>"
           role="tab"
           aria-selected="<?php echo $activeTab === 'edit' ? 'true' : 'false'; ?>">
            Edit current release
        </a>
    </div>

    <?php if ($activeTab === 'publish'): ?>
    <div class="rel-mgmt__form card-panel rel-mgmt__form--publish">
        <h2>Publish new release</h2>
        <p class="rel-mgmt__hint">
            Current live version <strong>v<?php echo e($release['version']); ?></strong> will move to <em>Previous releases</em>.
            Enter a <strong>new</strong> version number and this release’s feature list.
        </p>

        <div class="rel-mgmt__suggest-row">
            <span class="rel-mgmt__suggest-label">Quick pick:</span>
            <button type="button" class="btn btn-outline btn-sm" data-rel-version="<?php echo e($suggestedPatch); ?>">Patch <?php echo e($suggestedPatch); ?></button>
            <button type="button" class="btn btn-outline btn-sm" data-rel-version="<?php echo e($suggestedMinor); ?>">Minor <?php echo e($suggestedMinor); ?></button>
            <button type="button" class="btn btn-outline btn-sm" data-rel-version="<?php echo e($suggestedMajor); ?>">Major <?php echo e($suggestedMajor); ?></button>
        </div>

        <form method="POST" class="rel-mgmt__form-grid" id="rel-publish-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="publish_new">

            <div class="input-group">
                <label for="new_version">New version</label>
                <input type="text" id="new_version" name="version" required pattern="\d+\.\d+\.\d+"
                       value="<?php echo e($suggestedPatch); ?>" placeholder="<?php echo e($suggestedPatch); ?>">
            </div>

            <div class="input-group">
                <label for="new_build">Build</label>
                <input type="text" id="new_build" name="build" required pattern="\d{8}" value="<?php echo e($todayBuild); ?>" placeholder="20260523">
            </div>

            <div class="input-group">
                <label for="new_release_date">Release date</label>
                <input type="date" id="new_release_date" name="release_date" required value="<?php echo e($todayDate); ?>">
            </div>

            <div class="input-group rel-mgmt__full">
                <label for="new_features">Features &amp; changes in this release</label>
                <textarea id="new_features" name="features" rows="10" required placeholder="One item per line&#10;e.g. Email logs: export CSV&#10;Ticket: quick reply vendor fix"></textarea>
            </div>

            <div class="rel-mgmt__actions rel-mgmt__full">
                <button type="submit" class="btn btn-primary" data-confirm="Publish new release? Current v<?php echo e($release['version']); ?> will be archived.">
                    Publish new release
                </button>
            </div>
        </form>
    </div>
    <?php else: ?>
    <div class="rel-mgmt__form card-panel rel-mgmt__form--edit">
        <h2>Edit current release</h2>
        <p class="rel-mgmt__hint">
            Same version <strong>v<?php echo e($release['version']); ?></strong> — fix typos, build date, or feature text only.
            For a <strong>new</strong> version number, use the <a href="<?php echo e(url('admin/release_management.php?tab=publish')); ?>">Publish new release</a> tab.
        </p>

        <form method="POST" class="rel-mgmt__form-grid">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save_current">

            <div class="input-group">
                <label for="version">Version (locked)</label>
                <input type="text" id="version" name="version" required readonly
                       value="<?php echo e($release['version']); ?>" class="rel-mgmt__input-readonly">
            </div>

            <div class="input-group">
                <label for="build">Build</label>
                <input type="text" id="build" name="build" pattern="\d{8}" value="<?php echo e($release['build']); ?>" placeholder="20260523">
            </div>

            <div class="input-group">
                <label for="release_date">Release date</label>
                <input type="date" id="release_date" name="release_date" value="<?php echo e($release['release_date']); ?>">
            </div>

            <div class="input-group rel-mgmt__full">
                <label for="features">Features &amp; changes</label>
                <textarea id="features" name="features" rows="10" required placeholder="One item per line"><?php echo e(release_version_features_textarea_value($release['features'])); ?></textarea>
            </div>

            <div class="rel-mgmt__actions rel-mgmt__full">
                <button type="submit" class="btn btn-primary">Save changes</button>
            </div>
        </form>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if (!empty($release['history'])): ?>
    <div class="rel-mgmt__history card-panel">
        <h2>Previous releases</h2>
        <?php foreach ($release['history'] as $entry): ?>
            <?php if (!is_array($entry)) { continue; } ?>
            <article class="rel-mgmt__history-item">
                <header>
                    <strong>v<?php echo e((string) ($entry['version'] ?? '')); ?></strong>
                    <?php if (!empty($entry['build'])): ?>
                        <span class="rel-mgmt__history-meta"><?php echo e((string) $entry['build']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($entry['release_date'])): ?>
                        <span class="rel-mgmt__history-meta"><?php echo e(format_date((string) $entry['release_date'], 'd M Y')); ?></span>
                    <?php endif; ?>
                </header>
                <?php if (!empty($entry['features']) && is_array($entry['features'])): ?>
                    <ul class="rel-mgmt__feature-list">
                        <?php foreach ($entry['features'] as $histFeature): ?>
                            <li><?php echo e((string) $histFeature); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php if ($canManageRelease): ?>
<script>
(function () {
    var publishForm = document.getElementById('rel-publish-form');
    if (publishForm) {
        publishForm.addEventListener('submit', function (e) {
            var btn = publishForm.querySelector('[data-confirm]');
            var msg = btn && btn.getAttribute('data-confirm');
            if (msg && !window.confirm(msg)) {
                e.preventDefault();
            }
        });
    }
    document.querySelectorAll('[data-rel-version]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = document.getElementById('new_version');
            if (input) {
                input.value = btn.getAttribute('data-rel-version') || '';
                input.focus();
            }
        });
    });
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
