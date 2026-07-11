<?php

declare(strict_types=1);

/**
 * Business rules around Loop Entity master data CRUD used by dropdowns & filters.
 */
final class LoopEntityService
{
    public function __construct(
        private LoopEntityRepository $repo,
        private PartyAccountActivityLogService $audit
    ) {
    }

    /**
     * @return int New loop entity id
     * @throws RuntimeException on validation issues
     */
    public function create(array $payload, array $actor): int
    {
        $name = trim((string) ($payload['name'] ?? ''));

        if ($name === '' || mb_strlen($name) > 180) {
            throw new RuntimeException(json_encode(['errors' => ['name' => 'Valid name is required.'], ]));
        }

        $code = isset($payload['code']) ? trim((string) $payload['code']) : null;

        $status = (string) ($payload['status'] ?? 'active');
        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = 'active';
        }

        $sort = (int) ($payload['sort_order'] ?? 0);

        $byPk = (int) ($actor['id'] ?? 0) ?: null;

        $id = $this->repo->insert($name, $code ?: null, $status, $sort, $byPk);

        $this->audit->log(null, $actor, 'loop_entity.created', 'Loop entity #' . $id . ' created', compact('name', 'status'));

        return $id;
    }

    /** @throws RuntimeException when invalid */
    public function update(array $payload, array $actor): void
    {
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException(json_encode(['errors' => ['id' => 'Invalid identifier.'], ]));
        }

        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 180) {
            throw new RuntimeException(json_encode(['errors' => ['name' => 'Valid name is required.'], ]));
        }

        $code = isset($payload['code']) ? trim((string) $payload['code']) : '';
        $status = (string) ($payload['status'] ?? 'active');
        $sort = (int) ($payload['sort_order'] ?? 0);
        $byPk = (int) ($actor['id'] ?? 0) ?: null;

        $this->repo->update($id, $name, $code !== '' ? $code : null, $status, $sort, $byPk);

        $this->audit->log(null, $actor, 'loop_entity.updated', 'Loop entity #' . $id . ' updated', ['name' => $name]);
    }

    public function delete(int $id, array $actor): void
    {
        $byPk = (int) ($actor['id'] ?? 0) ?: null;
        $this->repo->softDelete($id, $byPk);
        $this->audit->log(null, $actor, 'loop_entity.deleted', 'Loop entity #' . $id . ' archived', []);
    }

    public function restore(int $id, array $actor): void
    {
        $byPk = (int) ($actor['id'] ?? 0) ?: null;
        $this->repo->restore($id, $byPk);
        $this->audit->log(null, $actor, 'loop_entity.restored', 'Loop entity #' . $id . ' restored', []);
    }
}
