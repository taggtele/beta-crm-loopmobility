<?php
$pageTitle = $pageTitle ?? APP_NAME;
$pageHeading = $pageHeading ?? $pageTitle;
$pageDescription = $pageDescription ?? '';
$pageEyebrow = $pageEyebrow ?? 'Workspace';
$includeSidebar = $includeSidebar ?? true;
$extraStylesheets = $extraStylesheets ?? [];
$currentUser = $currentUser ?? null;

if ($currentUser && ($includeSidebar ?? true)) {
    if (!function_exists('rbac_enforce_finance_party_account_scope')) {
        require_once __DIR__ . '/rbac.php';
    }
    rbac_enforce_finance_party_account_scope();
}

$notificationsUnreadCount = 0;
$recentNotifications = [];
if ($includeSidebar && $currentUser && file_exists(__DIR__ . '/../modules/notifications/notification_service.php')) {
    require_once __DIR__ . '/../modules/notifications/notification_service.php';
    require_once __DIR__ . '/../services/notification_ui_service.php';
    $notificationsUnreadCount = notifications_unread_count($pdo, (string) ($currentUser['user_id'] ?? ''));
    $recentNotifications = notifications_dropdown_feed_for_user($pdo, (string) ($currentUser['user_id'] ?? ''), 30, 10);
}

require_once __DIR__ . '/lucide_icons.php';
?>
<!DOCTYPE html>
<html lang="en">
    
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($pageTitle); ?> | <?php echo e(APP_NAME); ?></title>
    <link rel="icon" type="image/png" href="<?php echo url('assets/img/favicon.png'); ?>">
    <meta name="description" content="NOC Ticket Management System by Tagg TeleServices">
<script>
        (function () {
            try {
                if (window.localStorage.getItem('app-theme') === 'dark') {
                    document.documentElement.classList.add('theme-dark');
                }
                var savedColor = localStorage.getItem('app-theme-color');
                if (savedColor && savedColor !== 'blue') {
                    applyThemeColor(savedColor);
                }
            } catch (e) {}
            try {
                var savedSidebar = localStorage.getItem('app-sidebar') || 'dark';
                applySidebarTheme(savedSidebar);
                if (!document.getElementById('sidebar')) {
                    document.addEventListener('DOMContentLoaded', function () {
                        applySidebarTheme(savedSidebar);
                    });
                }
            } catch (e) {
                applySidebarTheme('dark');
            }
        }());

        function applyThemeColor(color) {
            var r = document.querySelector(':root');
            var colors = {
                blue: {primary: '#1d4ed8', primarySoft: '#dbeafe'},
                green: {primary: '#15803d', primarySoft: '#dcfce7'},
                purple: {primary: '#7c3aed', primarySoft: '#ede9fe'},
                orange: {primary: '#ea580c', primarySoft: '#ffedd5'},
                red: {primary: '#b91c1c', primarySoft: '#fee2e2'},
                magenta: {primary: '#6F1C53', primarySoft: '#fce7f3'},
                teal: {primary: '#0f766e', primarySoft: '#ccfbf1'},
                pink: {primary: '#be185d', primarySoft: '#fce7f3'},
                slate: {primary: '#475569', primarySoft: '#f1f5f9'}
            };
            var c = colors[color] || colors.blue;
            r.style.setProperty('--primary', c.primary);
            r.style.setProperty('--primary-soft', c.primarySoft);
            localStorage.setItem('app-theme-color', color);
        }
        function applySidebarTheme(theme) {
            var sidebar = document.getElementById('sidebar');
            if (!sidebar) return;
            var themes = {
                dark: {bg: 'linear-gradient(180deg, #111827 0%, #1f2937 100%)', text: '#ffffff'},
                navy: {bg: 'linear-gradient(180deg, #172033 0%, #0f172a 100%)', text: '#ffffff'},
                black: {bg: 'linear-gradient(180deg, #000000 0%, #1a1a1a 100%)', text: '#ffffff'},
                maroon: {bg: 'linear-gradient(180deg, #2c0a1e 0%, #1f0514 100%)', text: '#ffffff'},
                mulberry: {bg: 'linear-gradient(180deg, #4a0d26 0%, #2d0815 100%)', text: '#ffffff'},
                forest: {bg: 'linear-gradient(180deg, #052e16 0%, #064e3b 100%)', text: '#ffffff'},
                indigo: {bg: 'linear-gradient(180deg, #1e1b4b 0%, #312e81 100%)', text: '#ffffff'},
                violet: {bg: 'linear-gradient(180deg, #2e1065 0%, #4c1d95 100%)', text: '#ffffff'},
                rose: {bg: 'linear-gradient(180deg, #4c0519 0%, #881337 100%)', text: '#ffffff'},
                slate: {bg: 'linear-gradient(180deg, #0f172a 0%, #1e293b 100%)', text: '#ffffff'},
                teal: {bg: 'linear-gradient(180deg, #042f2e 0%, #134e4a 100%)', text: '#ffffff'},
                emerald: {bg: 'linear-gradient(180deg, #022c22 0%, #064e3b 100%)', text: '#ffffff'}
            };
            var t;
            if (theme && theme.indexOf('custom:') === 0) {
                var hex = theme.replace('custom:', '');
                t = {bg: 'linear-gradient(180deg, ' + hex + ' 0%, ' + hex + ' 100%)', text: '#ffffff'};
            } else {
                t = themes[theme] || themes.dark;
            }
            sidebar.style.background = t.bg;
            sidebar.style.color = t.text;
            localStorage.setItem('app-sidebar', theme);
        }
    </script>
    <link rel="stylesheet" href="<?php echo e(url('assets/css/app.css')); ?>">
    <?php if ($extraStylesheets): ?>
        <?php foreach ($extraStylesheets as $apdSheet): ?>
            <link rel="stylesheet" href="<?php echo e(url($apdSheet)); ?>">
        <?php endforeach; ?>
    <?php endif; ?>
    <?php foreach (($extraHeadScripts ?? []) as $script): ?>
        <?php echo $script; ?>
    <?php endforeach; ?>
