<?php

declare(strict_types=1);

/**
 * Party Account bulk import — CSV / XLSX parsing and column mapping.
 */

/** @return list<string> */
function party_account_import_template_headers(): array
{
    return [
        'party_name',
        'country',
        'party_email',
        'additional_emails',
        'party_phone',
        'address',
        'loop_entity',
        'assistant_manager_name',
        'business_manager_name',
        'payment_terms',
        'bank_name',
        'account_holder_name',
        'account_number',
        'ifsc_swift_code',
        'iban_number',
        'bank_branch_address',
        'credit_limit',
        'currency',
        'opening_balance',
        'opening_balance_type',
        'status',
        'notes',
    ];
}

/** @return array<string, list<string>> */
function party_account_import_column_aliases(): array
{
    return [
        'party_name' => ['party name', 'party_name', 'name'],
        'country' => ['country'],
        'party_email' => ['party email', 'party_email', 'email', 'primary email'],
        'additional_emails' => ['additional emails', 'additional_emails', 'extra emails'],
        'party_phone' => ['party phone', 'party_phone', 'phone', 'primary phone'],
        'address' => ['address', 'registered address'],
        'loop_entity' => ['loop entity', 'loop_entity', 'company branch', 'branch'],
        'assistant_manager_name' => ['assistant manager', 'assistant_manager_name', 'am'],
        'business_manager_name' => ['business manager', 'business_manager_name', 'bm'],
        'payment_terms' => ['payment terms', 'payment_terms'],
        'bank_name' => ['bank name', 'bank_name', 'bank'],
        'account_holder_name' => ['account holder', 'account_holder_name'],
        'account_number' => ['account number', 'account_number'],
        'ifsc_swift_code' => ['ifsc', 'swift', 'ifsc_swift_code', 'ifsc / swift', 'routing code'],
        'iban_number' => ['iban', 'iban_number'],
        'bank_branch_address' => ['bank branch address', 'bank_branch_address', 'branch address'],
        'credit_limit' => ['credit limit', 'credit_limit'],
        'currency' => ['currency'],
        'opening_balance' => ['opening balance', 'opening_balance'],
        'opening_balance_type' => ['opening balance type', 'opening_balance_type', 'balance type'],
        'status' => ['status', 'account status'],
        'notes' => ['notes', 'internal notes'],
    ];
}

function party_account_import_normalize_header(string $header): string
{
    $h = strtolower(trim($header));
    $h = str_replace(['_', '-'], ' ', $h);
    $h = preg_replace('/\s+/', ' ', $h) ?? $h;

    return trim($h);
}

/**
 * @param list<string> $headerRow
 * @return array<string, int> canonical field => column index
 */
function party_account_import_map_headers(array $headerRow): array
{
    $aliases = party_account_import_column_aliases();
    $lookup = [];
    foreach ($aliases as $canonical => $names) {
        foreach ($names as $name) {
            $lookup[party_account_import_normalize_header($name)] = $canonical;
        }
    }

    $map = [];
    foreach ($headerRow as $idx => $cell) {
        $key = party_account_import_normalize_header((string) $cell);
        if ($key === '' || !isset($lookup[$key])) {
            continue;
        }
        $canonical = $lookup[$key];
        if (!isset($map[$canonical])) {
            $map[$canonical] = (int) $idx;
        }
    }

    return $map;
}

/**
 * @return list<list<string>>
 */
function party_account_import_parse_upload(string $tmpPath, string $originalName): array
{
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext === 'csv') {
        return party_account_import_parse_csv($tmpPath);
    }
    if ($ext === 'xlsx') {
        return party_account_import_parse_xlsx($tmpPath);
    }

    throw new InvalidArgumentException('Unsupported file type. Upload .csv or .xlsx only.');
}

/**
 * @return list<list<string>>
 */
