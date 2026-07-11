<?php

declare(strict_types=1);

/**
 * Party Transaction export helpers.
 *
 * Builds a compact XLSX workbook with one sheet per party using a minimal
 * ZIP writer so we do not need an external spreadsheet dependency.
 */

/**
 * @param list<int> $ids
 * @return list<int>
 */
function party_transactions_normalize_party_ids(array $ids): array
{
    $out = [];
    foreach ($ids as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $out[$id] = $id;
        }
    }

    return array_values($out);
}

/**
 * @param mixed $raw
 * @return list<int>
 */
function party_transactions_extract_party_ids($raw): array
{
    if (is_array($raw)) {
        return party_transactions_normalize_party_ids($raw);
    }

    $id = (int) $raw;

    return $id > 0 ? [$id] : [];
}

/**
 * @param array<string,mixed> $row
 * @return array<int, list<array<string,mixed>>>
 */
function party_transactions_group_rows_by_party(array $rows): array
{
    $groups = [];
    foreach ($rows as $row) {
        $partyId = (int) ($row['party_account_id'] ?? 0);
        if ($partyId <= 0) {
            continue;
        }
        if (!isset($groups[$partyId])) {
            $groups[$partyId] = [];
        }
        $groups[$partyId][] = $row;
    }

    return $groups;
}

function party_transactions_format_amount(?float $amount, string $currency = ''): string
{
    if ($amount === null) {
        return '-';
    }

    $formatted = number_format($amount, 2);
    $currency = trim($currency);
    if ($currency === '' || strcasecmp($currency, 'multiple') === 0) {
        return $formatted;
    }

    return $currency . ' ' . $formatted;
}

function party_transactions_signed_amount(?float $amount, ?string $type): float
{
    $value = round((float) ($amount ?? 0), 2);

    return $type === 'payable' ? -$value : $value;
}

/**
 * @param list<array<string,mixed>> $rows
 * @return array{total_transactions:int,total_credit:float,total_debit:float,closing_balance:float,opening_balance:float,current_balance:float}
 */
function party_transactions_summary_from_rows(array $rows, ?float $openingBalance = 0.0): array
{
    $credit = 0.0;
    $debit = 0.0;
    $movement = 0.0;
    foreach ($rows as $row) {
        $customer = (float) ($row['customer_invoice_value'] ?? 0);
        $vendor = (float) ($row['vendor_invoice_value'] ?? 0);
        $paymentIn = (float) ($row['payment_in'] ?? 0);
        $paymentOut = (float) ($row['payment_out'] ?? 0);
        $credit += $customer + $paymentOut;
        $debit += $vendor + $paymentIn;
        $movement += $customer - $vendor - $paymentIn + $paymentOut;
    }

    $opening = round((float) $openingBalance, 2);
    $closing = round($opening + $movement, 2);

    return [
        'total_transactions' => count($rows),
        'total_credit' => round($credit, 2),
        'total_debit' => round($debit, 2),
        'opening_balance' => $opening,
        'closing_balance' => $closing,
        'current_balance' => $closing,
    ];
}

/**
 * @param array<int, list<array<string,mixed>>> $groupedRows
 * @param array<string,mixed> $party
 * @return array<string,mixed>
 */
