<?php
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if (!isset($logs) || !isset($totalLogs) || !isset($page) || !isset($totalPages) || !isset($perPage) || !isset($search) || !isset($actionTypeFilter) || !isset($exportFormatFilter) || !isset($statusFilter) || !isset($dateFrom) || !isset($dateTo)) {
    if ($isAjax) {
        exit;
    }
    return;
}

$perPageDisplay = (int) $perPage;
?>

<?php if (!$isAjax): ?>
<style>
    .apd-table-scroll {
        width: 100%;
        overflow-x: auto;
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: 8px;
        box-shadow: none;
        margin-bottom: 24px;
    }

    .apd-table-scroll::-webkit-scrollbar {
        height: 8px;
        width: 8px;
    }
    .apd-table-scroll::-webkit-scrollbar-track {
        background: #f1f5f9;
    }
    .apd-table-scroll::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 4px;
    }
    .apd-table-scroll::-webkit-scrollbar-thumb:hover {
        background: #94a3b8;
    }

    .apd-table {
        width: 100%;
        border-collapse: collapse;
        text-align: left;
        font-size: 0.8rem;
    }

    .apd-table th {
        background: #f8fafc;
        color: var(--text-secondary);
        font-weight: 600;
        padding: 10px 12px;
        border-bottom: 1px solid var(--border-color);
        white-space: nowrap;
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }

    .apd-table td {
        padding: 10px 12px;
        border-bottom: 1px solid var(--border-color);
        color: var(--text-primary);
        white-space: nowrap;
        vertical-align: middle;
    }

    .apd-table tbody tr:last-child td {
        border-bottom: none;
    }

    .apd-table tbody tr:hover td {
        background-color: #f8fafc;
    }

    .apd-mono {
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
        font-size: 0.825rem;
        letter-spacing: -0.01em;
    }

    .apd-td-muted {
        color: var(--text-secondary) !important;
    }

    .status-pill {
        display: inline-flex;
        align-items: center;
        font-size: 0.75rem;
        font-weight: 700;
        padding: 4px 8px;
        border-radius: 6px;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

    .status-pill-success {
        background-color: var(--success-bg);
        color: var(--success);
    }

    .status-pill-failed {
        background-color: var(--danger-bg);
        color: var(--danger);
    }

    .device-badge {
        font-size: 0.775rem;
        padding: 3px 6px;
        background: #f1f5f9;
        border-radius: 4px;
        border: 1px solid var(--border-color);
        color: var(--text-secondary);
        font-weight: 500;
    }

    .apd-empty {
        text-align: center !important;
        padding: 40px 16px !important;
        color: var(--text-muted);
        font-size: 0.825rem;
    }

    .apd-pagination-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: 8px;
        padding: 10px 14px;
        box-shadow: none;
        flex-wrap: wrap;
        gap: 10px;
    }

    .sl-page-info {
        font-size: 0.8rem;
        color: var(--text-secondary);
    }

    .sl-page-info strong {
        color: var(--text-primary);
    }

    .apd-pagination-actions {
        display: flex;
        gap: 8px;
    }

    .pagination-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.8rem;
        font-weight: 600;
        height: 32px;
        padding: 0 10px;
        border-radius: var(--radius-md);
        border: 1px solid var(--border-color);
        background: #fff;
        color: var(--text-secondary);
        text-decoration: none;
        transition: all 0.15s ease-in-out;
        cursor: pointer;
    }

    .pagination-btn:hover:not(.disabled) {
        background: #f1f5f9;
        color: var(--text-primary);
        border-color: var(--text-muted);
    }

    .pagination-btn.disabled {
        background: #f8fafc;
        color: var(--text-muted);
        border-color: var(--border-color);
        cursor: not-allowed;
        pointer-events: none;
    }

    .apd-empty {
        background: linear-gradient(180deg, #f8fafc 0%, #ffffff 100%);
        border: 1px dashed var(--border-color);
        border-radius: var(--radius-md);
    }

    .apd-empty__icon {
        width: 48px;
        height: 48px;
        margin: 0 auto 12px;
        color: var(--text-muted);
        opacity: 0.6;
    }

    .apd-empty__icon svg {
        width: 100%;
        height: 100%;
        stroke-width: 1.5;
    }

    .apd-empty__title {
        font-size: 0.95rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 4px;
    }

    .apd-empty__desc {
        font-size: 0.8rem;
        color: var(--text-secondary);
        max-width: 400px;
        margin: 0 auto;
        line-height: 1.5;
    }

    html.theme-dark .apd-table-scroll::-webkit-scrollbar-track {
        background: #162844;
    }
    html.theme-dark .apd-table-scroll::-webkit-scrollbar-thumb {
        background: #223553;
    }
    html.theme-dark .apd-table-scroll::-webkit-scrollbar-thumb:hover {
        background: #2d3f5c;
    }
    html.theme-dark .apd-table thead th {
        background: rgba(15, 27, 45, 0.96);
    }
    html.theme-dark .apd-table tbody tr:hover td {
        background-color: rgba(96, 165, 250, 0.08);
    }
    html.theme-dark .apd-empty {
        background: linear-gradient(180deg, #0f1b2d 0%, #13233a 100%);
    }
    html.theme-dark .pagination-btn {
        background: #0f1b2d;
        border-color: #223553;
        color: #9fb0c7;
    }
    html.theme-dark .pagination-btn:hover:not(.disabled) {
        background: #162844;
        color: #e2e8f0;
        border-color: #60a5fa;
    }
    html.theme-dark .pagination-btn.disabled {
        background: #13233a;
        color: #9fb0c7;
        border-color: #223553;
    }
</style>
<?php endif; ?>

<div id="el-ajax-table">
    <div class="apd-table-scroll">
        <table class="apd-table">
            <thead>
                <tr>
                    <th>Date & Time</th>
                    <th>Username</th>
                    <th>Module</th>
                    <th>Action</th>
                    <th>Format</th>
                    <th>Records</th>
                    <th>Status</th>
                    <th>IP Address</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($logs)): ?>
                    <?php foreach ($logs as $log): ?>
                        <?php
                            $isSuccess = strtolower((string) ($log['status'] ?? '')) === 'success';
                            $statusClass = $isSuccess ? 'status-pill-success' : 'status-pill-failed';
                            $filtersJson = $log['filters_json'] ?? null;
                            $filtersDisplay = '-';
                            if ($filtersJson !== null && $filtersJson !== '') {
                                $decoded = json_decode($filtersJson, true);
                                if (is_array($decoded) && !empty($decoded)) {
                                    $parts = [];
                                    foreach ($decoded as $k => $v) {
                                        if ($v !== null && $v !== '' && $v !== []) {
                                            $parts[] = (string) $k . ': ' . (is_array($v) ? implode(', ', $v) : (string) $v);
                                        }
                                    }
                                    if ($parts !== []) {
                                        $filtersDisplay = implode('; ', $parts);
                                    }
                                }
                            }
                        ?>
                        <tr>
                            <td>
                                <span style="display:inline-flex;align-items:center;gap:6px;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.7;flex-shrink:0;"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
                                    <?php echo e(format_date($log['created_at'] ?? null)); ?>
                                </span>
                            </td>
                            <td style="font-weight: 600; color: var(--text-primary);">
                                <span style="display:inline-flex;align-items:center;gap:6px;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.7;flex-shrink:0;"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                    <?php echo e((string) ($log['user_name'] ?? '-')); ?>
                                </span>
                            </td>
                            <td>
                                <span style="display:inline-flex;align-items:center;gap:6px;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.7;flex-shrink:0;"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H19a1 1 0 0 1 1 1v18a1 1 0 0 1-1 1H6.5a1 1 0 0 1 0-2H19v-15H6.5a1 1 0 0 0-1 1v15a1 1 0 0 0 1 1H19a1 1 0 0 0 1-1v-15"/></svg>
                                    <?php echo e((string) ($log['module_name'] ?? '-')); ?>
                                </span>
                            </td>
                            <td>
                                <span style="display:inline-flex;align-items:center;gap:6px;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.7;flex-shrink:0;"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                    <span class="device-badge">
                                        <?php echo e((string) ($log['action_type'] ?? '-')); ?>
                                    </span>
                                </span>
                            </td>
                            <td>
                                <span style="display:inline-flex;align-items:center;gap:6px;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.7;flex-shrink:0;"><path d="M4 22h14a2 2 0 0 0 2-2V7.5L14.5 2H6a2 2 0 0 0-2 2v4"/><path d="M14 2v6h6"/><path d="m7 12 5 5 5-5"/><path d="M7 17V7h3"/></svg>
                                    <?php echo e((string) ($log['export_format'] ?? '-')); ?>
                                </span>
                            </td>
                            <td class="apd-mono apd-td-muted"><?php echo e((string) ($log['total_records'] ?? '0')); ?></td>
                            <td>
                                <span class="status-pill <?php echo $statusClass; ?>">
                                    <?php if ($isSuccess): ?>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.9;flex-shrink:0;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                    <?php else: ?>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.9;flex-shrink:0;"><circle cx="12" cy="12" r="10"/><line x1="15" x2="9" y1="9" y2="15"/><line x1="9" x2="15" y1="9" y2="15"/></svg>
                                    <?php endif; ?>
                                    <?php echo e((string) ($log['status'] ?? 'UNKNOWN')); ?>
                                </span>
                            </td>
                            <td>
                                <span style="display:inline-flex;align-items:center;gap:6px;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.7;flex-shrink:0;"><path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><circle cx="12" cy="20" r="1"/></svg>
                                    <?php echo e((string) ($log['ip_address'] ?? '0.0.0.0')); ?>
                                </span>
                            </td>
                        </tr>
                        <?php if ($filtersDisplay !== '-' || !empty($log['remarks'])): ?>
                        <tr style="background-color: #fafafa;">
                            <td colspan="8" style="padding: 8px 12px; font-size: 0.75rem; color: var(--text-secondary); border-bottom: 1px solid var(--border-color);">
                                <?php if ($filtersDisplay !== '-' || !empty($log['remarks'])): ?>
                                    <span style="display:inline-flex;align-items:center;gap:6px;margin-right:6px;">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.7;"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                                    </span>
                                <?php endif; ?>
                                <?php if ($filtersDisplay !== '-'): ?>
                                    <?php echo e($filtersDisplay); ?>
                                <?php endif; ?>
                                <?php if (!empty($log['remarks'])): ?>
                                    <?php if ($filtersDisplay !== '-'): ?> | <?php endif; ?>
                                    <?php echo e((string) $log['remarks']); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="9" class="apd-empty">
                            <div class="apd-empty__icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="11" cy="11" r="8"/>
                                    <path d="M21 21l-4.35-4.35"/>
                                    <path d="M8 11h6" opacity="0.4"/>
                                </svg>
                            </div>
                            <div class="apd-empty__title">No matching records found</div>
                            <div class="apd-empty__desc">No export or import activity has been recorded yet.</div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<div id="el-ajax-pager">
    <?php if ($totalPages > 0): ?>
    <div class="apd-pagination-footer">
        <span class="sl-page-info">
            Showing Page <strong><?php echo e((string) $page); ?></strong> of <strong><?php echo e((string) $totalPages); ?></strong>
        </span>

        <div class="apd-pagination-actions">
            <?php if ($page > 1): ?>
                <a href="<?php echo e(url('system_logs/export_logs.php?page=' . ($page - 1) . '&search=' . rawurlencode($search) . '&action_type=' . rawurlencode($actionTypeFilter) . '&export_format=' . rawurlencode($exportFormatFilter) . '&status=' . rawurlencode($statusFilter) . '&date_from=' . rawurlencode($dateFrom) . '&date_to=' . rawurlencode($dateTo) . '&per_page=' . rawurlencode((string) $perPageDisplay))); ?>" class="pagination-btn">
                    &larr; Previous
                </a>
            <?php else: ?>
                <span class="pagination-btn disabled">&larr; Previous</span>
            <?php endif; ?>

            <?php if ($page < $totalPages): ?>
                <a href="<?php echo e(url('system_logs/export_logs.php?page=' . ($page + 1) . '&search=' . rawurlencode($search) . '&action_type=' . rawurlencode($actionTypeFilter) . '&export_format=' . rawurlencode($exportFormatFilter) . '&status=' . rawurlencode($statusFilter) . '&date_from=' . rawurlencode($dateFrom) . '&date_to=' . rawurlencode($dateTo) . '&per_page=' . rawurlencode((string) $perPageDisplay))); ?>" class="pagination-btn">
                    Next &rarr;
                </a>
            <?php else: ?>
                <span class="pagination-btn disabled">Next &rarr;</span>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
