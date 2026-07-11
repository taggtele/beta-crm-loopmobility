<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../modules/notifications/notification_service.php';
require_once __DIR__ . '/../modules/tickets/ticket_service.php';
require_once __DIR__ . '/../modules/tickets/ticket_description_sanitize.php';
require_once __DIR__ . '/../services/party_service.php';

$currentUser = require_login($pdo);

$extraStylesheets = ['assets/css/tickets_ui_party_country.css'];
$ticketCountryDropdownOptions = party_service_ticket_country_options($pdo);

$pageTitle = 'Create Ticket';
$pageHeading = 'Create Ticket';
$pageDescription = 'Log a new ticket quickly, then assign it now or later.';
$message = null;
$assignUsers = active_users($pdo);
$partyOptions = party_service_active_options($pdo);
$wantsJson = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $customerPartyId = (int) ($_POST['customer_party_id'] ?? 0);
    $partyRow = $customerPartyId > 0 ? party_service_get_active_party($pdo, $customerPartyId) : null;
    $customer = trim((string) ($_POST['customer'] ?? ''));
    $customerEmail = trim((string) ($_POST['customer_email'] ?? ''));
    $countryInput = trim((string) ($_POST['country'] ?? ''));
    $countryCanonical = party_service_ticket_country_canonical($pdo, $countryInput);
    $issue = trim((string) ($_POST['issue'] ?? ''));
    $description = ticket_description_normalize_for_storage((string) ($_POST['description'] ?? ''));
    $priority = trim($_POST['priority'] ?? '');
    $assignTo = normalize_assignee($_POST['assign_to'] ?? null);
    $assignedVendorId = (int) ($_POST['assigned_vendor_id'] ?? 0);
    $assignedVendorId = $assignedVendorId > 0 ? $assignedVendorId : null;

    if ($customerPartyId <= 0 || !$partyRow) {
        $message = ['type' => 'error', 'text' => 'Select an active customer party from the search list — free text alone is not allowed.'];
    } elseif ($countryCanonical === null) {
        $message = ['type' => 'error', 'text' => 'Choose a valid country from the suggestions (existing ticket/party countries are also accepted).'];
    } elseif ($issue === '' || $priority === '') {
        $message = ['type' => 'error', 'text' => 'Please fill all required fields.'];
    } elseif (!ticket_description_has_meaningful_content($description)) {
        $message = ['type' => 'error', 'text' => 'Please enter a description (text, an Excel table, or an image).'];
    } elseif (strlen($description) > TICKET_DESCRIPTION_MAX_BYTES) {
        $message = ['type' => 'error', 'text' => 'Description is too long. Shorten the text or use a smaller paste (storage limit).'];
    } elseif ($customerEmail !== '' && !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
        $message = ['type' => 'error', 'text' => 'Customer email must be a valid email address.'];
    } elseif (!in_array($priority, ticket_priorities(), true)) {
        $message = ['type' => 'error', 'text' => 'Invalid priority selected.'];
    } else {
        $allowedAssigneeIds = [];
        foreach ($assignUsers as $assignUser) {
            $allowedAssigneeIds[] = $assignUser['user_id'];
        }

        if ($currentUser['role'] !== 'Admin' && $assignTo !== null && $assignTo !== $currentUser['user_id']) {
            $message = ['type' => 'error', 'text' => 'Agents can only leave a ticket unassigned or assign it to themselves.'];
        } elseif ($assignTo !== null && !in_array($assignTo, $allowedAssigneeIds, true)) {
            $message = ['type' => 'error', 'text' => 'Assigned user must be active.'];
        }

        if (!$message && $assignedVendorId !== null) {
            $validVendorIds = array_map(static fn(array $party): int => (int) $party['id'], $partyOptions);
            if (!in_array($assignedVendorId, $validVendorIds, true)) {
                $message = ['type' => 'error', 'text' => 'Assigned vendor must be an active party.'];
            }
        }

        if (!$message && $assignedVendorId !== null && $assignedVendorId === $customerPartyId) {
            $message = ['type' => 'error', 'text' => party_service_ticket_customer_vendor_conflict_message()];
        }

        if (!$message) {
            $customer = trim((string) $partyRow['name']);
            $country = $countryCanonical;
            $insertStmt = $pdo->prepare(
                'INSERT INTO tickets (
                    customer,
                    customer_email,
                    country,
                    issue,
                    description,
                    status,
                    priority,
                    assign_to,
                    assigned_vendor_id,
                    initiator_party_id,
                    created_by,
                    created_at,
                    closed_at,
                    external_ticket_id
                ) VALUES (
                    :customer,
                    :customer_email,
                    :country,
                    :issue,
                    :description,
                    :status,
                    :priority,
                    :assign_to,
                    :assigned_vendor_id,
                    :initiator_party_id,
                    :created_by,
                    NOW(),
                    NULL,
                    :external_ticket_id
                )'
            );
            $insertStmt->execute([
                ':customer' => $customer,
                ':customer_email' => $customerEmail !== '' ? $customerEmail : null,
                ':country' => $country,
                ':issue' => $issue,
                ':description' => $description,
                ':status' => 'Open',
                ':priority' => $priority,
                ':assign_to' => $assignTo,
                ':assigned_vendor_id' => $assignedVendorId,
                ':initiator_party_id' => $customerPartyId,
                ':created_by' => $currentUser['user_id'],
                ':external_ticket_id' => null,
            ]);
            $ticketId = (int) $pdo->lastInsertId();
            $createdAt = date('Y-m-d H:i:s');
            $createdTicketPreview = ['ticket_id' => $ticketId, 'created_at' => $createdAt];
            party_service_set_ticket_internal_id($pdo, $ticketId, format_ticket_serial($pdo, $createdTicketPreview));
            $createdTicket = ticket_service_get_ticket_with_user_context($pdo, $ticketId);
            if ($createdTicket) {
                $sendCustomerAcknowledgement = (string) ($_POST['send_auto_acknowledgement'] ?? '1') !== '0';
                ticket_service_handle_ticket_created($pdo, $createdTicket, $sendCustomerAcknowledgement);
            }

            $serialRow = ['ticket_id' => $ticketId, 'created_at' => $createdAt];
            $serialLookup = $pdo->prepare(
                'SELECT ticket_id, created_at, internal_ticket_id FROM tickets WHERE ticket_id = :ticket_id LIMIT 1'
            );
            $serialLookup->execute([':ticket_id' => $ticketId]);
            $serialDb = $serialLookup->fetch();
            if ($serialDb) {
                $serialRow = $serialDb;
            }
            $ticketSerial = format_ticket_serial($pdo, $serialRow);

            if ($wantsJson) {
                ticket_json_response([
                    'success' => true,
                    'ticket_id' => $ticketId,
                    'ticket_serial' => $ticketSerial,
                    'view_url' => url('tickets/view.php?id=' . $ticketId),
                    'message' => $assignTo === null ? 'Ticket created as unassigned.' : 'Ticket created successfully.',
                ]);
            }
            set_flash('success', $assignTo === null ? 'Ticket created as unassigned.' : 'Ticket created successfully.');
            redirect('tickets/view.php?id=' . $ticketId);
        }
    }

    if ($wantsJson && $message !== null && !empty($message['text'])) {
        ticket_json_response(['success' => false, 'message' => $message['text']], 422);
    }
}

