<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../services/email_account_service.php';

$currentUser = require_login($pdo);
require_role(['Admin']);

$hasUsernameColumn = email_account_service_has_column($pdo, 'username');
$hasFromNameColumn = email_account_service_has_column($pdo, 'from_name');
$hasSmtpEncryptionColumn = email_account_service_has_column($pdo, 'smtp_encryption');
$imapEncryptionOptions = email_account_service_encryption_options($pdo, 'encryption');
$smtpEncryptionOptions = $hasSmtpEncryptionColumn
    ? email_account_service_encryption_options($pdo, 'smtp_encryption')
    : $imapEncryptionOptions;

function email_accounts_default_values(?array $account, bool $hasSmtpEncryptionColumn): array
{
    $email = trim((string) ($account['email'] ?? ''));
    $imapEncryption = strtolower(trim((string) ($account['encryption'] ?? 'ssl'))) ?: 'ssl';
    $smtpEncryption = strtolower(trim((string) ($account['smtp_encryption'] ?? ''))) ?: ($hasSmtpEncryptionColumn ? 'tls' : $imapEncryption);

    return [
        'id' => (int) ($account['id'] ?? 0),
        'email' => $email,
        'username' => trim((string) ($account['username'] ?? $email)),
        'password' => '',
        'imap_host' => trim((string) ($account['imap_host'] ?? '')),
        'imap_port' => (string) ($account['imap_port'] ?? 993),
        'imap_encryption' => $imapEncryption,
        'smtp_host' => trim((string) ($account['smtp_host'] ?? '')),
        'smtp_port' => (string) ($account['smtp_port'] ?? ($hasSmtpEncryptionColumn ? 587 : 465)),
        'smtp_encryption' => $smtpEncryption,
        'from_name' => trim((string) ($account['from_name'] ?? '')),
        'is_active' => (int) ($account['is_active'] ?? 1) === 1 ? 1 : 0,
    ];
}

function email_accounts_info_label(string $for, string $text, string $tooltip): void
{
    $label = e($text);
    $label = str_replace('*', '<span class="required-mark">*</span>', $label);

    echo '<span class="field-label-row">';
    echo '<label for="' . e($for) . '">' . $label . '</label>';
    echo '<button type="button" class="field-info tooltip-btn" data-tooltip="' . e($tooltip) . '" aria-label="' . e($tooltip) . '">i</button>';
    echo '</span>';
}

function email_accounts_post_values(bool $hasUsernameColumn, bool $hasSmtpEncryptionColumn, bool $hasFromNameColumn): array
{
    $email = trim((string) ($_POST['email'] ?? ''));
    $username = trim((string) ($_POST['mail_username'] ?? ($_POST['username'] ?? '')));
    $imapEncryption = strtolower(trim((string) ($_POST['imap_encryption'] ?? 'ssl')));
    $imapPort = trim((string) ($_POST['imap_port'] ?? '993'));
    $smtpPort = trim((string) ($_POST['smtp_port'] ?? '587'));

    return [
        'id' => max(0, (int) ($_POST['account_id'] ?? 0)),
        'email' => $email,
        'username' => $hasUsernameColumn ? ($username !== '' ? $username : $email) : $email,
        'password' => trim((string) ($_POST['mail_password'] ?? ($_POST['password'] ?? ''))),
        'imap_host' => trim((string) ($_POST['imap_host'] ?? '')),
        'imap_port' => $imapPort !== '' ? $imapPort : '993',
        'imap_encryption' => $imapEncryption,
        'smtp_host' => trim((string) ($_POST['smtp_host'] ?? '')),
        'smtp_port' => $smtpPort !== '' ? $smtpPort : '587',
        'smtp_encryption' => $hasSmtpEncryptionColumn ? strtolower(trim((string) ($_POST['smtp_encryption'] ?? 'tls'))) : $imapEncryption,
        'from_name' => $hasFromNameColumn ? trim((string) ($_POST['from_name'] ?? '')) : '',
        'is_active' => (($_POST['is_active'] ?? '0') === '1') ? 1 : 0,
    ];
}

