<?php

declare(strict_types=1);

namespace Belprise\Repository;

use PDO;

/** Actions of the platform operator on an organization, shown to its administrators (§5.11). */
final class AuditLog
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function add(int $organizationId, string $actor, string $action, string $details = ''): void
    {
        $this->db->prepare('INSERT INTO audit_log (organization_id, actor, action, details) VALUES (?, ?, ?, ?)')
            ->execute([$organizationId, $actor, $action, $details]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forOrganization(int $organizationId, int $limit = 100): array
    {
        $stmt = $this->db->prepare('SELECT * FROM audit_log WHERE organization_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . $limit);
        $stmt->execute([$organizationId]);

        return $stmt->fetchAll();
    }
}
