<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/_page_bootstrap.php';

$pageTitle = 'Notification Sound';
$pageHeading = 'Notification Sound';
$pageDescription = 'Choose and test your notification tone.';
$activeProfileSection = 'notification';

include __DIR__ . '/../includes/header.php';
?>

<?php if ($flash): ?>
    <div class="flash flash-<?php echo e($flash['type']); ?>"><?php echo e($flash['message']); ?></div>
<?php endif; ?>

<div class="profile-hero profile-hero--compact">
    <div class="profile-hero-main">
        <?php echo user_avatar_html($displayUser, 'profile-avatar profile-avatar-xl'); ?>
        <div>
            <span class="eyebrow">Workspace</span>
            <h2 class="section-title" style="margin-bottom:6px;"><?php echo e($pageHeading); ?></h2>
            <p class="section-subtitle"><?php echo e($pageDescription); ?></p>
        </div>
    </div>
    <a href="<?php echo e(url('profile/index.php')); ?>" class="btn btn-outline btn-sm">Back to Profile</a>
</div>

<?php include __DIR__ . '/_section_nav.php'; ?>

<div class="profile-grid profile-grid--single-panel">
    <div class="info-card">
        <h2 class="section-title">Notification Sound</h2>
        <p class="section-subtitle" style="margin-bottom:14px;">Choose the notification sound and test it.</p>
        <div style="display:flex; gap:6px; align-items:flex-end; margin-bottom:8px;">
            <div style="flex:1;">
                <label for="notification-sound-select" style="font-size:12px; color:var(--muted); display:block; margin-bottom:4px;">Sound</label>
                <select id="notification-sound-select" style="width:100%; padding:6px 8px; border-radius:8px; border:1px solid var(--border); background:var(--panel); color:var(--text); font-size:13px;">
                    <option value="soft">Soft Ding</option>
                    <option value="chime">Gentle Chime</option>
                    <option value="digital">Digital Beep</option>
                    <option value="bell">Bell Ring</option>
                </select>
            </div>
            <button type="button" class="btn btn-secondary btn-sm" id="notification-sound-test" style="margin-bottom:2px;">Test</button>
        </div>
        <div style="display:flex; justify-content:space-between; align-items:center; font-size:12px;">
            <span style="color:var(--muted);">Enable sound</span>
            <button type="button" id="sound-enable-toggle" style="padding:4px 10px; border:1px solid var(--border); background:var(--panel); color:var(--text); border-radius:6px; cursor:pointer;">On</button>
        </div>
    </div>

    <div class="info-card">
        <h2 class="section-title">Notes</h2>
        <ul class="hint-list">
            <li>Your sound choice is stored in this browser.</li>
            <li>Use Test to preview the current tone instantly.</li>
            <li>This page keeps the settings compact and focused.</li>
        </ul>
    </div>
</div>

<script>
(function () {
    var soundSelect = document.getElementById('notification-sound-select');
    var soundToggle = document.getElementById('sound-enable-toggle');
    var soundTestBtn = document.getElementById('notification-sound-test');
    if (soundSelect) {
        var savedSound = localStorage.getItem('notification-sound') || 'soft';
        soundSelect.value = savedSound;
        soundSelect.addEventListener('change', function () {
            localStorage.setItem('notification-sound', soundSelect.value);
        });
    }
    if (soundToggle) {
        var enabled = localStorage.getItem('notification-sound-enabled') !== '0';
        soundToggle.textContent = enabled ? 'On' : 'Off';
        soundToggle.addEventListener('click', function () {
            enabled = !enabled;
            localStorage.setItem('notification-sound-enabled', enabled ? '1' : '0');
            soundToggle.textContent = enabled ? 'On' : 'Off';
        });
    }
    if (soundTestBtn) {
        soundTestBtn.addEventListener('click', function () {
            var selectedSound = soundSelect ? soundSelect.value : (localStorage.getItem('notification-sound') || 'soft');
            localStorage.setItem('notification-sound', selectedSound);
            if (localStorage.getItem('notification-sound-enabled') === '0') {
                localStorage.setItem('notification-sound-enabled', '1');
                if (soundToggle) {
                    soundToggle.textContent = 'On';
                }
            }
            if (typeof window.playNotificationSound === 'function') {
                window.playNotificationSound(selectedSound);
            }
        });
    }
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
