<?php

declare(strict_types=1);

namespace Belprise\Controller\Admin;

use Belprise\Http\Response;
use Belprise\Review\ActivitySummary;
use Belprise\Services;
use Belprise\Support\Dates;

/**
 * Tableau de bord (CAHIER_DES_CHARGES §5.7): how many clients, projects and
 * videos, the storage, and quick access to what moved lately.
 */
final class DashboardController
{
    /** Events told for an item: those of the week before its latest one. */
    private const WINDOW = 7 * 86400;
    private const RECENT = ['video' => 8, 'project' => 6, 'client' => 6];

    public function __construct(private readonly Services $services)
    {
    }

    public function stats(): Response
    {
        $org = (int) $this->services->organizationId();
        $db = $this->services->db();
        $count = static function (string $sql) use ($db, $org): int {
            $stmt = $db->prepare($sql);
            $stmt->execute([$org]);

            return (int) $stmt->fetchColumn();
        };
        $counts = $this->services->comments()->counts();

        return Response::json([
            'clients' => $count('SELECT COUNT(*) FROM clients WHERE organization_id = ? AND archived_at IS NULL'),
            'projects' => $count('SELECT COUNT(*) FROM projects p JOIN clients c ON c.id = p.client_id
                WHERE c.organization_id = ? AND p.archived_at IS NULL AND c.archived_at IS NULL'),
            'videos' => $count('SELECT COUNT(*) FROM videos v JOIN projects p ON p.id = v.project_id JOIN clients c ON c.id = p.client_id
                WHERE c.organization_id = ? AND v.archived_at IS NULL AND p.archived_at IS NULL AND c.archived_at IS NULL'),
            'todo' => $counts['todo'],
            'unread' => $counts['unread'],
            'storage' => $this->services->quota()->usage($org),
            'recent' => [
                'videos' => $this->recentVideos(),
                'projects' => $this->recent('project'),
                'clients' => $this->recent('client'),
            ],
        ]);
    }

    /** Cheap call polled by the menu badge. */
    public function unread(): Response
    {
        return Response::json(['unread' => $this->services->comments()->counts()['unread']]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentVideos(): array
    {
        $out = [];
        foreach ($this->services->activity()->latest('video', self::RECENT['video']) as ['id' => $id, 'at' => $at]) {
            $video = $this->services->videos()->find($id);
            if ($video === null) {
                continue;
            }
            $events = $this->services->activity()->events('video', $id, self::since($at));
            $out[] = [
                'id' => $id,
                'number' => (int) $video['number'],
                'title' => $video['title'],
                'context' => $video['client_name'] . ' · ' . $video['project_name'],
                'summary' => ActivitySummary::line($events),
                'at' => Dates::toIso($at),
                // Revisions open the review of the version concerned.
                'versionId' => isset($events[0]['version_id']) ? (int) $events[0]['version_id'] : null,
            ];
        }

        return $out;
    }

    /**
     * Projects or clients: the line tells what happened to their latest video.
     *
     * @param 'project'|'client' $level
     * @return list<array<string, mixed>>
     */
    private function recent(string $level): array
    {
        $out = [];
        foreach ($this->services->activity()->latest($level, self::RECENT[$level]) as ['id' => $id, 'at' => $at]) {
            $row = $level === 'project' ? $this->services->projects()->find($id) : $this->services->clients()->find($id);
            if ($row === null) {
                continue;
            }
            $events = $this->services->activity()->events($level, $id, self::since($at));
            $latestVideo = (int) ($events[0]['video_id'] ?? 0);
            $videoEvents = array_values(array_filter($events, static fn (array $e) => (int) $e['video_id'] === $latestVideo));
            $others = count(array_unique(array_column($events, 'video_id'))) - 1;
            $summary = ($events[0]['video_title'] ?? '') . ' : ' . ActivitySummary::line($videoEvents, 2);
            if ($others > 0) {
                $summary .= ' · ' . $others . ' autre' . ($others > 1 ? 's vidéos' : ' vidéo');
            }
            $out[] = [
                'id' => $id,
                'name' => $row['name'],
                'context' => $level === 'project' ? $row['client_name'] : null,
                'summary' => $summary,
                'at' => Dates::toIso($at),
            ];
        }

        return $out;
    }

    private static function since(string $at): string
    {
        return gmdate('Y-m-d H:i:s', (int) strtotime($at . ' UTC') - self::WINDOW);
    }
}
