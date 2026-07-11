<?php
require_once __DIR__ . '/../includes/auth.php';

$currentUser = require_login($pdo);
require_role(['Admin']);
users_ensure_role_enum($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = trim($_POST['action'] ?? '');
    $targetUserId = trim($_POST['target_user_id'] ?? '');

    if ($targetUserId === '') {
        set_flash('error', 'Invalid user selected.');
        redirect('users/list.php');
    }

    $targetStmt = $pdo->prepare(
        'SELECT id, name, user_id, status, deleted
         FROM users
         WHERE user_id = :user_id
         LIMIT 1'
    );
    $targetStmt->execute([':user_id' => $targetUserId]);
    $targetUser = $targetStmt->fetch();

    if (!$targetUser) {
        set_flash('error', 'User not found.');
        redirect('users/list.php');
    }

    if ($targetUser['user_id'] === $currentUser['user_id']) {
        set_flash('error', 'You cannot update or delete your own account here.');
        redirect('users/list.php');
    }

    if ($action === 'toggle_status') {
        if ((int) $targetUser['deleted'] === 1) {
            set_flash('error', 'Deleted users cannot be activated from this page.');
            redirect('users/list.php');
        }

        $newStatus = $targetUser['status'] === 'Active' ? 'Suspended' : 'Active';
        $updateStmt = $pdo->prepare('UPDATE users SET status = :status WHERE user_id = :user_id');
        $updateStmt->execute([
            ':status' => $newStatus,
            ':user_id' => $targetUser['user_id'],
        ]);

        set_flash('success', 'User status updated successfully.');
        redirect('users/list.php');
    }

    if ($action === 'delete') {
        $deleteStmt = $pdo->prepare('UPDATE users SET deleted = 1, status = :status WHERE user_id = :user_id');
        $deleteStmt->execute([
            ':status' => 'Suspended',
            ':user_id' => $targetUser['user_id'],
        ]);

        set_flash('success', 'User deleted successfully.');
        redirect('users/list.php');
    }
}

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$deletedFilter = trim($_GET['deleted'] ?? '0');

$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(name LIKE :search OR user_id LIKE :search)';
    $params[':search'] = '%' . $search . '%';
}

if (in_array($statusFilter, user_statuses(), true)) {
    $where[] = 'status = :status';
    $params[':status'] = $statusFilter;
}

if (in_array($deletedFilter, ['0', '1'], true)) {
    $where[] = 'deleted = :deleted';
    $params[':deleted'] = (int) $deletedFilter;
}

$sql = 'SELECT id, name, user_id, role, status, deleted, created_at, profile_image FROM users';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

$flash = get_flash();
$pageTitle = 'Users';
$pageHeading = 'User Management';
$pageDescription = 'Compact admin view to search, edit, suspend, or delete user accounts.';

include __DIR__ . '/../includes/header.php';
?>

<?php if ($flash): ?>
    <div class="flash flash-<?php echo e($flash['type']); ?>"><?php echo e($flash['message']); ?></div>
<?php endif; ?>

<div class="page-actions">
    <div>
        <h2 class="section-title" style="margin-bottom:4px;">User Management</h2>
        <p class="section-subtitle">Administrative controls for user accounts, roles, and access status.</p>
    </div>
    <div class="toolbar">
        <a href="<?php echo e(url('users/create.php')); ?>" class="btn btn-primary">+ Add User</a>
    </div>
</div>

<div class="filter-card" style="padding:16px;margin-bottom:16px;border:1px solid var(--border);background:var(--panel);border-radius:var(--radius-md);">
    <form method="GET" class="filter-grid" style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:12px;align-items:end;">
        <div class="input-group">
            <label for="search" style="font-size:12px;color:var(--muted);font-weight:500;">Search</label>
            <input type="text" id="search" name="search" value="<?php echo e($search); ?>" placeholder="Name or user ID" style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;font-size:14px;">
        </div>

        <div class="input-group">
            <label for="status" style="font-size:12px;color:var(--muted);font-weight:500;">Status</label>
            <select id="status" name="status" style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;font-size:14px;background:var(--panel);color:var(--text);">
                <option value="">All</option>
                <?php foreach (user_statuses() as $status): ?>
                    <option value="<?php echo e($status); ?>" <?php echo $statusFilter === $status ? 'selected' : ''; ?>><?php echo e($status); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="input-group">
            <label for="deleted" style="font-size:12px;color:var(--muted);font-weight:500;">Include Deleted</label>
            <select id="deleted" name="deleted" style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;font-size:14px;background:var(--panel);color:var(--text);">
                <option value="0">Exclude deleted</option>
                <option value="1" <?php echo $deletedFilter === '1' ? 'selected' : ''; ?>>Include deleted</option>
            </select>
        </div>

        <div style="display:flex;gap:8px;">
            <button type="submit" class="btn btn-primary" style="flex:1;">Filter</button>
            <a href="<?php echo e(url('users/list.php')); ?>" class="btn btn-outline" title="Clear all filters">Reset</a>
        </div>
    </form>
</div>

