<?php
// DEBUG: Show all errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../services/email_account_service.php';
require_once __DIR__ . '/../services/email_log_service.php';
require_once __DIR__ . '/../modules/email/smtp_service.php';
require_once __DIR__ . '/../modules/email/imap_service.php';

$currentUser = require_login($pdo);
if (($currentUser['role'] ?? '') !== 'Admin') {
    set_flash('error', 'Admin access required.');
    redirect('dashboard/index.php');
}

// Auto-migrate schema: add any missing columns before querying
try {
    // Ensure table exists first (skip gracefully if missing)
    $tableCheck = $pdo->query("SELECT 1 FROM information_schema.tables 
                               WHERE table_schema = DATABASE() 
                               AND table_name = 'email_accounts' LIMIT 1");
    if ($tableCheck->fetch()) {
        email_account_service_ensure_schema($pdo);
        email_imap_ensure_account_columns($pdo);
    } else {
        // Table missing - show helpful message
        $actionMessage = ['type' => 'error', 'text' => 'Email accounts table not found. Please run the SQL setup script first.'];
    }
} catch (Throwable $e) {
    error_log('Schema migration failed: ' . $e->getMessage());
    $actionMessage = ['type' => 'error', 'text' => 'Database schema error: ' . $e->getMessage()];
}

// Ensure database connection is alive, reconnect if needed
function ensure_db_connection(): void {
    global $pdo;
    try {
        $pdo->query('SELECT 1');
    } catch (PDOException $e) {
        $errorMsg = strtolower($e->getMessage());
        if (strpos($errorMsg, 'server has gone away') !== false || 
            strpos($errorMsg, 'mysql server has gone away') !== false ||
            $e->getCode() === 'HY000') {
            $pdo = create_pdo_connection();
        } else {
            throw $e;
        }
    }
}

// Retry a callable on connection loss (reconnects then retries once)
function retry_action(callable $action, int $maxRetries = 1): mixed {
    global $pdo;
    $attempt = 0;
    do {
        try {
            ensure_db_connection();
            return $action($pdo);
        } catch (PDOException $e) {
            $msg = strtolower($e->getMessage());
            if (strpos($msg, 'server has gone away') !== false || $e->getCode() === 'HY000') {
                if ($attempt < $maxRetries) {
                    $pdo = create_pdo_connection();
                    $attempt++;
                    continue;
                }
            }
            throw $e;
        }
    } while (true);
}

// Helper function to execute queries with automatic retry on connection loss
function execute_with_retry(callable $callback, int $maxRetries = 1): mixed {
    global $pdo;
    $attempt = 0;
    do {
        try {
            ensure_db_connection();
            return $callback($pdo);
        } catch (PDOException $e) {
            $errorMsg = strtolower($e->getMessage());
            $isConnectionError = strpos($errorMsg, 'server has gone away') !== false ||
                                  strpos($errorMsg, 'mysql server has gone away') !== false ||
                                  strpos($errorMsg, 'cr server gone') !== false ||
                                  $e->getCode() === 'HY000' ||
                                  strpos($errorMsg, 'lost connection') !== false;
            
            if ($isConnectionError && $attempt < $maxRetries) {
                ensure_db_connection();
                $attempt++;
                continue;
            }
            throw $e;
        }
    } while ($attempt <= $maxRetries);
}

// Check capabilities before handling requests
$canTestConnections = function_exists('stream_socket_client');

// Handle POST actions
$actionMessage = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verify_csrf();
    try {
        // Ensure DB connection is healthy before processing actions
        ensure_db_connection();
        $action = $_POST['action'];
        $accountId = (int)($_POST['account_id'] ?? 0);
        
        if ($action === 'test_smtp' && $accountId > 0) {
            if (!$canTestConnections) {
                throw new RuntimeException('stream_socket_client() is disabled on this hosting provider. SMTP/IMAP tests cannot run.');
            }
            $account = email_smtp_active_account($pdo, $accountId);
            if ($account) {
                $host = $account['smtp_host'];
                $port = (int)$account['smtp_port'];
                $encryption = strtolower((string)($account['encryption'] ?? 'ssl'));
                $transportHost = $encryption === 'ssl' ? 'ssl://' . $host : $host;
                $stream = @stream_socket_client($transportHost . ':' . $port, $errno, $errstr, 5);
                if (is_resource($stream)) {
                    fclose($stream);
                    $actionMessage = ['type' => 'success', 'text' => "✓ SMTP OK: {$host}:{$port} ({$encryption})"];
                } else {
                    $actionMessage = ['type' => 'error', 'text' => "✗ SMTP failed: {$errstr}"];
                }
            }
        }
        
        if ($action === 'test_imap' && $accountId > 0) {
            if (!$canTestConnections) {
                throw new RuntimeException('stream_socket_client() is disabled on this hosting provider. SMTP/IMAP tests cannot run.');
            }
            $account = email_imap_account_by_id($pdo, $accountId, false);
            if ($account) {
                $encryption = strtolower((string)($account['encryption'] ?? 'ssl'));
                $host = $account['imap_host'];
                $port = (int)$account['imap_port'];
                $transportHost = $encryption === 'ssl' ? 'ssl://' . $host : $host;
                $stream = @stream_socket_client($transportHost . ':' . $port, $errno, $errstr, 5);
                if (is_resource($stream)) {
                    fclose($stream);
                    $actionMessage = ['type' => 'success', 'text' => "✓ IMAP OK: {$host}:{$port}"];
                } else {
                    $actionMessage = ['type' => 'error', 'text' => "✗ IMAP failed: {$errstr}"];
                }
            }
        }
        
        if ($action === 'retry_failed') {
            $outboxId = (int)($_POST['outbox_id'] ?? 0);
            $retried = 0;
            if ($outboxId > 0) {
                if (email_smtp_process_outbox_item($pdo, $outboxId)) $retried = 1;
            } else {
                $stmt = retry_action(function($pdo) {
                    return $pdo->query("SELECT id FROM email_outbox_log WHERE status = 'failed' ORDER BY id DESC LIMIT 10");
                });
                $failedIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
                foreach ($failedIds as $fid) {
                    if (email_smtp_process_outbox_item($pdo, (int)$fid)) $retried++;
                }
            }
            $actionMessage = ['type' => 'success', 'text' => "✓ Retried {$retried} email(s)."];
        }
        
        if ($action === 'run_imap_import_single' && $accountId > 0) {
            $summary = retry_action(function($pdo) use ($accountId) {
                return email_imap_import_messages($pdo, 10, $accountId);
            });
            $actionMessage = ['type' => 'success', 'text' => "✓ Imported last 10 from account #{$accountId}: {$summary['messages']} read, {$summary['created']} new tickets, {$summary['replied']} replies, {$summary['duplicates']} already in CRM. Check Email Logs → Incoming."];
        }
        
        if ($action === 'run_imap_import_all') {
            $summary = retry_action(function($pdo) {
                return email_imap_import_messages($pdo, 10);
            });
            $actionMessage = ['type' => 'success', 'text' => "✓ Imported last 10 per account: {$summary['messages']} read, {$summary['created']} new, {$summary['replied']} replies. Check Email Logs → Incoming."];
        }
        
        if ($action === 'toggle_cron' && $accountId > 0) {
            $enabled = isset($_POST['cron_enabled']) ? 1 : 0;
            $stmt = $pdo->prepare("UPDATE email_accounts SET cron_enabled = ? WHERE id = ?");
            $stmt->execute([$enabled, $accountId]);
            $actionMessage = ['type' => 'success', 'text' => $enabled ? '✓ Cron import enabled for this account.' : '✓ Cron import disabled for this account.'];
        }
        
    } catch (Throwable $e) {
        $actionMessage = ['type' => 'error', 'text' => '✗ Action failed: ' . $e->getMessage()];
    }
}