function party_transactions_build_sheet_payload(array $groupedRows, array $party): array
{
    $partyId = (int) ($party['id'] ?? 0);
    $rows = $groupedRows[$partyId] ?? [];
    $currencyValues = [];
    foreach ($rows as $row) {
        $cur = trim((string) ($row['currency'] ?? ''));
        if ($cur !== '') {
            $currencyValues[$cur] = true;
        }
    }
    $currencyCount = count($currencyValues);
    $sheetCurrency = trim((string) ($party['currency'] ?? ''));
    if ($currencyCount === 1) {
        $sheetCurrency = (string) array_key_first($currencyValues);
    } elseif ($currencyCount > 1) {
        $sheetCurrency = 'Multiple';
    } elseif ($sheetCurrency === '') {
        $sheetCurrency = '-';
    }

    $opening = null;
    if (($party['opening_balance'] ?? null) !== null && $sheetCurrency !== 'Multiple') {
        $opening = party_transactions_signed_amount(
            isset($party['opening_balance']) ? (float) $party['opening_balance'] : 0.0,
            isset($party['opening_balance_type']) ? (string) $party['opening_balance_type'] : null
        );
    } elseif (($party['opening_balance'] ?? null) !== null) {
        $opening = party_transactions_signed_amount(
            isset($party['opening_balance']) ? (float) $party['opening_balance'] : 0.0,
            isset($party['opening_balance_type']) ? (string) $party['opening_balance_type'] : null
        );
    }

    $summary = party_transactions_summary_from_rows($rows, $opening ?? 0.0);

    return [
        'sheet_name' => (string) ($party['party_name'] ?? 'Party'),
        'party' => $party,
        'rows' => $rows,
        'summary' => $summary,
        'currency_label' => $sheetCurrency,
        'has_opening_balance' => ($party['opening_balance'] ?? null) !== null && ($party['opening_balance'] ?? '') !== '',
    ];
}

/**
 * @param list<array<string,mixed>> $sheetPayloads
 */
function party_transactions_export_xlsx(array $sheetPayloads, string $filenameBase): void
{
    if ($sheetPayloads === []) {
        throw new RuntimeException('No party sheets available for export.');
    }

    $tmp = tempnam(sys_get_temp_dir(), 'ptx_xlsx_');
    if ($tmp === false) {
        throw new RuntimeException('Unable to initialize workbook export.');
    }

    try {
        party_transactions_write_xlsx_file($tmp, $sheetPayloads);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filenameBase . '.xlsx"');
        header('Content-Length: ' . (string) filesize($tmp));
        readfile($tmp);
    } finally {
        @unlink($tmp);
    }

    exit;
}

/**
 * @param list<array<string,mixed>> $sheetPayloads
 */
function party_transactions_write_xlsx_file(string $path, array $sheetPayloads): void
{
    $files = party_transactions_build_xlsx_files($sheetPayloads);
    party_transactions_write_zip_archive($path, $files);
}

/**
 * @param list<array<string,mixed>> $sheetPayloads
 * @return array<string,string>
 */
function party_transactions_build_xlsx_files(array $sheetPayloads): array
{
    $sheetPayloads = array_values($sheetPayloads);
    $sheetNames = [];
    $sheetXmlFiles = [];
    $sheetInfo = [];

    foreach ($sheetPayloads as $index => $payload) {
        $sheetName = party_transactions_unique_sheet_name((string) ($payload['sheet_name'] ?? ('Party ' . ($index + 1))), $sheetNames);
        $sheetInfo[] = [
            'sheetId' => $index + 1,
            'sheet_name' => $sheetName,
            'payload' => $payload,
        ];
    }

    $workbookRels = [];
    $contentTypes = [];
    $sheetNamesForApp = [];

    $contentTypes[] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $contentTypes[] = '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
    $contentTypes[] = '  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
    $contentTypes[] = '  <Default Extension="xml" ContentType="application/xml"/>';
    $contentTypes[] = '  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
    $contentTypes[] = '  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    $contentTypes[] = '  <Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>';
    $contentTypes[] = '  <Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';

    $sheetFileNames = [];
    foreach ($sheetInfo as $sheetIndex => $info) {
        $sheetFileName = 'xl/worksheets/sheet' . ($sheetIndex + 1) . '.xml';
        $sheetFileNames[] = $sheetFileName;
        $contentTypes[] = '  <Override PartName="/' . $sheetFileName . '" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $workbookRels[] = '  <Relationship Id="rId' . ($sheetIndex + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($sheetIndex + 1) . '.xml"/>';
        $sheetNamesForApp[] = $info['sheet_name'];
    }
    $stylesRelId = count($sheetInfo) + 1;
    $workbookRels[] = '  <Relationship Id="rId' . $stylesRelId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
    $contentTypes[] = '</Types>';

    $parts = [];
    $parts['[Content_Types].xml'] = implode("\n", $contentTypes);
    $parts['_rels/.rels'] = party_transactions_root_rels_xml();
    $parts['docProps/core.xml'] = party_transactions_core_props_xml();
    $parts['docProps/app.xml'] = party_transactions_app_props_xml($sheetNamesForApp);
    $parts['xl/styles.xml'] = party_transactions_styles_xml();
    $parts['xl/workbook.xml'] = party_transactions_workbook_xml($sheetInfo);
    $parts['xl/_rels/workbook.xml.rels'] = party_transactions_workbook_rels_xml($workbookRels);

    foreach ($sheetInfo as $index => $info) {
        $widths = array_fill(0, 17, 0.0);
        $merges = [];
        $sheetXmlFiles['xl/worksheets/sheet' . ($index + 1) . '.xml'] = party_transactions_sheet_xml($info['payload'], $widths, $merges);
        $parts['xl/worksheets/sheet' . ($index + 1) . '.xml'] = $sheetXmlFiles['xl/worksheets/sheet' . ($index + 1) . '.xml'];
    }

    return $parts;
}

