<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../services/user_email_signature_service.php';
require_once __DIR__ . '/_page_bootstrap.php';

$pageTitle = 'Email Signature';
$pageHeading = 'Email Signature';
$pageDescription = 'Create a reusable signature for your outgoing emails.';
$activeProfileSection = 'signature';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email_signature_action'])) {
    verify_csrf();

    $signatureAction = trim((string) ($_POST['email_signature_action'] ?? ''));

    if ($signatureAction === 'delete') {
        user_email_signature_delete($pdo, (int) $currentUser['id']);
        set_flash('success', 'Email signature removed.');
        redirect('profile/signature.php');
    }

    if ($signatureAction === 'save') {
        $signatureBody = trim((string) ($_POST['signature_body'] ?? ''));
        $useHtml = isset($_POST['signature_use_html']) && $_POST['signature_use_html'] === '1';
        $logoUrl = trim((string) ($_POST['signature_logo_url'] ?? ''));

        try {
            user_email_signature_save($pdo, (int) $currentUser['id'], $signatureBody, $useHtml, $logoUrl !== '' ? $logoUrl : null);
            set_flash('success', 'Email signature saved.');
            redirect('profile/signature.php');
        } catch (InvalidArgumentException $exception) {
            $message = ['type' => 'error', 'text' => $exception->getMessage()];
        }
    }
}

$userEmailSignature = getUserSignature($pdo, (int) $currentUser['id']);
$signatureFormBody = $_POST['signature_body'] ?? ($userEmailSignature['signature_html'] ?? '');
$signatureFormUseHtml = isset($_POST['signature_use_html'])
    ? ($_POST['signature_use_html'] === '1')
    : ($userEmailSignature !== null && trim((string) ($userEmailSignature['signature_html'] ?? '')) !== ''
        && strpos((string) $userEmailSignature['signature_html'], '<') !== false);
$signatureFormLogoUrl = $_POST['signature_logo_url'] ?? ($userEmailSignature['logo_url'] ?? '');

include __DIR__ . '/../includes/header.php';
?>

<?php if ($flash): ?>
    <div class="flash flash-<?php echo e($flash['type']); ?>"><?php echo e($flash['message']); ?></div>
<?php endif; ?>

<?php if ($message): ?>
    <div class="flash flash-<?php echo e($message['type']); ?>"><?php echo e($message['text']); ?></div>
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
        <h2 class="section-title">Email Signature</h2>
        <p class="section-subtitle" style="margin-bottom:14px;">Appended automatically at the bottom when you compose, reply, or raise a ticket to a vendor.</p>
        <form method="POST" class="profile-signature-form" novalidate>
            <?php echo csrf_field(); ?>
            <input type="hidden" name="email_signature_action" value="save">

            <div class="input-group full" style="margin-bottom:12px;">
                <label for="signature_body">Signature</label>
                <textarea id="signature_body" name="signature_body" rows="6" placeholder="Your name, title, phone, etc."><?php echo e($signatureFormBody); ?></textarea>
                <div class="field-help">Plain text by default. Enable HTML for links, bold, or line breaks you control.</div>
            </div>

            <div class="input-group full" style="margin-bottom:12px;">
                <label for="signature_logo_url">Logo / image URL (optional)</label>
                <input type="url" id="signature_logo_url" name="signature_logo_url" value="<?php echo e($signatureFormLogoUrl); ?>" placeholder="https://example.com/logo.png">
            </div>

            <label class="checkbox-row" style="margin-bottom:12px;">
                <input type="checkbox" name="signature_use_html" value="1" id="signature_use_html" <?php echo $signatureFormUseHtml ? 'checked' : ''; ?>>
                <span>Use HTML formatting in signature body</span>
            </label>

            <div class="profile-signature-preview-wrap">
                <span class="profile-signature-preview-label">Preview</span>
                <div id="signature-preview" class="profile-signature-preview"><?php
                    if ($userEmailSignature && trim((string) ($userEmailSignature['signature_html'] ?? '')) !== '') {
                        echo $userEmailSignature['signature_html'];
                    } else {
                        echo '<span class="profile-signature-preview-empty">No signature saved yet.</span>';
                    }
                ?></div>
            </div>

            <div class="form-actions" style="margin-top:14px; gap:8px; flex-wrap:wrap;">
                <button type="submit" class="btn btn-primary btn-sm">Save Signature</button>
                <?php if ($userEmailSignature): ?>
                    <button type="submit" class="btn btn-secondary btn-sm" formaction="<?php echo e(url('profile/signature.php')); ?>" name="email_signature_action" value="delete" formnovalidate onclick="return confirm('Remove your email signature?');">Delete Signature</button>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="info-card">
        <h2 class="section-title">Tips</h2>
        <ul class="hint-list">
            <li>Keep it short so it stays clean in replies.</li>
            <li>Use HTML only if you need links or formatting.</li>
            <li>The preview updates instantly as you type.</li>
        </ul>
    </div>
</div>

<script>
(function () {
    var bodyEl = document.getElementById('signature_body');
    var logoEl = document.getElementById('signature_logo_url');
    var htmlEl = document.getElementById('signature_use_html');
    var previewEl = document.getElementById('signature-preview');
    if (!bodyEl || !previewEl) {
        return;
    }
    function escText(s) {
        return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }
    function renderPreview() {
        var body = bodyEl.value.trim();
        var logo = logoEl ? logoEl.value.trim() : '';
        if (!body && !logo) {
            previewEl.innerHTML = '<span class="profile-signature-preview-empty">No signature saved yet.</span>';
            return;
        }
        var html = '';
        if (logo) {
            html += '<img src="' + escText(logo).replace(/"/g, '&quot;') + '" alt="" style="max-width:180px;height:auto;display:block;margin:0 0 8px;">';
        }
        if (htmlEl && htmlEl.checked) {
            html += body;
        } else if (body) {
            html += escText(body).replace(/\n/g, '<br>');
        }
        previewEl.innerHTML = html || '<span class="profile-signature-preview-empty">No signature saved yet.</span>';
    }
    ['input', 'change'].forEach(function (evt) {
        bodyEl.addEventListener(evt, renderPreview);
        if (logoEl) {
            logoEl.addEventListener(evt, renderPreview);
        }
        if (htmlEl) {
            htmlEl.addEventListener(evt, renderPreview);
        }
    });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
