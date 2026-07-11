<?php
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// Secure defensive route protection
if (!isset($logs) || !isset($totalLogs) || !isset($page) || !isset($totalPages) || !isset($search) || !isset($statusFilter) || !isset($dateFilter) || !isset($perPage)) {
    if ($isAjax) {
        exit;
    }
    return;
}

$perPageDisplay = (int) $perPage;
?>

<?php if (!$isAjax): ?>
<style>
/* Enterprise Grid Layout System */
.apd-table-scroll {
    width: 100%;
    overflow-x: auto;
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    box-shadow: none;
    margin-bottom: 24px;
}

/* Custom Scrollbar Optimization for Dense Logs */
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

/* Text & Type Utilities */
.apd-mono {
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    font-size: 0.825rem;
    letter-spacing: -0.01em;
}

.apd-td-muted {
    color: var(--text-secondary) !important;
}

/* Enterprise Semantic Badges */
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

/* Empty Presentation State */
.apd-empty {
    text-align: center !important;
    padding: 40px 16px !important;
    color: var(--text-muted);
    font-size: 0.825rem;
}

/* Modern Enterprise Footer Pagination Control */
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
    /* Enterprise Empty State Presentation */
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

<div id="sl-ajax-table">
    <div class="apd-table-scroll">
        <table class="apd-table">
            <thead>
                <tr>
                    <th>User Name</th>
                    <th>Login ID</th>
                    <th>Login Time</th>
                    <th>Logout Time</th>
                    <th>Status</th>
                    <th>IP Address</th>
                    <th>Resolved Location</th>
                    <th>Browser</th>
                    <th>Device Class</th>
                    <th>OS Platform</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($logs)): ?>
                    <?php foreach ($logs as $log): ?>
                        <?php 
                            $isSuccess = strtolower((string) ($log['status'] ?? '')) === 'success';
                            $statusClass = $isSuccess ? 'status-pill-success' : 'status-pill-failed';
                        ?>
                        <tr>
                            <td style="font-weight: 600; color: var(--text-primary);">
                                <span style="display:inline-flex;align-items:center;gap:6px;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.7;flex-shrink:0;"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                    <?php echo e((string) ($log['user_name'] ?? '-')); ?>
                                </span>
                            </td>
                            <td class="apd-mono apd-td-muted"><?php echo e((string) ($log['login_identifier'] ?? '-')); ?></td>
                            <td>
                                <span style="display:inline-flex;align-items:center;gap:6px;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.7;flex-shrink:0;"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M12 18v-6"/><path d="M9 15l3 3 3-3"/></svg>
                                    <?php echo e(format_date($log['login_time'] ?? null)); ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($log['logout_time'])): ?>
                                    <span style="display:inline-flex;align-items:center;gap:6px;">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.7;flex-shrink:0;"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/></svg>
                                        <?php echo e(format_date($log['logout_time'])); ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color:var(--text-muted);">-</span>
                                <?php endif; ?>
                            </td>
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
                            <td class="apd-mono apd-td-muted"><?php echo e((string) ($log['ip_address'] ?? '0.0.0.0')); ?></td>
                            <td>
                                <span style="display:inline-flex;align-items:center;gap:6px;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.7;flex-shrink:0;"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                                    <?php echo e((string) ($log['location'] ?? 'Remote Access')); ?>
                                </span>
                            </td>
                            <td>
                                <span style="display:inline-flex;align-items:center;gap:6px;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.7;flex-shrink:0;"><circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/></svg>
                                    <?php echo e((string) ($log['browser'] ?? '-')); ?>
                                </span>
                            </td>
                            <td>
                                <?php
                                    $deviceRaw = strtolower((string) ($log['device'] ?? ''));
                                    if (str_contains($deviceRaw, 'mobile')) {
                                        $deviceIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.7;flex-shrink:0;"><rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/></svg>';
                                    } elseif (str_contains($deviceRaw, 'tablet')) {
                                        $deviceIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.7;flex-shrink:0;"><rect width="16" height="20" x="4" y="2" rx="2" ry="2"/><path d="M12 18h.01"/></svg>';
                                    } else {
                                        $deviceIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.7;flex-shrink:0;"><rect width="20" height="14" x="2" y="3" rx="2"/><path d="M8 21h8"/><path d="M12 17v4"/></svg>';
                                    }
                                ?>
                                <span style="display:inline-flex;align-items:center;gap:6px;">
                                    <?php echo $deviceIcon; ?>
                                    <?php echo e((string) ($log['device'] ?? '-')); ?>
                                </span>
                            </td>
                            <td>
                                <?php
                                    $osRaw = strtolower((string) ($log['os'] ?? ''));
                                    if (str_contains($osRaw, 'windows')) {
                                        $osIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.7;flex-shrink:0;"><rect width="14" height="14" x="2" y="2" rx="1"/><rect width="14" height="14" x="8" y="8" rx="1"/></svg>';
                                    } elseif (str_contains($osRaw, 'mac')) {
                                        $osIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.7;flex-shrink:0;"><path d="M12 2a2 2 0 0 1 2 2c0 .74-.4 1.39-1 1.73V7h1a7 7 0 0 1 7 7h1a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1h-1v1a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-1H2a1 1 0 0 1-1-1v-3a1 1 0 0 1 1-1h1a7 7 0 0 1 7-7h1V5.73c-.6-.34-1-.99-1-1.73a2 2 0 0 1 2-2z"/><path d="M8 14h.01"/><path d="M12 14h.01"/><path d="M16 14h.01"/></svg>';
                                    } elseif (str_contains($osRaw, 'ios')) {
                                        $osIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.7;flex-shrink:0;"><rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/></svg>';
                                    } elseif (str_contains($osRaw, 'android')) {
                                        $osIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.7;flex-shrink:0;"><path d="M5 16V8a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v8"/><path d="M15 14h.01"/><path d="M9 14h.01"/><path d="M12 14h.01"/><path d="M12 18h.01"/><path d="M15 18h.01"/><path d="M9 18h.01"/></svg>';
                                    } elseif (str_contains($osRaw, 'linux')) {
                                        $osIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.7;flex-shrink:0;"><path d="M12 2a2 2 0 0 1 2 2c0 .74-.4 1.39-1 1.73V7h1a7 7 0 0 1 7 7h1a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1h-1v1a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-1H2a1 1 0 0 1-1-1v-3a1 1 0 0 1 1-1h1a7 7 0 0 1 7-7h1V5.73c-.6-.34-1-.99-1-1.73a2 2 0 0 1 2-2z"/></svg>';
                                    } else {
                                        $osIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.7;flex-shrink:0;"><circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/></svg>';
                                    }
                                ?>
                                <span style="display:inline-flex;align-items:center;gap:6px;">
                                    <?php echo $osIcon; ?>
                                    <?php echo e((string) ($log['os'] ?? '-')); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="10" class="apd-empty">
                            <div class="apd-empty__icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="11" cy="11" r="8"/>
                                    <path d="M21 21l-4.35-4.35"/>
                                    <path d="M8 11h6" opacity="0.4"/>
                                </svg>
                            </div>
                            <div class="apd-empty__title">No matching records found</div>
                            <div class="apd-empty__desc">The current filter combination did not return any login activity entries from the audit matrix.</div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div><div id="sl-ajax-pager">
    <?php if ($totalPages > 0): ?>
    <div class="apd-pagination-footer">
        <span class="sl-page-info">
            Showing Page <strong><?php echo e((string) $page); ?></strong> of <strong><?php echo e((string) $totalPages); ?></strong>
        </span>
        
        <div class="apd-pagination-actions">
            <?php if ($page > 1): ?>
                <a href="<?php echo e(url('system_logs/index.php?page=' . ($page - 1) . '&search=' . rawurlencode($search) . '&status=' . rawurlencode($statusFilter) . '&date=' . rawurlencode($dateFilter) . '&per_page=' . rawurlencode((string) $perPageDisplay))); ?>" class="pagination-btn">
                    &larr; Previous
                </a>
            <?php else: ?>
                <span class="pagination-btn disabled">&larr; Previous</span>
            <?php endif; ?>

            <?php if ($page < $totalPages): ?>
                <a href="<?php echo e(url('system_logs/index.php?page=' . ($page + 1) . '&search=' . rawurlencode($search) . '&status=' . rawurlencode($statusFilter) . '&date=' . rawurlencode($dateFilter) . '&per_page=' . rawurlencode((string) $perPageDisplay))); ?>" class="pagination-btn">
                    Next &rarr;
                </a>
            <?php else: ?>
                <span class="pagination-btn disabled">Next &rarr;</span>
    <?php endif; ?>
</div>
    </div>
    <?php endif; ?>
</div>






