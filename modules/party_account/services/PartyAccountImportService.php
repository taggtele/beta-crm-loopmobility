<?php

declare(strict_types=1);

/**
 * Bulk import preview + commit for Party Account (reuses PartyAccountService::create).
 */
final class PartyAccountImportService
{
    private const MAX_ROWS = 500;

    public function __construct(
        private PDO $pdo,
        private PartyAccountRepository $accounts,
        private PartyAccountService $partyService,
    ) {
    }

    /**
     * @return array{
     *   total_rows: int,
     *   ready: list<array{row_number: int, payload: array<string, mixed>}>,
     *   errors: list<array{row_number: int, message: string}>,
     *   duplicates: list<array{row_number: int, message: string}>,
     *   warnings: list<array{row_number: int, message: string}>
     * }
     */
    public function preview(string $tmpPath, string $originalName): array
    {
        $parsed = $this->loadRows($tmpPath, $originalName);

        return $this->analyzeRows($parsed);
    }

    /**
     * @return array{
     *   log_id: int,
     *   total_rows: int,
     *   success_count: int,
     *   skipped_count: int,
     *   failed_count: int,
     *   errors: list<array{row_number: int, message: string}>,
     *   created_ids: list<int>
     * }
     */
    public function runImport(
        string $tmpPath,
        string $originalName,
        array $currentUser,
        bool $skipDuplicates
    ): array {
        $analysis = $this->analyzeRows($this->loadRows($tmpPath, $originalName));

        $success = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];
        $createdIds = [];

        foreach ($analysis['ready'] as $item) {
            $rowNum = (int) $item['row_number'];
            foreach ($analysis['duplicates'] as $dup) {
                if ((int) $dup['row_number'] === $rowNum) {
                    if ($skipDuplicates) {
                        $skipped++;
                        continue 2;
                    }
                    $failed++;
                    $errors[] = ['row_number' => $rowNum, 'message' => $dup['message']];

                    continue 2;
                }
            }

            try {
                $id = $this->partyService->create($item['payload'], $currentUser);
                $createdIds[] = $id;
                $success++;
            } catch (RuntimeException $e) {
                $failed++;
                $errors[] = [
                    'row_number' => $rowNum,
                    'message' => $this->runtimeErrorMessage($e),
                ];
            } catch (Throwable $e) {
                $failed++;
                $errors[] = [
                    'row_number' => $rowNum,
                    'message' => 'Import failed for this row.',
                ];
            }
        }

        foreach ($analysis['errors'] as $err) {
            $failed++;
            $errors[] = $err;
        }

        $logId = $this->writeImportLog(
            $currentUser,
            $originalName,
            $analysis['total_rows'],
            $success,
            $skipped,
            $failed,
            $errors,
            $createdIds
        );