// Get filter values
$search = $_GET['search'] ?? '';
$filterActive = $_GET['filter'] ?? 'all';
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

// Build WHERE conditions
$whereConditions = [];
$params = [];

if ($search !== '') {
    $whereConditions[] = '(email LIKE :search1 OR from_name LIKE :search2)';
    $params[':search1'] = '%' . $search . '%';
    $params[':search2'] = '%' . $search . '%';
}

if ($filterActive === 'active') {
    $whereConditions[] = 'is_active = 1';
} elseif ($filterActive === 'inactive') {
    $whereConditions[] = 'is_active = 0';
}

if ($dateFrom !== '') {
    $whereConditions[] = 'last_checked_at >= :date_from';
    $params[':date_from'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $whereConditions[] = 'last_checked_at <= :date_to';
    $params[':date_to'] = $dateTo . ' 23:59:59';
}

$whereSql = $whereConditions ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Count total with retry (prepare + execute together)
$totalAccounts = (int)execute_with_retry(function($pdo) use ($whereSql, $params) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM email_accounts $whereSql");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('Count query failed: ' . $e->getMessage());
        return 0;
    }
});
$totalPages = max(1, (int)ceil($totalAccounts / $perPage));
$offset = ($page - 1) * $perPage;

// Fetch accounts with retry (prepare, bind, execute together)
$emailAccounts = execute_with_retry(function($pdo) use ($whereSql, $perPage, $offset, $params) {
    try {
        $stmt = $pdo->prepare("
            SELECT id, email, from_name, is_active, imap_host, imap_port, encryption,
                   smtp_host, smtp_port, smtp_encryption, username, last_checked_at, last_seen_uid,
                   cron_enabled
            FROM email_accounts
            $whereSql
            ORDER BY id ASC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Fetch accounts failed: ' . $e->getMessage());
        return [];
    }
});

// Get stats per account with retry
$accountStats = [];
foreach ($emailAccounts as $acc) {
    $inboundCount = (int)execute_with_retry(function($pdo) use ($acc) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM email_inbox_log WHERE from_email = ?");
        $stmt->execute([$acc['email']]);
        return $stmt->fetchColumn();
    });
    
    $outboundCount = (int)execute_with_retry(function($pdo) use ($acc) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM email_outbox_log WHERE from_email = ?");
        $stmt->execute([$acc['email']]);
        return $stmt->fetchColumn();
    });
    
    $recentFailed = (int)execute_with_retry(function($pdo) use ($acc) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM email_outbox_log WHERE from_email = ? AND status = 'failed' AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $stmt->execute([$acc['email']]);
        return $stmt->fetchColumn();
    });
    
    $accountStats[$acc['id']] = [
        'inbound' => $inboundCount,
        'outbound' => $outboundCount,
        'recent_failed' => $recentFailed,
    ];
}

