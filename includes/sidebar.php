<?php
/**
 * Primary navigation sidebar — branding, links, footer/release strip only.
 * Version strings come from version_management/app_version.php; environment badge from APP_ENV (.env).
 */

$currentPath = str_replace('\\', '/', $_SERVER['PHP_SELF'] ?? '');
if (!function_exists('rbac_can_view_party_accounts')) {
    require_once __DIR__ . '/rbac.php';
}
if (!function_exists('lucide_icon_svg')) {
    require_once __DIR__ . '/lucide_icons.php';
}
if (file_exists(__DIR__ . '/../version_management/app_version.php')) {
    require_once __DIR__ . '/../version_management/app_version.php';
}
if (file_exists(__DIR__ . '/../services/release_version_service.php')) {
    require_once __DIR__ . '/../services/release_version_service.php';
}


$releaseMeta = function_exists('release_version_get') ? release_version_get() : [];
$appVersion = (string) ($releaseMeta['version'] ?? (defined('APP_VERSION') ? APP_VERSION : '1.0.0'));
$appBuild = (string) ($releaseMeta['build'] ?? (defined('APP_BUILD') ? APP_BUILD : ''));
$appReleaseDate = (string) ($releaseMeta['release_date'] ?? (defined('APP_RELEASE_DATE') ? APP_RELEASE_DATE : ''));
$appReleaseFeatures = is_array($releaseMeta['features'] ?? null) ? $releaseMeta['features'] : [];
$appCompany = defined('APP_COMPANY') ? APP_COMPANY : '';
$appProductTagline = defined('APP_PRODUCT_TAGLINE') ? APP_PRODUCT_TAGLINE : 'Support Desk';

$envDisplay = function_exists('app_environment_display') ? app_environment_display() : ['slug' => 'production', 'label' => 'Production'];

$sidebarFooterTooltip = function_exists('release_version_sidebar_tooltip')
    ? release_version_sidebar_tooltip($releaseMeta, $envDisplay)
    : ('Version ' . $appVersion . ' · Environment: ' . $envDisplay['label']);

$sidebarCopyrightLine = '';
if (defined('APP_COPYRIGHT_NOTICE') && APP_COPYRIGHT_NOTICE !== '') {
    $sidebarCopyrightLine = APP_COPYRIGHT_NOTICE;
} elseif ($appCompany !== '') {
    $sidebarCopyrightLine = '© ' . date('Y') . ' ' . $appCompany;
}

/** Footer primary label — company name when set, otherwise product title */
$sidebarFooterBrand = $appCompany !== '' ? $appCompany : APP_NAME;

$sidebarFooterMetricsParts = ['v' . $appVersion];
if ($appBuild !== '') {
    $sidebarFooterMetricsParts[] = $appBuild;
}
if ($appReleaseDate !== '') {
    $sidebarFooterMetricsParts[] = format_date($appReleaseDate, 'd M Y');
}
$sidebarFooterMetricsLine = implode(' · ', $sidebarFooterMetricsParts);