/**
 * @param list<array<string,mixed>> $sheetInfo
 */
function party_transactions_workbook_xml(array $sheetInfo): string
{
    $sheets = '';
    foreach ($sheetInfo as $info) {
        $sheetName = party_transactions_xml_escape((string) $info['sheet_name']);
        $sheets .= '    <sheet name="' . $sheetName . '" sheetId="' . (int) $info['sheetId'] . '" r:id="rId' . (int) $info['sheetId'] . '"/>' . "\n";
    }

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' . "\n"
        . '  <sheets>' . "\n"
        . $sheets
        . '  </sheets>' . "\n"
        . '</workbook>';
}

/**
 * @param list<string> $rels
 */
function party_transactions_workbook_rels_xml(array $rels): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n"
        . implode("\n", $rels) . "\n"
        . '</Relationships>';
}

/**
 * @param list<string> $sheetNames
 */
function party_transactions_app_props_xml(array $sheetNames): string
{
    $titles = '';
    foreach ($sheetNames as $sheetName) {
        $titles .= '<vt:lpstr>' . party_transactions_xml_escape((string) $sheetName) . '</vt:lpstr>';
    }

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">' . "\n"
        . '  <Application>Microsoft Excel</Application>' . "\n"
        . '  <DocSecurity>0</DocSecurity>' . "\n"
        . '  <ScaleCrop>false</ScaleCrop>' . "\n"
        . '  <HeadingPairs><vt:vector size="2" baseType="variant"><vt:variant><vt:lpstr>Worksheets</vt:lpstr></vt:variant><vt:variant><vt:i4>' . count($sheetNames) . '</vt:i4></vt:variant></vt:vector></HeadingPairs>' . "\n"
        . '  <TitlesOfParts><vt:vector size="' . count($sheetNames) . '" baseType="lpstr">' . $titles . '</vt:vector></TitlesOfParts>' . "\n"
        . '  <Company></Company>' . "\n"
        . '  <LinksUpToDate>false</LinksUpToDate>' . "\n"
        . '  <SharedDoc>false</SharedDoc>' . "\n"
        . '  <HyperlinksChanged>false</HyperlinksChanged>' . "\n"
        . '  <AppVersion>16.0300</AppVersion>' . "\n"
        . '</Properties>';
}

function party_transactions_core_props_xml(): string
{
    $now = gmdate('Y-m-d\TH:i:s\Z');

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' . "\n"
        . '  <dc:creator>Party Account</dc:creator>' . "\n"
        . '  <cp:lastModifiedBy>Party Account</cp:lastModifiedBy>' . "\n"
        . '  <dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>' . "\n"
        . '  <dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>' . "\n"
        . '</cp:coreProperties>';
}

function party_transactions_root_rels_xml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n"
        . '  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' . "\n"
        . '  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>' . "\n"
        . '  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>' . "\n"
        . '</Relationships>';
}

