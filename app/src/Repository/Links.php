<?php

declare(strict_types=1);

namespace Belprise\Repository;

use Belprise\Security\Keys;
use PDO;

/**
 * Secret links: "space" (target: client), "client" and "view" (target: video).
 */
final class Links
{
    public const TYPES = ['space', 'client', 'view', 'collection'];

    /** @param int|null $org organization of the signed-in user: only its links are found (null: public pages) */
    public function __construct(private readonly PDO $db, private readonly ?int $org = null)
    {
    }

    /** " AND …" keeping only links whose target belongs to the organization. */
    private function scope(): string
    {
        if ($this->org === null) {
            return '';
        }
        $org = $this->org;

        return " AND ((type = 'space' AND target_id IN (SELECT id FROM clients WHERE organization_id = {$org}))
            OR (type IN ('client', 'view') AND target_id IN (SELECT v.id FROM videos v JOIN projects p ON p.id = v.project_id
                JOIN clients c ON c.id = p.client_id WHERE c.organization_id = {$org}))
            OR (type = 'collection' AND target_id IN (SELECT id FROM collections WHERE organization_id = {$org})))";
    }

    /**
     * @return array<string, mixed>
     */
    public function create(string $type, int $targetId): array
    {
        $this->db->prepare('INSERT INTO links (type, target_id, key) VALUES (?, ?, ?)')
            ->execute([$type, $targetId, $this->freshKey()]);

        return (array) $this->find((int) $this->db->lastInsertId());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM links WHERE id = ?' . $this->scope(), [$id]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByKey(string $key): ?array
    {
        return $this->fetchOne('SELECT * FROM links WHERE key = ?', [$key]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function forTarget(string $type, int $targetId): ?array
    {
        return $this->fetchOne('SELECT * FROM links WHERE type = ? AND target_id = ?' . $this->scope(), [$type, $targetId]);
    }

    /**
     * @param array{is_active?: int, password_hash?: string|null, expires_at?: string|null} $fields
     */
    public function update(int $id, array $fields): void
    {
        $fields = array_intersect_key($fields, array_flip(['is_active', 'password_hash', 'expires_at']));
        if ($fields === []) {
            return;
        }
        $set = implode(', ', array_map(static fn ($column) => $column . ' = :' . $column, array_keys($fields)));
        $this->db->prepare('UPDATE links SET ' . $set . ", updated_at = datetime('now') WHERE id = :id")
            ->execute($fields + ['id' => $id]);
    }

    /** New key; the old one stops working at once. */
    public function regenerate(int $id): void
    {
        $this->db->prepare("UPDATE links SET key = ?, updated_at = datetime('now') WHERE id = ?")
            ->execute([$this->freshKey(), $id]);
    }

    /**
     * @param list<int> $targetIds
     */
    public function deleteFor(string $type, array $targetIds): void
    {
        if ($targetIds === []) {
            return;
        }
        $placeholders = implode(', ', array_fill(0, count($targetIds), '?'));
        $this->db->prepare('DELETE FROM links WHERE type = ? AND target_id IN (' . $placeholders . ')')
            ->execute([$type, ...$targetIds]);
    }

    private function freshKey(): string
    {
        $exists = $this->db->prepare('SELECT 1 FROM links WHERE key = ?');
        do {
            $key = Keys::generate();
            $exists->execute([$key]);
        } while ($exists->fetchColumn() !== false);

        return $key;
    }

    /**
     * @param list<mixed> $params
     * @return array<string, mixed>|null
     */
    private function fetchOne(string $sql, array $params): ?array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }
}