function nav_active(string $path): string
{
    global $currentPath;

    return strpos($currentPath, $path) !== false ? 'active' : '';
}
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-top">
        <div class="sidebar-brand">
            <img src="<?php echo url('assets/img/logo.png'); ?>" alt="Logo" class="brand-logo">
            <div class="brand-text">
                <strong><?php echo e(APP_NAME); ?></strong>
                <?php if ($appProductTagline !== ''): ?>
                    <small><?php echo e($appProductTagline); ?></small>
                <?php endif; ?>
            </div>
        </div>

        <button type="button" class="icon-btn desktop-only sidebar-collapse-btn" data-sidebar-collapse aria-label="Collapse sidebar" title="Collapse sidebar">
            <?php echo lucide_icon_svg('panel_left_close', ['size' => 18]); ?>
        </button>
    </div>

    <div class="sidebar-user">
        <?php echo user_avatar_html($currentUser, 'avatar', true); ?>
        <div class="sidebar-user-text">
            <strong><?php echo e($currentUser['name'] ?? ''); ?></strong>
            <span><?php echo e($currentUser['role'] ?? ''); ?></span>
        </div>
    </div>

    <nav class="sidebar-nav">
        <?php if (rbac_is_finance($currentUser)): ?>
        <a class="nav-link <?php echo nav_active('/modules/party_account'); ?>" href="<?php echo e(url('modules/party_account/index.php')); ?>">
            <span class="nav-icon" aria-hidden="true"><?php echo lucide_icon_svg('landmark'); ?></span>
            <span class="nav-text">Party Account</span>
        </a>
        <a class="nav-link <?php echo nav_active('/modules/party_account/ledger.php'); ?>" href="<?php echo e(url('modules/party_account/ledger.php')); ?>">
            <span class="nav-icon" aria-hidden="true"><?php echo lucide_icon_svg('receipt_text'); ?></span>
            <span class="nav-text">Party Ledger</span>
        </a>
        <?php else: ?>
        <a class="nav-link <?php echo nav_active('/dashboard/'); ?>" href="<?php echo e(url('dashboard/index.php')); ?>">
            <span class="nav-icon" aria-hidden="true"><?php echo lucide_icon_svg('layout_dashboard'); ?></span>
            <span class="nav-text">Dashboard</span>
        </a>

        <a class="nav-link <?php echo nav_active('/tickets/list.php'); ?>" href="<?php echo e(url('tickets/list.php')); ?>">
            <span class="nav-icon" aria-hidden="true"><?php echo lucide_icon_svg('ticket'); ?></span>
            <span class="nav-text">Tickets</span>
        </a>

        <a class="nav-link <?php echo nav_active('/tickets/create.php'); ?>" href="<?php echo e(url('tickets/create.php')); ?>">
            <span class="nav-icon" aria-hidden="true"><?php echo lucide_icon_svg('square_plus'); ?></span>
            <span class="nav-text">Create Ticket</span>
        </a>

        <?php if (rbac_can_read_email_logs($currentUser)): ?>
        <a class="nav-link <?php echo nav_active('/emails/logs.php'); ?>" href="<?php echo e(url('emails/logs.php')); ?>">
            <span class="nav-icon" aria-hidden="true"><?php echo lucide_icon_svg('mail'); ?></span>
            <span class="nav-text">Email Logs</span>
        </a>
        <?php endif; ?>

         <?php if (($currentUser['role'] ?? '') === 'Admin'): ?>
         <a class="nav-link <?php echo nav_active('/emails/accounts.php'); ?>" href="<?php echo e(url('emails/accounts.php')); ?>">
             <span class="nav-icon" aria-hidden="true"><?php echo lucide_icon_svg('inbox'); ?></span>
             <span class="nav-text">Email Accounts</span>
         </a>
         <a class="nav-link <?php echo nav_active('/email/email_management.php'); ?>" href="<?php echo e(url('email/email_management.php')); ?>">
             <span class="nav-icon" aria-hidden="true"><?php echo lucide_icon_svg('zap'); ?></span>
             <span class="nav-text">Email Management</span>
         </a>
         <a class="nav-link <?php echo nav_active('/admin/unknown_emails.php'); ?>" href="<?php echo e(url('admin/unknown_emails.php')); ?>">
             <span class="nav-icon" aria-hidden="true"><?php echo lucide_icon_svg('circle_help'); ?></span>
             <span class="nav-text">Unknown Emails</span>
         </a>
         <?php endif; ?>
         <?php if (rbac_can_read_party_mapping($currentUser)): ?>
         <a class="nav-link <?php echo nav_active('/admin/vendor_am_mapping.php'); ?>" href="<?php echo e(url('admin/vendor_am_mapping.php')); ?>">
             <span class="nav-icon" aria-hidden="true"><?php echo lucide_icon_svg('link_2'); ?></span>
             <span class="nav-text">Party AM Mapping</span>
         </a>
         <?php endif; ?>
         <?php if (rbac_can_read_parties($currentUser)): ?>
         <a class="nav-link <?php echo nav_active('/admin/parties.php'); ?>" href="<?php echo e(url('admin/parties.php')); ?>">
             <span class="nav-icon" aria-hidden="true"><?php echo lucide_icon_svg('building'); ?></span>
             <span class="nav-text">Parties</span>
         </a>
         <?php endif; ?>
         <?php if (rbac_can_view_party_accounts($currentUser)): ?>
         <a class="nav-link <?php echo nav_active('/modules/party_account'); ?>" href="<?php echo e(url('modules/party_account/index.php')); ?>">
             <span class="nav-icon" aria-hidden="true"><?php echo lucide_icon_svg('landmark'); ?></span>
             <span class="nav-text">Party Account</span>
         </a>
         <?php if (rbac_is_admin($currentUser) || rbac_is_finance($currentUser)): ?>
         <a class="nav-link <?php echo nav_active('/modules/party_account/ledger.php'); ?>" href="<?php echo e(url('modules/party_account/ledger.php')); ?>">
             <span class="nav-icon" aria-hidden="true"><?php echo lucide_icon_svg('receipt_text'); ?></span>
             <span class="nav-text">Party Ledger</span>
         </a>
         <?php endif; ?>
         <?php endif; ?>
        <?php endif; ?>

        <a class="nav-link <?php echo nav_active('/profile/'); ?>" href="<?php echo e(url('profile/index.php')); ?>">
            <span class="nav-icon" aria-hidden="true"><?php echo lucide_icon_svg('user'); ?></span>
            <span class="nav-text">My Profile</span>
        </a>

        <?php if (rbac_can_read_release_management($currentUser)): ?>
            <a class="nav-link <?php echo nav_active('/admin/release_management.php'); ?>" href="<?php echo e(url('admin/release_management.php')); ?>">
                <span class="nav-icon" aria-hidden="true"><?php echo lucide_icon_svg('package'); ?></span>
                <span class="nav-text"><?php echo rbac_can_manage_release_management($currentUser) ? 'Release Management' : 'Release Notes'; ?></span>
            </a>
        <?php endif; ?>

        <?php if (($currentUser['role'] ?? '') === 'Admin'): ?>
            <a class="nav-link <?php echo nav_active('/users/list.php'); ?>" href="<?php echo e(url('users/list.php')); ?>">
                <span class="nav-icon" aria-hidden="true"><?php echo lucide_icon_svg('users'); ?></span>
                <span class="nav-text">Users</span>
            </a>

            <a class="nav-link <?php echo nav_active('/users/create.php'); ?>" href="<?php echo e(url('users/create.php')); ?>">
                <span class="nav-icon" aria-hidden="true"><?php echo lucide_icon_svg('user_plus'); ?></span>
                <span class="nav-text">New User</span>
            </a>
        <?php endif; ?>

        <?php if (($currentUser['role'] ?? '') === 'Admin'): ?>
            <?php
                $systemLogsPaths = ['/system_logs/index.php', '/system_logs/export_logs.php'];
                $systemLogsExpanded = false;
                foreach ($systemLogsPaths as $path) {
                    if (nav_active($path) === 'active') {
                        $systemLogsExpanded = true;
                        break;
                    }
                }
            ?>
            <div class="nav-group" data-nav-group="system-logs">
                <button type="button" class="nav-group-toggle <?php echo $systemLogsExpanded ? 'active' : ''; ?>" data-nav-group-toggle="system-logs" aria-expanded="<?php echo $systemLogsExpanded ? 'true' : 'false'; ?>">
                    <span class="nav-icon" aria-hidden="true"><?php echo lucide_icon_svg('MonitorCog'); ?></span>
                    <span class="nav-text">System Logs</span>
                    <span class="nav-group-arrow" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                    </span>
                </button>
                <div class="nav-group-children" <?php echo $systemLogsExpanded ? '' : 'hidden'; ?>>
                    <a class="nav-link <?php echo nav_active('/system_logs/index.php'); ?>" href="<?php echo e(url('system_logs/index.php')); ?>">
                        <span class="nav-icon" aria-hidden="true"><?php echo lucide_icon_svg('user'); ?></span>
                        <span class="nav-text">Auth Logs</span>
                    </a>
                    <a class="nav-link <?php echo nav_active('/system_logs/export_logs.php'); ?>" href="<?php echo e(url('system_logs/export_logs.php')); ?>">
                        <span class="nav-icon" aria-hidden="true"><?php echo lucide_icon_svg('file-down'); ?></span>
                        <span class="nav-text">Export Logs</span>
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </nav>

    <script>
        (function() {
            var toggles = document.querySelectorAll('[data-nav-group-toggle]');
            toggles.forEach(function(toggle) {
                toggle.addEventListener('click', function() {
                    var group = toggle.closest('[data-nav-group]');
                    if (!group) return;
                    var children = group.querySelector('.nav-group-children');
                    var isExpanded = toggle.getAttribute('aria-expanded') === 'true';
                    toggle.setAttribute('aria-expanded', isExpanded ? 'false' : 'true');
                    toggle.classList.toggle('active', !isExpanded);
                    if (children) {
                        children.hidden = isExpanded;
                    }
                });
            });
        })();
    </script>

    <footer class="sidebar-footer" role="contentinfo" title="<?php echo e($sidebarFooterTooltip); ?>">
        <?php if (rbac_can_read_release_management($currentUser)): ?>
        <a class="sidebar-footer-inner sidebar-footer-inner--link" href="<?php echo e(url('admin/release_management.php')); ?>" title="<?php echo e(rbac_can_manage_release_management($currentUser) ? 'Manage release (Admin)' : 'View release notes'); ?>">
        <?php else: ?>
        <div class="sidebar-footer-inner">
        <?php endif; ?>
            <div class="sidebar-footer-top">
                <div class="sidebar-footer-brand">
                    <img src="<?php echo url('assets/img/logo.png'); ?>" alt="" class="sidebar-footer-logo" width="22" height="22" decoding="async" loading="lazy">
                    <span class="sidebar-footer-brand-label"><?php echo e($sidebarFooterBrand); ?></span>
                </div>
                <span class="sidebar-env-badge sidebar-env-badge--<?php echo e($envDisplay['slug']); ?>">
                    <?php echo e($envDisplay['label']); ?>
                </span>
            </div>
            <div class="sidebar-footer-metrics"><?php echo e($sidebarFooterMetricsLine); ?></div>
            <?php if ($appReleaseFeatures !== []): ?>
                <?php
                    $firstFeatureLine = (string) $appReleaseFeatures[0];
                    $featurePreview = $firstFeatureLine;
                    if (function_exists('mb_strlen') && mb_strlen($firstFeatureLine) > 42) {
                        $featurePreview = mb_substr($firstFeatureLine, 0, 39) . '…';
                    } elseif (strlen($firstFeatureLine) > 42) {
                        $featurePreview = substr($firstFeatureLine, 0, 39) . '…';
                    }
                ?>
                <div class="sidebar-footer-features" title="<?php echo e(implode("\n", $appReleaseFeatures)); ?>">
                    <?php echo e($featurePreview); ?>
                </div>
            <?php endif; ?>
            <?php if ($sidebarCopyrightLine !== ''): ?>
                <div class="sidebar-footer-copyright"><?php echo e($sidebarCopyrightLine); ?></div>
            <?php endif; ?>
        <?php if (rbac_can_read_release_management($currentUser)): ?>
        </a>
        <?php else: ?>
        </div>
        <?php endif; ?>
    </footer>
</aside>
