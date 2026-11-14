<?php

declare(strict_types=1);

namespace Belprise\Repository;

use PDO;

/**
 * Queue of the playback versions to encode on the server (CAHIER_DES_CHARGES
 * §5.7 bis): one job per version; the scheduled task takes them one at a time.
 */
final class EncodeJobs
{
    /** Tries before a job is marked failed. */
    public const MAX_ATTEMPTS = 3;
    /** A running job without news for this long was cut (process killed, server restarted). */
    public const STALE_AFTER = 600;

    public function __construct(private readonly PDO $db)
    {
    }

    /** (Re)queues the encoding of a version: a new job, from the start. */
    public function enqueue(int $versionId): void
    {
        $this->db->prepare(
            "INSERT INTO encode_jobs (version_id) VALUES (?)
             ON CONFLICT (version_id) DO UPDATE SET status = 'queued', progress = 0, attempts = 0, error = NULL,
                started_at = NULL, heartbeat_at = NULL, finished_at = NULL, updated_at = datetime('now')"
        )->execute([$versionId]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function forVersion(int $versionId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM encode_jobs WHERE version_id = ?');
        $stmt->execute([$versionId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * The next job to run: a cut one first (it starts again), then the oldest queued.
     *
     * @return array<string, mixed>|null
     */
    public function next(int $now): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM encode_jobs
             WHERE status = 'queued' OR (status = 'running' AND heartbeat_at < ?)
             ORDER BY (status = 'queued'), created_at, id LIMIT 1"
        );
        $stmt->execute([gmdate('Y-m-d H:i:s', $now - self::STALE_AFTER)]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function start(int $id, int $now): void
    {
        $at = gmdate('Y-m-d H:i:s', $now);
        $this->db->prepare(
            "UPDATE encode_jobs SET status = 'running', progress = 0, attempts = attempts + 1, error = NULL,
                started_at = ?, heartbeat_at = ?, updated_at = datetime('now') WHERE id = ?"
        )->execute([$at, $at, $id]);
    }

    public function progress(int $id, float $progress, int $now): void
    {
        $this->db->prepare('UPDATE encode_jobs SET progress = ?, heartbeat_at = ? WHERE id = ?')
            ->execute([max(0.0, min(1.0, $progress)), gmdate('Y-m-d H:i:s', $now), $id]);
    }

    public function done(int $id, int $now): void
    {
        $this->db->prepare("UPDATE encode_jobs SET status = 'done', progress = 1, error = NULL, finished_at = ?, updated_at = datetime('now') WHERE id = ?")
            ->execute([gmdate('Y-m-d H:i:s', $now), $id]);
    }

    /** A try failed: queued again, or failed for good after MAX_ATTEMPTS. */
    public function failed(int $id, string $error): string
    {
        $stmt = $this->db->prepare('SELECT attempts FROM encode_jobs WHERE id = ?');
        $stmt->execute([$id]);
        $status = (int) $stmt->fetchColumn() >= self::MAX_ATTEMPTS ? 'failed' : 'queued';
        $this->db->prepare("UPDATE encode_jobs SET status = ?, error = ?, updated_at = datetime('now') WHERE id = ?")->execute([$status, $error, $id]);

        return $status;
    }

    /**
     * @return array{queued: int, running: int, failed: int}
     */
    public function counts(): array
    {
        $out = ['queued' => 0, 'running' => 0, 'failed' => 0];
        foreach ($this->db->query("SELECT status, COUNT(*) AS n FROM encode_jobs WHERE status != 'done' GROUP BY status")->fetchAll() as $row) {
            $out[(string) $row['status']] = (int) $row['n'];
        }

        return $out;
    }
}
