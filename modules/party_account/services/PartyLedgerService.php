<?php

declare(strict_types=1);

final class PartyLedgerService
{
    public function __construct(
        private PDO $pdo,
        private PartyAccountActivityLogService $log
    ) {
    }

    /** @return array{rows:list<array<string,mixed>>,summary:array<string,mixed>} */
    public function parties(array $filters): array
    {
        $params = [];
        $where = ['pa.deleted_at IS NULL'];

        $partyId = (int) ($filters['party_id'] ?? 0);
        if ($partyId > 0) {
            $where[] = 'pa.id = :party_id';
            $params[':party_id'] = $partyId;
        }

        $from = $this->dateOrNull((string) ($filters['from'] ?? ''));
        $to = $this->dateOrNull((string) ($filters['to'] ?? ''));
        $txWhere = 'lt.deleted_at IS NULL';
        if ($from !== null) {
            $txWhere .= ' AND lt.invoice_period >= :from_date';
            $params[':from_date'] = $this->periodFromDateString($from);
        }
        if ($to !== null) {
            $txWhere .= ' AND lt.invoice_period <= :to_date';
            $params[':to_date'] = $this->periodFromDateString($to);
        }

        $sql = '
            SELECT pa.id, pa.party_name, pa.party_email, pa.party_phone, pa.currency, pa.status, pa.is_multi_currency
            FROM party_accounts pa
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY pa.party_name ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = [];
        foreach ($stmt->fetchAll() as $party) {
            $currencies = $this->partyCurrencies((int) $party['id']);
            foreach ($currencies as $ledger) {
                $currency = (string) ($ledger['currency'] ?? $party['currency']);
                $openingBalance = $this->getOpeningBalance($party, $currency, $ledger);
                $signedOpening = $this->signedOpeningBalanceValue($openingBalance['opening_balance'], $openingBalance['opening_balance_type']);
                $stats = $this->currencyLedgerStats((int) $party['id'], $currency, $from, $to);
                $current = round($signedOpening + (float) $stats['movement'], 2);
                $type = $current > 0 ? 'receivable' : ($current < 0 ? 'payable' : 'zero');

                $balanceTypeFilter = trim((string) ($filters['balance_type'] ?? ''));
                if ($balanceTypeFilter !== '' && $balanceTypeFilter !== $type) {
                    continue;
                }

                $rows[] = [
                    'id' => (int) $party['id'],
                    'party_id' => (int) $party['id'],
                    'party_name' => $party['party_name'],
                    'party_email' => $party['party_email'],
                    'party_phone' => $party['party_phone'],
                    'currency' => $currency,
                    'status' => $party['status'],
                    'is_multi_currency' => $party['is_multi_currency'],
                    'opening_balance' => round($signedOpening, 2),
                    'opening_balance_type' => $openingBalance['opening_balance_type'],
                    'current_balance' => $current,
                    'balance_type' => $type,
                    'transaction_count' => (int) ($stats['transaction_count'] ?? 0),
                    'last_activity' => $stats['last_activity'] ?? null,
                ];
            }
        }

        return [
            'rows' => $rows,
            'summary' => ['count' => count($rows)],
        ];
    }

