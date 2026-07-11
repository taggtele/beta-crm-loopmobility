<?php
require_once __DIR__ . '/../includes/auth.php';

$currentUser = require_login($pdo);
require_role(['Admin']);
users_ensure_role_enum($pdo);

$targetUserId = trim($_GET['id'] ?? $_POST['original_user_id'] ?? '');

if ($targetUserId === '') {
    set_flash('error', 'User not found.');
    redirect('users/list.php');
}

$userStmt = $pdo->prepare(
    'SELECT id, name, user_id, role, status, deleted, profile_image, created_at, phone, department
     FROM users
     WHERE user_id = :user_id
     LIMIT 1'
);
$userStmt->execute([':user_id' => $targetUserId]);
$user = $userStmt->fetch();

if (!$user) {
    set_flash('error', 'User not found.');
    redirect('users/list.php');
}

$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $name = trim($_POST['name'] ?? '');
    $newUserId = trim($_POST['user_id'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $role = trim($_POST['role'] ?? '');
    $status = trim($_POST['status'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $deleted = (($_POST['deleted'] ?? '0') === '1') ? 1 : 0;

    if ($name === '' || $newUserId === '' || $role === '' || $status === '') {
        $message = ['type' => 'error', 'text' => 'Please fill all required fields.'];
    } elseif ($phone !== '' && !preg_match('/^[+]?[0-9\s\-]{7,20}$/', $phone)) {
        $message = ['type' => 'error', 'text' => 'Phone number format is invalid. Use only digits, spaces, and hyphens.'];
    } elseif ($department !== '' && !in_array($department, ['NOC', 'Sales', 'Routing', 'Marketing', 'Development', 'Testing', 'International', 'Support'], true)) {
        $message = ['type' => 'error', 'text' => 'Invalid department selected.'];
    } elseif (!valid_user_id($newUserId)) {
        $message = ['type' => 'error', 'text' => 'User ID format is invalid.'];
    } elseif (!in_array($role, user_roles(), true) || !in_array($status, user_statuses(), true)) {
        $message = ['type' => 'error', 'text' => 'Role or status is invalid.'];
    } elseif ($password !== '' && strlen($password) < 6) {
        $message = ['type' => 'error', 'text' => 'New password must be at least 6 characters long.'];
    } else {
        $duplicateStmt = $pdo->prepare(
            'SELECT id
             FROM users
             WHERE user_id = :user_id
             AND id != :id
             LIMIT 1'
        );
        $duplicateStmt->execute([
            ':user_id' => $newUserId,
            ':id' => $user['id'],
        ]);

        if ($duplicateStmt->fetch()) {
            $message = ['type' => 'error', 'text' => 'Another user already uses this user ID.'];
        } elseif ($user['user_id'] === $currentUser['user_id'] && $deleted === 1) {
            $message = ['type' => 'error', 'text' => 'You cannot delete your own account.'];
        } else {
            // Handle profile image upload
            $nextProfileImage = $user['profile_image'] ?? null;
            $uploadedProfileImage = $_FILES['profile_image'] ?? null;
            $hasUploadedProfileImage = $uploadedProfileImage && (int) ($uploadedProfileImage['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            $removeProfileImage = isset($_POST['remove_profile_image']) && $_POST['remove_profile_image'] === '1';

            if ($hasUploadedProfileImage) {
                try {
                    $nextProfileImage = store_profile_image_upload($uploadedProfileImage);
                } catch (RuntimeException $e) {
                    $message = ['type' => 'error', 'text' => $e->getMessage()];
                }
            }

            if ($message === null && $removeProfileImage && !$hasUploadedProfileImage) {
                $nextProfileImage = null;
            }

            if ($message !== null) {
                // Skip update on error
            } else {
            $sql = 'UPDATE users
                    SET name = :name,
                        user_id = :user_id,
                        role = :role,
                        status = :status,
                        deleted = :deleted,
                        profile_image = :profile_image,
                        phone = :phone,
                        department = :department';

            $params = [
                ':name' => $name,
                ':user_id' => $newUserId,
                ':role' => $role,
                ':status' => $deleted === 1 ? 'Suspended' : $status,
                ':deleted' => $deleted,
                ':profile_image' => $nextProfileImage,
                ':phone' => $phone !== '' ? $phone : null,
                ':department' => $department !== '' ? $department : null,
                ':id' => $user['id'],
            ];

                if ($password !== '') {
                    $sql .= ', password = :password';
                    $params[':password'] = password_hash($password, PASSWORD_DEFAULT);
                }

                $sql .= ' WHERE id = :id';

                $updateStmt = $pdo->prepare($sql);
                $updateStmt->execute($params);

                // Delete old image if replaced
                if ($nextProfileImage !== ($user['profile_image'] ?? null) && !empty($user['profile_image'])) {
                    delete_profile_image_file($user['profile_image']);
                }

                set_flash('success', 'User updated successfully.');
                redirect('users/list.php');
            }
        }
    }
}

$pageTitle = 'Edit User';
$pageHeading = 'Edit User';
$pageDescription = 'Update user details, role, status, or password.';

include __DIR__ . '/../includes/header.php';
?>

<?php if ($message): ?>
    <div class="flash flash-<?php echo e($message['type']); ?>"><?php echo e($message['text']); ?></div>
<?php endif; ?>

<div class="form-card" style="padding:24px;">
    <form method="POST" enctype="multipart/form-data" novalidate>
        <?php echo csrf_field(); ?>
        <input type="hidden" name="original_user_id" value="<?php echo e($user['user_id']); ?>">

        <div style="display:flex;align-items:center;gap:16px;margin-bottom:24px;padding-bottom:24px;border-bottom:1px solid var(--border);">
            <span class="profile-avatar profile-avatar-xl" style="width:64px;height:64px;font-size:24px;display:flex;align-items:center;justify-content:center;overflow:hidden;">
                 <?php if (!empty($user['profile_image'])): ?>
                     <img src="<?php echo e(user_profile_image_url($user['profile_image'])); ?>" alt="Profile" style="width:100%;height:100%;object-fit:cover;">
                 <?php else: ?>
                     <?php echo e(strtoupper(substr($user['name'], 0, 1))); ?>
                 <?php endif; ?>
            </span>
            <div>
                <h3 style="margin:0 0 4px 0;font-size:18px;font-weight:600;"><?php echo e($user['name']); ?></h3>
                <p style="margin:0;color:var(--muted);font-size:13px;">User ID: <code style="font-family:monospace;background:var(--panel-soft);padding:2px 6px;border-radius:4px;"><?php echo e($user['user_id']); ?></code></p>
            </div>
        </div>

        <div class="form-section" style="margin-bottom:24px;">
            <h4 style="margin:0 0 12px 0;font-size:14px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;">Profile Image</h4>
            <div class="input-group" style="display:flex;flex-direction:column;gap:8px;">
                <label for="profile_image" style="font-size:13px;font-weight:500;">Change Photo</label>
                <input type="file" id="profile_image" name="profile_image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" style="padding:8px;border:1px solid var(--border);border-radius:8px;font-size:13px;background:var(--panel);">
                <div class="field-help" style="font-size:12px;color:var(--muted);">JPG, PNG, or WebP — maximum 2 MB. Leave blank to keep current image.</div>
                <?php if (!empty($user['profile_image'])): ?>
                    <label class="checkbox-row" style="display:inline-flex;align-items:center;gap:6px;margin-top:4px;">
                        <input type="checkbox" name="remove_profile_image" value="1" style="width:14px;height:14px;">
                        <span style="font-size:13px;color:var(--danger);">Remove current image</span>
                    </label>
                <?php endif; ?>
            </div>
        </div>

        <div class="form-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div class="input-group">
                <label for="name" style="display:block;margin-bottom:6px;font-weight:500;font-size:13px;">Full Name *</label>
                <input type="text" id="name" name="name" value="<?php echo e($_POST['name'] ?? $user['name']); ?>" required style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:8px;font-size:14px;">
            </div>

            <div class="input-group">
                <label for="user_id" style="display:block;margin-bottom:6px;font-weight:500;font-size:13px;">User ID *</label>
                <input type="text" id="user_id" name="user_id" value="<?php echo e($_POST['user_id'] ?? $user['user_id']); ?>" required style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:8px;font-size:14px;font-family:monospace;">
                <small style="color:var(--muted);font-size:12px;">Unique identifier used for login.</small>
            </div>

            <div class="input-group">
                <label for="phone" style="display:block;margin-bottom:6px;font-weight:500;font-size:13px;">Phone Number</label>
                <input type="tel" id="phone" name="phone" value="<?php echo e($_POST['phone'] ?? ($user['phone'] ?? '')); ?>" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:8px;font-size:14px;font-family:monospace;">
                <small style="color:var(--muted);font-size:12px;">Optional contact number. Include country code if needed.</small>
            </div>

            <div class="input-group">
                <label for="department" style="display:block;margin-bottom:6px;font-weight:500;font-size:13px;">Department</label>
                <select id="department" name="department" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:8px;font-size:14px;background:var(--panel);color:var(--text);">
                    <option value="">Select department (optional)</option>
                    <?php
                    $departments = ['NOC', 'Sales', 'Routing', 'Marketing', 'Development', 'Testing', 'International', 'Support'];
                    foreach ($departments as $dept):
                    ?>
                        <option value="<?php echo e($dept); ?>" <?php echo (($_POST['department'] ?? ($user['department'] ?? '')) === $dept) ? 'selected' : ''; ?>>
                            <?php echo e($dept); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small style="color:var(--muted);font-size:12px;">Optional. Team or functional area.</small>
            </div>

            <div class="input-group">
                <label for="role" style="display:block;margin-bottom:6px;font-weight:500;font-size:13px;">Role *</label>
                <select id="role" name="role" required style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:8px;font-size:14px;background:var(--panel);color:var(--text);">
                    <?php foreach (user_roles() as $role): ?>
                        <?php $selectedRole = $_POST['role'] ?? $user['role']; ?>
                        <option value="<?php echo e($role); ?>" <?php echo $selectedRole === $role ? 'selected' : ''; ?>>
                            <?php echo e($role); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="input-group">
                <label for="status" style="display:block;margin-bottom:6px;font-weight:500;font-size:13px;">Status *</label>
                <select id="status" name="status" required style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:8px;font-size:14px;background:var(--panel);color:var(--text);">
                    <?php foreach (user_statuses() as $status): ?>
                        <?php $selectedStatus = $_POST['status'] ?? $user['status']; ?>
                        <option value="<?php echo e($status); ?>" <?php echo $selectedStatus === $status ? 'selected' : ''; ?>>
                            <?php echo e($status); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="input-group" style="grid-column: span 2;">
                <label for="password" style="display:block;margin-bottom:6px;font-weight:500;font-size:13px;">New Password (optional)</label>
                <input type="password" id="password" name="password" placeholder="Leave blank to keep current password" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:8px;font-size:14px;">
                <small style="color:var(--muted);font-size:12px;">Minimum 6 characters. Leave empty to maintain current password.</small>
            </div>

            <?php if ($user['user_id'] !== $currentUser['user_id']): ?>
                <div class="input-group" style="grid-column: span 2;">
                    <label class="checkbox-row" style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                        <input type="checkbox" name="deleted" value="1" <?php echo (int) $user['deleted'] === 1 ? 'checked' : ''; ?> style="width:16px;height:16px;accent-color:var(--primary);">
                        <span style="font-size:14px;color:var(--danger);font-weight:500;">Delete this user permanently</span>
                    </label>
                    <small style="color:var(--muted);font-size:12px;margin-left:24px;">Deleted users are suspended and hidden from the active list.</small>
                </div>
            <?php endif; ?>
        </div>

        <div class="form-actions" style="margin-top:24px;padding-top:16px;border-top:1px solid var(--border);display:flex;gap:10px;">
            <button type="submit" class="btn btn-primary">Save Changes</button>
            <a href="<?php echo e(url('users/list.php')); ?>" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
