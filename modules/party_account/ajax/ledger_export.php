<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/bootstrap.php';
require_once dirname(__DIR__) . '/middleware/require_party_account_access.php';
require_once dirname(__DIR__, 3) . '/system_logs/log_helper.php';

$currentUser = require_login($pdo);
party_account_middleware_gate_ledger($currentUser);
party_account_ensure_schema($pdo);

$partyId = (int) ($_GET['party_id'] ?? 0);
$currency = (string) ($_GET['currency'] ?? '');
$from = (string) ($_GET['from'] ?? '');
$to = (string) ($_GET['to'] ?? '');
$format = strtolower((string) ($_GET['format'] ?? 'excel'));

$service = new PartyLedgerService($pdo, new PartyAccountActivityLogService($pdo));
$ledger = $service->ledger($partyId, $currency !== '' ? $currency : null, $from ?: null, $to ?: null);
$party = $ledger['party'];
$summary = $ledger['summary'];
$ledgerCurrency = (string) ($ledger['currency'] ?? $currency ?: $party['currency']);
$filenameBase = 'party-ledger-' . $partyId . '-' . $ledgerCurrency . '-' . date('Ymd-His');
$totalRecords = count($ledger['rows'] ?? []);

if ($format === 'pdf') {
    try {
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: inline; filename="' . $filenameBase . '.html"');
        
        echo '<!doctype html>';
        echo '<html lang="en">';
        echo '<head>';
        echo '<meta charset="utf-8">';
        echo '<title>Party Ledger Statement</title>';
        echo '<style>
            :root {
                --primary: #0d6efd;
                --primary-dark: #0a58ca;
                --text-main: #0f172a;
                --text-muted: #475569;
                --border-color: #cbd5e1;
                --bg-card: #ffffff;
                --bg-light: #f8fafc;
                --table-header: #f1f5f9;
            }
            body {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
                font-size: 13px;
                color: var(--text-main);
                margin: 0;
                padding: 24px;
                background: #ffffff;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .header-container {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin-bottom: 12px;
            }
            .branding {
                display: flex;
                align-items: center;
                gap: 14px;
            }
            .logo {
                width: auto;
                height: 44px;
                object-fit: contain;
            }
            .logo-fallback {
                width: 44px;
                height: 44px;
                background: var(--primary);
                border-radius: 6px;
                display: none;
                align-items: center;
                justify-content: center;
                font-weight: bold;
                color: #fff;
                font-size: 18px;
            }
            .company-brand {
                font-size: 24px;
                font-weight: 700;
                color: var(--primary);
                letter-spacing: -0.5px;
            }
            .report-title-block {
                text-align: right;
            }
            .report-title {
                font-size: 20px;
                font-weight: 700;
                color: var(--text-main);
                letter-spacing: -0.3px;
            }
            .divider {
                border: 0;
                border-top: 2px solid var(--primary);
                width: 100%;
                margin: 0 0 24px 0;
            }
            .info-card {
                border: 1px solid var(--border-color);
                border-radius: 8px;
                padding: 20px;
                margin-bottom: 24px;
                display: flex;
                justify-content: space-between;
                background: var(--bg-card);
            }
            .info-left {
                display: flex;
                flex-direction: column;
                gap: 16px;
                flex: 1;
            }
            .info-right {
                display: flex;
                flex-direction: column;
                justify-content: space-between;
                align-items: flex-end;
                text-align: right;
                padding-left: 32px;
                min-width: 280px;
            }
            .info-item-row {
                display: flex;
                align-items: flex-start;
                gap: 12px;
            }
            .icon-wrapper {
                width: 32px;
                height: 32px;
                background: var(--bg-light);
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                color: var(--primary);
                flex-shrink: 0;
                border: 1px solid #e2e8f0;
            }
            .icon-wrapper svg {
                width: 16px;
                height: 16px;
                fill: currentColor;
            }
            .meta-details {
                display: flex;
                flex-direction: column;
            }
            .party-name {
                font-size: 18px;
                font-weight: 600;
                color: var(--text-main);
                margin-bottom: 1px;
            }
            .party-email {
                font-size: 13px;
                color: var(--text-muted);
            }
            .bank-details {
                font-size: 13px;
                color: var(--text-muted);
                display: flex;
                gap: 16px;
                flex-wrap: wrap;
                margin-top: 2px;
            }
            .bank-details span strong {
                color: var(--text-main);
            }
            .balance-box-row {
                display: flex;
                gap: 32px;
                margin-top: 12px;
            }
            .balance-label {
                font-size: 11px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                color: var(--text-muted);
                margin-bottom: 4px;
                font-weight: 600;
            }
            .balance-value {
                font-size: 18px;
                font-weight: 700;
                color: var(--text-main);
            }
            table {
                width: 100%;
                border-collapse: separate;
                border-spacing: 0;
                margin-top: 8px;
                border: 1px solid var(--border-color);
                border-radius: 6px;
                overflow: hidden;
            }
            th, td {
                padding: 10px 12px;
                text-align: left;
                font-size: 12px;
                border-bottom: 1px solid var(--border-color);
                border-right: 1px solid var(--border-color);
            }
            th:last-child, td:last-child {
                border-right: none;
            }
            tr:last-child td {
                border-bottom: none;
            }
            th {
                background: var(--table-header);
                font-weight: 600;
                color: var(--text-main);
            }
            .right {
                text-align: right;
            }
            .balance-bold {
                font-weight: 700;
                color: var(--text-main);
            }
            tr:nth-child(even) {
                background: var(--bg-light);
            }
            tr:hover {
                background: #f1f5f9;
            }
            .print-btn {
                position: absolute;
                top: 20px;
                right: 24px;
                padding: 10px 20px;
                background: var(--primary);
                color: #fff;
                border: none;
                border-radius: 6px;
                font-weight: 600;
                cursor: pointer;
                box-shadow: 0 2px 4px rgba(13, 110, 253, 0.2);
                transition: background 0.15s ease;
            }
            .print-btn:hover {
                background: var(--primary-dark);
            }
            @media print {
                .print-btn { display: none; }
                body { padding: 0; }
            }
        </style>';
        echo '</head><body>';
        
        echo '<button class="print-btn" onclick="window.print()">Print / Save PDF</button>';
        
        echo '<div class="header-container">';
        echo '<div class="branding">';
        echo '  <img id="main-logo" src="https://loopmobility.com.au/wp-content/uploads/2025/08/loop-logo-1.png" class="logo" onerror="this.style.display=\'none\'; document.getElementById(\'fallback-logo\').style.display=\'flex\';">';
        echo '  <div id="fallback-logo" class="logo-fallback">L</div>';
        echo '  <div class="company-brand">LOOP MOBILITY PTY LTD</div>';
        echo '</div>';
        echo '<div class="report-title-block">';
        echo '  <div class="report-title">Party Ledger Statement</div>';
        echo '</div></div>';
        
        echo '<div class="divider"></div>';
        
        echo '<div class="info-card">';
        
        echo '<div class="info-left">';
        echo '  <div class="info-item-row">';
        echo '      <div class="icon-wrapper"><svg viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5-4-8-4z"/></svg></div>';
        echo '      <div class="meta-details">';
        echo '          <div class="party-name">' . e((string) $party['party_name']) . '</div>';
        echo '          <div class="party-email">' . e((string) ($party['party_email'] ?? '-')) . '</div>';
        echo '      </div>';
        echo '  </div>';
        
        echo '  <div class="info-item-row" style="margin-top: 2px;">';
        echo '      <div class="icon-wrapper"><svg viewBox="0 0 24 24"><path d="M4 10h3v7H4zm6.5 0h3v7h-3zM2 19h20v3H2zm15-9h3v7h-3zM12 2L2 7v2h20V7z"/></svg></div>';
        echo '      <div class="meta-details">';
        echo '          <div class="bank-details">';
        echo '              <span>Bank: <strong>' . e((string) ($party['bank_name'] ?? '-')) . '</strong></span>';
        echo '              <span>A/C: <strong>' . e((string) ($party['account_number'] ?? '-')) . '</strong></span>';
        echo '              <span>IFSC/SWIFT: <strong>' . e((string) ($party['ifsc_swift_code'] ?? '-')) . '</strong></span>';
        if (!empty($party['bank_branch_address'])) {
            echo '              <span>Branch: <strong>' . e((string) ($party['bank_branch_address'] ?? '-')) . '</strong></span>';
        }
        echo '          </div>';
        echo '          <div class="bank-details" style="margin-top: 4px;">';
        echo '              <span>Loop Entity: <strong>' . e((string) ($party['loop_entity_name'] ?? 'N/A')) . '</strong></span>';
        echo '          </div>';
        echo '      </div>';
        echo '  </div>';
        echo '</div>';
        
        echo '<div class="info-right">';
        echo '  <div class="info-item-row" style="text-align: right; justify-content: flex-end; width: 100%;">';
        echo '      <div class="meta-details">';
        echo '          <div style="font-size: 13px; color: var(--text-muted);">Currency: <strong style="color:var(--text-main); font-size:14px;">' . e($ledgerCurrency) . '</strong></div>';
        echo '      </div>';
        echo '  </div>';
        echo '  <div class="balance-box-row">';
        echo '      <div style="border-left: 1px solid var(--border-color); padding-left: 32px;"><div class="balance-label">Closing Balance</div><div class="balance-value" style="color: var(--primary);">' . e(number_format((float) ($summary['closing_balance'] ?? 0), 2)) . '</div></div>';
        echo '  </div>';
        echo '</div>';
        
        echo '</div>';
        
        echo '<table>';
        echo '<thead><tr>';
        echo '<th>Invoice Period</th><th>Customer Invoice No</th><th class="right">Customer Invoice Value</th>';
        echo '<th>Vendor Invoice No</th><th class="right">Vendor Invoice Value</th>';
        echo '<th class="right">Payment In</th><th>Payment In Date</th><th class="right">Payment Out</th><th>Payment Out Date</th><th class="right balance-bold">Running Balance</th>';
        echo '</tr></thead><tbody>';
        
        foreach ($ledger['rows'] as $row) {
            $custInvoiceVal = isset($row['customer_invoice_value']) && $row['customer_invoice_value'] !== '' ? e(number_format((float)$row['customer_invoice_value'], 2)) : '';
            $vendorInvoiceVal = isset($row['vendor_invoice_value']) && $row['vendor_invoice_value'] !== '' ? e(number_format((float)$row['vendor_invoice_value'], 2)) : '';
            $payIn = isset($row['payment_in']) && $row['payment_in'] !== '' ? e(number_format((float)$row['payment_in'], 2)) : '';
            $payOut = isset($row['payment_out']) && $row['payment_out'] !== '' ? e(number_format((float)$row['payment_out'], 2)) : '';
            
            echo '<tr>';
            echo '<td>' . e((string) $row['invoice_period']) . '</td>';
            echo '<td>' . e((string) ($row['customer_invoice_no'] ?? '')) . '</td>';
            echo '<td class="right">' . $custInvoiceVal . '</td>';
            echo '<td>' . e((string) ($row['vendor_invoice_no'] ?? '')) . '</td>';
            echo '<td class="right">' . $vendorInvoiceVal . '</td>';
            echo '<td class="right">' . $payIn . '</td>';
            echo '<td>' . e((string) ($row['payment_in_date'] ?? '-')) . '</td>';
            echo '<td class="right">' . $payOut . '</td>';
            echo '<td>' . e((string) ($row['payment_out_date'] ?? '-')) . '</td>';
            echo '<td class="right balance-bold">' . e(number_format((float) ($row['running_balance'] ?? 0), 2)) . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
        echo '<script>window.addEventListener("load",function(){setTimeout(function(){window.print()},300)})</script></body></html>';

        $userId = isset($currentUser['user_id']) && $currentUser['user_id'] !== '' ? (int) $currentUser['user_id'] : null;
        $userName = $currentUser['name'] ?? $currentUser['user_id'] ?? null;
        $filterSnapshot = [
            'party_name' => (string) ($party['party_name'] ?? ('Party ' . $partyId)),
            'currency' => $currency,
            'from' => $from,
            'to' => $to,
        ];
        [$browser, $device, $os] = system_logs_parse_user_agent();

        log_export_activity(
            $pdo,
            $userId,
            $userName,
            'Party Ledger',
            'Party Ledger',
            'EXPORT',
            'PDF',
            $totalRecords,
            $filterSnapshot,
            'SUCCESS',
            null,
            $browser,
            $device,
            $os
        );

        exit;
    } catch (Throwable $e) {
        $userId = isset($currentUser['user_id']) && $currentUser['user_id'] !== '' ? (int) $currentUser['user_id'] : null;
        $userName = $currentUser['name'] ?? $currentUser['user_id'] ?? null;
        [$browser, $device, $os] = system_logs_parse_user_agent();

        log_export_activity(
            $pdo,
            $userId,
            $userName,
            'Party Ledger',
            'Party Ledger',
            'EXPORT',
            'PDF',
            0,
            null,
            'FAILED',
            $e->getMessage(),
            $browser,
            $device,
            $os
        );

        http_response_code(500);
        echo 'Export failed.';
        exit;
    }
    exit;
}

// Fallback to CSV handling
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filenameBase . '.csv"');
$out = fopen('php://output', 'wb');
fputcsv($out, ['Party', $party['party_name']]);
fputcsv($out, ['Currency', $ledgerCurrency]);
fputcsv($out, ['Opening Balance', number_format((float) ($summary['opening_balance'] ?? 0), 2)]);
fputcsv($out, ['Closing Balance', number_format((float) ($summary['closing_balance'] ?? 0), 2)]);
fputcsv($out, ['Bank', $party['bank_name'] ?? '-']);
fputcsv($out, ['Account Number', $party['account_number'] ?? '-']);
fputcsv($out, ['IFSC/SWIFT', $party['ifsc_swift_code'] ?? '-']);
if (!empty($party['bank_branch_address'])) {
    fputcsv($out, ['Bank Branch Address', $party['bank_branch_address']]);
}
fputcsv($out, ['Loop Entity', (string) ($party['loop_entity_name'] ?? 'N/A')]);
fputcsv($out, []);
fputcsv($out, ['Invoice Period', 'Customer Invoice No', 'Customer Invoice Value', 'Vendor Invoice No', 'Vendor Invoice Value', 'Payment In', 'Payment In Date', 'Payment Out', 'Payment Out Date', 'Running Balance']);
foreach ($ledger['rows'] as $row) {
    fputcsv($out, [
        $row['invoice_period'],
        $row['customer_invoice_no'],
        number_format((float) ($row['customer_invoice_value'] ?? 0), 2),
        $row['vendor_invoice_no'],
        number_format((float) ($row['vendor_invoice_value'] ?? 0), 2),
        number_format((float) ($row['payment_in'] ?? 0), 2),
        $row['payment_in_date'] ?? '',
        number_format((float) ($row['payment_out'] ?? 0), 2),
        $row['payment_out_date'] ?? '',
        number_format((float) ($row['running_balance'] ?? 0), 2),
    ]);
}
fclose($out);

$userId = isset($currentUser['user_id']) && $currentUser['user_id'] !== '' ? (int) $currentUser['user_id'] : null;
$userName = $currentUser['name'] ?? $currentUser['user_id'] ?? null;
$filterSnapshot = [
    'party_name' => (string) ($party['party_name'] ?? ('Party ' . $partyId)),
    'currency' => $currency,
    'from' => $from,
    'to' => $to,
];
[$browser, $device, $os] = system_logs_parse_user_agent();

log_export_activity(
    $pdo,
    $userId,
    $userName,
    'Party Ledger',
    'Party Ledger',
    'EXPORT',
    'CSV',
    $totalRecords,
    $filterSnapshot,
    'SUCCESS',
    null,
    $browser,
    $device,
    $os
);

exit;
