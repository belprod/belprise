<?php

declare(strict_types=1);

namespace Belprise\Media;

use Belprise\Database\Database;
use Belprise\Storage;
use PDO;

/**
 * Deletes a whole video: its links, versions, revisions, uploads in
 * progress (database) and then its files. Used by the purge (§5.12).
 */
final class VideoEraser
{
    public function __construct(
        private readonly PDO $db,
        private readonly Storage $storage,
    ) {
    }

    /** Bytes the video takes on disk, uploads in progress included (received and still reserved). */
    public function footprint(int $videoId, int $clientId, int $number): int
    {
        $bytes = $this->storage->treeSize(Storage::videoMediaDir($clientId, $number));
        foreach ($this->uploads($videoId) as $upload) {
            $bytes += $this->storage->treeSize(Storage::uploadPart((int) $upload['id']));
            $bytes += max(0, (int) $upload['size_bytes'] - (int) $upload['bytes_received']);
        }

        return $bytes;
    }

    public function erase(int $videoId, int $clientId, int $number): void
    {
        $uploads = array_map(static fn (array $u) => (int) $u['id'], $this->uploads($videoId));
        Database::transaction($this->db, function () use ($videoId): void {
            $this->db->prepare("DELETE FROM links WHERE type IN ('client', 'view') AND target_id = ?")->execute([$videoId]);
            $this->db->prepare('DELETE FROM videos WHERE id = ?')->execute([$videoId]);
            $this->db->exec('DELETE FROM tags WHERE id NOT IN (SELECT tag_id FROM video_tags)');
        });
        $this->storage->deleteTree(Storage::videoMediaDir($clientId, $number));
        foreach ($uploads as $id) {
            $this->storage->deleteFile(Storage::uploadPart($id));
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function uploads(int $videoId): array
    {
        $stmt = $this->db->prepare('SELECT id, size_bytes, bytes_received FROM uploads WHERE video_id = ?');
        $stmt->execute([$videoId]);

        return $stmt->fetchAll();
    }
}