        return [
            'log_id' => $logId,
            'total_rows' => $analysis['total_rows'],
            'success_count' => $success,
            'skipped_count' => $skipped,
            'failed_count' => $failed,
            'errors' => $errors,
            'created_ids' => $createdIds,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function recentLogs(int $limit = 15): array
    {
        if (!party_account_table_exists($this->pdo, 'party_account_import_logs')) {
            return [];
        }

        $limit = max(1, min(50, $limit));
        $stmt = $this->pdo->query(
            'SELECT id, filename, total_rows, success_count, skipped_count, failed_count, actor_name, created_at
             FROM party_account_import_logs
             ORDER BY id DESC
             LIMIT ' . $limit
        );

        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    /** @return list<array{row_number: int, payload: array<string, mixed>}> */
    private function loadRows(string $tmpPath, string $originalName): array
    {
        $rows = party_account_import_parse_upload($tmpPath, $originalName);
        $payloads = party_account_import_rows_to_payloads($rows, $this->pdo);
        if (count($payloads) > self::MAX_ROWS) {
            throw new InvalidArgumentException(sprintf('Import limited to %u data rows per file.', self::MAX_ROWS));
        }

        return $payloads;
    }

    /**
     * @param list<array{row_number: int, payload: array<string, mixed>}> $parsed
     * @return array{
     *   total_rows: int,
     *   ready: list<array{row_number: int, payload: array<string, mixed>}>,
     *   errors: list<array{row_number: int, message: string}>,
     *   duplicates: list<array{row_number: int, message: string}>,
     *   warnings: list<array{row_number: int, message: string}>
     * }
     */
    private function analyzeRows(array $parsed): array
    {
        $ready = [];
        $errors = [];
        $duplicates = [];
        $warnings = [];
        $seenEmails = [];
        $seenNameCountry = [];

        foreach ($parsed as $item) {
            $rowNum = (int) $item['row_number'];
            $raw = $item['payload'];

            if (!empty($raw['_loop_entity_error'])) {
                $errors[] = ['row_number' => $rowNum, 'message' => (string) $raw['_loop_entity_error']];
                unset($raw['_loop_entity_error']);

                continue;
            }

            $normalized = party_account_normalize_payload($raw);
            $valErrors = party_account_validate_payload($normalized, false);
            if ($valErrors !== []) {
                $firstKey = array_key_first($valErrors);
                $errors[] = [
                    'row_number' => $rowNum,
                    'message' => ($firstKey ? $firstKey . ': ' : '') . (string) ($valErrors[$firstKey] ?? 'Validation failed.'),
                ];

                continue;
            }

            $dupMsg = $this->detectDuplicate($normalized, $seenEmails, $seenNameCountry);
            if ($dupMsg !== null) {
                $duplicates[] = ['row_number' => $rowNum, 'message' => $dupMsg];
            }

            $emailKeys = [];
            foreach (party_account_collect_email_addresses($normalized) as $addr) {
                $ek = strtolower(trim($addr));
                if ($ek !== '') {
                    $emailKeys[] = $ek;
                }
            }
            foreach ($emailKeys as $ek) {
                if ($this->accounts->emailTakenByAnother($ek, null)) {
                    $duplicates[] = [
                        'row_number' => $rowNum,
                        'message' => 'Email already used by another live party account: ' . $ek,
                    ];
                    break;
                }
            }

            $ready[] = [
                'row_number' => $rowNum,
                'payload' => $normalized,
            ];
        }

        return [
            'total_rows' => count($parsed),
            'ready' => $ready,
            'errors' => $errors,
            'duplicates' => $duplicates,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<string, bool> $seenEmails
     * @param array<string, bool> $seenNameCountry
     */
    private function detectDuplicate(array $normalized, array &$seenEmails, array &$seenNameCountry): ?string
    {
        foreach (party_account_collect_email_addresses($normalized) as $addr) {
            $ek = strtolower(trim($addr));
            if ($ek === '') {
                continue;
            }
            if (isset($seenEmails[$ek])) {
                return 'Duplicate email in file: ' . $addr;
            }
            $seenEmails[$ek] = true;
        }

        $nameKey = strtolower(trim((string) ($normalized['party_name'] ?? '')))
            . '|' . strtolower(trim((string) ($normalized['country'] ?? '')));
        if ($nameKey !== '|' && isset($seenNameCountry[$nameKey])) {
            return 'Duplicate party name + country in file.';
        }
        if ($nameKey !== '|') {
            $seenNameCountry[$nameKey] = true;
        }

        return null;
    }

    private function runtimeErrorMessage(RuntimeException $e): string
    {
        $payload = json_decode($e->getMessage(), true);
        if (is_array($payload) && isset($payload['errors']) && is_array($payload['errors'])) {
            $firstKey = array_key_first($payload['errors']);

            return ($firstKey ? $firstKey . ': ' : '') . (string) ($payload['errors'][$firstKey] ?? $e->getMessage());
        }

        return $e->getMessage();
    }

    /**
     * @param list<array{row_number: int, message: string}> $errors
     * @param list<int> $createdIds
     */
    private function writeImportLog(
        array $currentUser,
        string $filename,
        int $totalRows,
        int $success,
        int $skipped,
        int $failed,
        array $errors,
        array $createdIds
    ): int {
        party_account_ensure_import_logs_table($this->pdo);

        $stmt = $this->pdo->prepare(
            'INSERT INTO party_account_import_logs (
                actor_user_id, actor_name, filename, total_rows, success_count, skipped_count, failed_count,
                errors_json, created_ids_json, created_at
            ) VALUES (
                :uid, :un, :fn, :tot, :ok, :skip, :fail, :err, :ids, NOW()
            )'
        );

        $stmt->execute([
            ':uid' => $currentUser['user_id'] ?? null,
            ':un' => mb_substr((string) ($currentUser['name'] ?? ''), 0, 180),
            ':fn' => mb_substr($filename, 0, 255),
            ':tot' => $totalRows,
            ':ok' => $success,
            ':skip' => $skipped,
            ':fail' => $failed,
            ':err' => json_encode(array_slice($errors, 0, 200), JSON_UNESCAPED_SLASHES) ?: '[]',
            ':ids' => json_encode(array_slice($createdIds, 0, 500), JSON_UNESCAPED_SLASHES) ?: '[]',
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