function email_accounts_validate_values(PDO $pdo, array $values, bool $isEdit, array $imapEncryptionOptions, array $smtpEncryptionOptions, bool $hasSmtpEncryptionColumn): array
{
    $errors = [];

    if ($values['email'] === '' || !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email address is required.';
    }

    if (!$isEdit && $values['password'] === '') {
        $errors[] = 'Password is required.';
    }

    if ($values['smtp_host'] === '') {
        $errors[] = 'SMTP host is required.';
    }

    $portFields = ['smtp_port' => 'SMTP port'];
    if ($values['imap_host'] !== '') {
        $portFields['imap_port'] = 'IMAP port';
    }

    foreach ($portFields as $field => $label) {
        if (!preg_match('/^\d+$/', (string) $values[$field])) {
            $errors[] = $label . ' must be numeric.';
            continue;
        }

        $port = (int) $values[$field];
        if ($port < 1 || $port > 65535) {
            $errors[] = $label . ' must be between 1 and 65535.';
        }
    }

    if (!isset($imapEncryptionOptions[$values['imap_encryption']])) {
        $errors[] = 'Choose a valid IMAP encryption option.';
    }

    if ($hasSmtpEncryptionColumn && !isset($smtpEncryptionOptions[$values['smtp_encryption']])) {
        $errors[] = 'Choose a valid SMTP encryption option.';
    }

    if (!$errors && email_account_service_email_exists($pdo, $values['email'], $isEdit ? (int) $values['id'] : null)) {
        $errors[] = 'An email account with this address already exists.';
    }

    return $errors;
}

function email_accounts_smtp_port_options(string $currentPort): array
{
    $options = [
        '465' => '465',
        '587' => '587',
    ];

    if ($currentPort !== '' && !isset($options[$currentPort]) && preg_match('/^\d+$/', $currentPort)) {
        $options[$currentPort] = $currentPort;
    }

    return $options;
}