include __DIR__ . '/../includes/header.php';

$ticketCountryFieldNs = 'create';
$ticketPartySearchUrl = url('tickets/party_search.php');
$selectedCustomerPartyId = (int) ($_POST['customer_party_id'] ?? 0);
$selectedCustomerDisplay = (string) ($_POST['customer'] ?? '');
$selectedCountry = (string) ($_POST['country'] ?? '');
$ticketCountryShowRequired = true;
?>

<?php if ($message): ?>
    <div class="flash flash-<?php echo e($message['type']); ?>"><?php echo e($message['text']); ?></div>
<?php endif; ?>

<div class="panel-grid">
    <div class="form-card">
        <div class="info-strip">
            <div>
                <strong>Optional assignment</strong>
                <p>If you leave assignment blank, the ticket will stay unassigned and can be picked up later.</p>
            </div>
        </div>

        <form method="POST" novalidate>
            <?php echo csrf_field(); ?>

            <div class="form-grid">
                <?php include __DIR__ . '/../views/tickets/ticket_ui_party_country_fields.php'; ?>

                <?php
                $customerEmailInputId = 'customer_email';
                $customerEmailGroupExtraClass = '';
                $customerEmailValue = (string) ($_POST['customer_email'] ?? '');
                $sendAutoAckEnabled = (string) ($_POST['send_auto_acknowledgement'] ?? '1') !== '0';
                include __DIR__ . '/../views/tickets/ticket_ui_customer_email_ack_fields.php';
