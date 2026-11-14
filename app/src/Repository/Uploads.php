<?php

declare(strict_types=1);

namespace Belprise\Repository;

use PDO;

/**
 * Chunked uploads in progress. The file grows in data/uploads/{id}.part,
 * each chunk written at its position.
 */
final class Uploads
{
    private const SELECT = 'SELECT u.*, v.number AS video_number, v.title AS video_title
        FROM uploads u LEFT JOIN videos v ON v.id = u.video_id';

    /** @param int|null $org organization of the signed-in user: every query is limited to it (null: no limit, public pages) */
    public function __construct(private readonly PDO $db, private readonly ?int $org = null)
    {
    }

    private function scope(): string
    {
        return $this->org === null ? '' : ' AND u.video_id IN (SELECT v.id FROM videos v JOIN projects p ON p.id = v.project_id JOIN clients c ON c.id = p.client_id WHERE c.organization_id = ' . $this->org . ')';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(?int $videoId = null): array
    {
        $stmt = $this->db->prepare(self::SELECT . ' WHERE (:video IS NULL OR u.video_id = :video)' . $this->scope() . ' ORDER BY u.updated_at DESC');
        $stmt->execute(['video' => $videoId]);

        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare(self::SELECT . ' WHERE u.id = ?' . $this->scope());
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByFingerprint(string $fingerprint): ?array
    {
        $stmt = $this->db->prepare(self::SELECT . ' WHERE u.fingerprint = ?' . $this->scope());
        $stmt->execute([$fingerprint]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** $versionId / $target: a file of a playback version ("hls/segments-1.mp4"), not a master. */
    public function create(string $name, int $size, string $fingerprint, int $videoId, ?int $versionId = null, ?string $target = null): int
    {
        $this->db->prepare('INSERT INTO uploads (name, size_bytes, fingerprint, video_id, version_id, target) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$name, $size, $fingerprint, $videoId, $versionId, $target]);

        return (int) $this->db->lastInsertId();
    }

    public function setVideo(int $id, int $videoId): void
    {
        $this->db->prepare("UPDATE uploads SET video_id = ?, updated_at = datetime('now') WHERE id = ?")->execute([$videoId, $id]);
    }

    public function setReceived(int $id, int $bytes): void
    {
        $this->db->prepare("UPDATE uploads SET bytes_received = ?, updated_at = datetime('now') WHERE id = ?")->execute([$bytes, $id]);
    }

    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM uploads WHERE id = ?')->execute([$id]);
    }

    /**
     * @return list<int>
     */
    /**
     * Uploads in progress for these videos (to remove their parts with them).
     *
     * @param list<int> $videoIds
     * @return list<int>
     */
    public function idsForVideos(array $videoIds): array
    {
        if ($videoIds === []) {
            return [];
        }
        $stmt = $this->db->prepare('SELECT id FROM uploads WHERE video_id IN (' . implode(',', array_fill(0, count($videoIds), '?')) . ')');
        $stmt->execute(array_map('intval', $videoIds));

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function ids(): array
    {
        return array_map('intval', $this->db->query('SELECT id FROM uploads')->fetchAll(PDO::FETCH_COLUMN));
    }
}
