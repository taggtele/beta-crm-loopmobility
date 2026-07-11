<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../services/unknown_email_service.php';

$currentUser = require_login($pdo);
require_role(['Admin']);

$pageTitle = 'Unknown Emails';
$pageHeading = 'Unknown Emails';
$pageDescription = 'Review incoming emails from unregistered senders without creating tickets.';
$message = null;

unknown_email_service_ensure_table($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    try {
        $action = trim((string) ($_POST['action'] ?? ''));

        if ($action === 'convert_to_party') {
            unknown_email_service_convert_to_party(
                $pdo,
                (int) ($_POST['unknown_email_id'] ?? 0),
                (string) ($_POST['party_name'] ?? '')
            );
            set_flash('success', 'Unknown email converted to party.');
            redirect('admin/unknown_emails.php');
        }

        $message = ['type' => 'error', 'text' => 'Invalid unknown-email request.'];
    } catch (Throwable $throwable) {
        $message = ['type' => 'error', 'text' => $throwable->getMessage()];
    }
}

$status = trim((string) ($_GET['status'] ?? 'pending'));
if (!in_array($status, ['pending', 'converted', 'ignored', 'all'], true)) {
    $status = 'pending';
}
$search = trim((string) ($_GET['search'] ?? ''));
$unknownEmails = unknown_email_service_list($pdo, $status === 'all' ? '' : $status, $search, 150);
$flash = get_flash();

include __DIR__ . '/../includes/header.php';
?>

<?php if ($flash): ?>
    <div class="flash flash-<?php echo e($flash['type']); ?>"><?php echo e($flash['message']); ?></div>
<?php endif; ?>

<?php if ($message): ?>
    <div class="flash flash-<?php echo e($message['type']); ?>"><?php echo e($message['text']); ?></div>
<?php endif; ?>

<form method="GET" class="filter-card">
    <div class="filter-header">
        <div>
            <h3>Unknown Email Review</h3>
            <p>Unknown senders are stored here and never create tickets until registered as parties.</p>
        </div>
        <a href="<?php echo e(url('admin/unknown_emails.php')); ?>" class="btn btn-outline btn-sm">Clear</a>
    </div>

    <div class="filter-grid ticket-filter-grid">
        <div class="input-group">
            <label for="search">Search</label>
            <input type="text" id="search" name="search" value="<?php echo e($search); ?>" placeholder="Email, name, subject">
        </div>
        <div class="input-group">
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="converted" <?php echo $status === 'converted' ? 'selected' : ''; ?>>Converted</option>
                <option value="ignored" <?php echo $status === 'ignored' ? 'selected' : ''; ?>>Ignored</option>
                <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All</option>
            </select>
        </div>
    </div>

    <div class="form-actions" style="margin-top:14px;">
        <button type="submit" class="btn btn-primary">Apply Filter</button>
    </div>
</form>

<section class="table-card" style="margin-top:16px;">
    <div class="table-header">
        <div>
            <h2 class="section-title">Stored Unknown Emails</h2>
            <p class="section-subtitle"><?php echo e((string) count($unknownEmails)); ?> record(s).</p>
        </div>
    </div>

    <div class="mail-card-list" style="padding:0 18px 18px;">
        <?php if ($unknownEmails): ?>
            <?php foreach ($unknownEmails as $email): ?>
                <article class="mail-card">
                    <div class="mail-card-header">
                        <div>
                            <h3><?php echo e($email['subject'] ?: 'Incoming Email'); ?></h3>
                            <p class="mail-meta-line">
                                From <?php echo e($email['from_name'] ? $email['from_name'] . ' <' . $email['from_email'] . '>' : $email['from_email']); ?>
                                | <?php echo e(format_date($email['received_at'] ?: $email['created_at'])); ?>
                            </p>
                        </div>
                        <span class="badge <?php echo $email['review_status'] === 'pending' ? 'badge-medium' : 'badge-open'; ?>">
                            <?php echo e(ucfirst((string) $email['review_status'])); ?>
                        </span>
                    </div>

                    <div class="mail-body"><?php echo e(trim((string) $email['body']) !== '' ? $email['body'] : 'No body available.'); ?></div>

                    <?php if ($email['review_status'] === 'pending'): ?>
                        <form method="POST" class="inline-form" style="margin-top:12px;">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="convert_to_party">
                            <input type="hidden" name="unknown_email_id" value="<?php echo e((string) $email['id']); ?>">
                            <div class="input-group">
                                <label for="party-name-<?php echo e((string) $email['id']); ?>">Party Name</label>
                                <input type="text" id="party-name-<?php echo e((string) $email['id']); ?>" name="party_name" value="<?php echo e($email['from_name'] ?: $email['from_email']); ?>" required>
                            </div>
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary btn-sm">Convert To Party</button>
                            </div>
                        </form>
                    <?php elseif (!empty($email['converted_party_name'])): ?>
                        <div class="info-strip" style="margin-top:12px;">
                            <div>
                                <strong>Converted Party</strong>
                                <p><?php echo e($email['converted_party_name']); ?></p>
                            </div>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">No unknown emails found.</div>
        <?php endif; ?>
    </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