$flash = get_flash();
$message = null;
$showForm = false;
$editingAccount = null;
$formValues = email_accounts_default_values(null, $hasSmtpEncryptionColumn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'delete') {
        $accountId = max(0, (int) ($_POST['account_id'] ?? 0));
        $account = email_account_service_find($pdo, $accountId);

        if (!$account) {
            set_flash('error', 'Email account not found.');
        } else {
            email_account_service_delete($pdo, $accountId);
            set_flash('success', 'Email account deleted successfully.');
        }

        redirect('emails/accounts.php');
    }

    if ($action === 'save') {
        $formValues = email_accounts_post_values($hasUsernameColumn, $hasSmtpEncryptionColumn, $hasFromNameColumn);
        $accountId = (int) $formValues['id'];
        $isEdit = $accountId > 0;
        $editingAccount = $isEdit ? email_account_service_find($pdo, $accountId) : null;

        if ($isEdit && !$editingAccount) {
            set_flash('error', 'Email account not found.');
            redirect('emails/accounts.php');
        }

        $errors = email_accounts_validate_values(
            $pdo,
            $formValues,
            $isEdit,
            $imapEncryptionOptions,
            $smtpEncryptionOptions,
            $hasSmtpEncryptionColumn
        );

        if ($errors) {
            $message = ['type' => 'error', 'text' => implode(' ', $errors)];
            $showForm = true;
        } else {
            $formValues['imap_port'] = (int) $formValues['imap_port'];
            $formValues['smtp_port'] = (int) $formValues['smtp_port'];

            if ($isEdit) {
                email_account_service_update($pdo, $accountId, $formValues, $currentUser);
                set_flash('success', 'Email account updated successfully.');
            } else {
                email_account_service_insert($pdo, $formValues, $currentUser);
                set_flash('success', 'Email account added successfully.');
            }

            redirect('emails/accounts.php');
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $mode = trim((string) ($_GET['mode'] ?? ''));
    $editId = max(0, (int) ($_GET['edit'] ?? 0));

    if ($editId > 0) {
        $editingAccount = email_account_service_find($pdo, $editId);
        if (!$editingAccount) {
            set_flash('error', 'Email account not found.');
            redirect('emails/accounts.php');
        }

        $formValues = email_accounts_default_values($editingAccount, $hasSmtpEncryptionColumn);
        $showForm = true;
    } elseif ($mode === 'create') {
        $showForm = true;
    }
}

$accounts = email_account_service_all($pdo);

$pageTitle = 'Email Accounts';
$pageHeading = 'Email Accounts';
$pageDescription = 'Manage flexible SMTP delivery and optional IMAP imports for Gmail, domain mailboxes, and custom providers.';

include __DIR__ . '/../includes/header.php';
?>

<?php if ($flash): ?>
    <div class="flash flash-<?php echo e($flash['type']); ?>"><?php echo e($flash['message']); ?></div>
<?php endif; ?>

<?php if ($message): ?>
    <div class="flash flash-<?php echo e($message['type']); ?>"><?php echo e($message['text']); ?></div>
<?php endif; ?>

<div class="page-actions">
    <div>
        <h2 class="section-title" style="margin-bottom:4px;">Email Accounts</h2>
        <p class="section-subtitle">Manage flexible SMTP delivery and optional IMAP imports for Gmail, domain mailboxes, and custom providers.</p>
    </div>
    <div class="toolbar">
        <a href="<?php echo e(url('emails/accounts.php?mode=create')); ?>" class="btn btn-primary">Add Email Account</a>
        <a href="<?php echo e(url('emails/logs.php')); ?>" class="btn btn-outline">Email Logs</a>
    </div>
</div>

<?php if ($showForm): ?>
    <div class="form-card email-account-form-card">
        <div class="email-account-form-head">
            <div>
                <h3><?php echo (int) $formValues['id'] > 0 ? 'Edit Email Account' : 'Add Email Account'; ?></h3>
                <p><?php echo (int) $formValues['id'] > 0 ? 'Update the mailbox connection settings.' : 'Create a mailbox configuration for SMTP delivery and optional IMAP imports.'; ?></p>
            </div>
            <a href="<?php echo e(url('emails/accounts.php')); ?>" class="btn btn-outline btn-sm">Close</a>
        </div>

        <form method="POST" novalidate autocomplete="off" data-lpignore="true">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="account_id" value="<?php echo e((string) $formValues['id']); ?>">
            <input type="text" name="browser_ignore_username" autocomplete="username" tabindex="-1" aria-hidden="true" class="email-account-autofill-trap">
            <input type="password" name="browser_ignore_password" autocomplete="current-password" tabindex="-1" aria-hidden="true" class="email-account-autofill-trap">

            <div class="email-account-section">
                <h4>Basic Info</h4>
                <div class="form-grid">
                    <div class="input-group">
                        <?php email_accounts_info_label('provider_preset', 'Provider Preset', 'Optional shortcut. It only fills common host, port, and encryption values; every field remains editable.'); ?>
                        <select id="provider_preset" autocomplete="off">
                            <option value="">Custom / Manual</option>
                            <option value="gmail">Gmail / Google Workspace</option>
                            <option value="outlook">Microsoft 365 / Outlook</option>
                            <option value="zoho">Zoho Mail</option>
                            <option value="yahoo">Yahoo Mail</option>
                            <option value="domain">Domain / cPanel / BigRock</option>
                            <option value="smtp_only">SMTP Only</option>
                        </select>
                        <div class="field-help">Use this for quick setup, then adjust values according to the provider panel.</div>
                    </div>

                    <div class="input-group">
                        <?php email_accounts_info_label('email', 'Email Address *', 'Mailbox address shown as the sender and used as login fallback.'); ?>
                        <input type="email" id="email" name="email" value="<?php echo e($formValues['email']); ?>" required placeholder="support@example.com" autocomplete="off" inputmode="email">
                    </div>

                    <div class="input-group">
                        <?php email_accounts_info_label('mail_username', 'Login Username', 'Leave blank to use the email address. Some domain providers use only the mailbox name.'); ?>
                        <input
                            type="text"
                            id="mail_username"
                            name="mail_username"
                            value="<?php echo e($formValues['username']); ?>"
                            autocomplete="new-password"
                            data-lpignore="true"
                            data-form-type="other"
                            <?php echo $hasUsernameColumn ? '' : 'readonly'; ?>
                        >
                        <div class="field-help"><?php echo $hasUsernameColumn ? 'Leave blank to use the email address, or enter the exact provider login username.' : 'Uses the email address for this setup.'; ?></div>
                    </div>

                    <div class="input-group">
                        <?php email_accounts_info_label('mail_password', 'Password ' . ((int) $formValues['id'] > 0 ? '' : '*'), 'Use mailbox password or app password. This field is intentionally blank on edit.'); ?>
                        <input
                            type="password"
                            id="mail_password"
                            name="mail_password"
                            <?php echo (int) $formValues['id'] > 0 ? '' : 'required'; ?>
                            placeholder="<?php echo (int) $formValues['id'] > 0 ? 'Leave blank to keep current password' : 'Mailbox password'; ?>"
                            autocomplete="new-password"
                            data-lpignore="true"
                            data-form-type="other"
                        >
                        <div class="field-help"><?php echo (int) $formValues['id'] > 0 ? 'Leave blank to keep the current password.' : 'Use the password or app password required by the provider.'; ?></div>
                    </div>

                    <?php if ($hasFromNameColumn): ?>
                        <div class="input-group">
                            <?php email_accounts_info_label('from_name', 'From Name', 'Display name recipients see before the email address.'); ?>
                            <input type="text" id="from_name" name="from_name" value="<?php echo e($formValues['from_name']); ?>" placeholder="Support Team" autocomplete="off">
                        </div>
                    <?php endif; ?>

                    <div class="input-group">
                        <label>Active Status</label>
                        <input type="hidden" name="is_active" value="0">
                        <label class="email-account-status-toggle">
                            <input type="checkbox" name="is_active" value="1" <?php echo (int) $formValues['is_active'] === 1 ? 'checked' : ''; ?>>
                            <span class="email-account-status-track" aria-hidden="true">
                                <span class="email-account-status-choice email-account-status-off">Inactive</span>
                                <span class="email-account-status-choice email-account-status-on">Active</span>
                            </span>
                        </label>
                        <div class="field-help">Active accounts are available for SMTP delivery; IMAP import runs when IMAP settings are filled.</div>
                    </div>
                </div>
            </div>

            <div class="email-account-section">
                <h4>IMAP Settings</h4>
                <div class="form-grid">
                    <div class="input-group">
                        <?php email_accounts_info_label('imap_host', 'Host', 'Incoming mail server. Leave blank for SMTP-only sending accounts.'); ?>
                        <input type="text" id="imap_host" name="imap_host" value="<?php echo e($formValues['imap_host']); ?>" placeholder="imap.example.com" autocomplete="off">
                        <div class="field-help">Optional. Fill this only when the account should receive/import email.</div>
                    </div>

                    <div class="input-group">
                        <?php email_accounts_info_label('imap_port', 'Port', 'Common IMAP ports: 993 for SSL, 143 for TLS/None. Use provider values if different.'); ?>
                        <input type="number" id="imap_port" name="imap_port" value="<?php echo e((string) $formValues['imap_port']); ?>" min="1" max="65535">
                        <div class="field-help">Use the port provided by the mail server. Common IMAP port is 993.</div>
                    </div>

                    <div class="input-group">
                        <?php email_accounts_info_label('imap_encryption', 'Encryption', 'Security mode for incoming IMAP connection.'); ?>
                        <select id="imap_encryption" name="imap_encryption">
                            <?php foreach ($imapEncryptionOptions as $value => $label): ?>
                                <option value="<?php echo e($value); ?>" <?php echo $formValues['imap_encryption'] === $value ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="email-account-section">
                <h4>SMTP Settings</h4>
                <div class="form-grid">
                    <div class="input-group">
                        <?php email_accounts_info_label('smtp_host', 'Host *', 'Outgoing mail server used to send email.'); ?>
                        <input type="text" id="smtp_host" name="smtp_host" value="<?php echo e($formValues['smtp_host']); ?>" required placeholder="smtp.example.com" autocomplete="off">
                    </div>

                    <div class="input-group">
                        <?php email_accounts_info_label('smtp_port', 'Port *', 'Common SMTP ports: 587 for TLS, 465 for SSL, 25 for some relays.'); ?>
                        <input type="number" id="smtp_port" name="smtp_port" value="<?php echo e((string) $formValues['smtp_port']); ?>" min="1" max="65535" required>
                        <div class="field-help">Use any provider port. Common SMTP ports are 465 and 587.</div>
                    </div>

                    <div class="input-group">
                        <?php email_accounts_info_label('smtp_encryption', 'Encryption *', 'Security mode for outgoing SMTP connection.'); ?>
                        <?php if ($hasSmtpEncryptionColumn): ?>
                            <select id="smtp_encryption" name="smtp_encryption" required>
                                <?php foreach ($smtpEncryptionOptions as $value => $label): ?>
                                    <option value="<?php echo e($value); ?>" <?php echo $formValues['smtp_encryption'] === $value ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <select id="smtp_encryption" disabled data-shared-smtp-encryption>
                                <?php foreach ($imapEncryptionOptions as $value => $label): ?>
                                    <option value="<?php echo e($value); ?>" <?php echo $formValues['imap_encryption'] === $value ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="field-help">Current schema uses one shared encryption value.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="form-actions email-account-form-actions">
                <button type="submit" class="btn btn-primary"><?php echo (int) $formValues['id'] > 0 ? 'Save Changes' : 'Create Account'; ?></button>
                <a href="<?php echo e(url('emails/accounts.php')); ?>" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
<?php endif; ?>

<section class="table-card">
    <div class="table-header">
        <div>
            <h2 class="section-title">Configured Accounts</h2>
            <p class="section-subtitle"><?php echo e((string) count($accounts)); ?> email account<?php echo count($accounts) === 1 ? '' : 's'; ?> found.</p>
        </div>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Email</th>
                    <th>IMAP Host</th>
                    <th>SMTP Host</th>
                    <th>Status</th>
                    <th>Created At</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($accounts): ?>
                    <?php foreach ($accounts as $account): ?>
                        <tr>
                            <td>
                                <strong><?php echo e($account['email']); ?></strong>
                                <small>IMAP <?php echo e((string) $account['imap_port']); ?> / SMTP <?php echo e((string) $account['smtp_port']); ?></small>
                            </td>
                            <td>
                                <?php echo e($account['imap_host']); ?>
                                <small><?php echo e(strtoupper((string) $account['encryption'])); ?></small>
                            </td>
                            <td>
                                <?php echo e($account['smtp_host']); ?>
                                <small><?php echo e(strtoupper((string) $account['smtp_encryption'])); ?></small>
                            </td>
                            <td>
                                <?php if ((int) $account['is_active'] === 1): ?>
                                    <span class="badge badge-active">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-suspended">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo e(format_date($account['created_at'], 'd M Y, h:i A')); ?></td>
                            <td style="text-align:right;">
                                <div class="table-actions email-account-row-actions">
                                    <a href="<?php echo e(url('emails/accounts.php?edit=' . (int) $account['id'])); ?>" class="btn btn-outline btn-sm">Edit</a>
                                    <form method="POST" class="inline-form" onsubmit="return confirm('Delete this email account?');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="account_id" value="<?php echo e((string) $account['id']); ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="empty-state" style="padding:40px;text-align:center;color:var(--muted);">
                            No email accounts configured yet.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<script>
(function () {
    var providerPreset = document.getElementById('provider_preset');
    var emailInput = document.getElementById('email');
    var usernameInput = document.getElementById('mail_username');
    var imapHost = document.getElementById('imap_host');
    var imapPort = document.getElementById('imap_port');
    var imapEncryption = document.getElementById('imap_encryption');
    var smtpHost = document.getElementById('smtp_host');
    var smtpPort = document.getElementById('smtp_port');
    var smtpEncryption = document.getElementById('smtp_encryption');
    var sharedSmtpEncryption = document.querySelector('[data-shared-smtp-encryption]');

    if (emailInput && usernameInput && usernameInput.hasAttribute('readonly')) {
        emailInput.addEventListener('input', function () {
            usernameInput.value = emailInput.value;
        });
    }

    function emailDomain() {
        var parts = (emailInput ? emailInput.value : '').split('@');
        return parts.length === 2 && parts[1] ? parts[1].trim() : 'example.com';
    }

    function setValue(input, value) {
        if (input) input.value = value;
    }

    function applyProviderPreset(value) {
        var domain = emailDomain();
        var presets = {
            gmail: {
                imap_host: 'imap.gmail.com',
                imap_port: '993',
                imap_encryption: 'ssl',
                smtp_host: 'smtp.gmail.com',
                smtp_port: '587',
                smtp_encryption: 'tls'
            },
            outlook: {
                imap_host: 'outlook.office365.com',
                imap_port: '993',
                imap_encryption: 'ssl',
                smtp_host: 'smtp.office365.com',
                smtp_port: '587',
                smtp_encryption: 'tls'
            },
            zoho: {
                imap_host: 'imap.zoho.com',
                imap_port: '993',
                imap_encryption: 'ssl',
                smtp_host: 'smtp.zoho.com',
                smtp_port: '587',
                smtp_encryption: 'tls'
            },
            yahoo: {
                imap_host: 'imap.mail.yahoo.com',
                imap_port: '993',
                imap_encryption: 'ssl',
                smtp_host: 'smtp.mail.yahoo.com',
                smtp_port: '465',
                smtp_encryption: 'ssl'
            },
            domain: {
                imap_host: 'mail.' + domain,
                imap_port: '993',
                imap_encryption: 'ssl',
                smtp_host: 'mail.' + domain,
                smtp_port: '465',
                smtp_encryption: 'ssl'
            },
            smtp_only: {
                imap_host: '',
                imap_port: '',
                imap_encryption: 'ssl',
                smtp_host: 'smtp.' + domain,
                smtp_port: '587',
                smtp_encryption: 'tls'
            }
        };

        if (!presets[value]) return;
        setValue(imapHost, presets[value].imap_host);
        setValue(imapPort, presets[value].imap_port);
        if (imapEncryption) imapEncryption.value = presets[value].imap_encryption;
        setValue(smtpHost, presets[value].smtp_host);
        setValue(smtpPort, presets[value].smtp_port);
        if (smtpEncryption && !smtpEncryption.disabled) smtpEncryption.value = presets[value].smtp_encryption;
        if (sharedSmtpEncryption) sharedSmtpEncryption.value = presets[value].imap_encryption;
    }

    if (providerPreset) {
        providerPreset.addEventListener('change', function () {
            applyProviderPreset(providerPreset.value);
        });
    }

    if (imapEncryption && sharedSmtpEncryption) {
        imapEncryption.addEventListener('change', function () {
            sharedSmtpEncryption.value = imapEncryption.value;
        });
    }
}());
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