function party_transactions_styles_xml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . "\n"
        . '  <fonts count="4">' . "\n"
        . '    <font><sz val="11"/><color rgb="FF1F2937"/><name val="Aptos"/></font>' . "\n"
        . '    <font><sz val="11"/><color rgb="FFFFFFFF"/><name val="Aptos"/><b/></font>' . "\n"
        . '    <font><sz val="11"/><color rgb="FF0F172A"/><name val="Aptos"/><b/></font>' . "\n"
        . '    <font><sz val="10"/><color rgb="FF1E3A8A"/><name val="Aptos"/><b/></font>' . "\n"
        . '  </fonts>' . "\n"
        . '  <fills count="4">' . "\n"
        . '    <fill><patternFill patternType="none"/></fill>' . "\n"
        . '    <fill><patternFill patternType="gray125"/></fill>' . "\n"
        . '    <fill><patternFill patternType="solid"><fgColor rgb="FF1D4ED8"/><bgColor indexed="64"/></patternFill></fill>' . "\n"
        . '    <fill><patternFill patternType="solid"><fgColor rgb="FFEFF6FF"/><bgColor indexed="64"/></patternFill></fill>' . "\n"
        . '  </fills>' . "\n"
        . '  <borders count="2">' . "\n"
        . '    <border><left/><right/><top/><bottom/><diagonal/></border>' . "\n"
        . '    <border><left style="thin"><color rgb="FFD7E1EF"/></left><right style="thin"><color rgb="FFD7E1EF"/></right><top style="thin"><color rgb="FFD7E1EF"/></top><bottom style="thin"><color rgb="FFD7E1EF"/></bottom><diagonal/></border>' . "\n"
        . '  </borders>' . "\n"
        . '  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' . "\n"
        . '  <cellXfs count="7">' . "\n"
        . '    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' . "\n"
        . '    <xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>' . "\n"
        . '    <xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>' . "\n"
        . '    <xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>' . "\n"
        . '    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>' . "\n"
        . '    <xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>' . "\n"
        . '    <xf numFmtId="1" fontId="2" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>' . "\n"
        . '  </cellXfs>' . "\n"
        . '  <cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>' . "\n"
        . '  <dxfs count="0"/>' . "\n"
        . '  <tableStyles count="0" defaultTableStyle="TableStyleMedium2" defaultPivotStyle="PivotStyleLight16"/>' . "\n"
        . '</styleSheet>';
}

/**
 * @param array<string,mixed> $sheet
 */
