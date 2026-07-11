<?php

declare(strict_types=1);

/**
 * Lightweight CRUD helpers for dropdown master `loop_entities`.
 */
final class LoopEntityRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function listActive(): array
    {
        $sql = '
            SELECT id, name, code, status
            FROM loop_entities
            WHERE deleted_at IS NULL
            ORDER BY sort_order ASC, name ASC';

        return $this->pdo->query($sql)->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, code, status FROM loop_entities WHERE id = :id AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @param array<string, mixed> $filters */
    public function listAllIncludingDeleted(array $filters = [], int $page = 1, int $perPage = 80): array
    {
        $page = max(1, $page);
        $perPage = max(10, min(120, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $params = [];

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(name LIKE :s OR code LIKE :s)';
            $params[':s'] = '%' . $search . '%';
        }

        $showDeleted = !empty($filters['include_deleted']);
        if (!$showDeleted) {
            $where[] = 'deleted_at IS NULL';
        }

        $whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM loop_entities ' . $whereSql);
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $sql = 'SELECT * FROM loop_entities ' . $whereSql . ' ORDER BY sort_order ASC, name ASC LIMIT ' . $offset . ', ' . $perPage;

        $stmt2 = $this->pdo->prepare($sql);
        $stmt2->execute($params);

        return [$stmt2->fetchAll(), $total];
    }

    public function insert(string $name, ?string $code, string $status, int $sortOrder, ?int $byUserPk): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO loop_entities (name, code, status, sort_order, created_by, updated_by, created_at, updated_at)
             VALUES (:n, :c, :st, :so, :cb, :ub, NOW(), NOW())'
        );

        $stmt->execute([
            ':n' => $name,
            ':c' => ($code ?? '') !== '' ? $code : null,
            ':st' => $status,
            ':so' => $sortOrder,
            ':cb' => $byUserPk,
            ':ub' => $byUserPk,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $name, ?string $code, string $status, int $sortOrder, ?int $byUserPk): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE loop_entities SET name = :n, code = :c, status = :st, sort_order = :so, updated_by = :ub, updated_at = NOW()
             WHERE id = :id AND deleted_at IS NULL'
        );

        return $stmt->execute([
            ':n' => $name,
            ':c' => ($code ?? '') !== '' ? $code : null,
            ':st' => $status,
            ':so' => $sortOrder,
            ':ub' => $byUserPk,
            ':id' => $id,
        ]);
    }

    public function softDelete(int $id, ?int $byUserPk): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE loop_entities SET deleted_at = NOW(), updated_by = :ub, updated_at = NOW(), status = :st
             WHERE id = :id AND deleted_at IS NULL'
        );

        return $stmt->execute([
            ':ub' => $byUserPk,
            ':id' => $id,
            ':st' => 'inactive',
        ]);
    }

    public function restore(int $id, ?int $byUserPk): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE loop_entities SET deleted_at = NULL, updated_by = :ub, updated_at = NOW()
             WHERE id = :id AND deleted_at IS NOT NULL'
        );

        return $stmt->execute([':ub' => $byUserPk, ':id' => $id]);
    }
}
