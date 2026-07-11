<?php
require_once __DIR__ . '/../includes/auth.php';

$currentUser = require_login($pdo);
require_role(['Admin']);
users_ensure_role_enum($pdo);

$pageTitle = 'Create User';
$pageHeading = 'Create User';
$pageDescription = 'Add Admin, Agent, Finance, or Sales account.';
$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $name = trim($_POST['name'] ?? '');
    $userId = trim($_POST['user_id'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $role = trim($_POST['role'] ?? '');
    $status = trim($_POST['status'] ?? 'Active');
    $phone = trim($_POST['phone'] ?? '');
    $department = trim($_POST['department'] ?? '');

    if ($name === '' || $userId === '' || $password === '' || $role === '') {
        $message = ['type' => 'error', 'text' => 'All required fields must be filled.'];
    } elseif ($phone !== '' && !preg_match('/^[+]?[0-9\s\-]{7,20}$/', $phone)) {
        $message = ['type' => 'error', 'text' => 'Phone number format is invalid. Use only digits, spaces, and hyphens.'];
    } elseif ($department !== '' && !in_array($department, ['NOC', 'Sales', 'Routing', 'Marketing', 'Development', 'Testing', 'International', 'Support'], true)) {
        $message = ['type' => 'error', 'text' => 'Invalid department selected.'];
    } elseif (!valid_user_id($userId)) {
        $message = ['type' => 'error', 'text' => 'User ID can contain only letters, numbers, underscore and hyphen.'];
    } elseif (strlen($password) < 6) {
        $message = ['type' => 'error', 'text' => 'Password must be at least 6 characters long.'];
    } elseif (!in_array($role, user_roles(), true) || !in_array($status, user_statuses(), true)) {
        $message = ['type' => 'error', 'text' => 'Please choose a valid role and status.'];
    } else {
        $checkStmt = $pdo->prepare('SELECT id FROM users WHERE user_id = :user_id LIMIT 1');
        $checkStmt->execute([':user_id' => $userId]);

        if ($checkStmt->fetch()) {
            $message = ['type' => 'error', 'text' => 'This user ID already exists.'];
        } else {
            $insertStmt = $pdo->prepare(
                'INSERT INTO users (name, user_id, password, role, status, deleted, created_at, phone, department)
                 VALUES (:name, :user_id, :password, :role, :status, :deleted, NOW(), :phone, :department)'
            );
            $insertStmt->execute([
                ':name' => $name,
                ':user_id' => $userId,
                ':password' => password_hash($password, PASSWORD_DEFAULT),
                ':role' => $role,
                ':status' => $status,
                ':deleted' => 0,
                ':phone' => $phone !== '' ? $phone : null,
                ':department' => $department !== '' ? $department : null,
            ]);

            set_flash('success', 'User created successfully.');
            redirect('users/list.php');
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<?php if ($message): ?>
    <div class="flash flash-<?php echo e($message['type']); ?>"><?php echo e($message['text']); ?></div>
<?php endif; ?>

<div class="form-card">
    <form method="POST" novalidate>
        <?php echo csrf_field(); ?>

        <div class="form-grid">
            <div class="input-group">
                <label for="name">Full Name</label>
                <input type="text" id="name" name="name" value="<?php echo e($_POST['name'] ?? ''); ?>" required>
            </div>

            <div class="input-group">
                <label for="user_id">User ID</label>
                <input type="text" id="user_id" name="user_id" value="<?php echo e($_POST['user_id'] ?? ''); ?>" required>
            </div>

            <div class="input-group">
                <label for="phone">Phone Number</label>
                <input type="tel" id="phone" name="phone" value="<?php echo e($_POST['phone'] ?? ''); ?>" placeholder="+91 98765 43210" style="font-family:monospace;">
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
                        <option value="<?php echo e($dept); ?>" <?php echo (($_POST['department'] ?? '') === $dept) ? 'selected' : ''; ?>>
                            <?php echo e($dept); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="field-help">Optional. Helps organize users by team.</div>
            </div>

            <div class="input-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>

            <div class="input-group">
                <label for="role">Role</label>
                <select id="role" name="role" required>
                    <option value="">Select role</option>
                    <?php foreach (user_roles() as $role): ?>
                        <option value="<?php echo e($role); ?>" <?php echo (($_POST['role'] ?? '') === $role) ? 'selected' : ''; ?>><?php echo e($role); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="input-group">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <?php foreach (user_statuses() as $status): ?>
                        <option value="<?php echo e($status); ?>" <?php echo (($_POST['status'] ?? 'Active') === $status) ? 'selected' : ''; ?>><?php echo e($status); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Create User</button>
            <a href="<?php echo e(url('users/list.php')); ?>" class="btn btn-secondary">Back to User List</a>
        </div>
    </form>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
