<?php

declare(strict_types=1);

namespace Belprise\Repository;

use PDO;

final class Videos
{
    public const FIRST_NUMBER = 1001;

    private const SELECT = "SELECT v.*, p.name AS project_name, p.client_id, c.name AS client_name,
            p.archived_at AS project_archived_at, c.archived_at AS client_archived_at,
            (SELECT group_concat(t.name, char(31)) FROM video_tags vt JOIN tags t ON t.id = vt.tag_id
                WHERE vt.video_id = v.id) AS tag_names,
            (SELECT ve.status FROM versions ve WHERE ve.video_id = v.id AND ve.is_published = 1
                ORDER BY ve.number DESC LIMIT 1) AS status,
            (SELECT COUNT(*) FROM versions ve WHERE ve.video_id = v.id) AS version_count,
            (SELECT MAX(ve.number) FROM versions ve WHERE ve.video_id = v.id) AS last_version,
            (SELECT MAX(ve.created_at) FROM versions ve WHERE ve.video_id = v.id) AS last_version_at
        FROM videos v JOIN projects p ON p.id = v.project_id JOIN clients c ON c.id = p.client_id";

    /** @param int|null $org organization of the signed-in user: every query is limited to it (null: no limit, public pages) */
    public function __construct(private readonly PDO $db, private readonly ?int $org = null)
    {
    }

    private function scope(): string
    {
        return $this->org === null ? '' : ' AND c.organization_id = ' . $this->org;
    }

    /**
     * @return list<array<string, mixed>> with project/client names and a "tags" list
     */
    public function list(?int $clientId, ?int $projectId, bool $includeArchived): array
    {
        $stmt = $this->db->prepare(self::SELECT . '
            WHERE (:client IS NULL OR p.client_id = :client)
              AND (:project IS NULL OR v.project_id = :project)' . $this->scope()
            . ($includeArchived ? '' : ' AND v.archived_at IS NULL AND p.archived_at IS NULL AND c.archived_at IS NULL') . '
            ORDER BY v.number DESC');
        $stmt->execute(['client' => $clientId, 'project' => $projectId]);

        return array_map(self::withTags(...), $stmt->fetchAll());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare(self::SELECT . ' WHERE v.id = ?' . $this->scope());
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : self::withTags($row);
    }

    /**
     * Allocates the next number: strictly increasing, never reused, from 1001.
     * Call inside a write transaction.
     */
    public function create(int $projectId, string $title, bool $downloadAllowed): int
    {
        // Numbers are per organization (1001, 1002…), the one of the project's client.
        $stmt = $this->db->prepare('SELECT c.organization_id FROM projects p JOIN clients c ON c.id = p.client_id WHERE p.id = ?');
        $stmt->execute([$projectId]);
        $organization = $stmt->fetchColumn();
        if ($organization === false || $organization === null) {
            throw new \LogicException('Project without organization.');
        }
        $number = (new Organizations($this->db))->nextVideoNumber((int) $organization);

        $this->db->prepare('INSERT INTO videos (number, project_id, title, download_allowed) VALUES (?, ?, ?, ?)')
            ->execute([$number, $projectId, $title, $downloadAllowed ? 1 : 0]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, string $title, bool $downloadAllowed): void
    {
        $this->db->prepare("UPDATE videos SET title = ?, download_allowed = ?, updated_at = datetime('now') WHERE id = ?")
            ->execute([$title, $downloadAllowed ? 1 : 0, $id]);
    }

    public function setArchived(int $id, bool $archived): void
    {
        $value = $archived ? "COALESCE(archived_at, datetime('now'))" : 'NULL';
        $this->db->prepare('UPDATE videos SET archived_at = ' . $value . ", updated_at = datetime('now') WHERE id = ?")
            ->execute([$id]);
    }

    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM videos WHERE id = ?')->execute([$id]);
    }

    /**
     * @param list<int> $projectIds
     * @return list<array{id: int, number: int}>
     */
    public function forProjects(array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($projectIds), '?'));
        $stmt = $this->db->prepare('SELECT id, number FROM videos WHERE project_id IN (' . $placeholders . ')');
        $stmt->execute($projectIds);

        return array_map(static fn ($r) => ['id' => (int) $r['id'], 'number' => (int) $r['number']], $stmt->fetchAll());
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function withTags(array $row): array
    {
        $tags = $row['tag_names'] === null ? [] : explode("\x1F", (string) $row['tag_names']);
        sort($tags, SORT_NATURAL | SORT_FLAG_CASE);
        $row['tags'] = $tags;
        unset($row['tag_names']);

        return $row;
    }
}
