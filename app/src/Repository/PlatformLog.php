<?php

declare(strict_types=1);

namespace Belprise\Repository;

use PDO;

/**
 * Journal of the platform (downgrades, deletion requests, deleted
 * organizations). Unlike audit_log it outlives the organization: an
 * organization deleted for good leaves its name and the date here, never
 * its content.
 */
final class PlatformLog
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function add(?int $organizationId, string $organizationName, string $actor, string $action, string $details = ''): void
    {
        $this->db->prepare('INSERT INTO platform_log (organization_id, organization_name, actor, action, details) VALUES (?, ?, ?, ?, ?)')
            ->execute([$organizationId, $organizationName, $actor, $action, $details]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function latest(int $limit = 50): array
    {
        return $this->db->query('SELECT * FROM platform_log ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit))->fetchAll();
    }
}