function party_transactions_sheet_xml(array $sheet, array &$widths, array &$merges): string
{
    $party = is_array($sheet['party'] ?? null) ? $sheet['party'] : [];
    $rows = is_array($sheet['rows'] ?? null) ? $sheet['rows'] : [];
    $summary = is_array($sheet['summary'] ?? null) ? $sheet['summary'] : [];
    $currencyLabel = (string) ($sheet['currency_label'] ?? '-');
    $partyName = (string) ($party['party_name'] ?? 'Party');
    $currentBalance = isset($summary['current_balance']) ? (float) $summary['current_balance'] : 0.0;
    $openingBalance = isset($summary['opening_balance']) ? (float) $summary['opening_balance'] : 0.0;
    $totalCredit = isset($summary['total_credit']) ? (float) $summary['total_credit'] : 0.0;
    $totalDebit = isset($summary['total_debit']) ? (float) $summary['total_debit'] : 0.0;
    $totalTransactions = isset($summary['total_transactions']) ? (int) $summary['total_transactions'] : 0;

    $rowMap = [];
    $maxRow = 25;

    $headers = [
        'Party Name',
        'Currency',
        'Invoice Period',
        'Customer Invoice No',
        'Customer Invoice Value',
        'Vendor Invoice No',
        'Vendor Invoice Value',
        'Payment In',
        'Payment In Date',
        'Payment Out',
        'Payment Out Date',
        'Net Balance',
        'Status',
        'Created By',
    ];

    foreach ($headers as $index => $label) {
        party_transactions_sheet_add_cell($rowMap, $widths, 1, $index, $label, 1);
    }
    party_transactions_sheet_add_merged_text($rowMap, $widths, $merges, 1, 13, 16, 'Party Information', 1);

    $row = 2;
    party_transactions_sheet_add_merged_text($rowMap, $widths, $merges, $row, 14, 16, 'Party Details', 2);
    $fields = [
        ['Party Name', $partyName, false],
        ['Email', (string) ($party['party_email'] ?? '-'), false],
        ['Phone', (string) ($party['party_phone'] ?? '-'), false],
        ['Address', (string) ($party['address'] ?? '-'), false],
        ['Country', (string) ($party['country'] ?? '-'), false],
        ['Currency', $currencyLabel, false],
        ['Credit Limit', isset($party['credit_limit']) ? (float) $party['credit_limit'] : null, true],
        ['Payment Terms', (string) ($party['payment_terms'] ?? '-'), false],
        ['Opening Balance', !empty($sheet['has_opening_balance']) ? (float) $summary['opening_balance'] : null, true],
        ['Current Balance', $currentBalance, true],
    ];
    foreach ($fields as $field) {
        $row++;
        party_transactions_sheet_add_field_row($rowMap, $widths, $row, (string) $field[0], (string) $field[1], (bool) $field[2]);
    }

    $row = 14;
    party_transactions_sheet_add_merged_text($rowMap, $widths, $merges, $row, 14, 16, 'Bank Details', 2);
    $bankFields = [
        ['Bank Name', (string) ($party['bank_name'] ?? '-')],
        ['Account Holder Name', (string) ($party['account_holder_name'] ?? '-')],
        ['Account Number', (string) ($party['account_number'] ?? '-')],
        ['IFSC / SWIFT Code', (string) ($party['ifsc_swift_code'] ?? '-')],
        ['IBAN Number', (string) ($party['iban_number'] ?? '-')],
        ['Bank Branch Address', (string) ($party['bank_branch_address'] ?? '-')],
    ];
    foreach ($bankFields as $field) {
        $row++;
        party_transactions_sheet_add_field_row($rowMap, $widths, $row, (string) $field[0], (string) $field[1], false);
    }

    $row = 21;
    party_transactions_sheet_add_merged_text($rowMap, $widths, $merges, $row, 14, 16, 'Summary', 2);
    $summaryRows = [
        ['Total Transactions', (string) $totalTransactions, false],
        ['Total Credit', $totalCredit, true],
        ['Total Debit', $totalDebit, true],
        ['Closing Balance', $currentBalance, true],
    ];
    foreach ($summaryRows as $field) {
        $row++;
        party_transactions_sheet_add_field_row($rowMap, $widths, $row, (string) $field[0], (string) $field[1], (bool) $field[2]);
    }

    $txRow = 2;
    foreach ($rows as $rowData) {
        $values = [
            (string) ($rowData['party_name'] ?? ''),
            (string) ($rowData['currency'] ?? ''),
            (string) ($rowData['invoice_period'] ?? ''),
            (string) ($rowData['customer_invoice_no'] ?? ''),
            isset($rowData['customer_invoice_value']) ? (float) $rowData['customer_invoice_value'] : 0.0,
            (string) ($rowData['vendor_invoice_no'] ?? ''),
            isset($rowData['vendor_invoice_value']) ? (float) $rowData['vendor_invoice_value'] : 0.0,
            isset($rowData['payment_in']) ? (float) $rowData['payment_in'] : 0.0,
            (string) ($rowData['payment_in_date'] ?? ''),
            isset($rowData['payment_out']) ? (float) $rowData['payment_out'] : 0.0,
            (string) ($rowData['payment_out_date'] ?? ''),
            isset($rowData['customer_invoice_value'], $rowData['vendor_invoice_value'], $rowData['payment_in'], $rowData['payment_out'])
                ? (float) $rowData['customer_invoice_value'] - (float) $rowData['vendor_invoice_value'] - (float) $rowData['payment_in'] + (float) $rowData['payment_out']
                : 0.0,
            (string) ($rowData['derived_status'] ?? ''),
            (string) ($rowData['created_by'] ?? ''),
        ];
        foreach ($values as $index => $value) {
            $style = in_array($index, [4, 6, 7, 9, 11], true) ? 5 : 4;
            party_transactions_sheet_add_cell($rowMap, $widths, $txRow, $index, $value, $style);
        }
        $txRow++;
    }

    $maxRow = max($maxRow, $txRow - 1);

    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    $sheetXml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' . "\n";
    $sheetXml .= '  <sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft" activeCell="A2" sqref="A2"/></sheetView></sheetViews>' . "\n";
    $sheetXml .= '  <sheetFormatPr defaultRowHeight="18"/>' . "\n";
    $sheetXml .= '  <cols>' . "\n";
    foreach (party_transactions_build_column_widths($widths) as $idx => $width) {
        $col = $idx + 1;
        $sheetXml .= '    <col min="' . $col . '" max="' . $col . '" width="' . party_transactions_format_width($width) . '" customWidth="1"/>' . "\n";
    }
    $sheetXml .= '  </cols>' . "\n";
    $sheetXml .= '  <sheetData>' . "\n";
    for ($r = 1; $r <= $maxRow; $r++) {
        if (!isset($rowMap[$r])) {
            continue;
        }
        ksort($rowMap[$r]);
        $sheetXml .= '    <row r="' . $r . '">';
        foreach ($rowMap[$r] as $cell) {
            $sheetXml .= $cell;
        }
        $sheetXml .= '</row>' . "\n";
    }
    $sheetXml .= '  </sheetData>' . "\n";

    if ($merges !== []) {
        $sheetXml .= '  <mergeCells count="' . count($merges) . '">' . "\n";
        foreach ($merges as $merge) {
            $sheetXml .= '    <mergeCell ref="' . $merge . '"/>' . "\n";
        }
        $sheetXml .= '  </mergeCells>' . "\n";
    }

    $sheetXml .= '</worksheet>';

    return $sheetXml;
}

