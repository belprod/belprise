<?php

declare(strict_types=1);

namespace Belprise\Media;

use Belprise\Storage;
use DateTimeImmutable;
use PDO;

/**
 * Automatic archiving (CAHIER_DES_CHARGES §5.13): a validated video (its
 * latest published version is approved) with no new version and no new
 * revision for 6 months is archived. No notice beforehand (decision Q4);
 * each archiving is written to the organization's journal.
 */
final class AutoArchiver
{
    public const ACTOR = 'Archivage automatique';
    public const IDLE = '-6 months';

    public function __construct(
        private readonly PDO $db,
        private readonly Storage $storage,
    ) {
    }

    /**
     * Videos to archive at this date.
     *
     * @return list<array<string, mixed>> video rows (id, number, title, client_id, last_activity)
     */
    public function candidates(DateTimeImmutable $now): array
    {
        $limit = $now->setTimezone(new \DateTimeZone('UTC'))->modify(self::IDLE)->format('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            "SELECT * FROM (
                SELECT v.id, v.number, v.title, p.client_id,
                    (SELECT ve.status FROM versions ve WHERE ve.video_id = v.id AND ve.is_published = 1
                        ORDER BY ve.number DESC LIMIT 1) AS status,
                    COALESCE(
                        (SELECT MAX(a.created_at) FROM activity a WHERE a.video_id = v.id AND a.type IN ('version', 'revision', 'reply')),
                        v.created_at
                    ) AS last_activity
                FROM videos v JOIN projects p ON p.id = v.project_id
                WHERE v.archived_at IS NULL
             ) WHERE status = 'approved' AND last_activity < ?
             ORDER BY last_activity, id"
        );
        $stmt->execute([$limit]);

        return $stmt->fetchAll();
    }

    /**
     * @return list<array{video: array<string, mixed>, plan: array<string, mixed>}>
     */
    public function run(DateTimeImmutable $now, bool $dryRun): array
    {
        $archiver = new VideoArchiver($this->db, $this->storage);
        $done = [];
        foreach ($this->candidates($now) as $video) {
            $done[] = ['video' => $video, 'plan' => $dryRun ? $archiver->plan($video) : $archiver->execute($video, self::ACTOR)];
        }

        return $done;
    }
}