?>

                <div class="input-group full">
                    <label for="issue">Issue</label>
                    <input type="text" id="issue" name="issue" value="<?php echo e($_POST['issue'] ?? ''); ?>" required>
                </div>

                <div class="input-group full">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" required><?php echo e($_POST['description'] ?? ''); ?></textarea>
                </div>

                <div class="input-group">
                    <label for="priority">Priority</label>
                    <select id="priority" name="priority" required>
                        <option value="">Select priority</option>
                        <?php foreach (ticket_priorities() as $priority): ?>
                            <option value="<?php echo e($priority); ?>" <?php echo (($_POST['priority'] ?? '') === $priority) ? 'selected' : ''; ?>><?php echo e($priority); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="input-group">
                    <label for="assign_to">Assign To</label>
                    <select id="assign_to" name="assign_to">
                        <option value="">Unassigned</option>
                        <?php if ($currentUser['role'] === 'Admin'): ?>
                            <?php foreach ($assignUsers as $assignUser): ?>
                                <option value="<?php echo e($assignUser['user_id']); ?>" <?php echo (($_POST['assign_to'] ?? '') === $assignUser['user_id']) ? 'selected' : ''; ?>>
                                    <?php echo e($assignUser['name'] . ' (' . $assignUser['role'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <option value="<?php echo e($currentUser['user_id']); ?>" <?php echo (($_POST['assign_to'] ?? '') === $currentUser['user_id']) ? 'selected' : ''; ?>>
                                <?php echo e($currentUser['name'] . ' (Assign to me)'); ?>
                            </option>
                        <?php endif; ?>
                    </select>
                    <div class="field-help">
                        <?php echo $currentUser['role'] === 'Admin' ? 'Admins can assign to any active user or leave it unassigned.' : 'Agents can create the ticket as unassigned or assign it to themselves.'; ?>
                    </div>
                </div>

                <div class="input-group">
                    <label for="assigned_vendor_id">Assigned Vendor</label>
                    <select id="assigned_vendor_id" name="assigned_vendor_id">
                        <option value="">No vendor assigned</option>
                        <?php foreach ($partyOptions as $party): ?>
                            <option value="<?php echo e((string) $party['id']); ?>" <?php echo (string) ($_POST['assigned_vendor_id'] ?? '') === (string) $party['id'] ? 'selected' : ''; ?>>
                                <?php echo e($party['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="field-help">Optional. Pick a registered party if the ticket should be routed to a vendor.</div>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Create Ticket</button>
                <a href="<?php echo e(url('tickets/list.php')); ?>" class="btn btn-secondary">Back to Ticket List</a>
            </div>
        </form>
    </div>

    <div class="hero-card">
        <h2 class="section-title">Creation Tips</h2>
        <ul class="hint-list">
            <li>Keep the issue short and clear so it scans well in the list view.</li>
            <li>Use the description for exact customer symptoms and context.</li>
            <li>Unassigned tickets remain available for pickup from the ticket list.</li>
        </ul>
    </div>
</div>

<script src="<?php echo e(url('assets/js/tickets_ui_party_country.js')); ?>" defer></script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