/**
 * @param array<int, array<int, string>> $rowMap
 */
function party_transactions_sheet_add_cell(array &$rowMap, array &$widths, int $row, int $colIndex, $value, int $style = 0): void
{
    if ($value === null || $value === '') {
        return;
    }
    $col = party_transactions_column_letter($colIndex + 1);
    $ref = $col . $row;
    $rowMap[$row][$colIndex] = party_transactions_cell_xml($ref, $value, $style);
    $display = is_float($value) || is_int($value) ? number_format((float) $value, 2) : (string) $value;
    $widths[$colIndex] = max($widths[$colIndex] ?? 0.0, party_transactions_estimate_width($display));
}

/**
 * @param array<int, array<int, string>> $rowMap
 */
function party_transactions_sheet_add_field_row(array &$rowMap, array &$widths, int $row, string $label, $value, bool $numeric = false): void
{
    party_transactions_sheet_add_cell($rowMap, $widths, $row, 14, $label, 3);
    if ($numeric) {
        if ($value === null || $value === '' || $value === '-') {
            party_transactions_sheet_add_cell($rowMap, $widths, $row, 15, '-', 4);
            return;
        }
        $num = is_numeric($value) ? (float) $value : (float) preg_replace('/[^0-9.\-]/', '', $value);
        party_transactions_sheet_add_cell($rowMap, $widths, $row, 15, $num, 5);
        return;
    }
    party_transactions_sheet_add_cell($rowMap, $widths, $row, 15, $value, 4);
}

/**
 * @param array<int, array<int, string>> $rowMap
 */
function party_transactions_sheet_add_merged_text(array &$rowMap, array &$widths, array &$merges, int $row, int $startColIndex, int $endColIndex, string $value, int $style): void
{
    $startCol = party_transactions_column_letter($startColIndex + 1);
    $endCol = party_transactions_column_letter($endColIndex + 1);
    $merges[] = $startCol . $row . ':' . $endCol . $row;
    party_transactions_sheet_add_cell($rowMap, $widths, $row, $startColIndex, $value, $style);
}

function party_transactions_unique_sheet_name(string $name, array &$usedNames): string
{
    $name = trim(party_transactions_clean_sheet_name($name));
    if ($name === '') {
        $name = 'Party';
    }

    $base = mb_strlen($name, 'UTF-8') > 31 ? mb_substr($name, 0, 31, 'UTF-8') : $name;
    $candidate = $base;
    $counter = 2;
    while (isset($usedNames[strtolower($candidate)])) {
        $suffix = ' (' . $counter . ')';
        $trim = 31 - strlen($suffix);
        $candidate = mb_substr($base, 0, max(1, $trim), 'UTF-8') . $suffix;
        $counter++;
    }
    $usedNames[strtolower($candidate)] = true;

    return $candidate;
}