</head>
<body class="<?php echo $includeSidebar ? 'body-app' : 'body-auth'; ?>">
<?php if ($includeSidebar): ?>
    <div class="app-layout">
        <?php include __DIR__ . '/sidebar.php'; ?>

        <div class="app-main">
            <header class="topbar">
                <div class="topbar-left">
                    <button type="button" class="menu-toggle icon-btn" data-sidebar-toggle aria-label="Open navigation">
                        <span></span>
                        <span></span>
                        <span></span>
                    </button>

                    <button type="button" class="icon-btn desktop-only" data-sidebar-collapse aria-label="Collapse sidebar" title="Collapse sidebar">
                        <?php echo lucide_icon_svg('panel_left_close', ['size' => 18]); ?>
                    </button>

                    <div class="page-title">
                        <span class="eyebrow"><?php echo e($pageEyebrow); ?></span>
                        <h1><?php echo e($pageHeading); ?></h1>
                        <?php if ($pageDescription !== ''): ?>
                            <p><?php echo e($pageDescription); ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="topbar-right">
                    <button
                        type="button"
                        class="icon-btn theme-toggle-btn"
                        data-theme-toggle
                        data-theme-light-label="Light mode"
                        data-theme-dark-label="Dark mode"
                        aria-pressed="false"
                        aria-label="Toggle dark theme"
                        title="Toggle dark theme"
                    >
                        <span class="theme-toggle-icon" data-theme-icon>Moon</span>
                    </button>

                     <div class="profile-menu" data-dropdown data-notification-root>
                         <button type="button" class="profile-trigger" data-dropdown-trigger aria-expanded="false">
                             <span class="profile-avatar notification-bell">
                                 <span aria-hidden="true">&#128276;</span>
                                 <?php if ($notificationsUnreadCount > 0): ?>
                                     <span class="notification-pill" data-notification-pill><?php echo e((string) $notificationsUnreadCount); ?></span>
                                 <?php else: ?>
                                     <span class="notification-pill" data-notification-pill hidden>0</span>
                                 <?php endif; ?>
                             </span>
                             <span class="profile-meta profile-meta--notification">
                                 <strong>Notifications</strong>
                                 <small class="profile-meta__subtle"><?php echo e(($currentUser['email'] ?? '') !== '' ? $currentUser['email'] : ($currentUser['user_id'] ?? '')); ?></small>
                                 <small data-notification-count><?php echo $notificationsUnreadCount > 0 ? e($notificationsUnreadCount . ' unread') : 'No new alerts'; ?></small>
                             </span>
                             <span class="profile-caret">v</span>
                         </button>

                         <div
                             class="dropdown-menu"
                             data-dropdown-menu
                             data-notification-stream="<?php echo e(url('controllers/notifications_stream.php')); ?>"
                             data-notification-mark-read="<?php echo e(url('modules/notifications/mark_read.php')); ?>"
                         >
                             <div class="notification-dropdown-head">
                                 <strong>Inbox alerts</strong>
                                 <small><?php echo e(($currentUser['email'] ?? '') !== '' ? $currentUser['email'] : ($currentUser['user_id'] ?? '')); ?></small>
                             </div>

                             <div data-notification-list>
                                 <?php echo notification_ui_service_render_items($recentNotifications); ?>
                             </div>

                             <div class="notification-sound-panel">
                                 <div class="notification-sound-panel__controls">
                                     <label class="notification-sound-panel__field">
                                         <span>Sound</span>
                                         <select id="notification-sound-select">
                                             <option value="soft">Soft</option>
                                             <option value="chime">Chime</option>
                                             <option value="digital">Digital</option>
                                             <option value="bell">Bell</option>
                                         </select>
                                     </label>
                                     <button type="button" class="btn btn-secondary btn-sm" id="sound-enable-toggle">🔊 On</button>
                                     <button type="button" class="btn btn-primary btn-sm" id="notification-sound-test">Test</button>
                                 </div>
                             </div>

                             <form method="POST" action="<?php echo e(url('modules/notifications/mark_read.php')); ?>" style="padding:8px 12px 4px;">
                                 <?php echo csrf_field(); ?>
                                 <input type="hidden" name="redirect_to" value="<?php echo e($_SERVER['REQUEST_URI'] ?? url('dashboard/index.php')); ?>">
                                 <button type="submit" class="btn btn-secondary btn-sm" style="width:100%;">Mark All Read</button>
                             </form>
                         </div>
                     </div>

                    <div class="topbar-chip">
                        <span class="chip-label">Signed in as</span>
                        <strong><?php echo e($currentUser['role'] ?? ''); ?></strong>
                    </div>

                    <div class="profile-menu" data-dropdown>
                        <button type="button" class="profile-trigger" data-dropdown-trigger aria-expanded="false">
                            <?php echo user_avatar_html($currentUser, 'profile-avatar', true); ?>
                            <span class="profile-meta">
                                <strong><?php echo e($currentUser['name'] ?? ''); ?></strong>
                                <small><?php echo e($currentUser['user_id'] ?? ''); ?></small>
                            </span>
                            <span class="profile-caret">v</span>
                        </button>

                        <div class="dropdown-menu" data-dropdown-menu>
                            <a href="<?php echo e(url('profile/index.php')); ?>">My Profile</a>
                            <?php if (($currentUser['role'] ?? '') === 'Admin'): ?>
                                <a href="<?php echo e(url('users/list.php')); ?>">Manage Users</a>
                            <?php endif; ?>
                            <a href="<?php echo e(url('tickets/list.php')); ?>">My Tickets</a>
                            <a href="<?php echo e(url('auth/logout.php')); ?>" class="danger-link">Logout</a>
                        </div>
                    </div>
                </div>
            </header>

            <main class="page-content">
<?php else: ?>
            <main class="auth-content">
<?php endif; ?>