// Overall stats with retry
$outboxStats = [
    'pending' => (int)execute_with_retry(function($pdo) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM email_outbox_log WHERE status = 'pending'");
        return $stmt->fetchColumn();
    }),
    'sent' => (int)execute_with_retry(function($pdo) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM email_outbox_log WHERE status = 'sent'");
        return $stmt->fetchColumn();
    }),
    'failed' => (int)execute_with_retry(function($pdo) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM email_outbox_log WHERE status = 'failed'");
        return $stmt->fetchColumn();
    }),
];

$today = date('Y-m-d');
$ticketsToday = (int)execute_with_retry(function($pdo) use ($today) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE DATE(created_at) = ?");
    $stmt->execute([$today]);
    return $stmt->fetchColumn();
});

$pageTitle = 'Email System Management';
include __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
:root {
    --accent-primary: #1d4ed8;
    --accent-success: #059669;
    --accent-warning: #d97706;
    --accent-danger: #dc2626;
    --bg-light: #f8fafc;
    --border: #e2e8f0;
    --text-muted: #64748b;
    --text-main: #1e293b;
}

/* Compact stats */
.stat-card {
    background: white;
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 12px 16px;
    text-align: center;
    transition: transform 0.2s, box-shadow 0.2s;
    min-width: 140px;
}
.stat-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
.stat-value { font-size: 24px; font-weight: bold; color: var(--accent-primary); margin: 4px 0; line-height: 1; }
.stat-label { font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; line-height: 1.2; }

.panel-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-bottom: 20px; }

/* Tooltip - Working version */
.tooltip-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 18px;
    height: 18px;
    font-size: 10px;
    color: var(--text-muted);
    background: #f1f5f9;
    border: 1px solid var(--border);
    border-radius: 50%;
    cursor: help;
    margin-left: 4px;
    font-weight: bold;
    position: relative;
    vertical-align: middle;
    z-index: 1;
    flex-shrink: 0;
}
.tooltip-icon i { font-size: 10px; line-height: 1; }
.tooltip-icon:hover::after {
    content: attr(title);
    position: absolute;
    bottom: calc(100% + 8px);
    left: 50%;
    transform: translateX(-50%);
    background: #1e293b;
    color: white;
    padding: 6px 10px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: normal;
    white-space: nowrap;
    z-index: 999999;
    box-shadow: 0 6px 20px rgba(0,0,0,0.2);
    pointer-events: none;
    line-height: 1.3;
}
.tooltip-icon:hover::before {
    content: '';
    position: absolute;
    bottom: calc(100% + 8px);
    left: 50%;
    transform: translateX(-50%);
    border: 5px solid transparent;
    border-top-color: #1e293b;
    z-index: 999999;
}
.tooltip-icon:hover { z-index: 999999; position: relative; }

