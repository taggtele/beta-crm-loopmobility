<?php
/** @var array $filters @var array $listAssignees @var array $listPartyOptions */
$activeFilterCount = 0;
foreach (['status', 'priority', 'country', 'customer', 'assign_to', 'assigned_vendor_id', 'external_ticket_id', 'from_date', 'to_date'] as $filterKey) {
    if (trim((string) ($filters[$filterKey] ?? '')) !== '') {
        $activeFilterCount++;
    }
}
$hasActiveFilters = $activeFilterCount > 0;
?>
<div class="ticket-filter-premium" id="filter-section">
    <div class="ticket-filter-premium-top">
        <label class="ticket-search-premium">
            <span class="sr-only">Search tickets</span>
            <svg class="ticket-search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3-3"/></svg>
            <input
                type="search"
                id="search"
                value="<?php echo e($filters['search']); ?>"
                placeholder="Search ticket ID, subject, customer, email…"
                autocomplete="off"
            >
        </label>
        <div class="ticket-filter-premium-actions">
            <button
                type="button"
                class="ticket-filter-chip-btn"
                id="toggle-filters"
                aria-expanded="<?php echo $hasActiveFilters ? 'true' : 'false'; ?>"
                aria-controls="filter-fields"
            >
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 6h16M7 12h10M10 18h4"/></svg>
                Filters
                <?php if ($activeFilterCount > 0): ?>
                    <span class="ticket-filter-count" id="filter-active-count"><?php echo e((string) $activeFilterCount); ?></span>
                <?php else: ?>
                    <span class="ticket-filter-count is-hidden" id="filter-active-count">0</span>
                <?php endif; ?>
            </button>
            <button type="button" class="ticket-filter-chip-btn ticket-filter-clear" id="clear-filters">Clear all</button>
        </div>
    </div>

    <div class="ticket-filter-drawer" id="filter-fields"<?php echo $hasActiveFilters ? '' : ' hidden'; ?>>
        <div class="ticket-filter-drawer-grid">
            <div class="input-group input-group-compact">
                <label for="status">Status</label>
                <select id="status">
                    <option value="">All statuses</option>
                    <?php foreach (ticket_statuses() as $status): ?>
                        <option value="<?php echo e($status); ?>" <?php echo ($filters['status'] ?? '') === $status ? 'selected' : ''; ?>><?php echo e($status); ?></option>
                    <?php endforeach; ?>
                    <option value="Archived" <?php echo ($filters['status'] ?? '') === 'Archived' ? 'selected' : ''; ?>>Archived</option>
                    <option value="Deleted" <?php echo ($filters['status'] ?? '') === 'Deleted' ? 'selected' : ''; ?>>Deleted</option>
                </select>
            </div>
            <div class="input-group input-group-compact">
                <label for="priority">Priority</label>
                <select id="priority">
                    <option value="">All priorities</option>
                    <?php foreach (ticket_priorities() as $priority): ?>
                        <option value="<?php echo e($priority); ?>" <?php echo ($filters['priority'] ?? '') === $priority ? 'selected' : ''; ?>><?php echo e($priority); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="input-group input-group-compact">
                <label for="assign-to">Assigned user</label>
                <select id="assign-to">
                    <option value="">Anyone</option>
                    <option value="__unassigned__" <?php echo ($filters['assign_to'] ?? '') === '__unassigned__' ? 'selected' : ''; ?>>Unassigned</option>
                    <?php foreach ($listAssignees as $assignee): ?>
                        <option value="<?php echo e($assignee['user_id']); ?>" <?php echo ($filters['assign_to'] ?? '') === $assignee['user_id'] ? 'selected' : ''; ?>>
                            <?php echo e($assignee['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="input-group input-group-compact">
                <label for="vendor-id">Vendor</label>
                <select id="vendor-id">
                    <option value="">All vendors</option>
                    <?php foreach ($listPartyOptions as $party): ?>
                        <option value="<?php echo e((string) $party['id']); ?>" <?php echo (string) ($filters['assigned_vendor_id'] ?? '') === (string) $party['id'] ? 'selected' : ''; ?>>
                            <?php echo e($party['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="input-group input-group-compact">
                <label for="customer">Customer</label>
                <input type="text" id="customer" value="<?php echo e($filters['customer'] ?? ''); ?>" placeholder="Customer name">
            </div>
            <div class="input-group input-group-compact">
                <label for="country">Country</label>
                <input type="text" id="country" value="<?php echo e($filters['country'] ?? ''); ?>" placeholder="Country">
            </div>
            <div class="input-group input-group-compact">
                <label for="external-ticket-id">External ID</label>
                <input type="text" id="external-ticket-id" value="<?php echo e($filters['external_ticket_id']); ?>" placeholder="Vendor reference">
            </div>
            <div class="input-group input-group-compact">
                <label for="from-date">From</label>
                <input type="date" id="from-date" value="<?php echo e($filters['from_date']); ?>">
            </div>
            <div class="input-group input-group-compact">
                <label for="to-date">To</label>
                <input type="date" id="to-date" value="<?php echo e($filters['to_date']); ?>">
            </div>
        </div>
    </div>
</div>