<div class="table-card" style="border-radius:var(--radius-md);overflow:hidden;">
    <div style="padding:16px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;">
        <div>
            <h3 style="margin:0;font-size:16px;font-weight:600;">All Users (<?php echo e(count($users)); ?>)</h3>
        </div>
    </div>

    <div class="table-wrap" style="margin:0;">
        <table style="width:100%;border-collapse:collapse;">
            <thead style="background:var(--panel-soft);">
                <tr>
                    <th style="padding:12px 16px;text-align:left;font-size:12px;text-transform:uppercase;letter-spacing:0.5px;color:var(--muted);font-weight:600;">User</th>
                    <th style="padding:12px 16px;text-align:left;font-size:12px;text-transform:uppercase;letter-spacing:0.5px;color:var(--muted);font-weight:600;">ID</th>
                    <th style="padding:12px 16px;text-align:left;font-size:12px;text-transform:uppercase;letter-spacing:0.5px;color:var(--muted);font-weight:600;">Role</th>
                    <th style="padding:12px 16px;text-align:left;font-size:12px;text-transform:uppercase;letter-spacing:0.5px;color:var(--muted);font-weight:600;">Status</th>
                    <th style="padding:12px 16px;text-align:left;font-size:12px;text-transform:uppercase;letter-spacing:0.5px;color:var(--muted);font-weight:600;">Created</th>
                    <th style="padding:12px 16px;text-align:right;font-size:12px;text-transform:uppercase;letter-spacing:0.5px;color:var(--muted);font-weight:600;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($users): ?>
                    <?php foreach ($users as $user): ?>
                        <?php
                            $isDeleted = (int) $user['deleted'] === 1;
                            $isSelf = $user['user_id'] === $currentUser['user_id'];
                            $rowStyle = $isDeleted ? 'style="opacity:0.6;background:var(--panel-soft);"' : '';
                        ?>
                        <tr <?php echo $rowStyle; ?>>
                            <td style="padding:12px 16px;border-bottom:1px solid var(--border);">
                                <div style="display:flex;align-items:center;gap:10px;">
                                    <span class="profile-avatar" style="width:32px;height:32px;font-size:12px;flex-shrink:0;display:flex;align-items:center;justify-content:center;">
                                             <?php
                                                 $initial = strtoupper(substr($user['name'], 0, 1));
                                                 if (!empty($user['profile_image'])): ?>
                                                     <img src="<?php echo e(user_profile_image_url($user['profile_image'])); ?>" alt="<?php echo e($user['name']); ?>" style="width:100%;height:100%;border-radius:50%;object-fit:cover;">
                                                 <?php else: ?>
                                                     <?php echo e($initial); ?>
                                                 <?php endif; ?>
                                             </span>
                                    <div>
                                        <div style="font-weight:600;color:var(--text);font-size:14px;"><?php echo e($user['name']); ?></div>
                                        <div style="font-size:12px;color:var(--muted);"><?php echo e($user['user_id']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td style="padding:12px 16px;border-bottom:1px solid var(--border);color:var(--muted);font-size:13px;font-family:monospace;"><?php echo e($user['user_id']); ?></td>
                            <td style="padding:12px 16px;border-bottom:1px solid var(--border);">
                                <span class="badge <?php echo e(user_role_badge_class((string) ($user['role'] ?? ''))); ?>" style="font-size:11px;padding:4px 10px;border-radius:20px;">
                                    <?php echo e($user['role']); ?>
                                </span>
                            </td>
                            <td style="padding:12px 16px;border-bottom:1px solid var(--border);">
                                <span class="badge <?php echo $user['status'] === 'Active' ? 'badge-active' : 'badge-suspended'; ?>" style="font-size:11px;padding:4px 10px;border-radius:20px;">
                                    <?php echo e($user['status']); ?>
                                </span>
                            </td>
                            <td style="padding:12px 16px;border-bottom:1px solid var(--border);color:var(--muted);font-size:13px;">
                                <?php echo e(format_date($user['created_at'], 'd M Y')); ?>
                            </td>
                            <td style="padding:12px 16px;border-bottom:1px solid var(--border);text-align:right;">
                                <div class="table-actions" style="justify-content:flex-end;">
                                    <a href="<?php echo e(url('users/edit.php?id=' . urlencode($user['user_id']))); ?>" class="btn btn-outline btn-sm" data-tooltip="Edit user">Edit</a>

                                    <?php if (!$isSelf && !$isDeleted): ?>
                                        <form method="POST" class="inline-form" onsubmit="return confirm('Are you sure you want to <?php echo $user['status'] === 'Active' ? 'suspend' : 'activate'; ?> this user?');">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="target_user_id" value="<?php echo e($user['user_id']); ?>">
                                            <button type="submit" class="btn btn-<?php echo $user['status'] === 'Active' ? 'warning' : 'success'; ?> btn-sm" data-tooltip="<?php echo $user['status'] === 'Active' ? 'Suspend' : 'Activate'; ?> this user">
                                                <?php echo $user['status'] === 'Active' ? 'Suspend' : 'Activate'; ?>
                                            </button>
                                        </form>

                                        <form method="POST" class="inline-form" onsubmit="return confirm('Permanently delete user <?php echo e($user['name']); ?>? This cannot be undone.');">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="target_user_id" value="<?php echo e($user['user_id']); ?>">
                                            <button type="submit" class="btn btn-danger btn-sm" data-tooltip="Delete user">Delete</button>
                                        </form>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-outline btn-sm btn-disabled" data-tooltip="Protected account" disabled>Protected</button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="empty-state" style="padding:40px;text-align:center;color:var(--muted);">
                            <div style="font-size:48px;margin-bottom:12px;">📋</div>
                            <div>No users found. Try adjusting filters or <a href="<?php echo e(url('users/create.php')); ?>" style="color:var(--primary);">create a new user</a>.</div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