function party_transactions_clean_sheet_name(string $name): string
{
    $name = preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/u', ' ', $name) ?? $name;
    $name = preg_replace('/\\s+/u', ' ', $name) ?? $name;

    return trim($name);
}

function party_transactions_xml_escape(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function party_transactions_column_letter(int $index): string
{
    $letter = '';
    while ($index > 0) {
        $mod = ($index - 1) % 26;
        $letter = chr(65 + $mod) . $letter;
        $index = intdiv($index - 1, 26);
    }

    return $letter;
}

function party_transactions_cell_xml(string $ref, $value, int $style = 0): string
{
    if (is_float($value) || is_int($value)) {
        return '<c r="' . $ref . '" s="' . $style . '"><v>' . rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.') . '</v></c>';
    }

    $text = party_transactions_xml_escape((string) $value);
    $space = ($text !== trim($text)) ? ' xml:space="preserve"' : '';

    return '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t' . $space . '>' . $text . '</t></is></c>';
}

function party_transactions_estimate_width(string $value): float
{
    $value = trim(str_replace(["\r", "\n"], ' ', $value));
    if ($value === '') {
        return 8.0;
    }

    $len = function_exists('mb_strlen') ? (int) mb_strlen($value, 'UTF-8') : strlen($value);

    return min(42.0, max(8.0, ($len * 1.05) + 2.0));
}

/**
 * @param array<int, float> $widths
 * @return array<int, float>
 */
function party_transactions_build_column_widths(array $widths): array
{
    $defaults = [
        0 => 13.0,
        1 => 24.0,
        2 => 11.0,
        3 => 14.0,
        4 => 18.0,
        5 => 16.0,
        6 => 18.0,
        7 => 16.0,
        8 => 13.0,
        9 => 13.0,
        10 => 14.0,
        11 => 11.0,
        12 => 13.0,
        13 => 4.0,
        14 => 20.0,
        15 => 32.0,
        16 => 4.0,
    ];
    foreach ($defaults as $idx => $default) {
        $widths[$idx] = max($default, (float) ($widths[$idx] ?? 0.0));
    }

    return $widths;
}

function party_transactions_format_width(float $width): string
{
    return number_format($width, 2, '.', '');
}

/**
 * @param array<string,string> $files
 */
function party_transactions_write_zip_archive(string $path, array $files): void
{
    $fh = fopen($path, 'wb');
    if ($fh === false) {
        throw new RuntimeException('Unable to create workbook file.');
    }

    $central = [];
    foreach ($files as $name => $content) {
        $name = str_replace('\\', '/', $name);
        $data = (string) $content;
        $crc = (int) sprintf('%u', crc32($data));
        $def = deflate_init(ZLIB_ENCODING_RAW);
        $compressed = deflate_add($def, $data, ZLIB_FINISH);
        if ($compressed === false) {
            fclose($fh);
            throw new RuntimeException('Unable to compress workbook data.');
        }
        $offset = ftell($fh);
        $nameLen = strlen($name);
        $compressedLen = strlen($compressed);
        $uncompressedLen = strlen($data);

        fwrite($fh, pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 8, 0, 0, $crc, $compressedLen, $uncompressedLen, $nameLen, 0));
        fwrite($fh, $name);
        fwrite($fh, $compressed);

        $central[] = [
            'name' => $name,
            'crc' => $crc,
            'compressed' => $compressedLen,
            'uncompressed' => $uncompressedLen,
            'offset' => $offset,
        ];
    }

    $centralOffset = ftell($fh);
    foreach ($central as $entry) {
        $name = $entry['name'];
        $nameLen = strlen($name);
        fwrite($fh, pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 8, 0, 0, $entry['crc'], $entry['compressed'], $entry['uncompressed'], $nameLen, 0, 0, 0, 0, 0, $entry['offset']));
        fwrite($fh, $name);
    }

    $centralSize = ftell($fh) - $centralOffset;
    fwrite($fh, pack('VvvvvVVv', 0x06054b50, 0, 0, count($central), count($central), $centralSize, $centralOffset, 0));
    fclose($fh);
}