    /** @return array{rows:list<array<string,mixed>>,summary:array<string,mixed>} */
    public function ledgerRecords(array $filters): array
    {
        $params = [];
        $where = ['pa.deleted_at IS NULL'];

        $partyId = (int) ($filters['party_id'] ?? 0);
        if ($partyId > 0) {
            $where[] = 'pa.id = :party_id';
            $params[':party_id'] = $partyId;
        }

        $from = $this->dateOrNull((string) ($filters['from'] ?? ''));
        $to = $this->dateOrNull((string) ($filters['to'] ?? ''));
        $currencyFilter = trim((string) ($filters['currency'] ?? ''));
        $balanceFilter = trim((string) ($filters['balance_type'] ?? ''));

        $stmt = $this->pdo->prepare(
            'SELECT pa.id, pa.party_name, pa.party_email, pa.party_phone, pa.currency, pa.opening_balance, pa.opening_balance_type, pa.status, pa.is_multi_currency
             FROM party_accounts pa
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY pa.party_name ASC'
        );
        $stmt->execute($params);

        $rows = [];
        foreach ($stmt->fetchAll() as $party) {
            foreach ($this->partyCurrencies((int) $party['id']) as $ledger) {
                $currency = (string) ($ledger['currency'] ?? $party['currency']);
                if ($currencyFilter !== '' && $currencyFilter !== $currency) {
                    continue;
                }

                $openingBalance = $this->getOpeningBalance($party, $currency, $ledger);
                $signedOpening = $this->signedOpeningBalanceValue($openingBalance['opening_balance'], $openingBalance['opening_balance_type']);
                $stats = $this->currencyLedgerStats((int) $party['id'], $currency, $from, $to);
                $current = round($signedOpening + (float) $stats['movement'], 2);
                $type = $current > 0 ? 'receivable' : ($current < 0 ? 'payable' : 'zero');
                if ($balanceFilter !== '' && $balanceFilter !== $type) {
                    continue;
                }

                $rows[] = [
                    'id' => (int) $party['id'],
                    'party_id' => (int) $party['id'],
                    'party_name' => $party['party_name'],
                    'party_email' => $party['party_email'],
                    'party_phone' => $party['party_phone'],
                    'currency' => $currency,
                    'status' => $party['status'],
                    'is_multi_currency' => $party['is_multi_currency'],
                    'opening_balance' => round($signedOpening, 2),
                    'opening_balance_type' => $openingBalance['opening_balance_type'],
                    'current_balance' => $current,
                    'balance_type' => $type,
                    'transaction_count' => (int) ($stats['transaction_count'] ?? 0),
                    'last_activity' => $stats['last_activity'] ?? null,
                ];
            }
        }

        return [
            'rows' => $rows,
            'summary' => ['count' => count($rows)],
        ];
    }

