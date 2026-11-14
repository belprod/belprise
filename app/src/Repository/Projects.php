<?php

declare(strict_types=1);

namespace Belprise\Repository;

use PDO;

final class Projects
{
    private const SELECT = 'SELECT p.*, c.name AS client_name, c.archived_at AS client_archived_at,
            (SELECT COUNT(*) FROM videos v WHERE v.project_id = p.id) AS video_count
        FROM projects p JOIN clients c ON c.id = p.client_id';

    /** @param int|null $org organization of the signed-in user: every query is limited to it (null: no limit, public pages) */
    public function __construct(private readonly PDO $db, private readonly ?int $org = null)
    {
    }

    private function scope(): string
    {
        return $this->org === null ? '' : ' AND c.organization_id = ' . $this->org;
    }

    /**
     * Archived projects, and projects of archived clients, are listed only on request.
     *
     * @return list<array<string, mixed>>
     */
    public function list(?int $clientId, bool $includeArchived): array
    {
        $stmt = $this->db->prepare(self::SELECT . '
            WHERE (:client IS NULL OR p.client_id = :client)' . $this->scope()
            . ($includeArchived ? '' : ' AND p.archived_at IS NULL AND c.archived_at IS NULL') . '
            ORDER BY (p.archived_at IS NOT NULL OR c.archived_at IS NOT NULL), p.created_at DESC, p.id DESC');
        $stmt->execute(['client' => $clientId]);

        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare(self::SELECT . ' WHERE p.id = ?' . $this->scope());
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function create(int $clientId, string $name): int
    {
        $this->db->prepare('INSERT INTO projects (client_id, name) VALUES (?, ?)')->execute([$clientId, $name]);

        return (int) $this->db->lastInsertId();
    }

    public function rename(int $id, string $name): void
    {
        $this->db->prepare("UPDATE projects SET name = ?, updated_at = datetime('now') WHERE id = ?")->execute([$name, $id]);
    }

    public function setArchived(int $id, bool $archived): void
    {
        $value = $archived ? "COALESCE(archived_at, datetime('now'))" : 'NULL';
        $this->db->prepare('UPDATE projects SET archived_at = ' . $value . ", updated_at = datetime('now') WHERE id = ?")
            ->execute([$id]);
    }

    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM projects WHERE id = ?')->execute([$id]);
    }

    /**
     * @return list<int>
     */
    public function idsForClient(int $clientId): array
    {
        $stmt = $this->db->prepare('SELECT id FROM projects WHERE client_id = ?');
        $stmt->execute([$clientId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
