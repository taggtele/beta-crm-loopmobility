<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../services/message_template_service.php';
require_once __DIR__ . '/_page_bootstrap.php';

$pageTitle = 'Message Templates';
$pageHeading = 'Message Templates';
$pageDescription = 'Manage reusable snippets for email compose flows.';
$activeProfileSection = 'templates';

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
    <div class="info-card profile-message-templates-card" data-message-templates-manager>
        <div class="profile-templates-header">
            <div>
                <h2 class="section-title">Message Templates</h2>
                <p class="section-subtitle" style="margin-bottom:0;">Create reusable snippets for Send Mail, Reply, Reply to Vendor, and Raise to Vendor.</p>
            </div>
        </div>

        <form class="profile-template-form" data-message-templates-form novalidate>
            <?php echo csrf_field(); ?>
            <input type="hidden" id="message-template-id" name="template_id" value="" data-message-template-id>
            <input type="hidden" name="action" value="save">

            <div class="input-group full">
                <label for="message-template-title">Title</label>
                <input type="text" id="message-template-title" name="title" maxlength="190" placeholder="Follow-up reminder" data-message-template-title required>
            </div>

            <div class="input-group full">
                <label for="message-template-content">Content</label>
                <textarea id="message-template-content" name="content" rows="6" placeholder="Write the reusable text here..." data-message-template-content required></textarea>
                <div class="field-help">Templates are inserted at the top of the email body without removing anything already there.</div>
            </div>

            <div class="profile-template-form-actions">
                <button type="submit" class="btn btn-primary btn-sm" data-message-templates-submit>Save Template</button>
                <button type="button" class="btn btn-secondary btn-sm" data-message-templates-cancel>Clear</button>
            </div>

            <div class="profile-template-status" data-message-templates-status>Loading templates...</div>
        </form>

        <div class="profile-templates-list" data-message-templates-list></div>
    </div>
</div>

<script>
window.messageTemplatesConfig = {
    apiUrl: <?php echo json_encode(url('profile/api_message_templates.php'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES); ?>,
    csrfToken: <?php echo json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES); ?>
};
</script>
<script src="<?php echo e(url('assets/js/message-templates.js')); ?>"></script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
