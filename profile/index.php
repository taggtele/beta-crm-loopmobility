<?php
require_once __DIR__ . '/../includes/auth.php';

$currentUser = require_login($pdo);
$pageTitle = 'My Profile';
$pageHeading = 'My Profile';
$pageDescription = 'View and manage your account settings.';
$message = null;

// ================= AGENT: UPDATE OWN PROFILE IMAGE + PHONE ONLY =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $currentUser['role'] !== 'Admin') {
    verify_csrf();

    $phone = trim($_POST['phone'] ?? '');
    $removeProfileImage = isset($_POST['remove_profile_image']) && $_POST['remove_profile_image'] === '1';
    $uploadedProfileImage = $_FILES['profile_image'] ?? null;
    $hasUploadedProfileImage = $uploadedProfileImage && (int) ($uploadedProfileImage['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    $nextProfileImage = $currentUser['profile_image'] ?? null;

    if ($phone !== '' && !preg_match('/^[+]?[0-9\s\-]{7,20}$/', $phone)) {
        $message = ['type' => 'error', 'text' => 'Phone number format is invalid.'];
    }

    if ($message === null && $hasUploadedProfileImage) {
        try {
            $nextProfileImage = store_profile_image_upload($uploadedProfileImage);
        } catch (RuntimeException $e) {
            $message = ['type' => 'error', 'text' => $e->getMessage()];
        }
    }

    if ($message === null && $removeProfileImage && !$hasUploadedProfileImage) {
        $nextProfileImage = null;
    }

    if ($message === null) {
        $updateStmt = $pdo->prepare('UPDATE users SET profile_image = :profile_image, phone = :phone WHERE id = :id');
        $updateStmt->execute([
            ':profile_image' => $nextProfileImage,
            ':phone' => $phone !== '' ? $phone : null,
            ':id' => $currentUser['id'],
        ]);

        $previousProfileImage = $currentUser['profile_image'] ?? null;
        if ($nextProfileImage !== $previousProfileImage && $previousProfileImage) {
            delete_profile_image_file($previousProfileImage);
        }

        set_flash('success', 'Profile updated successfully.');
        redirect('profile/index.php');
    }
}

// ================= ADMIN: UPDATE OWN FULL PROFILE =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $currentUser['role'] === 'Admin') {
    verify_csrf();

    $name = trim($_POST['name'] ?? '');
    $currentPassword = trim($_POST['current_password'] ?? '');
    $newPassword = trim($_POST['new_password'] ?? '');
    $confirmPassword = trim($_POST['confirm_password'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $removeProfileImage = isset($_POST['remove_profile_image']) && $_POST['remove_profile_image'] === '1';
    $uploadedProfileImage = $_FILES['profile_image'] ?? null;
    $hasUploadedProfileImage = $uploadedProfileImage && (int) ($uploadedProfileImage['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    $nextProfileImage = $currentUser['profile_image'] ?? null;
    $passwordToStore = null;

    if ($name === '') {
        $message = ['type' => 'error', 'text' => 'Name is required.'];
    }

    if ($message === null && $phone !== '' && !preg_match('/^[+]?[0-9\s\-]{7,20}$/', $phone)) {
        $message = ['type' => 'error', 'text' => 'Phone number format is invalid.'];
    }

    if ($message === null && $department !== '' && !in_array($department, ['NOC', 'Sales', 'Routing', 'Marketing', 'Development', 'Testing', 'International', 'Support'], true)) {
        $message = ['type' => 'error', 'text' => 'Invalid department selected.'];
    }

    if ($message === null && ($newPassword !== '' || $confirmPassword !== '')) {
        $passwordStmt = $pdo->prepare('SELECT password FROM users WHERE id = :id LIMIT 1');
        $passwordStmt->execute([':id' => $currentUser['id']]);
        $passwordRow = $passwordStmt->fetch();

        if ($currentPassword === '') {
            $message = ['type' => 'error', 'text' => 'Enter your current password to set a new password.'];
        } elseif (!$passwordRow || !verify_user_password($currentPassword, $passwordRow['password'])) {
            $message = ['type' => 'error', 'text' => 'Current password is incorrect.'];
        } elseif (strlen($newPassword) < 6) {
            $message = ['type' => 'error', 'text' => 'New password must be at least 6 characters long.'];
        } elseif ($newPassword !== $confirmPassword) {
            $message = ['type' => 'error', 'text' => 'New password and confirm password do not match.'];
        } else {
            $passwordToStore = password_hash($newPassword, PASSWORD_DEFAULT);
        }
    }

    if ($message === null && $hasUploadedProfileImage) {
        try {
            $nextProfileImage = store_profile_image_upload($uploadedProfileImage);
        } catch (RuntimeException $exception) {
            $message = ['type' => 'error', 'text' => $exception->getMessage()];
        }
    }

    if ($message === null && $removeProfileImage && !$hasUploadedProfileImage) {
        $nextProfileImage = null;
    }

    if ($message === null) {
        $sql = 'UPDATE users SET name = :name, profile_image = :profile_image, phone = :phone, department = :department';
        $params = [
            ':name' => $name,
            ':profile_image' => $nextProfileImage,
            ':phone' => $phone !== '' ? $phone : null,
            ':department' => $department !== '' ? $department : null,
            ':id' => $currentUser['id'],
        ];

        if ($passwordToStore !== null) {
            $sql .= ', password = :password';
            $params[':password'] = $passwordToStore;
        }

        $sql .= ' WHERE id = :id';

        $updateStmt = $pdo->prepare($sql);
        $updateStmt->execute($params);

        $previousProfileImage = $currentUser['profile_image'] ?? null;
        if ($nextProfileImage !== $previousProfileImage && $previousProfileImage) {
            delete_profile_image_file($previousProfileImage);
        }

        if ($passwordToStore !== null) {
            set_flash('success', 'Profile, photo, password, phone, and department updated successfully.');
        } elseif ($nextProfileImage !== ($currentUser['profile_image'] ?? null)) {
            set_flash('success', 'Profile updated successfully with your new photo settings.');
        } else {
            set_flash('success', 'Profile updated successfully.');
        }

        redirect('profile/index.php');
    }
}

$flash = get_flash();
$currentUser = require_login($pdo);

$statsStmt = $pdo->prepare(
    'SELECT
        SUM(CASE WHEN assign_to = :user_id_a THEN 1 ELSE 0 END) AS assigned_total,
        SUM(CASE WHEN assign_to = :user_id_b AND status = :open_status THEN 1 ELSE 0 END) AS open_assigned_total,
        SUM(CASE WHEN created_by = :user_id_c THEN 1 ELSE 0 END) AS created_total
     FROM tickets'
);
$statsStmt->execute([
    ':user_id_a' => $currentUser['user_id'],
    ':user_id_b' => $currentUser['user_id'],
    ':user_id_c' => $currentUser['user_id'],
    ':open_status' => 'Open',
]);
$ticketStats = $statsStmt->fetch() ?: [];

$profileStats = [
    'assigned_total' => (int) ($ticketStats['assigned_total'] ?? 0),
    'open_assigned_total' => (int) ($ticketStats['open_assigned_total'] ?? 0),
    'created_total' => (int) ($ticketStats['created_total'] ?? 0),
];

$displayUser = $currentUser;

include __DIR__ . '/../includes/header.php';
?>

<?php if ($flash): ?>
    <div class="flash flash-<?php echo e($flash['type']); ?>"><?php echo e($flash['message']); ?></div>
<?php endif; ?>

<?php if ($message): ?>
    <div class="flash flash-<?php echo e($message['type']); ?>"><?php echo e($message['text']); ?></div>
<?php endif; ?>

<div class="profile-page">
    <div class="profile-hero profile-hero--compact">
        <div class="profile-hero-main">
            <?php echo user_avatar_html($displayUser, 'profile-avatar profile-avatar-xl'); ?>
            <div class="profile-hero-copy">
                <span class="eyebrow">Workspace</span>
                <h2 class="section-title" style="margin-bottom:6px;">My Profile</h2>
                <p class="section-subtitle" style="margin-bottom:10px;">Compact account hub with quick links for signature, templates, and notification sound.</p>
                <div class="profile-hero-tags">
                    <span class="badge badge-<?php echo strtolower($currentUser['status']) === 'active' ? 'active' : 'suspended'; ?>"><?php echo e($currentUser['status']); ?></span>
                    <span class="badge badge-<?php echo strtolower($currentUser['role']) === 'admin' ? 'admin' : 'agent'; ?>"><?php echo e($currentUser['role']); ?></span>
                    <span class="badge"><?php echo e($currentUser['user_id']); ?></span>
                </div>
            </div>
            <div class="profile-hero-actions">
                <a href="<?php echo e(url('profile/signature.php')); ?>" class="btn btn-outline btn-sm profile-action-pill">
                    <span>Signature</span>
                    <?php echo lucide_icon_svg('arrow_right', ['size' => 16]); ?>
                </a>
                <a href="<?php echo e(url('profile/templates.php')); ?>" class="btn btn-outline btn-sm profile-action-pill">
                    <span>Templates</span>
                    <?php echo lucide_icon_svg('arrow_right', ['size' => 16]); ?>
                </a>
                <a href="<?php echo e(url('profile/notification.php')); ?>" class="btn btn-outline btn-sm profile-action-pill">
                    <span>Notification</span>
                    <?php echo lucide_icon_svg('arrow_right', ['size' => 16]); ?>
                </a>
            </div>
        </div>

        <div class="profile-stats-strip">
            <div class="profile-stat-tile">
                <span>Assigned</span>
                <strong><?php echo e((string) $profileStats['assigned_total']); ?></strong>
                <small>tickets owned by you</small>
            </div>
            <div class="profile-stat-tile">
                <span>Open Queue</span>
                <strong><?php echo e((string) $profileStats['open_assigned_total']); ?></strong>
                <small>still active right now</small>
            </div>
            <div class="profile-stat-tile">
                <span>Created</span>
                <strong><?php echo e((string) $profileStats['created_total']); ?></strong>
                <small>tickets raised by you</small>
            </div>
        </div>
    </div>

    <?php $activeProfileSection = 'profile'; include __DIR__ . '/_section_nav.php'; ?>

    <div class="profile-grid">
        <div class="form-card">
            <?php if ($currentUser['role'] === 'Admin'): ?>
                <form method="POST" enctype="multipart/form-data" novalidate>
                    <?php echo csrf_field(); ?>

                    <div class="form-grid">
                        <div class="input-group full">
                            <label for="name">Display Name</label>
                            <input type="text" id="name" name="name" value="<?php echo e($_POST['name'] ?? $currentUser['name']); ?>" required>
                            <div class="field-help">This name appears in the sidebar, topbar, notifications, and ticket ownership views.</div>
                        </div>

                        <div class="input-group full">
                            <label for="profile_image">Profile Image</label>
                            <div class="profile-upload-row">
                                <div class="profile-upload-preview">
                                    <?php echo user_avatar_html($displayUser, 'profile-avatar profile-avatar-lg'); ?>
                                </div>
                                <div class="profile-upload-meta">
                                    <input type="file" id="profile_image" name="profile_image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                                    <div class="field-help">Upload a JPG, PNG, or WebP image up to 2 MB.</div>
                                    <?php if (!empty($currentUser['profile_image'])): ?>
                                        <label class="checkbox-row">
                                            <input type="checkbox" name="remove_profile_image" value="1">
                                            <span>Remove current profile image</span>
                                        </label>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="input-group">
                            <label for="phone">Phone Number</label>
                            <input type="tel" id="phone" name="phone" value="<?php echo e($_POST['phone'] ?? ($currentUser['phone'] ?? '')); ?>" placeholder="+91 98765 43210" style="font-family:monospace;">
                            <div class="field-help">Optional. Include country code for international numbers.</div>
                        </div>

                        <div class="input-group">
                            <label for="department">Department</label>
                            <select id="department" name="department">
                                <option value="">Select department (optional)</option>
                                <?php
                                $departments = ['NOC', 'Sales', 'Routing', 'Marketing', 'Development', 'Testing', 'International', 'Support'];
                                foreach ($departments as $dept):
                                ?>
                                    <option value="<?php echo e($dept); ?>" <?php echo (($_POST['department'] ?? ($currentUser['department'] ?? '')) === $dept) ? 'selected' : ''; ?>>
                                        <?php echo e($dept); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="field-help">Optional. Team or functional area.</div>
                        </div>

                        <div class="input-group">
                            <label for="current_password">Current Password</label>
                            <input type="password" id="current_password" name="current_password" placeholder="Enter current password to change it">
                        </div>

                        <div class="input-group">
                            <label for="new_password">New Password</label>
                            <input type="password" id="new_password" name="new_password" placeholder="Leave blank to keep current">
                        </div>

                        <div class="input-group full">
                            <label for="confirm_password">Confirm New Password</label>
                            <input type="password" id="confirm_password" name="confirm_password" placeholder="Repeat new password">
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Save Profile</button>
                    </div>
                </form>
            <?php else: ?>
                <form method="POST" enctype="multipart/form-data" novalidate>
                    <?php echo csrf_field(); ?>

                    <div class="form-grid">
                        <div class="input-group full">
                            <label for="profile_image">Profile Image</label>
                            <div class="profile-upload-row">
                                <div class="profile-upload-preview">
                                    <?php echo user_avatar_html($displayUser, 'profile-avatar profile-avatar-lg'); ?>
                                </div>
                                <div class="profile-upload-meta">
                                    <input type="file" id="profile_image" name="profile_image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                                    <div class="field-help">Upload a JPG, PNG, or WebP image up to 2 MB. This image will appear in the sidebar and account menu.</div>
                                    <?php if (!empty($currentUser['profile_image'])): ?>
                                        <label class="checkbox-row">
                                            <input type="checkbox" name="remove_profile_image" value="1">
                                            <span>Remove current profile image</span>
                                        </label>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="input-group">
                            <label for="phone">Phone Number</label>
                            <input type="tel" id="phone" name="phone" value="<?php echo e($_POST['phone'] ?? ($currentUser['phone'] ?? '')); ?>" placeholder="+91 98765 43210" style="font-family:monospace;">
                            <div class="field-help">Update your contact number. Optional.</div>
                        </div>

                        <div class="input-group">
                            <label>Department</label>
                            <input type="text" value="<?php echo e($currentUser['department'] ?? 'Not assigned'); ?>" readonly style="background:var(--panel-soft);color:var(--muted);">
                            <div class="field-help">Department is assigned by admin.</div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Update Photo & Phone</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>

        <div class="profile-summary">
            <div class="info-card">
                <h2 class="section-title">Account Details</h2>
                <div class="meta-list">
                    <div class="meta-item">
                        <span>User ID</span>
                        <strong><?php echo e($currentUser['user_id']); ?></strong>
                    </div>
                    <div class="meta-item">
                        <span>Role</span>
                        <strong><?php echo e($currentUser['role']); ?></strong>
                    </div>
                    <div class="meta-item">
                        <span>Status</span>
                        <strong><?php echo e($currentUser['status']); ?></strong>
                    </div>
                    <div class="meta-item">
                        <span>Created At</span>
                        <strong><?php echo e(format_date($currentUser['created_at'])); ?></strong>
                    </div>
                    <div class="meta-item">
                        <span>Profile Photo</span>
                        <strong><?php echo !empty($currentUser['profile_image']) ? 'Uploaded' : 'Using initials'; ?></strong>
                    </div>
                    <div class="meta-item">
                        <span>Phone</span>
                        <strong><?php echo e($currentUser['phone'] ?? 'Not set'); ?></strong>
                    </div>
                    <div class="meta-item">
                        <span>Department</span>
                        <strong><?php echo e($currentUser['department'] ?? 'Not assigned'); ?></strong>
                    </div>
                    <div class="meta-item">
                        <span>Theme Preference</span>
                        <strong data-theme-status>Light mode</strong>
                    </div>
                </div>
            </div>

            <div class="info-card">
                <h2 class="section-title">Appearance</h2>
                <p class="section-subtitle" style="margin-bottom:14px;">Switch between the brighter workspace and a darker, late-shift friendly layout.</p>
                <button
                    type="button"
                    class="btn btn-secondary"
                    data-theme-toggle
                    data-theme-light-label="Switch to light mode"
                    data-theme-dark-label="Switch to dark mode"
                >
                    <span data-theme-icon>Moon</span>
                    <span data-theme-label>Switch to dark mode</span>
                </button>

                <h3 style="margin-top:20px; margin-bottom:12px; font-size:14px; color:var(--text);">Accent Color</h3>
                <p class="section-subtitle" style="margin-bottom:14px;">Choose your preferred color theme for the dashboard.</p>
                <div class="theme-grid" style="display:grid; grid-template-columns:repeat(3, 1fr); gap:10px; max-width:200px;">
                    <label class="theme-option" style="cursor:pointer;">
                        <input type="radio" name="app_theme_color" value="blue" style="display:none;">
                        <div class="theme-preview" style="height:40px; border-radius:10px; background:linear-gradient(135deg, #1d4ed8, #3b82f6); border:2px solid transparent; display:flex; align-items:center; justify-content:center; color:#fff; font-size:11px;">Blue</div>
                    </label>
                    <label class="theme-option" style="cursor:pointer;">
                        <input type="radio" name="app_theme_color" value="green" style="display:none;">
                        <div class="theme-preview" style="height:40px; border-radius:10px; background:linear-gradient(135deg, #15803d, #22c55e); border:2px solid transparent; display:flex; align-items:center; justify-content:center; color:#fff; font-size:11px;">Green</div>
                    </label>
                    <label class="theme-option" style="cursor:pointer;">
                        <input type="radio" name="app_theme_color" value="purple" style="display:none;">
                        <div class="theme-preview" style="height:40px; border-radius:10px; background:linear-gradient(135deg, #7c3aed, #a78bfa); border:2px solid transparent; display:flex; align-items:center; justify-content:center; color:#fff; font-size:11px;">Purple</div>
                    </label>
                    <label class="theme-option" style="cursor:pointer;">
                        <input type="radio" name="app_theme_color" value="orange" style="display:none;">
                        <div class="theme-preview" style="height:40px; border-radius:10px; background:linear-gradient(135deg, #ea580c, #f97316); border:2px solid transparent; display:flex; align-items:center; justify-content:center; color:#fff; font-size:11px;">Orange</div>
                    </label>
                    <label class="theme-option" style="cursor:pointer;">
                        <input type="radio" name="app_theme_color" value="red" style="display:none;">
                        <div class="theme-preview" style="height:40px; border-radius:10px; background:linear-gradient(135deg, #b91c1c, #ef4444); border:2px solid transparent; display:flex; align-items:center; justify-content:center; color:#fff; font-size:11px;">Red</div>
                    </label>
                    <label class="theme-option" style="cursor:pointer;">
                        <input type="radio" name="app_theme_color" value="magenta" style="display:none;">
                        <div class="theme-preview" style="height:40px; border-radius:10px; background:linear-gradient(135deg, #6F1C53, #ec4899); border:2px solid transparent; display:flex; align-items:center; justify-content:center; color:#fff; font-size:11px;">Magenta</div>
                    </label>
                    <label class="theme-option" style="cursor:pointer;">
                        <input type="radio" name="app_theme_color" value="teal" style="display:none;">
                        <div class="theme-preview" style="height:40px; border-radius:10px; background:linear-gradient(135deg, #0f766e, #14b8a6); border:2px solid transparent; display:flex; align-items:center; justify-content:center; color:#fff; font-size:11px;">Teal</div>
                    </label>
                    <label class="theme-option" style="cursor:pointer;">
                        <input type="radio" name="app_theme_color" value="slate" style="display:none;">
                        <div class="theme-preview" style="height:40px; border-radius:10px; background:linear-gradient(135deg, #475569, #94a3b8); border:2px solid transparent; display:flex; align-items:center; justify-content:center; color:#fff; font-size:11px;">Slate</div>
                    </label>
                </div>

                <h3 style="margin-top:20px; margin-bottom:12px; font-size:14px; color:var(--text);">Sidebar Theme</h3>
                <p class="section-subtitle" style="margin-bottom:14px;">Choose the sidebar background theme.</p>
                <div class="theme-grid" style="display:grid; grid-template-columns:repeat(3, 1fr); gap:10px; max-width:240px;">
                    <label class="theme-option" style="cursor:pointer;">
                        <input type="radio" name="app_sidebar" value="dark" style="display:none;">
                        <div class="theme-preview" style="height:40px; border-radius:10px; background:linear-gradient(180deg, #111827, #1f2937); border:2px solid transparent; display:flex; align-items:center; justify-content:center; color:#fff; font-size:10px;">Dark</div>
                    </label>
                    <label class="theme-option" style="cursor:pointer;">
                        <input type="radio" name="app_sidebar" value="navy" style="display:none;">
                        <div class="theme-preview" style="height:40px; border-radius:10px; background:linear-gradient(180deg, #172033, #0f172a); border:2px solid transparent; display:flex; align-items:center; justify-content:center; color:#fff; font-size:10px;">Navy</div>
                    </label>
                    <label class="theme-option" style="cursor:pointer;">
                        <input type="radio" name="app_sidebar" value="black" style="display:none;">
                        <div class="theme-preview" style="height:40px; border-radius:10px; background:linear-gradient(180deg, #000000, #1a1a1a); border:2px solid transparent; display:flex; align-items:center; justify-content:center; color:#fff; font-size:10px;">Black</div>
                    </label>
                    <label class="theme-option" style="cursor:pointer;">
                        <input type="radio" name="app_sidebar" value="maroon" style="display:none;">
                        <div class="theme-preview" style="height:40px; border-radius:10px; background:linear-gradient(180deg, #2c0a1e, #1f0514); border:2px solid transparent; display:flex; align-items:center; justify-content:center; color:#fff; font-size:10px;">Maroon</div>
                    </label>
                    <label class="theme-option" style="cursor:pointer;">
                        <input type="radio" name="app_sidebar" value="mulberry" style="display:none;">
                        <div class="theme-preview" style="height:40px; border-radius:10px; background:linear-gradient(180deg, #4a0d26, #2d0815); border:2px solid transparent; display:flex; align-items:center; justify-content:center; color:#fff; font-size:10px;">Mulberry</div>
                    </label>
                    <label class="theme-option" style="cursor:pointer;">
                        <input type="radio" name="app_sidebar" value="forest" style="display:none;">
                        <div class="theme-preview" style="height:40px; border-radius:10px; background:linear-gradient(180deg, #052e16, #064e3b); border:2px solid transparent; display:flex; align-items:center; justify-content:center; color:#fff; font-size:10px;">Forest</div>
                    </label>
                    <label class="theme-option" style="cursor:pointer;">
                        <input type="radio" name="app_sidebar" value="indigo" style="display:none;">
                        <div class="theme-preview" style="height:40px; border-radius:10px; background:linear-gradient(180deg, #1e1b4b, #312e81); border:2px solid transparent; display:flex; align-items:center; justify-content:center; color:#fff; font-size:10px;">Indigo</div>
                    </label>
                    <label class="theme-option" style="cursor:pointer;">
                        <input type="radio" name="app_sidebar" value="violet" style="display:none;">
                        <div class="theme-preview" style="height:40px; border-radius:10px; background:linear-gradient(180deg, #2e1065, #4c1d95); border:2px solid transparent; display:flex; align-items:center; justify-content:center; color:#fff; font-size:10px;">Violet</div>
                    </label>
                    <label class="theme-option" style="cursor:pointer;">
                        <input type="radio" name="app_sidebar" value="rose" style="display:none;">
                        <div class="theme-preview" style="height:40px; border-radius:10px; background:linear-gradient(180deg, #4c0519, #881337); border:2px solid transparent; display:flex; align-items:center; justify-content:center; color:#fff; font-size:10px;">Rose</div>
                    </label>
                    <label class="theme-option" style="cursor:pointer;">
                        <input type="radio" name="app_sidebar" value="slate" style="display:none;">
                        <div class="theme-preview" style="height:40px; border-radius:10px; background:linear-gradient(180deg, #0f172a, #1e293b); border:2px solid transparent; display:flex; align-items:center; justify-content:center; color:#fff; font-size:10px;">Slate</div>
                    </label>
                    <label class="theme-option" style="cursor:pointer;">
                        <input type="radio" name="app_sidebar" value="teal" style="display:none;">
                        <div class="theme-preview" style="height:40px; border-radius:10px; background:linear-gradient(180deg, #042f2e, #134e4a); border:2px solid transparent; display:flex; align-items:center; justify-content:center; color:#fff; font-size:10px;">Teal</div>
                    </label>
                    <label class="theme-option" style="cursor:pointer;">
                        <input type="radio" name="app_sidebar" value="emerald" style="display:none;">
                        <div class="theme-preview" style="height:40px; border-radius:10px; background:linear-gradient(180deg, #022c22, #064e3b); border:2px solid transparent; display:flex; align-items:center; justify-content:center; color:#fff; font-size:10px;">Emerald</div>
                    </label>
                    <label class="theme-option" style="cursor:pointer;">
                        <input type="radio" name="app_sidebar" value="custom" style="display:none;">
                        <div class="theme-preview" id="custom-sidebar-preview" style="height:40px; border-radius:10px; background:#111827; border:2px dashed rgba(255,255,255,0.3); display:flex; align-items:center; justify-content:center; color:#fff; font-size:10px;">Custom</div>
                        <input type="color" id="custom-sidebar-color" value="#111827" style="display:none;">
                    </label>
                </div>
            </div>

            <div class="info-card">
                <h2 class="section-title">Helpful Notes</h2>
                <ul class="hint-list">
                    <li>Signature, templates, and notification sound are now on separate pages.</li>
                    <li>Your profile image is shown in the sidebar and account menu automatically.</li>
                    <li>Theme choices stay saved in this browser for the next visit.</li>
                </ul>
            </div>

            <?php if ($currentUser['role'] === 'Admin'): ?>
                <div class="info-card">
                    <h2 class="section-title">Admin User Management</h2>
                    <p class="section-subtitle">Full user management is available in the dedicated User Management section.</p>
                    <a href="<?php echo e(url('users/list.php')); ?>" class="btn btn-primary">Manage All Users</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function () {
    var savedColor = localStorage.getItem('app-theme-color') || 'blue';
    document.querySelectorAll('input[name="app_theme_color"]').forEach(function (radio) {
        if (radio.value === savedColor) {
            radio.checked = true;
            selectThemePreview(radio);
        }
        radio.addEventListener('change', function () {
            localStorage.setItem('app-theme-color', radio.value);
            applyThemeColor(radio.value);
            document.querySelectorAll('input[name="app_theme_color"]').forEach(function (r) { clearThemePreview(r); });
            selectThemePreview(radio);
        });
    });

    var savedSidebar = localStorage.getItem('app-sidebar') || 'dark';
    var customSidebarRadio = document.querySelector('input[name="app_sidebar"][value="custom"]');
    var customSidebarColor = document.getElementById('custom-sidebar-color');
    var customSidebarPreview = document.getElementById('custom-sidebar-preview');

    function applyCustomSidebarColor(hex) {
        if (customSidebarPreview) {
            customSidebarPreview.style.background = hex;
        }
        if (customSidebarColor) {
            customSidebarColor.value = hex;
        }
        localStorage.setItem('app-sidebar', 'custom:' + hex);
        applySidebarTheme('custom:' + hex);
    }

    document.querySelectorAll('input[name="app_sidebar"]').forEach(function (radio) {
        if (radio.value === savedSidebar) {
            radio.checked = true;
            selectThemePreview(radio);
        }
        radio.addEventListener('change', function () {
            document.querySelectorAll('input[name="app_sidebar"]').forEach(function (r) { clearThemePreview(r); });
            selectThemePreview(radio);
            if (radio.value === 'custom') {
                if (customSidebarColor) {
                    customSidebarColor.style.display = 'block';
                    customSidebarColor.click();
                }
            } else {
                if (customSidebarColor) {
                    customSidebarColor.style.display = 'none';
                }
                localStorage.setItem('app-sidebar', radio.value);
                applySidebarTheme(radio.value);
            }
        });
    });

    if (customSidebarColor) {
        customSidebarColor.addEventListener('input', function () {
            var customRadio = document.querySelector('input[name="app_sidebar"][value="custom"]');
            if (customRadio) {
                customRadio.checked = true;
                document.querySelectorAll('input[name="app_sidebar"]').forEach(function (r) { clearThemePreview(r); });
                selectThemePreview(customRadio);
            }
            applyCustomSidebarColor(customSidebarColor.value);
        });
    }

    if (savedSidebar.startsWith('custom:')) {
        var hex = savedSidebar.replace('custom:', '');
        if (customSidebarRadio) {
            customSidebarRadio.checked = true;
            selectThemePreview(customSidebarRadio);
        }
        if (customSidebarColor) {
            customSidebarColor.style.display = 'block';
            customSidebarColor.value = hex;
        }
        if (customSidebarPreview) {
            customSidebarPreview.style.background = hex;
        }
        applySidebarTheme(savedSidebar);
    }

    function selectThemePreview(radio) {
        var preview = radio.closest('.theme-option').querySelector('.theme-preview');
        preview.style.borderColor = 'var(--primary)';
        preview.style.boxShadow = '0 0 0 2px var(--panel), 0 0 0 4px var(--primary)';
    }

    function clearThemePreview(radio) {
        var preview = radio.closest('.theme-option').querySelector('.theme-preview');
        preview.style.borderColor = 'transparent';
        preview.style.boxShadow = 'none';
    }
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
