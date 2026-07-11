<?php

declare(strict_types=1);

/**
 * Business rules for Party Account CRUD (+ audit logging hooks).
 */
final class PartyAccountService
{
    public function __construct(
        private PDO $pdo,
        private PartyAccountRepository $accounts,
        private PartyAccountActivityLogService $log
    ) {
    }

    /** @throws RuntimeException on validation/conflict failures */
    public function create(array $input, array $currentUser): int
    {
        $normalized = party_account_normalize_payload($input);

        $errors = party_account_validate_payload($normalized, false);
        if ($errors !== []) {
            throw new RuntimeException(json_encode(['errors' => $errors]));
        }

        $this->assertEmailsAvailable($normalized, null);

        $isMultiCurrency = !empty($normalized['is_multi_currency']);
        $primaryCurrency = $normalized['primary_currency'] ?? $normalized['currency'] ?? 'INR';
        $primaryOpening = $normalized['primary_opening_balance'] ?? $normalized['opening_balance'];
        $primaryOpeningType = $normalized['primary_opening_balance_type'] ?? $normalized['opening_balance_type'];

        if ($isMultiCurrency) {
            $normalized['currency'] = null;
            $normalized['opening_balance'] = null;
            $normalized['opening_balance_type'] = null;
        } else {
            $normalized['currency'] = $primaryCurrency;
        }

        $normalized['created_by_pk'] = (int) ($currentUser['id'] ?? 0) ?: null;
        $normalized['updated_by_pk'] = (int) ($currentUser['id'] ?? 0) ?: null;

        $this->pdo->beginTransaction();

        try {
            $newId = $this->accounts->insert($normalized);

            if ($isMultiCurrency) {
                $currencies = $normalized['currencies'] ?? [];
                $hasPrimary = false;
                foreach ($currencies as $curLedger) {
                    if (($curLedger['currency'] ?? '') === $primaryCurrency) {
                        $hasPrimary = true;
                    }
                    $this->accounts->insertCurrencyLedger($newId, $curLedger);
                }
                if (!$hasPrimary && $primaryCurrency && $primaryOpening !== null) {
                    $signedOpening = ($primaryOpeningType === 'payable') ? -round((float) $primaryOpening, 2) : round((float) $primaryOpening, 2);
                    $this->accounts->insertCurrencyLedger($newId, [
                        'currency' => $primaryCurrency,
                        'opening_balance' => $signedOpening,
                        'opening_balance_type' => $primaryOpeningType,
                    ]);
                }
            }

            party_account_emails_sync(
                $this->pdo,
                $newId,
                (string) $normalized['party_email'],
                $normalized['additional_emails'] ?? []
            );
            $this->log->log(
                $newId,
                $currentUser,
                'created',
                'Party account #' . $newId . ' created',
                ['party_name' => $normalized['party_name'], 'is_multi_currency' => !empty($normalized['is_multi_currency']), 'currency_count' => !empty($normalized['currencies']) ? count($normalized['currencies']) : 0]
            );
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        return $newId;
    }

    /** @throws RuntimeException when validation/conflict/state errors occur */
public function update(int $id, array $input, array $currentUser): void
    {
        $normalized = party_account_normalize_payload($input);

        $existing = $this->accounts->findById($id, true);
        if (!$existing || $existing['deleted_at'] !== null) {
            throw new RuntimeException(json_encode(['errors' => ['_form' => 'Record unavailable or archived. Refresh the page.']]));
        }

        $isMultiCurrency = !empty($normalized['is_multi_currency']);
        $primaryCurrency = $normalized['primary_currency'] ?? $normalized['currency'] ?? 'INR';
        $primaryOpening = $normalized['primary_opening_balance'] ?? $normalized['opening_balance'];
        $primaryOpeningType = $normalized['primary_opening_balance_type'] ?? $normalized['opening_balance_type'];

        if ($isMultiCurrency) {
            $normalized['currency'] = null;
            $normalized['opening_balance'] = null;
            $normalized['opening_balance_type'] = null;
        } else {
            $normalized['currency'] = $primaryCurrency;
            $normalized['opening_balance'] = $existing['opening_balance'];
            $normalized['opening_balance_type'] = $existing['opening_balance_type'];
        }

        $errors = party_account_validate_payload($normalized, true);
        if ($errors !== []) {
            throw new RuntimeException(json_encode(['errors' => $errors]));
        }

        $this->assertEmailsAvailable($normalized, $id);

        $normalized['updated_by_pk'] = (int) ($currentUser['id'] ?? 0) ?: null;

        $this->pdo->beginTransaction();

        try {
            $this->accounts->update($id, $normalized);
            $existingLedgers = $this->accounts->findCurrencyLedgers($id);
            $existingCurrencyMap = [];
            foreach ($existingLedgers as $ledger) {
                $existingCurrencyMap[$ledger['currency']] = $ledger;
            }

            if ($isMultiCurrency) {
                $currencies = $normalized['currencies'] ?? [];
                $hasPrimary = false;
                foreach ($currencies as $curLedger) {
                    if (($curLedger['currency'] ?? '') === $primaryCurrency) {
                        $hasPrimary = true;
                    }
                    if (isset($existingCurrencyMap[$curLedger['currency']])) {
                        $this->accounts->updateCurrencyLedger($id, $curLedger);
                        continue;
                    }
                    $this->accounts->insertCurrencyLedger($id, $curLedger);
                }
                if (!$hasPrimary && $primaryCurrency && $primaryOpening !== null) {
                    $signedOpening = ($primaryOpeningType === 'payable') ? -round((float) $primaryOpening, 2) : round((float) $primaryOpening, 2);
                    $this->accounts->insertCurrencyLedger($id, [
                        'currency' => $primaryCurrency,
                        'opening_balance' => $signedOpening,
                        'opening_balance_type' => $primaryOpeningType,
                    ]);
                }

                $keptCurrencies = [];
                foreach ($currencies as $curLedger) {
                    $keptCurrencies[$curLedger['currency']] = true;
                }
                $keptCurrencies[$primaryCurrency] = true;
                foreach ($existingCurrencyMap as $currency => $ledger) {
                    if (empty($keptCurrencies[$currency])) {
                        $this->accounts->softDeleteCurrencyLedger($id, $currency);
                    }
                }
            } else {
                foreach ($existingLedgers as $ledger) {
                    $this->accounts->softDeleteCurrencyLedger($id, (string) $ledger['currency']);
                }
            }

            party_account_emails_sync(
                $this->pdo,
                $id,
                (string) $normalized['party_email'],
                $normalized['additional_emails'] ?? []
            );

            $this->log->log(
                $id,
                $currentUser,
                'updated',
                'Party account #' . $id . ' saved',
                [
                    'status' => $normalized['status'],
                    'credit_limit' => $normalized['credit_limit'],
                    'currency' => $normalized['currency'],
                    'is_multi_currency' => $isMultiCurrency,
                    'currency_count' => !empty($normalized['currencies']) ? count($normalized['currencies']) : 0,
                ]
            );
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }

    public function softDelete(int $id, array $currentUser): bool
    {
        $existing = $this->accounts->findById($id, true);
        if (!$existing || $existing['deleted_at'] !== null) {
            return false;
        }

        $updatedByPk = (int) ($currentUser['id'] ?? 0);

        $this->pdo->beginTransaction();

        try {
            $done = $this->accounts->softDelete($id, $updatedByPk);
            $this->log->log($id, $currentUser, 'deleted', 'Soft-deleted #' . $id, ['party_name' => $existing['party_name']]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        return $done;
    }

    public function restore(int $id, array $currentUser): bool
    {
        $existing = $this->accounts->findById($id, true);
        if (!$existing || $existing['deleted_at'] === null) {
            return false;
        }

        $updatedByPk = (int) ($currentUser['id'] ?? 0);

        foreach (party_account_emails_all_addresses($this->pdo, $id) as $addr) {
            $emailNorm = $this->normalizedEmail($addr);
            if ($emailNorm !== '' && $this->accounts->emailTakenByAnother($emailNorm, $id)) {
                throw new RuntimeException(json_encode([
                    'errors' => ['party_email' => 'Duplicate email conflicts with another live Party Account — fix email before restore.'],
                ]));
            }
        }

        $this->pdo->beginTransaction();

        try {
            $done = $this->accounts->restore($id, $updatedByPk);
            $this->log->log($id, $currentUser, 'restored', 'Restored #' . $id, []);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        return $done;
    }

    /** @param int[] $ids */
    public function bulkSoftDelete(array $ids, array $currentUser): int
    {
        $updatedByPk = (int) ($currentUser['id'] ?? 0);
        $affected = $this->accounts->bulkSoftDelete($ids, $updatedByPk);

        $this->log->log(null, $currentUser, 'bulk_deleted', sprintf('Bulk archived %u party account row(s)', $affected), [
            'targets' => array_slice($ids, 0, 100),
            'requested' => count($ids),
        ]);

        return $affected;
    }

    /** @param int[] $ids */
    public function bulkRestore(array $ids, array $currentUser): int
    {
        $updatedByPk = (int) ($currentUser['id'] ?? 0);

        return $this->accounts->bulkRestore($ids, $updatedByPk);
    }

    private function normalizedEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /** @param array<string, mixed> $normalized */
    private function assertEmailsAvailable(array $normalized, ?int $exceptId): void
    {
        foreach (party_account_collect_email_addresses($normalized) as $addr) {
            $emailNorm = $this->normalizedEmail($addr);
            if ($emailNorm === '') {
                continue;
            }
            if ($this->accounts->emailTakenByAnother($emailNorm, $exceptId)) {
                $field = strtolower(trim((string) ($normalized['party_email'] ?? ''))) === $emailNorm
                    ? 'party_email'
                    : 'additional_emails';
                throw new RuntimeException(json_encode([
                    'errors' => [$field => 'Another active Party Account uses this email.'],
                ]));
            }
        }
    }
}