function party_account_import_parse_csv(string $path): array
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Unable to read CSV file.');
    }

    $rows = [];
    while (($data = fgetcsv($handle)) !== false) {
        $rows[] = array_map(static fn ($v) => trim((string) $v), $data);
    }
    fclose($handle);

    return $rows;
}

/**
 * Minimal XLSX reader (first worksheet, shared strings).
 *
 * @return list<list<string>>
 */
function party_account_import_parse_xlsx(string $path): array
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('XLSX import requires PHP Zip extension. Save the file as CSV instead.');
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Unable to open XLSX file.');
    }

    $shared = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false && $sharedXml !== '') {
        $sx = @simplexml_load_string($sharedXml);
        if ($sx !== false) {
            foreach ($sx->si as $si) {
                if (isset($si->t)) {
                    $shared[] = (string) $si->t;
                } elseif (isset($si->r)) {
                    $parts = [];
                    foreach ($si->r as $run) {
                        $parts[] = (string) ($run->t ?? '');
                    }
                    $shared[] = implode('', $parts);
                } else {
                    $shared[] = '';
                }
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();

    if ($sheetXml === false || $sheetXml === '') {
        throw new RuntimeException('XLSX worksheet not found.');
    }

    $sheet = @simplexml_load_string($sheetXml);
    if ($sheet === false) {
        throw new RuntimeException('Invalid XLSX worksheet XML.');
    }

    $rows = [];
    $sheet->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

    foreach ($sheet->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $cell) {
            $ref = (string) ($cell['r'] ?? '');
            if ($ref === '') {
                continue;
            }
            if (!preg_match('/^([A-Z]+)/', $ref, $m)) {
                continue;
            }
            $col = $m[1];
            $colIndex = party_account_import_column_index($col);
            $type = (string) ($cell['t'] ?? '');
            $value = '';
            if ($type === 's') {
                $idx = (int) ($cell->v ?? 0);
                $value = $shared[$idx] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = (string) ($cell->is->t ?? '');
            } else {
                $value = (string) ($cell->v ?? '');
            }
            $cells[$colIndex] = trim($value);
        }
        if ($cells === []) {
            $rows[] = [];

            continue;
        }
        $max = max(array_keys($cells));
        $line = [];
        for ($i = 0; $i <= $max; $i++) {
            $line[] = $cells[$i] ?? '';
        }
        $rows[] = $line;
    }

    return $rows;
}

function party_account_import_column_index(string $letters): int
{
    $letters = strtoupper($letters);
    $num = 0;
    $len = strlen($letters);
    for ($i = 0; $i < $len; $i++) {
        $num = $num * 26 + (ord($letters[$i]) - 64);
    }

    return max(0, $num - 1);
}

/**
 * @param list<list<string>> $rows
 * @return list<array{row_number: int, payload: array<string, mixed>}>
 */
function party_account_import_rows_to_payloads(array $rows, PDO $pdo): array
{
    if ($rows === []) {
        return [];
    }

    $header = array_shift($rows);
    $map = party_account_import_map_headers($header);
    if (!isset($map['party_name']) || !isset($map['country'])) {
        throw new InvalidArgumentException('Template must include party_name and country columns.');
    }

    $entityMap = party_account_import_loop_entity_lookup($pdo);
    $out = [];
    $rowNum = 1;

    foreach ($rows as $line) {
        $rowNum++;
        if (party_account_import_line_is_empty($line)) {
            continue;
        }

        $get = static function (string $field) use ($map, $line): string {
            if (!isset($map[$field])) {
                return '';
            }
            $idx = $map[$field];

            return trim((string) ($line[$idx] ?? ''));
        };

        $additionalRaw = $get('additional_emails');
        $additional = [];
        if ($additionalRaw !== '') {
            foreach (preg_split('/[;,]+/', $additionalRaw) ?: [] as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $additional[] = $part;
                }
            }
        }

        $loopName = $get('loop_entity');
        $loopId = null;
        if ($loopName !== '') {
            $key = strtolower($loopName);
            $loopId = $entityMap[$key] ?? null;
        }

        $payload = [
            'party_name' => $get('party_name'),
            'country' => $get('country'),
            'party_email' => $get('party_email'),
            'additional_emails' => $additional,
            'party_phone' => $get('party_phone'),
            'address' => $get('address'),
            'loop_entity_id' => $loopId,
            'assistant_manager_name' => $get('assistant_manager_name'),
            'business_manager_name' => $get('business_manager_name'),
            'payment_terms' => $get('payment_terms'),
            'bank_name' => $get('bank_name'),
            'account_holder_name' => $get('account_holder_name'),
            'account_number' => $get('account_number'),
            'ifsc_swift_code' => $get('ifsc_swift_code'),
            'iban_number' => $get('iban_number'),
            'bank_branch_address' => $get('bank_branch_address'),
            'credit_limit' => $get('credit_limit') !== '' ? $get('credit_limit') : null,
            'currency' => $get('currency') !== '' ? $get('currency') : 'INR',
            'opening_balance' => $get('opening_balance') !== '' ? $get('opening_balance') : null,
            'opening_balance_type' => $get('opening_balance_type'),
            'status' => $get('status') !== '' ? $get('status') : 'active',
            'notes' => $get('notes'),
        ];

        if ($loopName !== '' && $loopId === null) {
            $payload['_loop_entity_error'] = 'Unknown loop entity: ' . $loopName;
        }

        $out[] = [
            'row_number' => $rowNum,
            'payload' => $payload,
        ];
    }

    return $out;
}

