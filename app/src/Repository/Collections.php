<?php

declare(strict_types=1);

namespace Belprise\Repository;

use PDO;

/** Collections of videos for prospects (CAHIER_DES_CHARGES §5.9). */
final class Collections
{
    private const EDITABLE = ['title', 'introduction', 'bg_color', 'text_color', 'accent_color'];

    /** @param int|null $org organization of the signed-in user (null: public pages) */
    public function __construct(private readonly PDO $db, private readonly ?int $org = null)
    {
    }

    private function scope(string $alias = 'co'): string
    {
        return $this->org === null ? '' : ' AND ' . $alias . '.organization_id = ' . $this->org;
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return $this->db->query(
            'SELECT co.*, (SELECT COUNT(*) FROM collection_videos cv WHERE cv.collection_id = co.id) AS video_count
             FROM collections co WHERE 1 = 1' . $this->scope() . ' ORDER BY co.updated_at DESC, co.id DESC'
        )->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT co.* FROM collections co WHERE co.id = ?' . $this->scope());
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string, string> $fields */
    public function create(array $fields): int
    {
        $org = $this->org ?? throw new \LogicException('Collections are created inside an organization.');
        $this->db->prepare(
            'INSERT INTO collections (organization_id, title, introduction, bg_color, text_color, accent_color)
             VALUES (:organization_id, :title, :introduction, :bg_color, :text_color, :accent_color)'
        )->execute(['organization_id' => $org] + array_intersect_key($fields, array_flip(self::EDITABLE)));

        return (int) $this->db->lastInsertId();
    }

    /** @param array<string, string> $fields */
    public function update(int $id, array $fields): void
    {
        $fields = array_intersect_key($fields, array_flip(self::EDITABLE));
        if ($fields === []) {
            return;
        }
        $set = implode(', ', array_map(static fn ($c) => $c . ' = :' . $c, array_keys($fields)));
        $this->db->prepare('UPDATE collections SET ' . $set . ", updated_at = datetime('now') WHERE id = :id")->execute($fields + ['id' => $id]);
    }

    public function setLogo(int $id, ?string $logo): void
    {
        $this->db->prepare("UPDATE collections SET logo = ?, updated_at = datetime('now') WHERE id = ?")->execute([$logo, $id]);
    }

    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM collections WHERE id = ?')->execute([$id]);
    }

    /**
     * Video ids in their order.
     *
     * @return list<int>
     */
    public function videoIds(int $id): array
    {
        $stmt = $this->db->prepare('SELECT video_id FROM collection_videos WHERE collection_id = ? ORDER BY position, video_id');
        $stmt->execute([$id]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Replaces the selection, in this order. The caller has checked that
     * every video belongs to the organization.
     *
     * @param list<int> $videoIds
     */
    public function setVideos(int $id, array $videoIds): void
    {
        $this->db->prepare('DELETE FROM collection_videos WHERE collection_id = ?')->execute([$id]);
        $insert = $this->db->prepare('INSERT INTO collection_videos (collection_id, video_id, position) VALUES (?, ?, ?)');
        foreach (array_values(array_unique($videoIds)) as $position => $videoId) {
            $insert->execute([$id, $videoId, $position]);
        }
        $this->db->prepare("UPDATE collections SET updated_at = datetime('now') WHERE id = ?")->execute([$id]);
    }
}