/* Account panels */
.account-panel {
    background: white;
    border: 1px solid var(--border);
    border-radius: 10px;
    margin-bottom: 12px;
    overflow: visible;
}
.account-summary {
    padding: 14px 16px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid transparent;
    transition: background 0.2s;
}
.account-summary:hover { background: var(--bg-light); }
.account-panel.expanded .account-summary { border-bottom: 1px solid var(--border); background: #fafbfc; }
.account-main { display: flex; align-items: center; gap: 12px; flex: 1; }
.account-icon {
    width: 40px; height: 40px; border-radius: 10px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    display: flex; align-items: center; justify-content: center;
    color: white; font-size: 16px; flex-shrink: 0;
}
.account-title { font-size: 14px; font-weight: 600; color: var(--text-main); margin-bottom: 2px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.account-meta { font-size: 12px; color: var(--text-muted); line-height: 1.5; }
.account-stats { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
.badge-stat {
    text-align: center;
    padding: 4px 8px;
    border-radius: 6px;
    background: var(--bg-light);
    min-width: 70px;
}
.badge-stat-value { font-size: 13px; font-weight: bold; color: var(--text-main); line-height: 1.2; }
.badge-stat-label { font-size: 9px; color: var(--text-muted); text-transform: uppercase; line-height: 1.2; }
.expand-icon { font-size: 12px; color: var(--text-muted); transition: transform 0.3s; }
.account-panel.expanded .expand-icon { transform: rotate(180deg); }
.account-details {
    padding: 16px;
    display: none;
    background: #fafbfc;
    border-top: 1px solid var(--border);
}
.account-panel.expanded .account-details { display: block; }
.details-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px; margin-bottom: 16px;
}
.detail-block h4 {
    font-size: 12px; text-transform: uppercase;
    color: var(--text-muted); margin: 0 0 8px 0;
    font-weight: 600; letter-spacing: 0.5px;
    display: flex; align-items: center; gap: 6px;
}
.detail-block p { margin: 3px 0; font-size: 13px; color: var(--text-main); line-height: 1.4; }
.detail-block code { background: #e2e8f0; padding: 1px 5px; border-radius: 4px; font-size: 11px; font-family: monospace; }
.action-buttons { display: flex; flex-wrap: wrap; gap: 6px; padding-top: 12px; border-top: 1px solid var(--border); }
.btn-xs { padding: 5px 10px; font-size: 11px; border-radius: 5px; }
.btn-success { background: var(--accent-success); color: white; border: none; }
.btn-warning { background: var(--accent-warning); color: white; border: none; }
.btn-danger { background: var(--accent-danger); color: white; border: none; }
.btn-secondary { background: #64748b; color: white; border: none; }
.btn-outline { background: white; color: var(--text-main); border: 1px solid var(--border); }
.btn-primary { background: var(--accent-primary); color: white; border: none; }
.btn:hover { opacity: 0.9; transform: translateY(-1px); }
.status-active { color: var(--accent-success); font-weight: 600; font-size: 12px; }
.status-inactive { color: var(--accent-danger); font-weight: 600; font-size: 12px; }
.health-ok { color: var(--accent-success); font-weight: 600; }
.health-err { color: var(--accent-danger); font-weight: 600; }

/* Compact filter bar */
.top-filter-bar {
    background: #f8fafc;
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 10px 12px;
    margin-bottom: 16px;
}
.top-filter-bar .form-row {
    display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-end;
}
.top-filter-bar input[type="text"],
.top-filter-bar select,
.top-filter-bar input[type="date"] {
    padding: 5px 8px; border: 1px solid var(--border);
    border-radius: 4px; font-size: 12px; height: 28px;
}
.top-filter-bar input[type="text"] { flex: 1; min-width: 120px; max-width: 200px; }
.top-filter-bar select { min-width: 80px; }
.top-filter-bar input[type="date"] { width: 120px; }

/* Filter chips */
.filter-chips {
    display: flex; flex-wrap: wrap; gap: 4px;
    align-items: center; font-size: 11px; margin-top: 8px;
}
.filter-chip {
    background: #e2e8f0; padding: 2px 6px; border-radius: 3px;
    display: inline-flex; align-items: center; gap: 4px;
}
.filter-chip a { color: var(--accent-danger); text-decoration: none; font-weight: bold; }

/* Results count */
.results-count {
    font-size: 11px; color: var(--text-muted);
    margin: 8px 0; display: flex; justify-content: space-between; align-items: center;
}

/* Pagination */
.pagination { display: flex; gap: 4px; flex-wrap: wrap; margin-bottom: 18px; }
.pagination a, .pagination span {
    padding: 4px 8px; font-size: 12px; border-radius: 4px;
    border: 1px solid var(--border); min-width: 32px;
    text-align: center; text-decoration: none; color: var(--text-main);
}
.pagination span.current {
    background: var(--accent-primary); color: white; border-color: var(--accent-primary);
}

/* Empty state */
.empty-state { padding: 40px; text-align: center; }
.empty-state i { font-size: 48px; color: #cbd5e1; margin-bottom: 16px; }

/* Flash */
.flash {
    position: fixed; top: 90px; right: 20px; z-index: 10000;
    max-width: 500px; padding: 12px 16px;
    border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

@media (max-width: 768px) {
    .panel-grid { grid-template-columns: repeat(2, 1fr); }
    .stat-card { padding: 10px; min-width: auto; }
    .stat-value { font-size: 20px; }
    .stat-label { font-size: 10px; }
    .account-main { flex-direction: column; align-items: flex-start; }
    .account-stats { width: 100%; justify-content: space-between; margin-top: 10px; }
    .details-grid { grid-template-columns: 1fr; }
    .top-filter-bar input[type="text"] { min-width: 100%; }
    .top-filter-bar input[type="date"], .top-filter-bar select { width: auto; }
}
</style>

<div class="page-actions">
    <div>
        <h2 class="section-title" style="margin-bottom:4px;">Email System Management</h2>
        <p class="section-subtitle">Monitor, test, and control all email accounts from one place.</p>
    </div>
    <div class="toolbar">
        <a href="<?php echo e(url('emails/logs.php')); ?>" class="btn btn-outline">View All Logs</a>
        <button type="button" class="btn btn-secondary" onclick="location.reload()"><i class="fas fa-sync"></i> Refresh</button>
    </div>
</div>

<?php if ($actionMessage): ?>
    <div class="flash flash-<?php echo e($actionMessage['type']); ?>">
        <?php echo e($actionMessage['text']); ?>
    </div>
<?php endif; ?>

<!-- Stats -->
<div class="panel-grid">
    <div class="stat-card" title="Outgoing emails waiting to be sent">
        <div class="stat-label">Outbox Pending</div>
        <div class="stat-value" style="color:#d97706;"><?php echo e($outboxStats['pending']); ?></div>
    </div>
    <div class="stat-card" title="Total emails successfully sent">
        <div class="stat-label">Sent Total</div>
        <div class="stat-value" style="color:#059669;"><?php echo e($outboxStats['sent']); ?></div>
    </div>
    <div class="stat-card" title="Emails that failed to send">
        <div class="stat-label">Failed</div>
        <div class="stat-value" style="color:#dc2626;"><?php echo e($outboxStats['failed']); ?></div>
    </div>
    <div class="stat-card" title="Tickets created today">
        <div class="stat-label">Today's Tickets</div>
        <div class="stat-value"><?php echo e($ticketsToday); ?></div>
    </div>
</div>

<!-- Global Actions -->
<div class="table-card" style="margin-bottom: 24px;">
    <div class="table-header">
        <div>
            <h2 class="section-title">Global Actions</h2>
            <p class="section-subtitle">Safe operations affecting all accounts</p>
        </div>
    </div>
    <div style="padding:18px;">
        <div style="display:flex; flex-wrap:wrap; gap:12px; align-items:center;">
            <form method="POST" onsubmit="return confirm('Force IMAP import on ALL accounts? Each account will fetch up to 10 newest emails only.')">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="run_imap_import_all">
                <button type="submit" class="btn btn-primary"><i class="fas fa-download"></i> Import All Accounts (Last 10 each)</button>
            </form>
            
            <form method="POST" onsubmit="return confirm('Retry ALL failed emails (max 10 recent)?')">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="retry_failed">
                <button type="submit" class="btn btn-warning"><i class="fas fa-redo"></i> Retry All Failed (Max 10)</button>
            </form>
            
            <div style="margin-left: auto; display:flex; gap:8px;">
                <a href="<?php echo e(url('cron/import_imap_tickets.php')); ?>" class="btn btn-outline" target="_blank" title="Run via CLI">
                    <i class="fas fa-terminal"></i> Import CLI
                </a>
                <a href="<?php echo e(url('cron/process_email_outbox.php')); ?>" class="btn btn-outline" target="_blank" title="Process outgoing queue">
                    <i class="fas fa-paper-plane"></i> Outbox CLI
                </a>
            </div>
        </div>
        <p style="font-size:12px; color:var(--text-muted); margin-top:12px;">
            <i class="fas fa-shield-alt"></i> Manual import respects safe limits (10 emails/account).
        </p>
    </div>
</div>

<!-- Compact Filter Bar (Top) -->
<div class="top-filter-bar">
    <form method="GET">
        <div class="form-row">
            <input type="text" name="search" value="<?php echo e($search); ?>" placeholder="Search email or from name...">
            
            <select name="filter">
                <option value="all" <?php echo $filterActive === 'all' ? 'selected' : ''; ?>>All</option>
                <option value="active" <?php echo $filterActive === 'active' ? 'selected' : ''; ?>>Active</option>
                <option value="inactive" <?php echo $filterActive === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
            </select>
            
            <div style="display:flex; align-items:center; gap:4px;">
                <label style="font-size:11px; color:var(--text-muted);">From:</label>
                <input type="date" name="date_from" value="<?php echo e($dateFrom); ?>">
            </div>
            
            <div style="display:flex; align-items:center; gap:4px;">
                <label style="font-size:11px; color:var(--text-muted);">To:</label>
                <input type="date" name="date_to" value="<?php echo e($dateTo); ?>">
            </div>
            
            <select name="per_page">
                <option value="10" <?php echo $perPage == 10 ? 'selected' : ''; ?>>10</option>
                <option value="25" <?php echo $perPage == 25 ? 'selected' : ''; ?>>25</option>
                <option value="50" <?php echo $perPage == 50 ? 'selected' : ''; ?>>50</option>
            </select>
            
            <button type="submit" class="btn btn-secondary btn-xs" style="padding:5px 10px; font-size:11px;">
                <i class="fas fa-check"></i> Apply
            </button>
            <a href="?" class="btn btn-outline btn-xs" style="padding:5px 10px; font-size:11px;" title="Clear all">
                <i class="fas fa-times"></i>
            </a>
        </div>
        
        <!-- Active filter chips -->
        <?php if ($search || $filterActive !== 'all' || $dateFrom || $dateTo): ?>
        <div class="filter-chips">
            <span style="color:var(--text-muted);">Active:</span>
            <?php if ($search): ?>
                <span class="filter-chip">Search: "<?php echo e($search); ?>" <a href="?<?php echo http_build_query(array_merge($_GET, ['search' => ''])); ?>" title="Remove">&times;</a></span>
            <?php endif; ?>
            <?php if ($filterActive !== 'all'): ?>
                <span class="filter-chip">Status: <?php echo e(ucfirst($filterActive)); ?> <a href="?<?php echo http_build_query(array_merge($_GET, ['filter' => 'all'])); ?>" title="Remove">&times;</a></span>
            <?php endif; ?>
            <?php if ($dateFrom): ?>
                <span class="filter-chip">From: <?php echo e($dateFrom); ?> <a href="?<?php echo http_build_query(array_merge($_GET, ['date_from' => ''])); ?>" title="Remove">&times;</a></span>
            <?php endif; ?>
            <?php if ($dateTo): ?>
                <span class="filter-chip">To: <?php echo e($dateTo); ?> <a href="?<?php echo http_build_query(array_merge($_GET, ['date_to' => ''])); ?>" title="Remove">&times;</a></span>
            <?php endif; ?>
            <a href="?" style="font-size:11px; color:var(--accent-danger); text-decoration:none;">Clear all</a>
        </div>
        <?php endif; ?>
    </form>
</div>

<!-- Results count -->
<div class="results-count">
    <div>Showing <strong><?php echo e($offset + 1); ?></strong>–<strong><?php echo e(min($offset + $perPage, $totalAccounts)); ?></strong> of <strong><?php echo e($totalAccounts); ?></strong> accounts</div>
    <div><?php if ($search || $filterActive !== 'all' || $dateFrom || $dateTo): ?>
        <a href="?" style="color:var(--accent-primary); text-decoration:none; font-size:11px;">Clear filters</a>
    <?php endif; ?></div>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div class="pagination">
    <?php if ($page > 1): ?>
        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" title="First page">&laquo;</a>
        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" title="Previous">&lsaquo;</a>
    <?php endif; ?>
    
    <?php
    $startPage = max(1, $page - 2);
    $endPage = min($totalPages, $page + 2);
    for ($i = $startPage; $i <= $endPage; $i++):
    ?>
        <?php if ($i === $page): ?>
            <span class="current"><?php echo e($i); ?></span>
        <?php else: ?>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo e($i); ?></a>
        <?php endif; ?>
    <?php endfor; ?>
    
    <?php if ($page < $totalPages): ?>
        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" title="Next">&rsaquo;</a>
        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $totalPages])); ?>" title="Last page">&raquo;</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Email Accounts List -->
<div class="table-card">
    <div class="table-header">
        <div>
            <h2 class="section-title">Email Accounts</h2>
            <p class="section-subtitle">Click any account to expand and manage. Safe: Manual import reads only last 10 messages.</p>
        </div>
    </div>
    <div style="padding:18px;">
        <?php if (empty($emailAccounts)): ?>
            <div class="empty-state">
                <i class="fas fa-envelope-slash"></i>
                <p>No email accounts found<?php echo $search ? ' matching your search' : ''; ?>.</p>
                <a href="<?php echo e(url('emails/accounts.php')); ?>" class="btn btn-primary">Create Your First Account</a>
            </div>
        <?php else: ?>
            <?php foreach ($emailAccounts as $acc): 
                $smtpEnc = $acc['smtp_encryption'] ?? $acc['encryption'];
                $imapEnc = $acc['encryption'];
                $stats = $accountStats[$acc['id']] ?? ['inbound'=>0, 'outbound'=>0, 'recent_failed'=>0];
                $isExpanded = isset($_GET['expand']) && (int)$_GET['expand'] === (int)$acc['id'];
            ?>
                <div class="account-panel <?php echo $isExpanded ? 'expanded' : ''; ?>" id="account-<?php echo e($acc['id']); ?>">
                    <div class="account-summary" onclick="togglePanel(<?php echo e($acc['id']); ?>)">
                        <div class="account-main">
                            <div class="account-icon"><i class="fas fa-envelope"></i></div>
                            <div class="account-info">
                                <div class="account-title">
                                    <?php echo e($acc['email']); ?>
                                    <?php if ($acc['from_name']): ?>
                                        <span style="font-weight:400; color:var(--text-muted); font-size:14px;">(<?php echo e($acc['from_name']); ?>)</span>
                                    <?php endif; ?>
                                    <span class="status-badge <?php echo $acc['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                        <?php echo $acc['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </div>
                                <div class="account-meta">
                                    <i class="fas fa-inbox"></i> Inbound: <strong><?php echo e($stats['inbound']); ?></strong> &nbsp;
                                    <i class="fas fa-paper-plane"></i> Outbound: <strong><?php echo e($stats['outbound']); ?></strong> &nbsp;
                                    <?php if ($stats['recent_failed'] > 0): ?>
                                        <span style="color:var(--accent-danger);"><i class="fas fa-exclamation-triangle"></i> <?php echo e($stats['recent_failed']); ?> failed (7d)</span>
                                    <?php endif; ?>
                                    <span style="margin-left:8px; font-size:11px; <?php echo $acc['cron_enabled'] ? 'color:var(--accent-success);' : 'color:var(--text-muted);'; ?>">
                                        <i class="fas fa-<?php echo $acc['cron_enabled'] ? 'check-circle' : 'pause-circle'; ?>"></i>
                                        Cron: <?php echo $acc['cron_enabled'] ? 'On' : 'Off'; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="account-stats">
                            <div class="badge-stat" title="Last time this mailbox was polled">
                                <div class="badge-stat-value" style="font-size:14px;"><?php echo $acc['last_checked_at'] ? e(format_date($acc['last_checked_at'])) : 'Never'; ?></div>
                                <div class="badge-stat-label">Last Checked</div>
                            </div>
                            <div class="badge-stat" title="Highest IMAP UID processed">
                                <div class="badge-stat-value" style="font-size:14px;"><?php echo e($acc['last_seen_uid'] ?: 'N/A'); ?></div>
                                <div class="badge-stat-label">Last UID</div>
                            </div>
                            <i class="fas fa-chevron-down expand-icon"></i>
                        </div>
                    </div>
                    
                    <div class="account-details">
                        <div class="details-grid">
                            <div class="detail-block">
                                <h4><i class="fas fa-cloud"></i> IMAP Configuration
                                    <span class="tooltip-icon" title="Incoming mail server settings. Cron job uses these to fetch emails."><i class="fas fa-question-circle"></i></span>
                                </h4>
                                <?php if ($acc['imap_host']): ?>
                                    <p><strong>Server:</strong> <?php echo e($acc['imap_host']); ?>:<?php echo e($acc['imap_port']); ?></p>
                                    <p><strong>Encryption:</strong> <code><?php echo e($imapEnc); ?></code></p>
                                    <p><strong>Username:</strong> <?php echo e($acc['username'] ?? $acc['email']); ?></p>
                                    <p><strong>Status:</strong> <span class="<?php echo $acc['is_active'] ? 'health-ok' : 'health-err'; ?>"><?php echo $acc['is_active'] ? '● Active' : '● Inactive'; ?></span></p>
                                <?php else: ?>
                                    <p class="text-muted">IMAP not configured.</p>
                                <?php endif; ?>
                            </div>
                            
                            <div class="detail-block">
                                <h4><i class="fas fa-paper-plane"></i> SMTP Configuration
                                    <span class="tooltip-icon" title="Outgoing mail server settings. Used to send all outgoing emails."><i class="fas fa-question-circle"></i></span>
                                </h4>
                                <p><strong>Server:</strong> <?php echo e($acc['smtp_host']); ?>:<?php echo e($acc['smtp_port']); ?></p>
                                <p><strong>Encryption:</strong> <code><?php echo e($smtpEnc); ?></code></p>
                                <p><strong>From Name:</strong> <?php echo e($acc['from_name'] ?: 'Not set'); ?></p>
                                <p><strong>From Email:</strong> <?php echo e($acc['email']); ?></p>
                            </div>
                            
                            <div class="detail-block">
                                <h4><i class="fas fa-chart-line"></i> Activity Stats
                                    <span class="tooltip-icon" title="Email activity tracked for this account"><i class="fas fa-question-circle"></i></span>
                                </h4>
                                <p><strong>Total Inbound:</strong> <?php echo e($stats['inbound']); ?> emails</p>
                                <p><strong>Total Outbound:</strong> <?php echo e($stats['outbound']); ?> emails</p>
                                <p><strong>Failed (7 days):</strong> <?php echo e($stats['recent_failed']); ?></p>
                            </div>
                            
                            <div class="detail-block">
                                <h4><i class="fas fa-history"></i> Import Status
                                    <span class="tooltip-icon" title="IMAP import progress tracking. UID ensures no duplicates."><i class="fas fa-question-circle"></i></span>
                                </h4>
                                <p><strong>Baseline Set:</strong> <?php echo $acc['import_cutoff_at'] ? e(format_date($acc['import_cutoff_at'])) : 'Not yet'; ?></p>
                                <p><strong>Last Poll:</strong> <?php echo $acc['last_checked_at'] ? e(format_date($acc['last_checked_at'])) : 'Never'; ?></p>
                                <p><strong>Processed UID:</strong> <code><?php echo e($acc['last_seen_uid'] ?: '0 (will baseline)'); ?></code></p>
                            </div>
                            
                            <div class="detail-block">
                                <h4><i class="fas fa-cogs"></i> Automation
                                    <span class="tooltip-icon" title="Enable/disable automatic IMAP imports via cron job for this account."><i class="fas fa-question-circle"></i></span>
                                </h4>
                                <form method="POST" style="display:flex; align-items:center; gap:8px;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="toggle_cron">
                                    <input type="hidden" name="account_id" value="<?php echo e($acc['id']); ?>">
                                    <label style="display:flex; align-items:center; gap:6px; font-size:13px; cursor:pointer;">
                                        <input type="checkbox" name="cron_enabled" value="1" <?php echo $acc['cron_enabled'] ? 'checked' : ''; ?> onchange="if(confirm('Update cron import setting for this account?')){ this.form.submit(); }">
                                        <span>Include in automatic imports</span>
                                    </label>
                                </form>
                                <p style="font-size:11px; color:var(--text-muted); margin-top:6px; margin-bottom:0;">
                                    <?php echo $acc['cron_enabled'] ? '✓ This account will be polled by cron jobs.' : '✗ Cron imports skipped for this account.'; ?>
                                </p>
                            </div>
                        </div>
                        
                        <div class="action-buttons">
                            <?php if ($canTestConnections): ?>
                            <form method="POST" style="display:inline;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="test_smtp">
                                <input type="hidden" name="account_id" value="<?php echo e($acc['id']); ?>">
                                <button type="submit" class="btn btn-success btn-xs" title="Test SMTP connection">
                                    <i class="fas fa-plug"></i> Test SMTP
                                </button>
                            </form>
                            
                            <?php if ($acc['imap_host']): ?>
                            <form method="POST" style="display:inline;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="test_imap">
                                <input type="hidden" name="account_id" value="<?php echo e($acc['id']); ?>">
                                <button type="submit" class="btn btn-success btn-xs" title="Test IMAP connection">
                                    <i class="fas fa-server"></i> Test IMAP
                                </button>
                            </form>
                            <?php endif; ?>
                            <?php else: ?>
                            <span style="font-size:11px; color:var(--text-muted);" title="Connection testing disabled on this host">
                                <i class="fas fa-ban"></i> Tests disabled
                            </span>
                            <?php endif; ?>
                            
                            <?php if ($acc['imap_host'] && $acc['is_active']): ?>
                            <form method="POST" style="display:inline;" 
                                  onsubmit="return confirm('Import last 10 emails from this account only?\\n\\nNew emails will be processed and linked to tickets if possible.\\nThis is safe — only recent messages are read.');">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="run_imap_import_single">
                                <input type="hidden" name="account_id" value="<?php echo e($acc['id']); ?>">
                                <button type="submit" class="btn btn-primary btn-xs" title="Import up to 10 newest emails">
                                    <i class="fas fa-download"></i> Import Last 10
                                </button>
                            </form>
                            <?php endif; ?>
                            
                            <a href="<?php echo e(url('emails/accounts.php?id=' . $acc['id'])); ?>" class="btn btn-outline btn-xs" title="Edit account settings">
                                <i class="fas fa-edit"></i> Edit Account
                            </a>
                            
                            <a href="<?php echo e(url('emails/logs.php?from_email=' . urlencode($acc['email']))); ?>" class="btn btn-outline btn-xs" title="View all emails sent/received by this account">
                                <i class="fas fa-search"></i> View Logs
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Help Section -->
<div class="table-card">
    <div class="table-header">
        <div>
            <h2 class="section-title">Understanding This System</h2>
            <p class="section-subtitle">How email-to-ticket conversion works, safety measures, and configuration.</p>
        </div>
    </div>
    <div style="padding:18px;">
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap:16px;">
            <div style="padding:12px; background:#f8fafc; border-radius:8px;">
                <h4 style="margin-top:0;"><i class="fas fa-shield-alt" style="color:var(--accent-success);"></i> Safe Manual Import</h4>
                <p style="font-size:13px; color:var(--text-muted); margin:0;">
                    <strong>Import Last 10</strong> per account reads only the 10 newest emails. Prevents flood creating unwanted tickets.
                </p>
            </div>
            <div style="padding:12px; background:#f8fafc; border-radius:8px;">
                <h4 style="margin-top:0;"><i class="fas fa-search" style="color:var(--accent-primary);"></i> Filter & Search</h4>
                <p style="font-size:13px; color:var(--text-muted); margin:0;">
                    Search by email/name, filter by Active/Inactive, or date range. All filters combine and update on form submit.
                </p>
            </div>
            <div style="padding:12px; background:#f8fafc; border-radius:8px;">
                <h4 style="margin-top:0;"><i class="fas fa-link" style="color:var(--accent-primary);"></i> Vendor Ticket ID Detection</h4>
                <p style="font-size:13px; color:var(--text-muted); margin:0;">
                    Auto-detects any of these formats: <code>TT-123456</code>, <code>TCK-987654</code>, <code>TKT-ABC123</code>, <code>LM-20260429-01</code>, bracketed variants, and numeric IDs (6+ digits with keywords). All normalized to uppercase.
                </p>
            </div>
            <div style="padding:12px; background:#f8fafc; border-radius:8px;">
                <h4 style="margin-top:0;"><i class="fas fa-paper-plane" style="color:var(--accent-warning);"></i> Auto-Emails Include Description</h4>
                <p style="font-size:13px; color:var(--text-muted); margin:0;">
                    Automatic emails on Created, In-Progress, and Closed status changes include the current ticket description for context.
                </p>
            </div>
            <div style="padding:12px; background:#f8fafc; border-radius:8px;">
                <h4 style="margin-top:0;"><i class="fas fa-history" style="color:var(--text-muted);"></i> UID Tracking — Zero Duplicates</h4>
                <p style="font-size:13px; color:var(--text-muted); margin:0;">
                    IMAP UID (Unique ID) per message is stored as last_seen_uid. Only UIDs greater than this are processed. Never decreases — prevents duplicates forever.
                </p>
            </div>
            <div style="padding:12px; background:#f8fafc; border-radius:8px;">
                <h4 style="margin-top:0;"><i class="fas fa-ban" style="color:var(--accent-danger);"></i> Guardrails — No Unwanted Tickets</h4>
                <p style="font-size:13px; color:var(--text-muted); margin:0;">
                    Multi-layer check: External ID found? → ✅ Allow. Otherwise: issue ≥10 chars, subject not blacklisted, body ≥20 chars, domain whitelist (if set). Fails → ignored with reason logged.
                </p>
            </div>
        </div>
        
        <div style="margin-top:16px; padding:12px; background:#e0f2fe; border-radius:8px; border-left:4px solid #0284c7;">
            <h4 style="margin-top:0; color:#0284c7;"><i class="fas fa-book"></i> Full Documentation</h4>
            <p style="font-size:13px; color:var(--text-muted); margin:0;">
                Comprehensive guides: 
                <a href="<?php echo e(url('docs/PARSER_CONFIGURATION_GUIDE.md')); ?>" target="_blank" rel="noopener"><strong>Parser Configuration</strong></a> — vendor formats, customization;
                <a href="<?php echo e(url('docs/ADMIN_QUICK_REFERENCE.md')); ?>" target="_blank" rel="noopener"><strong>Admin Quick Reference</strong></a> — daily ops;
                <a href="<?php echo e(url('docs/SYSTEM_OVERVIEW.md')); ?>" target="_blank" rel="noopener"><strong>System Overview</strong></a> — architecture.
            </p>
        </div>
    </div>
</div>

<script>
function togglePanel(id) {
    const panel = document.getElementById('account-' + id);
    if (!panel) return;
    const wasExpanded = panel.classList.contains('expanded');
    document.querySelectorAll('.account-panel').forEach(p => p.classList.remove('expanded'));
    if (!wasExpanded) panel.classList.add('expanded');
}

<?php if (isset($_GET['expand'])): ?>
document.addEventListener('DOMContentLoaded', function() {
    const panel = document.getElementById('account-<?php echo e((int)$_GET['expand']); ?>');
    if (panel) panel.classList.add('expanded');
});
<?php endif; ?>

setTimeout(() => {
    const flash = document.querySelector('.flash');
    if (flash) {
        flash.style.transition = 'opacity 0.5s';
        flash.style.opacity = '0';
        setTimeout(() => flash.remove(), 500);
    }
}, 5000);
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