/** @return array<string, int> lowercase name => id */
function party_account_import_loop_entity_lookup(PDO $pdo): array
{
    $map = [];
    $stmt = $pdo->query(
        'SELECT id, name FROM loop_entities WHERE deleted_at IS NULL'
    );
    if (!$stmt) {
        return $map;
    }
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $name = trim((string) ($row['name'] ?? ''));
        if ($name !== '') {
            $map[strtolower($name)] = (int) $row['id'];
        }
    }

    return $map;
}

/** @param list<string> $line */
function party_account_import_line_is_empty(array $line): bool
{
    foreach ($line as $cell) {
        if (trim((string) $cell) !== '') {
            return false;
        }
    }

    return true;
}

function party_account_import_stream_template(): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="party-accounts-import-template.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    if (!$out) {
        return;
    }
    fputcsv($out, party_account_import_template_headers());
    fputcsv($out, [
        'Acme Logistics Pvt Ltd',
        'India',
        'finance@acme.example',
        'billing@acme.example;ops@acme.example',
        '9876543210',
        'Street, city, postal code',
        'Loop Mobility Mumbai',
        'Nitish Gupta',
        'Rahul Sharma',
        'Net 30',
        'HDFC Bank',
        'Acme Logistics Pvt Ltd',
        '1234567890',
        'HDFC0001234',
        '',
        'HDFC Bank Ltd., Subhash Nagar Branch, B-12, Main Najafgarh Road, Subhash Nagar, New Delhi, Delhi 110027',
        '500000',
        'INR',
        '10000',
        'receivable',
        'active',
        'Sample row — delete before import',
    ]);
    fclose($out);
}

function party_account_ensure_import_logs_table(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    if (!party_account_table_exists($pdo, 'party_account_import_logs')) {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `party_account_import_logs` (
              `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
              `actor_user_id` varchar(50) DEFAULT NULL,
              `actor_name` varchar(180) DEFAULT NULL,
              `filename` varchar(255) NOT NULL,
              `total_rows` int UNSIGNED NOT NULL DEFAULT 0,
              `success_count` int UNSIGNED NOT NULL DEFAULT 0,
              `skipped_count` int UNSIGNED NOT NULL DEFAULT 0,
              `failed_count` int UNSIGNED NOT NULL DEFAULT 0,
              `errors_json` json DEFAULT NULL,
              `created_ids_json` json DEFAULT NULL,
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_pa_import_logs_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    $ready = true;
}
