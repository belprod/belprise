<?php

declare(strict_types=1);

namespace Belprise\Controller\Admin;

use Belprise\Media\MasterFormat;
use Belprise\Repository\EncodeJobs;
use Belprise\Security\LinkAccess;
use Belprise\Security\MediaSigner;
use Belprise\Support\Dates;
use Belprise\Timecode;

/**
 * Shapes database rows into the JSON returned by the admin API.
 */
final class Presenter
{
    public function __construct(
        private readonly MediaSigner $signer,
        private readonly LinkAccess $access,
        /** Encoding on the server (§5.7 bis): its state is shown with the version. */
        private readonly ?EncodeJobs $encodeJobs = null,
    ) {
    }

    /**
     * @param array<string, mixed>      $row
     * @param array<string, mixed>|null $spaceLink
     * @return array<string, mixed>
     */
    public function client(array $row, ?array $spaceLink = null): array
    {
        $out = [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'logoUrl' => $row['logo'] === null ? null : $this->signer->url((string) $row['logo']),
            'bgColor' => $row['bg_color'],
            'textColor' => $row['text_color'],
            'accentColor' => $row['accent_color'],
            'welcomeText' => $row['welcome_text'],
            'archived' => $row['archived_at'] !== null,
            'createdAt' => Dates::toIso((string) $row['created_at']),
        ];
        if (isset($row['project_count'])) {
            $out['projectCount'] = (int) $row['project_count'];
            $out['videoCount'] = (int) $row['video_count'];
        }
        if ($spaceLink !== null) {
            $out['link'] = $this->link($spaceLink, null);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function project(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'clientId' => (int) $row['client_id'],
            'clientName' => $row['client_name'],
            'videoCount' => (int) $row['video_count'],
            'archived' => $row['archived_at'] !== null,
            'clientArchived' => $row['client_archived_at'] !== null,
            'createdAt' => Dates::toIso((string) $row['created_at']),
        ];
    }

    /**
     * @param array<string, mixed>                 $row
     * @param array<string, array<string, mixed>>  $links "client" and "view" rows
     * @return array<string, mixed>
     */
    public function video(array $row, array $links = []): array
    {
        $out = [
            'id' => (int) $row['id'],
            'number' => (int) $row['number'],
            'title' => $row['title'],
            'projectId' => (int) $row['project_id'],
            'projectName' => $row['project_name'],
            'clientId' => (int) $row['client_id'],
            'clientName' => $row['client_name'],
            'tags' => $row['tags'],
            'downloadAllowed' => (int) $row['download_allowed'] === 1,
            'archived' => $row['archived_at'] !== null,
            'parentArchived' => $row['project_archived_at'] !== null || $row['client_archived_at'] !== null,
            // Status shown in lists: the latest published version's (§5.4).
            'status' => $row['status'] ?? null,
            'versionCount' => (int) ($row['version_count'] ?? 0),
            'lastVersion' => isset($row['last_version']) ? (int) $row['last_version'] : null,
            'lastActivityAt' => Dates::toIso((string) ($row['last_version_at'] ?? $row['created_at'])),
            'createdAt' => Dates::toIso((string) $row['created_at']),
        ];
        foreach ($links as $type => $link) {
            $out['links'][$type] = $this->link($link, (int) $row['number']);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row from Versions
     * @return array<string, mixed>
     */
    public function version(array $row): array
    {
        $num = $row['fps_num'] === null ? null : (int) $row['fps_num'];
        $den = $row['fps_den'] === null ? null : (int) $row['fps_den'];
        $dropFrame = (int) $row['drop_frame'] === 1;

        return [
            'id' => (int) $row['id'],
            'videoId' => (int) $row['video_id'],
            'number' => (int) $row['number'],
            'status' => $row['status'],
            'isPublished' => (int) $row['is_published'] === 1,
            'publishedAt' => $row['published_at'] === null ? null : Dates::toIso((string) $row['published_at']),
            'approvedBy' => $row['approved_by'],
            'approvedAt' => $row['approved_at'] === null ? null : Dates::toIso((string) $row['approved_at']),
            'createdAt' => Dates::toIso((string) $row['created_at']),
            'originalName' => $row['original_name'],
            'size' => $row['size_bytes'] === null ? null : (int) $row['size_bytes'],
            'codec' => $row['codec'],
            'width' => $row['width'] === null ? null : (int) $row['width'],
            'height' => $row['height'] === null ? null : (int) $row['height'],
            'fpsNum' => $num,
            'fpsDen' => $den,
            'dropFrame' => $dropFrame,
            'durationFrames' => $row['duration_frames'] === null ? null : (int) $row['duration_frames'],
            'startFrame' => (int) $row['start_frame'],
            'startTimecode' => $num === null || $den === null
                ? null
                : Timecode::fromFrames((int) $row['start_frame'], $num, $den, $dropFrame),
            'timecodeSource' => $row['timecode_source'],
            'bitRate' => $row['bit_rate'] === null ? null : (int) $row['bit_rate'],
            'videoUrl' => $row['file_path'] === null ? null : $this->signer->url((string) $row['file_path']),
            'hlsUrl' => ($row['hls_path'] ?? null) === null ? null : $this->signer->url((string) $row['hls_path']),
            'playable' => MasterFormat::isPlayable((string) $row['codec']),
            'posterUrl' => $row['poster_path'] === null ? null : $this->signer->url((string) $row['poster_path']),
            'posterFrame' => $row['poster_frame'] === null ? null : (int) $row['poster_frame'],
            'thumbUrl' => $row['thumb_path'] === null ? null : $this->signer->url((string) $row['thumb_path']),
            'spriteUrl' => $row['sprite_path'] === null ? null : $this->signer->url((string) $row['sprite_path']),
            'sprite' => $row['sprite_meta'] === null ? null : json_decode((string) $row['sprite_meta'], true),
            'encoding' => $this->encoding((int) $row['id']),
        ];
    }

    /**
     * The encoding on the server of a version, while it matters: waiting,
     * running or failed (a done job is shown by the playback version itself).
     *
     * @return array{status: string, progress: float, error: string|null, attempts: int}|null
     */
    private function encoding(int $versionId): ?array
    {
        $job = $this->encodeJobs?->forVersion($versionId);
        if ($job === null || $job['status'] === 'done') {
            return null;
        }

        return [
            'status' => (string) $job['status'],
            'progress' => round((float) $job['progress'], 3),
            'error' => $job['error'],
            'attempts' => (int) $job['attempts'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function link(array $row, ?int $videoNumber): array
    {
        $usable = $this->access->isUsable($row);

        return [
            'id' => (int) $row['id'],
            'type' => $row['type'],
            'path' => $videoNumber === null ? '/' . $row['key'] : '/v/' . $videoNumber . $row['key'],
            'isActive' => (int) $row['is_active'] === 1,
            'hasPassword' => $row['password_hash'] !== null,
            'expiresOn' => $row['expires_at'] === null ? null : Dates::expiryToLocalDate((string) $row['expires_at']),
            'status' => $usable ? 'active' : ((int) $row['is_active'] === 1 ? 'expired' : 'disabled'),
        ];
    }
}
