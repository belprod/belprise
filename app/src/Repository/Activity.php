<?php

declare(strict_types=1);

namespace Belprise\Repository;

use PDO;

/**
 * Events on videos (CAHIER_DES_CHARGES §5.7, §5.13): a new version, a
 * revision or a reply, a validation, a status change. The client, project
 * and organization are taken from the video, so a row is always consistent.
 */
final class Activity
{
    public const TYPES = ['version', 'revision', 'reply', 'approved', 'status'];

    public function __construct(private readonly PDO $db, private readonly ?int $org = null)
    {
    }

    public function record(
        string $type,
        int $videoId,
        ?int $versionId = null,
        ?int $versionNumber = null,
        string $actor = '',
        bool $admin = false,
        string $details = '',
    ): void {
        $this->db->prepare(
            'INSERT INTO activity (organization_id, client_id, project_id, video_id, version_id, version_number, type, actor, is_admin, details)
             SELECT c.organization_id, c.id, p.id, v.id, ?, ?, ?, ?, ?, ?
             FROM videos v JOIN projects p ON p.id = v.project_id JOIN clients c ON c.id = p.client_id
             WHERE v.id = ? AND c.organization_id IS NOT NULL'
        )->execute([$versionId, $versionNumber, $type, $actor, $admin ? 1 : 0, $details, $videoId]);
    }

    /**
     * Clients, projects or videos of the organization with the latest activity.
     *
     * @param 'client'|'project'|'video' $level
     * @return list<array{id: int, at: string}>
     */
    public function latest(string $level, int $limit): array
    {
        $column = ['client' => 'a.client_id', 'project' => 'a.project_id', 'video' => 'a.video_id'][$level];
        $stmt = $this->db->prepare(
            'SELECT ' . $column . ' AS id, MAX(a.created_at) AS at FROM activity a
             JOIN videos v ON v.id = a.video_id JOIN projects p ON p.id = a.project_id JOIN clients c ON c.id = a.client_id
             WHERE a.organization_id = ? AND v.archived_at IS NULL AND p.archived_at IS NULL AND c.archived_at IS NULL
             GROUP BY ' . $column . ' ORDER BY at DESC LIMIT ' . $limit
        );
        $stmt->execute([$this->organization()]);

        return array_map(static fn (array $r) => ['id' => (int) $r['id'], 'at' => (string) $r['at']], $stmt->fetchAll());
    }

    /**
     * Events of a client, project or video, newest first, from a date on.
     *
     * @param 'client'|'project'|'video' $level
     * @return list<array<string, mixed>>
     */
    public function events(string $level, int $id, string $since, int $limit = 100): array
    {
        $column = ['client' => 'a.client_id', 'project' => 'a.project_id', 'video' => 'a.video_id'][$level];
        $stmt = $this->db->prepare(
            'SELECT a.*, v.number AS video_number, v.title AS video_title FROM activity a JOIN videos v ON v.id = a.video_id
             WHERE ' . $column . ' = ? AND a.organization_id = ? AND a.created_at >= ?
             ORDER BY a.created_at DESC, a.id DESC LIMIT ' . $limit
        );
        $stmt->execute([$id, $this->organization(), $since]);

        return $stmt->fetchAll();
    }

    private function organization(): int
    {
        return $this->org ?? throw new \LogicException('Activity is read inside an organization.');
    }
}