    public function party(int $partyId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT pa.id, pa.party_name, pa.party_email, pa.party_phone, pa.currency, pa.opening_balance, pa.opening_balance_type, pa.status, pa.is_multi_currency,
                    pa.bank_name, pa.account_number, pa.ifsc_swift_code, pa.bank_branch_address, pa.loop_entity_id,
                    le.name AS loop_entity_name
             FROM party_accounts pa
             LEFT JOIN loop_entities le ON le.id = pa.loop_entity_id
              WHERE pa.id = :id AND pa.deleted_at IS NULL
              LIMIT 1'
        );
        $stmt->execute([':id' => $partyId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function partyCurrencies(int $partyId): array
    {
        $party = $this->party($partyId);
        if (!$party) {
            return [];
        }

        $isMultiCurrency = !empty($party['is_multi_currency']) && party_account_table_exists($this->pdo, 'party_currency_ledgers');
        $ledgers = [];

        if ($isMultiCurrency) {
            $stmt = $this->pdo->prepare(
                'SELECT currency, opening_balance, opening_balance_type, status
                 FROM party_currency_ledgers
                 WHERE party_account_id = :party_id AND deleted_at IS NULL
                 ORDER BY currency ASC'
            );
            $stmt->execute([':party_id' => $partyId]);
            $ledgers = $stmt->fetchAll();
        }

        if (!$isMultiCurrency || empty($ledgers)) {
            $ledgers = [
                [
                    'currency' => $party['currency'],
                    'opening_balance' => $party['opening_balance'] ?? 0,
                    'opening_balance_type' => $party['opening_balance_type'] ?? null,
                    'status' => $party['status'],
                ]
            ];
        }

        return $ledgers;
    }

    public function ledger(int $partyId, ?string $currency = null, ?string $from = null, ?string $to = null): array
    {
        $party = $this->party($partyId);
        if (!$party) {
            throw new RuntimeException('Party not found.');
        }

        $useCurrency = $currency ?? $party['currency'];
        if (!empty($party['is_multi_currency'])) {
            if ($useCurrency === '') {
                throw new RuntimeException('Currency is required for this party.');
            }
            $useCurrency = $this->resolveCurrency($partyId, $useCurrency);
        } else {
            $useCurrency = $party['currency'];
        }

        $partyCurrencies = $this->partyCurrencies($partyId);
        $ledger = null;
        foreach ($partyCurrencies as $lc) {
            if (($lc['currency'] ?? '') === $useCurrency) {
                $ledger = $lc;
                break;
            }
        }
        $openingBalance = $this->getOpeningBalance($party, $useCurrency, $ledger ?: []);

        $params = [':id' => $partyId, ':cur' => $useCurrency];
        $where = ['party_account_id = :id', 'currency = :cur', 'deleted_at IS NULL'];
        $from = $this->dateOrNull((string) $from);
        $to = $this->dateOrNull((string) $to);
        if ($from !== null) {
            $where[] = 'invoice_period >= :from';
            $params[':from'] = $this->periodFromDateString($from);
        }
        if ($to !== null) {
            $where[] = 'invoice_period <= :to';
            $params[':to'] = $this->periodFromDateString($to);
        }

        $stmt = $this->pdo->prepare(
            'SELECT *
             FROM party_ledger_transactions
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY invoice_period ASC, id ASC'
        );
        $stmt->execute($params);

        $rows = [];
        $running = $this->openingBeforeCurrency($partyId, $useCurrency, $from, $openingBalance);
        $summary = [
            'opening_balance' => round($running, 2),
            'currency' => $useCurrency,
            'total_customer_invoice' => 0.0,
            'total_vendor_invoice' => 0.0,
            'total_payment_in' => 0.0,
            'total_payment_out' => 0.0,
            'net_balance' => 0.0,
        ];

        foreach ($stmt->fetchAll() as $row) {
             $movement = (float) $row['customer_invoice_value'] - (float) $row['vendor_invoice_value']
                 - (float) $row['payment_in'] + (float) $row['payment_out'];
            $running += $movement;
            $row['running_balance'] = round($running, 2);
            $row['is_locked'] = $this->periodClosedCurrency($partyId, $useCurrency, (string) $row['invoice_period']);
            $rows[] = $row;
            $summary['total_customer_invoice'] += (float) $row['customer_invoice_value'];
            $summary['total_vendor_invoice'] += (float) $row['vendor_invoice_value'];
            $summary['total_payment_in'] += (float) $row['payment_in'];
            $summary['total_payment_out'] += (float) $row['payment_out'];
        }
        $summary['closing_balance'] = round($running, 2);
        $summary['net_balance'] = round($running, 2);

        return ['party' => $party, 'currency' => $useCurrency, 'rows' => $rows, 'summary' => $summary];
    }

    public function monthlySummary(int $partyId, ?string $currency = null): array
    {
        $party = $this->party($partyId);
        if (!$party) {
            throw new RuntimeException('Party not found.');
        }

        $useCurrency = $currency ?? $party['currency'];
        if (!empty($party['is_multi_currency'])) {
            if ($useCurrency === '') {
                throw new RuntimeException('Currency is required for this party.');
            }
            $useCurrency = $this->resolveCurrency($partyId, $useCurrency);
        } else {
            $useCurrency = $party['currency'];
        }

        $partyCurrencies = $this->partyCurrencies($partyId);
        $ledger = null;
        foreach ($partyCurrencies as $lc) {
            if (($lc['currency'] ?? '') === $useCurrency) {
                $ledger = $lc;
                break;
            }
        }
        $openingBalance = $this->getOpeningBalance($party, $useCurrency, $ledger ?: []);

        $stmt = $this->pdo->prepare(
            'SELECT invoice_period,
                 SUM(customer_invoice_value - vendor_invoice_value - payment_in + payment_out) AS movement
             FROM party_ledger_transactions
             WHERE party_account_id = :id AND currency = :cur AND deleted_at IS NULL
             GROUP BY invoice_period
             ORDER BY invoice_period ASC'
        );
        $stmt->execute([':id' => $partyId, ':cur' => $useCurrency]);
        $closings = $this->closingsCurrency($partyId, $useCurrency);
        $signedOpening = $this->signedOpeningBalanceValue($openingBalance['opening_balance'], $openingBalance['opening_balance_type']);
        $running = $signedOpening;
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $period = (string) $row['invoice_period'];
            $opening = $running;
            $running = round($running + (float) $row['movement'], 2);
            $rows[] = [
                'period_month' => $period,
                'opening_balance' => round($opening, 2),
                'closing_balance' => $running,
                'status' => $closings[$period]['status'] ?? 'open',
                'closed_at' => $closings[$period]['closed_at'] ?? null,
            ];
        }

        return $rows;
    }

    public function saveTransaction(array $payload, array $actor, ?string $expectedCurrency = null): int
    {
        $id = (int) ($payload['id'] ?? 0);
        $partyId = (int) ($payload['party_account_id'] ?? 0);
        $party = $this->party($partyId);
        if ($partyId <= 0 || !$party) {
            throw new RuntimeException('Invalid party.');
        }
        $currency = $this->resolveCurrency($partyId, (string) ($payload['currency'] ?? ''));
        if (!empty($party['is_multi_currency']) && ($expectedCurrency === null || $expectedCurrency === '')) {
            throw new RuntimeException('Currency is required for this ledger.');
        }
        if ($expectedCurrency !== null && $expectedCurrency !== '' && $currency !== $expectedCurrency) {
            throw new RuntimeException('Invalid currency for this ledger.');
        }
        $period = $this->period((string) ($payload['invoice_period'] ?? ''));
        if ($this->periodClosedCurrency($partyId, $currency, $period)) {
            throw new RuntimeException('This month is closed.');
        }

        $actorId = (int) ($actor['id'] ?? 0) ?: null;
        $row = [
            ':party_id' => $partyId,
            ':currency' => $currency,
            ':period' => $period,
            ':cin' => $this->shortText($payload['customer_invoice_no'] ?? null, 120),
            ':civ' => $this->money($payload['customer_invoice_value'] ?? 0),
            ':vin' => $this->shortText($payload['vendor_invoice_no'] ?? null, 120),
            ':viv' => $this->money($payload['vendor_invoice_value'] ?? 0),
            ':pin' => $this->money($payload['payment_in'] ?? 0),
            ':pout' => $this->money($payload['payment_out'] ?? 0),
            ':pin_date' => $this->dateOrNull((string) ($payload['payment_in_date'] ?? '')),
            ':pout_date' => $this->dateOrNull((string) ($payload['payment_out_date'] ?? '')),
            ':notes' => $this->shortText($payload['notes'] ?? null, 500),
            ':uid' => $actorId,
        ];

        $this->pdo->beginTransaction();
        try {
            if ($id > 0) {
                $old = $this->transaction($id);
                if (!$old || (int) $old['party_account_id'] !== $partyId) {
                    throw new RuntimeException('Transaction not found.');
                }
                if ($this->periodClosedCurrency($partyId, (string) $old['currency'], (string) $old['invoice_period'])) {
                    throw new RuntimeException('This month is closed.');
                }
                if ((string) $old['currency'] !== $currency) {
                    throw new RuntimeException('Cannot move transactions between currencies.');
                }
                $row[':id'] = $id;
                $this->pdo->prepare(
                    'UPDATE party_ledger_transactions SET
                        currency = :currency, invoice_period = :period,
                        customer_invoice_no = :cin, customer_invoice_value = :civ,
                        vendor_invoice_no = :vin, vendor_invoice_value = :viv,
                        payment_in = :pin, payment_out = :pout,
                        payment_in_date = :pin_date, payment_out_date = :pout_date,
                        notes = :notes,
                        updated_by = :uid, updated_at = NOW()
                     WHERE id = :id AND party_account_id = :party_id AND deleted_at IS NULL'
                )->execute($row);
                $action = 'ledger_updated';
            } else {
                $insertRow = $row;
                unset($insertRow[':uid']);
                $insertRow[':created_uid'] = $actorId;
                $insertRow[':updated_uid'] = $actorId;
                $this->pdo->prepare(
                    'INSERT INTO party_ledger_transactions (
                        party_account_id, currency, invoice_period, customer_invoice_no, customer_invoice_value,
                        vendor_invoice_no, vendor_invoice_value, payment_in, payment_out,
                        payment_in_date, payment_out_date, notes, created_by, updated_by
                    ) VALUES (
                        :party_id, :currency, :period, :cin, :civ, :vin, :viv, :pin, :pout,
                        :pin_date, :pout_date, :notes, :created_uid, :updated_uid
                    )'
                )->execute($insertRow);
                $id = (int) $this->pdo->lastInsertId();
                $action = 'ledger_created';
            }
            $this->log->log($partyId, $actor, $action, 'Ledger transaction #' . $id . ' saved', ['period' => $period, 'currency' => $currency]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $id;
    }

    public function closeMonth(int $partyId, string $currency, string $period, array $actor): void
    {
        $currency = $this->resolveCurrency($partyId, $currency);
        $period = $this->period($period);
        $summary = $this->monthBalanceCurrency($partyId, $currency, $period);
        $stmt = $this->pdo->prepare(
            'INSERT INTO party_ledger_monthly_closing (
                party_account_id, currency, period_month, opening_balance, closing_balance, closed_by, closed_at, status
             ) VALUES (
                :party_id, :currency, :period, :opening, :closing, :uid, NOW(), \'closed\'
             )
             ON DUPLICATE KEY UPDATE
                opening_balance = VALUES(opening_balance),
                closing_balance = VALUES(closing_balance),
                closed_by = VALUES(closed_by),
                closed_at = NOW(),
                reopened_by = NULL,
                reopened_at = NULL,
                status = \'closed\''
        );
        $stmt->execute([
            ':party_id' => $partyId,
            ':currency' => $currency,
            ':period' => $period,
            ':opening' => $summary['opening'],
            ':closing' => $summary['closing'],
            ':uid' => (int) ($actor['id'] ?? 0) ?: null,
        ]);
        $this->log->log($partyId, $actor, 'month_closed', 'Ledger month ' . $period . ' closed for ' . $currency, $summary);
    }

    public function reopenMonth(int $partyId, string $currency, string $period, array $actor): void
    {
        $currency = $this->resolveCurrency($partyId, $currency);
        $period = $this->period($period);
        $stmt = $this->pdo->prepare(
            'UPDATE party_ledger_monthly_closing
             SET status = \'reopened\', reopened_by = :uid, reopened_at = NOW(), updated_at = NOW()
             WHERE party_account_id = :party_id AND currency = :currency AND period_month = :period AND status = \'closed\''
        );
        $stmt->execute([
            ':uid' => (int) ($actor['id'] ?? 0) ?: null,
            ':party_id' => $partyId,
            ':currency' => $currency,
            ':period' => $period,
        ]);
        $this->log->log($partyId, $actor, 'month_reopened', 'Ledger month ' . $period . ' reopened for ' . $currency, []);
    }

    public function periodClosed(int $partyId, string $period): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM party_ledger_monthly_closing
             WHERE party_account_id = :party_id AND period_month = :period AND status = \'closed\'
             LIMIT 1'
        );
        $stmt->execute([':party_id' => $partyId, ':period' => $period]);

