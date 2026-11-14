<?php

declare(strict_types=1);

namespace Belprise\Repository;

use PDO;

final class Versions
{
    public const STATUSES = ['in_progress', 'to_review', 'changes_requested', 'approved'];

    private const SELECT = 'SELECT ve.*, v.number AS video_number, v.title AS video_title, p.client_id
        FROM versions ve JOIN videos v ON v.id = ve.video_id JOIN projects p ON p.id = v.project_id';

    /** Columns set from the media analysis or corrected by hand. */
    private const META = ['duration_frames', 'fps_num', 'fps_den', 'drop_frame', 'start_frame', 'width', 'height',
        'codec', 'bit_rate', 'timecode_source'];

    /** @param int|null $org organization of the signed-in user: every query is limited to it (null: no limit, public pages) */
    public function __construct(private readonly PDO $db, private readonly ?int $org = null)
    {
    }

    private function scope(): string
    {
        return $this->org === null ? '' : ' AND p.client_id IN (SELECT id FROM clients WHERE organization_id = ' . $this->org . ')';
    }

    /** Bytes of the masters of the organization. */
    public function totalBytes(): int
    {
        return (int) $this->db->query('SELECT COALESCE(SUM(ve.size_bytes), 0) FROM versions ve JOIN videos v ON v.id = ve.video_id
            JOIN projects p ON p.id = v.project_id WHERE 1 = 1' . $this->scope())->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>> newest first
     */
    public function forVideo(int $videoId): array
    {
        $stmt = $this->db->prepare(self::SELECT . ' WHERE ve.video_id = ?' . $this->scope() . ' ORDER BY ve.number DESC');
        $stmt->execute([$videoId]);

        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare(self::SELECT . ' WHERE ve.id = ?' . $this->scope());
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function nextNumber(int $videoId): int
    {
        $stmt = $this->db->prepare('SELECT COALESCE(MAX(number), 0) + 1 FROM versions WHERE video_id = ?');
        $stmt->execute([$videoId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $meta see META
     */
    public function create(int $videoId, int $number, string $filePath, string $originalName, int $size, array $meta): int
    {
        $meta = array_intersect_key($meta, array_flip(self::META));
        $columns = ['video_id', 'number', 'file_path', 'original_name', 'size_bytes', ...array_keys($meta)];
        $this->db->prepare(
            'INSERT INTO versions (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', array_map(static fn ($c) => ':' . $c, $columns)) . ')'
        )->execute([
            'video_id' => $videoId,
            'number' => $number,
            'file_path' => $filePath,
            'original_name' => $originalName,
            'size_bytes' => $size,
        ] + $meta);

        return (int) $this->db->lastInsertId();
    }

    /**
     * @param array<string, mixed> $fields subset of META, status, is_published, published_at,
     *                                     approved_by, approved_at, poster_path, poster_frame, sprite_path, sprite_meta
     */
    public function update(int $id, array $fields): void
    {
        $allowed = [...self::META, 'status', 'is_published', 'published_at', 'approved_by', 'approved_at',
            'poster_path', 'poster_frame', 'thumb_path', 'sprite_path', 'sprite_meta'];
        $fields = array_intersect_key($fields, array_flip($allowed));
        if ($fields === []) {
            return;
        }
        $set = implode(', ', array_map(static fn ($c) => $c . ' = :' . $c, array_keys($fields)));
        $this->db->prepare('UPDATE versions SET ' . $set . ", updated_at = datetime('now') WHERE id = :id")
            ->execute($fields + ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM versions WHERE id = ?')->execute([$id]);
    }
}
