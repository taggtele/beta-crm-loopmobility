<?php

declare(strict_types=1);

/**
 * Data access for `party_accounts` with composable filters.
 */
final class PartyAccountRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Aggregated counters for KPI strip (same predicates as listings).
     * @param array<string, mixed> $filters
     */
    public function summarize(array $filters): array
    {
        [$whereClause, $params] = $this->buildFilters($filters);
        $whereSql = $whereClause !== '' ? 'WHERE ' . $whereClause : '';
        $ledgerJoin = '';
        $ledgerMovementSql = '0';
        if (party_account_table_exists($this->pdo, 'party_ledger_transactions')) {
            $ledgerJoin = '
            LEFT JOIN (
                 SELECT party_account_id,
                     SUM(customer_invoice_value - vendor_invoice_value - payment_in + payment_out) AS movement
                 FROM party_ledger_transactions
                 WHERE deleted_at IS NULL
                 GROUP BY party_account_id
            ) ledger_net ON ledger_net.party_account_id = pa.id';
            $ledgerMovementSql = 'COALESCE(ledger_net.movement, 0)';
        }

        $sql = '
            SELECT COUNT(*) AS total_filtered,
                COALESCE(SUM(CASE WHEN pa.deleted_at IS NULL THEN
                    (CASE
                        WHEN pa.opening_balance_type = \'payable\' THEN -COALESCE(pa.opening_balance, 0)
                        ELSE COALESCE(pa.opening_balance, 0)
                    END) + ' . $ledgerMovementSql . '
                ELSE 0 END), 0) AS company_net_total
            FROM party_accounts pa
            LEFT JOIN loop_entities le ON le.id = pa.loop_entity_id
            ' . $ledgerJoin . '
            ' . $whereSql;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $aggregate = $stmt->fetch() ?: [];

        $stmt2 = $this->pdo->prepare(
            'SELECT pa.status AS st, COUNT(*) AS qty
             FROM party_accounts pa
             LEFT JOIN loop_entities le ON le.id = pa.loop_entity_id
             ' . $whereSql . '
             GROUP BY pa.status'
        );
        $stmt2->execute($params);
        $matrix = [];
        foreach ($stmt2->fetchAll() as $chunk) {
            $matrix[(string) $chunk['st']] = (int) $chunk['qty'];
        }

        return [
            'total_rows' => (int) ($aggregate['total_filtered'] ?? 0),
            'company_net_total' => (string) ($aggregate['company_net_total'] ?? '0'),
            'by_status' => $matrix,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    public function paginate(array $filters, string $sortKey, string $sortDir, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(10, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        [$whereClause, $params] = $this->buildFilters($filters);
        $whereSql = $whereClause !== '' ? 'WHERE ' . $whereClause : '';

        $orderBy = $this->orderByClause($sortKey, $sortDir);

        $countSql = 'SELECT COUNT(*) FROM party_accounts pa LEFT JOIN loop_entities le ON le.id = pa.loop_entity_id ' . $whereSql;
        $cStmt = $this->pdo->prepare($countSql);
        $cStmt->execute($params);
        $total = (int) $cStmt->fetchColumn();

        $extraEmailSql = party_account_table_exists($this->pdo, 'party_account_emails')
            ? ', (SELECT COUNT(*) FROM party_account_emails pae
                WHERE pae.party_account_id = pa.id AND pae.is_primary = 0) AS extra_email_count'
            : ', 0 AS extra_email_count';

        $sql = '
            SELECT pa.*, le.name AS loop_entity_name' . $extraEmailSql . '
            FROM party_accounts pa
            LEFT JOIN loop_entities le ON le.id = pa.loop_entity_id
            ' . $whereSql . '
            ORDER BY ' . $orderBy . '
            LIMIT ' . $offset . ', ' . $perPage;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return [$stmt->fetchAll(), $total];
    }

    public function findById(int $id, bool $includeDeleted): ?array
    {
        $sql = '
            SELECT pa.*, le.name AS loop_entity_name
            FROM party_accounts pa
            LEFT JOIN loop_entities le ON le.id = pa.loop_entity_id
            WHERE pa.id = :id
        ';
        if (!$includeDeleted) {
            $sql .= ' AND pa.deleted_at IS NULL';
        }
        $stmt = $this->pdo->prepare($sql . ' LIMIT 1');
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** Duplicate check on normalized email */
    public function emailTakenByAnother(string $normalizedEmail, ?int $exceptId): bool
    {
        if ($normalizedEmail === '') {
            return false;
        }

        if (!party_account_table_exists($this->pdo, 'party_account_emails')) {
            $sql = '
                SELECT id FROM party_accounts
                WHERE LOWER(TRIM(party_email)) = :email';
            $params = [':email' => $normalizedEmail];
            if ($exceptId !== null) {
                $sql .= ' AND id != :eid';
                $params[':eid'] = $exceptId;
            }
            $stmt = $this->pdo->prepare($sql . ' LIMIT 1');
            $stmt->execute($params);

            return (bool) $stmt->fetchColumn();
        }

        $sql = '
            SELECT pa.id FROM party_accounts pa
            LEFT JOIN party_account_emails pae
                ON pae.party_account_id = pa.id AND LOWER(TRIM(pae.email)) = :email_join
            WHERE (
                LOWER(TRIM(pa.party_email)) = :email_col
                OR pae.id IS NOT NULL
            )';

        $params = [
            ':email_join' => $normalizedEmail,
            ':email_col' => $normalizedEmail,
        ];
        if ($exceptId !== null) {
            $sql .= ' AND pa.id != :eid';
            $params[':eid'] = $exceptId;
        }

        $stmt = $this->pdo->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);

        return (bool) $stmt->fetchColumn();
    }

    /** @param array<string, mixed> $data */
    public function insert(array $data): int
    {
        $sql = '
            INSERT INTO party_accounts (
                party_name, party_email, party_phone, address, country, bank_name,
                account_holder_name, account_number, ifsc_swift_code, iban_number, bank_branch_address,
                credit_limit, currency, opening_balance, opening_balance_type, payment_terms, loop_entity_id,
                assistant_manager_name, business_manager_name, notes,
                is_multi_currency, status, created_by, updated_by, created_at, updated_at, deleted_at
            ) VALUES (
                :party_name, :party_email, :party_phone, :address, :country, :bank_name,
                :account_holder_name, :account_number, :ifsc_swift_code, :iban_number, :bank_branch_address,
                :credit_limit, :currency, :opening_balance, :opening_balance_type, :payment_terms, :loop_entity_id,
                :assistant_manager_name, :business_manager_name, :notes,
                :is_multi_currency, :status, :created_by, :updated_by, NOW(), NOW(), NULL
            )';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindableRow($data));

        return (int) $this->pdo->lastInsertId();
    }

    public function insertCurrencyLedger(int $partyId, array $ledger): int
    {
        $sql = '
            INSERT INTO party_currency_ledgers (
                party_account_id, currency, opening_balance, opening_balance_type
            ) VALUES (
                :party_id, :currency, :opening_balance, :opening_balance_type
            )
            ON DUPLICATE KEY UPDATE
                opening_balance = VALUES(opening_balance),
                opening_balance_type = VALUES(opening_balance_type),
                deleted_at = NULL,
                status = \'active\',
                updated_at = NOW()';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':party_id' => $partyId,
            ':currency' => $ledger['currency'],
            ':opening_balance' => $ledger['opening_balance'],
            ':opening_balance_type' => $ledger['opening_balance_type'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findCurrencyLedgers(int $partyId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, currency, opening_balance, opening_balance_type, status
             FROM party_currency_ledgers
             WHERE party_account_id = :party_id AND deleted_at IS NULL
             ORDER BY currency ASC'
        );
        $stmt->execute([':party_id' => $partyId]);
        return $stmt->fetchAll();
    }

    public function findCurrencyLedger(int $partyId, string $currency): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, currency, opening_balance, opening_balance_type, status
             FROM party_currency_ledgers
             WHERE party_account_id = :party_id AND currency = :currency AND deleted_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([':party_id' => $partyId, ':currency' => $currency]);
        return $stmt->fetch() ?: null;
    }

    public function updateCurrencyLedger(int $partyId, array $ledger): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE party_currency_ledgers
             SET opening_balance = :opening_balance,
                 opening_balance_type = :opening_balance_type,
                 updated_at = NOW()
             WHERE party_account_id = :party_id AND currency = :currency AND deleted_at IS NULL'
        );

        return $stmt->execute([
            ':party_id' => $partyId,
            ':currency' => $ledger['currency'],
            ':opening_balance' => $ledger['opening_balance'],
            ':opening_balance_type' => $ledger['opening_balance_type'],
        ]);
    }

    public function softDeleteCurrencyLedger(int $partyId, string $currency): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE party_currency_ledgers
             SET deleted_at = NOW(), status = \'inactive\', updated_at = NOW()
             WHERE party_account_id = :party_id AND currency = :currency AND deleted_at IS NULL'
        );

        return $stmt->execute([
            ':party_id' => $partyId,
            ':currency' => $currency,
        ]);
    }

    public function hasCurrencyLedgers(int $partyId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM party_currency_ledgers
             WHERE party_account_id = :party_id AND deleted_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([':party_id' => $partyId]);
        return (bool) $stmt->fetchColumn();
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): bool
    {
        $sql = '
            UPDATE party_accounts SET
                party_name = :party_name,
                party_email = :party_email,
                party_phone = :party_phone,
                address = :address,
                country = :country,
                bank_name = :bank_name,
                account_holder_name = :account_holder_name,
                account_number = :account_number,
                ifsc_swift_code = :ifsc_swift_code,
                iban_number = :iban_number,
                bank_branch_address = :bank_branch_address,
                credit_limit = :credit_limit,
                currency = :currency,
                opening_balance = :opening_balance,
                opening_balance_type = :opening_balance_type,
                payment_terms = :payment_terms,
                loop_entity_id = :loop_entity_id,
                assistant_manager_name = :assistant_manager_name,
                business_manager_name = :business_manager_name,
                notes = :notes,
                is_multi_currency = :is_multi_currency,
                status = :status,
                updated_by = :updated_by,
                updated_at = NOW()
            WHERE id = :id AND deleted_at IS NULL';

        $row = $this->bindableRow($data);
        unset($row[':created_by']);
        $row[':id'] = $id;

        $stmt = $this->pdo->prepare($sql);

        return $stmt->execute($row);
    }

    public function softDelete(int $id, int $updatedByUserPk): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE party_accounts SET deleted_at = NOW(), updated_by = :u, updated_at = NOW(), status = :st
             WHERE id = :id AND deleted_at IS NULL'
        );

        $stmt->execute([
            ':u' => $updatedByUserPk,
            ':id' => $id,
            ':st' => 'archived',
        ]);

        return $stmt->rowCount() > 0;
    }

    public function restore(int $id, int $updatedByUserPk): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE party_accounts SET deleted_at = NULL, updated_by = :u, updated_at = NOW(), status = :st
             WHERE id = :id AND deleted_at IS NOT NULL'
        );

        $stmt->execute([
            ':u' => $updatedByUserPk,
            ':id' => $id,
            ':st' => 'active',
        ]);

        return $stmt->rowCount() > 0;
    }

    /** @param int[] $ids */
    public function bulkSoftDelete(array $ids, int $updatedByUserPk): int
    {
        return $this->bulkFlagDeleted($ids, $updatedByUserPk, true);
    }

    /** @param int[] $ids */
    public function bulkRestore(array $ids, int $updatedByUserPk): int
    {
        return $this->bulkFlagDeleted($ids, $updatedByUserPk, false);
    }

    /** @param array<string, mixed> $filters Export uses same predicates as listings (no paging). */
    public function iterateForExport(array $filters): iterable
    {
        [$whereClause, $params] = $this->buildFilters($filters);
        $whereSql = $whereClause !== '' ? 'WHERE ' . $whereClause : '';

        $sql = '
            SELECT pa.*, le.name AS loop_entity_name
            FROM party_accounts pa
            LEFT JOIN loop_entities le ON le.id = pa.loop_entity_id
            ' . $whereSql . '
            ORDER BY pa.party_name ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        while ($row = $stmt->fetch()) {
            yield $row;
        }
    }

    /** @return array<string, scalar|null> keyed for PDO bind */
    private function bindableRow(array $normalized): array
    {
        $credit = $normalized['credit_limit'] ?? null;
        if ($credit === '' || $credit === null) {
            $creditValue = null;
        } else {
            $creditValue = round((float) $credit, 2);
        }

        $opening = $normalized['opening_balance'] ?? null;
        if ($opening === '' || $opening === null) {
            $openingValue = null;
        } else {
            $openingValue = round((float) $opening, 2);
        }

        $openingType = trim((string) ($normalized['opening_balance_type'] ?? ''));
        $openingTypeValue = null;
        if ($openingValue !== null && in_array($openingType, party_account_opening_balance_types(), true)) {
            $openingTypeValue = $openingType;
        }

        return [
            ':party_name' => $normalized['party_name'],
            ':party_email' => $normalized['party_email'] === '' ? null : $normalized['party_email'],
            ':party_phone' => $normalized['party_phone'] === '' ? null : $normalized['party_phone'],
            ':address' => $normalized['address'] === '' ? null : $normalized['address'],
            ':country' => $normalized['country'] === '' ? null : $normalized['country'],
            ':bank_name' => $normalized['bank_name'] === '' ? null : $normalized['bank_name'],
            ':account_holder_name' => $normalized['account_holder_name'] === '' ? null : $normalized['account_holder_name'],
            ':account_number' => $normalized['account_number'] === '' ? null : $normalized['account_number'],
            ':ifsc_swift_code' => $normalized['ifsc_swift_code'] === '' ? null : $normalized['ifsc_swift_code'],
            ':iban_number' => $normalized['iban_number'] === '' ? null : $normalized['iban_number'],
            ':bank_branch_address' => $normalized['bank_branch_address'] === '' ? null : $normalized['bank_branch_address'],
            ':credit_limit' => $creditValue,
            ':currency' => $normalized['currency'] !== '' ? $normalized['currency'] : 'INR',
            ':opening_balance' => $openingValue,
            ':opening_balance_type' => $openingTypeValue,
            ':payment_terms' => $normalized['payment_terms'] === '' ? null : $normalized['payment_terms'],
            ':loop_entity_id' => $normalized['loop_entity_id'],
            ':assistant_manager_name' => $normalized['assistant_manager_name'] === ''
                ? null
                : $normalized['assistant_manager_name'],
            ':business_manager_name' => $normalized['business_manager_name'] === ''
                ? null
                : $normalized['business_manager_name'],
            ':notes' => $normalized['notes'] === '' ? null : $normalized['notes'],
            ':status' => $normalized['status'],
            ':created_by' => $normalized['created_by_pk'] ?? null,
            ':updated_by' => $normalized['updated_by_pk'] ?? null,
            ':is_multi_currency' => !empty($normalized['is_multi_currency']) ? 1 : 0,
        ];
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function buildFilters(array $filters): array
    {
        $chunks = [];
        $params = [];

        $scope = strtolower((string) ($filters['scope'] ?? 'live'));
        if ($scope === 'live') {
            $chunks[] = 'pa.deleted_at IS NULL';
        } elseif ($scope === 'deleted' || $scope === 'archived') {
            $chunks[] = 'pa.deleted_at IS NOT NULL';
        }
        // scope=all retains both live + archived collections

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $searchLike = '%' . $search . '%';
            $extraSearch = party_account_table_exists($this->pdo, 'party_account_emails')
                ? ' OR EXISTS (
                    SELECT 1 FROM party_account_emails pae_s
                    WHERE pae_s.party_account_id = pa.id AND pae_s.email LIKE :s_extra_email
                )'
                : '';
            $chunks[] = '(
                pa.party_name LIKE :s_party_name OR pa.party_email LIKE :s_party_email OR pa.party_phone LIKE :s_party_phone
                OR pa.bank_name LIKE :s_bank_name OR pa.account_holder_name LIKE :s_account_holder OR pa.country LIKE :s_country
                OR pa.currency LIKE :s_currency OR CAST(pa.id AS CHAR) LIKE :s_id' . $extraSearch . '
            )';
            $params[':s_party_name'] = $searchLike;
            $params[':s_party_email'] = $searchLike;
            $params[':s_party_phone'] = $searchLike;
            $params[':s_bank_name'] = $searchLike;
            $params[':s_account_holder'] = $searchLike;
            $params[':s_country'] = $searchLike;
            $params[':s_currency'] = $searchLike;
            $params[':s_id'] = $searchLike;
            if ($extraSearch !== '') {
                $params[':s_extra_email'] = $searchLike;
            }
        }

        $statusFilter = isset($filters['status']) ? trim((string) $filters['status']) : '';
        if ($statusFilter !== '' && in_array($statusFilter, party_account_statuses(), true)) {
            $chunks[] = 'pa.status = :stfil';
            $params[':stfil'] = $statusFilter;
        }

        foreach (['country' => ':country_fil', 'currency' => ':cur_fil'] as $field => $key) {
            $v = isset($filters[$field]) ? trim((string) $filters[$field]) : '';
            if ($v !== '') {
                $chunks[] = 'pa.' . $field . ' = ' . $key;
                $params[$key] = $v;
            }
        }

        $leId = $filters['loop_entity_id'] ?? '';
        if ($leId !== '' && $leId !== null) {
            $chunks[] = 'pa.loop_entity_id = :loid';
            $params[':loid'] = (int) $leId;
        }

        $from = isset($filters['created_from']) ? trim((string) $filters['created_from']) : '';
        $to = isset($filters['created_to']) ? trim((string) $filters['created_to']) : '';

        if ($from !== '') {
            $chunks[] = 'DATE(pa.created_at) >= :cfrom';
            $params[':cfrom'] = $from;
        }
        if ($to !== '') {
            $chunks[] = 'DATE(pa.created_at) <= :cto';
            $params[':cto'] = $to;
        }

        return [implode(' AND ', $chunks), $params];
    }

    private function orderByClause(string $sortKey, string $sortDir): string
    {
        $dir = strtolower($sortDir) === 'asc' ? 'ASC' : 'DESC';

        return match ($sortKey) {
            'party_name', 'party_email', 'country', 'currency', 'status', 'credit_limit',
            'created_at', 'updated_at' => 'pa.' . $sortKey . ' ' . $dir,
            default => 'pa.updated_at DESC',
        };
    }

    /** @param int[] $ids */
    private function bulkFlagDeleted(array $ids, int $updatedByUserPk, bool $delete): int
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn ($v) => (int) $v, $ids))));
        if ($ids === []) {
            return 0;
        }

        // chunk to avoid gigantic IN()
        $affected = 0;
        foreach (array_chunk($ids, 200) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $params = array_merge([$updatedByUserPk], $chunk);
            if ($delete) {
                $sql = "UPDATE party_accounts SET deleted_at = NOW(), status = 'archived', updated_by = ?, updated_at = NOW()
                        WHERE deleted_at IS NULL AND id IN ($placeholders)";
            } else {
                $sql = "UPDATE party_accounts SET deleted_at = NULL, status = 'active', updated_by = ?, updated_at = NOW()
                        WHERE deleted_at IS NOT NULL AND id IN ($placeholders)";
            }
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $affected += $stmt->rowCount();
        }

        return $affected;
    }
}
