<?php

declare(strict_types=1);

namespace Belprise\Media;

use Belprise\Database\Database;
use Belprise\Http\HttpException;
use Belprise\Repository\AuditLog;
use Belprise\Storage;
use PDO;

/**
 * Archiving a video (CAHIER_DES_CHARGES §5.13): one version is kept — the
 * latest approved, else the latest published —, the other versions (files
 * included) and every revision are deleted for good, uploads in progress
 * are cancelled, and the video moves to the client's library.
 *
 * plan() only reads: it tells what execute() would do (manual confirmation,
 * dry run of the automatic archiving).
 */
final class VideoArchiver
{
    public function __construct(
        private readonly PDO $db,
        private readonly Storage $storage,
    ) {
    }

    /**
     * @param array<string, mixed> $video row of Videos (with client_id)
     * @return array{keep: array<string, mixed>|null, delete: list<array<string, mixed>>, revisions: int, freedBytes: int}
     */
    public function plan(array $video): array
    {
        $stmt = $this->db->prepare('SELECT * FROM versions WHERE video_id = ? ORDER BY number DESC');
        $stmt->execute([(int) $video['id']]);
        $versions = $stmt->fetchAll();

        $keep = null;
        foreach ($versions as $version) {
            if ($version['status'] === 'approved' && (int) $version['is_published'] === 1) {
                $keep = $version;
                break;
            }
        }
        if ($keep === null) {
            foreach ($versions as $version) {
                if ((int) $version['is_published'] === 1) {
                    $keep = $version;
                    break;
                }
            }
        }
        $delete = $keep === null ? [] : array_values(array_filter($versions, static fn (array $v) => (int) $v['id'] !== (int) $keep['id']));

        $revisions = $this->db->prepare('SELECT COUNT(*) FROM comments c JOIN versions v ON v.id = c.version_id WHERE v.video_id = ? AND c.deleted_at IS NULL');
        $revisions->execute([(int) $video['id']]);

        $freed = 0;
        foreach ($delete as $version) {
            $freed += $this->storage->treeSize(Storage::versionDir((int) $video['client_id'], (int) $video['number'], (int) $version['number']));
        }

        return ['keep' => $keep, 'delete' => $delete, 'revisions' => (int) $revisions->fetchColumn(), 'freedBytes' => $freed];
    }

    /**
     * @param array<string, mixed> $video
     * @return array{keep: array<string, mixed>|null, delete: list<array<string, mixed>>, revisions: int, freedBytes: int}
     */
    public function execute(array $video, string $actor): array
    {
        $plan = $this->plan($video);
        if ($plan['keep'] === null) {
            throw new HttpException(422, 'Cette vidéo n’a aucune version publiée : il n’y a rien à garder dans la bibliothèque du client.');
        }
        $videoId = (int) $video['id'];
        $keepId = (int) $plan['keep']['id'];
        $uploads = $this->db->prepare('SELECT id FROM uploads WHERE video_id = ?');
        $uploads->execute([$videoId]);
        $uploadIds = array_map('intval', $uploads->fetchAll(PDO::FETCH_COLUMN));

        Database::transaction($this->db, function () use ($videoId, $keepId, $uploadIds, $video, $plan, $actor): void {
            // Revisions of the kept version too: the library has none.
            $this->db->prepare('DELETE FROM comments WHERE version_id IN (SELECT id FROM versions WHERE video_id = ?)')->execute([$videoId]);
            $this->db->prepare('DELETE FROM versions WHERE video_id = ? AND id <> ?')->execute([$videoId, $keepId]);
            $this->db->prepare('DELETE FROM uploads WHERE video_id = ?')->execute([$videoId]);
            $this->db->prepare("UPDATE videos SET archived_at = COALESCE(archived_at, datetime('now')), updated_at = datetime('now') WHERE id = ?")
                ->execute([$videoId]);
            $org = $this->db->prepare('SELECT organization_id FROM clients WHERE id = ?');
            $org->execute([(int) $video['client_id']]);
            (new AuditLog($this->db))->add(
                (int) $org->fetchColumn(),
                $actor,
                'Vidéo archivée',
                'N° ' . $video['number'] . ' ' . $video['title'] . ' : V' . $plan['keep']['number'] . ' gardée'
                . ($plan['delete'] === [] ? '' : ', ' . implode(', ', array_map(static fn ($v) => 'V' . $v['number'], array_reverse($plan['delete']))) . ' supprimée' . (count($plan['delete']) > 1 ? 's' : ''))
                . ', ' . $plan['revisions'] . ' révision' . ($plan['revisions'] > 1 ? 's' : '') . ' supprimée' . ($plan['revisions'] > 1 ? 's' : ''),
            );
        });

        // Files go once the database no longer points at them.
        foreach ($plan['delete'] as $version) {
            $this->storage->deleteTree(Storage::versionDir((int) $video['client_id'], (int) $video['number'], (int) $version['number']));
        }
        foreach ($uploadIds as $id) {
            $this->storage->deleteFile(Storage::uploadPart($id));
        }

        return $plan;
    }
}