        return (bool) $stmt->fetchColumn();
    }

    public function periodClosedCurrency(int $partyId, string $currency, string $period): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM party_ledger_monthly_closing
             WHERE party_account_id = :party_id AND currency = :currency AND period_month = :period AND status = \'closed\'
             LIMIT 1'
        );
        $stmt->execute([':party_id' => $partyId, ':currency' => $currency, ':period' => $period]);

        return (bool) $stmt->fetchColumn();
    }

    private function transaction(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM party_ledger_transactions WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function deleteTransaction(int $id, array $actor, ?string $expectedCurrency = null): void
    {
        $tx = $this->transaction($id);
        if (!$tx) {
            throw new RuntimeException('Transaction not found.');
        }

        $partyId = (int) $tx['party_account_id'];
        $party = $this->party($partyId);
        if (!$party) {
            throw new RuntimeException('Invalid party.');
        }
        $currency = $this->resolveCurrency($partyId, (string) $tx['currency']);
        if ($expectedCurrency !== null && $expectedCurrency !== '' && $currency !== $expectedCurrency) {
            throw new RuntimeException('Invalid currency for this ledger.');
        }
        if ($this->periodClosedCurrency($partyId, $currency, (string) $tx['invoice_period'])) {
            throw new RuntimeException('This month is closed.');
        }

        $actorId = (int) ($actor['id'] ?? 0) ?: null;
        $this->pdo->prepare(
            'UPDATE party_ledger_transactions
             SET deleted_at = NOW(), updated_by = :uid, updated_at = NOW()
             WHERE id = :id AND party_account_id = :party_id AND deleted_at IS NULL'
        )->execute([
            ':id' => $id,
            ':party_id' => $partyId,
            ':uid' => $actorId,
        ]);

        $this->log->log($partyId, $actor, 'ledger_deleted', 'Ledger transaction #' . $id . ' deleted', [
            'period' => $tx['invoice_period'],
            'currency' => $currency,
        ]);
    }

    private function getOpeningBalance(array $party, string $currency, array $ledger): array
    {
        if (!empty($party['is_multi_currency']) && party_account_table_exists($this->pdo, 'party_currency_ledgers')) {
            return [
                'opening_balance' => (float) ($ledger['opening_balance'] ?? 0),
                'opening_balance_type' => $ledger['opening_balance_type'] ?? null,
            ];
        }

        return ['opening_balance' => (float) ($party['opening_balance'] ?? 0), 'opening_balance_type' => $party['opening_balance_type'] ?? null];
    }

    private function resolveCurrency(int $partyId, string $currency): string
    {
        $party = $this->party($partyId);
        if (!$party) {
            throw new RuntimeException('Party not found.');
        }

        if (!empty($party['is_multi_currency'])) {
            if ($currency === '') {
                throw new RuntimeException('Currency is required for this party.');
            }
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM party_currency_ledgers
                 WHERE party_account_id = :party_id AND currency = :currency AND deleted_at IS NULL
                 LIMIT 1'
            );
            $stmt->execute([':party_id' => $partyId, ':currency' => $currency]);
            if (!$stmt->fetchColumn()) {
                throw new RuntimeException('Invalid currency for this party.');
            }
            return $currency;
        }

        return $party['currency'];
    }

    private function signedOpeningBalanceValue($amount, $type): float
    {
        $amt = round((float) ($amount ?? 0), 2);
        return ($type ?? '') === 'payable' ? -$amt : $amt;
    }

    private function openingBeforeCurrency(int $partyId, string $currency, ?string $from, array $openingBalance): float
    {
        $signedOpening = $this->signedOpeningBalanceValue($openingBalance['opening_balance'], $openingBalance['opening_balance_type']);
        if ($from === null) {
            return $signedOpening;
        }
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(customer_invoice_value - vendor_invoice_value - payment_in + payment_out), 0)
             FROM party_ledger_transactions
             WHERE party_account_id = :party_id AND currency = :currency AND deleted_at IS NULL AND invoice_period < :from_date'
        );
        $stmt->execute([':party_id' => $partyId, ':currency' => $currency, ':from_date' => $this->periodFromDateString($from)]);

        return round($signedOpening + (float) $stmt->fetchColumn(), 2);
    }

    private function currencyLedgerStats(int $partyId, string $currency, ?string $from, ?string $to): array
    {
        $params = [':party_id' => $partyId, ':currency' => $currency];
        $where = ['party_account_id = :party_id', 'currency = :currency', 'deleted_at IS NULL'];
        if ($from !== null) {
            $where[] = 'invoice_period >= :from_date';
            $params[':from_date'] = $this->periodFromDateString($from);
        }
        if ($to !== null) {
            $where[] = 'invoice_period <= :to_date';
            $params[':to_date'] = $this->periodFromDateString($to);
        }

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) AS transaction_count,
                    MAX(invoice_period) AS last_activity,
                    COALESCE(SUM(customer_invoice_value - vendor_invoice_value - payment_in + payment_out), 0) AS movement
             FROM party_ledger_transactions
             WHERE ' . implode(' AND ', $where)
        );
        $stmt->execute($params);

        return $stmt->fetch() ?: [
            'transaction_count' => 0,
            'last_activity' => null,
            'movement' => 0,
        ];
    }

    private function closingsCurrency(int $partyId, string $currency): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM party_ledger_monthly_closing WHERE party_account_id = :id AND currency = :currency');
        $stmt->execute([':id' => $partyId, ':currency' => $currency]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[(string) $row['period_month']] = $row;
        }

        return $rows;
    }

    private function monthBalanceCurrency(int $partyId, string $currency, string $period): array
    {
        $party = $this->party($partyId);
        $partyCurrencies = $this->partyCurrencies($partyId);
        $ledger = null;
        foreach ($partyCurrencies as $lc) {
            if (($lc['currency'] ?? '') === $currency) {
                $ledger = $lc;
                break;
            }
        }
        $openingBalance = $this->getOpeningBalance($party ?: [], $currency, $ledger ?: []);
        $signedOpening = $this->signedOpeningBalanceValue($openingBalance['opening_balance'], $openingBalance['opening_balance_type']);
        $opening = $this->openingBeforeCurrency($partyId, $currency, $period . '-01', $openingBalance);
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(customer_invoice_value - vendor_invoice_value - payment_in + payment_out), 0)
             FROM party_ledger_transactions
             WHERE party_account_id = :party_id AND currency = :currency AND invoice_period = :period AND deleted_at IS NULL'
        );
        $stmt->execute([':party_id' => $partyId, ':currency' => $currency, ':period' => $period]);
        $movement = (float) $stmt->fetchColumn();

        return ['opening' => round($opening, 2), 'closing' => round($opening + $movement, 2)];
    }

    private function signedOpeningBalance(array $party): float
    {
        $amount = round((float) ($party['opening_balance'] ?? 0), 2);

        return ($party['opening_balance_type'] ?? '') === 'payable' ? -$amount : $amount;
    }

    private function openingBefore(int $partyId, ?string $from, float $base): float
    {
        if ($from === null) {
            return $base;
        }
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(customer_invoice_value - vendor_invoice_value - payment_in + payment_out), 0)
             FROM party_ledger_transactions
             WHERE party_account_id = :party_id AND deleted_at IS NULL AND invoice_period < :from_date'
        );
        $stmt->execute([':party_id' => $partyId, ':from_date' => $this->periodFromDateString($from)]);

        return round($base + (float) $stmt->fetchColumn(), 2);
    }

    private function closings(int $partyId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM party_ledger_monthly_closing WHERE party_account_id = :id');
        $stmt->execute([':id' => $partyId]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[(string) $row['period_month']] = $row;
        }

        return $rows;
    }

    private function period(string $value): string
    {
        $value = trim($value) !== '' ? trim($value) : date('Y-m');
        if (!preg_match('/^\d{4}-\d{2}$/', $value)) {
            throw new RuntimeException('Invalid invoice period.');
        }

        return $value;
    }

    private function dateOrNull(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new RuntimeException('Invalid date.');
        }

        return $value;
    }

    private function periodFromDateString(string $date): string
    {
        return substr($date, 0, 7);
    }

    private function money(mixed $value): float
    {
        $value = str_replace(',', '', trim((string) $value));
        if ($value === '') {
            return 0.0;
        }
        if (!is_numeric($value) || (float) $value < 0) {
            throw new RuntimeException('Amounts must be positive numbers.');
        }

        return round((float) $value, 2);
    }

    private function shortText(mixed $value, int $max): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
