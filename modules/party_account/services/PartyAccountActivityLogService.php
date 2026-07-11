<?php

declare(strict_types=1);

/**
 * Persist append-only-ish audit trails for regulator-friendly traceability.
 */
final class PartyAccountActivityLogService
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @param array<string, mixed>|null $meta */
    public function log(
        ?int $partyAccountId,
        array $actor,
        string $action,
        string $summary,
        ?array $meta = null
    ): void {
        $payload = json_encode($meta ?? [], JSON_UNESCAPED_SLASHES);
        $stmt = $this->pdo->prepare(
            'INSERT INTO party_account_activity_logs (
                party_account_id, actor_user_id, actor_name, action, summary, metadata, ip_address, user_agent, created_at
            ) VALUES (
                :paid, :uid, :un, :act, :sum, :meta, :ip, :ua, NOW()
            )'
        );

        $stmt->execute([
            ':paid' => $partyAccountId,
            ':uid' => $actor['user_id'] ?? null,
            ':un' => $actor['name'] ?? '',
            ':act' => $action,
            ':sum' => mb_substr($summary, 0, 499),
            ':meta' => $payload ?: '{}',
            ':ip' => party_account_client_ip(),
            ':ua' => isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 253) : null,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function forParty(int $partyAccountId, int $limit = 80): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT action, actor_name, actor_user_id, summary, created_at FROM party_account_activity_logs
             WHERE party_account_id = :id
             ORDER BY id DESC LIMIT ' . max(10, min(200, $limit))
        );

        $stmt->execute([':id' => $partyAccountId]);

        return $stmt->fetchAll();
    }
}
