<?php

declare(strict_types=1);

namespace Belprise\Repository;

use PDO;

final class Clients
{
    private const EDITABLE = ['name', 'bg_color', 'text_color', 'accent_color', 'welcome_text'];

    /** @param int|null $org organization of the signed-in user: every query is limited to it (null: no limit, public pages) */
    public function __construct(private readonly PDO $db, private readonly ?int $org = null)
    {
    }

    /** " AND …" restricting the client alias to the organization. */
    private function scope(string $alias): string
    {
        return $this->org === null ? '' : ' AND ' . $alias . '.organization_id = ' . $this->org;
    }

    private function organization(): int
    {
        return $this->org ?? throw new \LogicException('Clients are created inside an organization.');
    }

    /**
     * @return list<array<string, mixed>> with project_count and video_count
     */
    public function list(bool $includeArchived): array
    {
        $stmt = $this->db->prepare(
            'SELECT c.*,
                (SELECT COUNT(*) FROM projects p WHERE p.client_id = c.id) AS project_count,
                (SELECT COUNT(*) FROM videos v JOIN projects p ON p.id = v.project_id WHERE p.client_id = c.id) AS video_count
             FROM clients c WHERE 1 = 1' . $this->scope('c')
            . ($includeArchived ? '' : ' AND c.archived_at IS NULL')
            . ' ORDER BY c.archived_at IS NOT NULL, c.name COLLATE NOCASE'
        );
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM clients c WHERE c.id = ?' . $this->scope('c'));
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, string> $fields name, bg_color, text_color, accent_color, welcome_text
     */
    public function create(array $fields): int
    {
        $this->db->prepare(
            'INSERT INTO clients (organization_id, name, bg_color, text_color, accent_color, welcome_text)
             VALUES (:organization_id, :name, :bg_color, :text_color, :accent_color, :welcome_text)'
        )->execute(['organization_id' => $this->organization()] + array_intersect_key($fields, array_flip(self::EDITABLE)));

        return (int) $this->db->lastInsertId();
    }

    /**
     * @param array<string, string> $fields subset of the editable columns
     */
    public function update(int $id, array $fields): void
    {
        $fields = array_intersect_key($fields, array_flip(self::EDITABLE));
        if ($fields === []) {
            return;
        }
        $set = implode(', ', array_map(static fn ($column) => $column . ' = :' . $column, array_keys($fields)));
        $this->db->prepare('UPDATE clients SET ' . $set . ", updated_at = datetime('now') WHERE id = :id")
            ->execute($fields + ['id' => $id]);
    }

    public function setArchived(int $id, bool $archived): void
    {
        $value = $archived ? "COALESCE(archived_at, datetime('now'))" : 'NULL';
        $this->db->prepare('UPDATE clients SET archived_at = ' . $value . ", updated_at = datetime('now') WHERE id = ?")
            ->execute([$id]);
    }

    public function setLogo(int $id, ?string $logo): void
    {
        $this->db->prepare("UPDATE clients SET logo = ?, updated_at = datetime('now') WHERE id = ?")->execute([$logo, $id]);
    }

    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
    }
}
