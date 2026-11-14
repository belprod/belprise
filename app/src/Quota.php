<?php

declare(strict_types=1);

namespace Belprise;

use Belprise\Http\HttpException;
use Belprise\Support\Dates;
use PDO;

/**
 * Storage of an organization against its quota (CAHIER_DES_CHARGES §5.12): a
 * number of bytes set by the operator, or no limit (the disk is then the only
 * bound, checked by the uploads).
 * Counted on disk: masters, posters, thumbnails, sprites (data/media/{client}/),
 * client logos and the parts of uploads in progress. An upload in progress
 * also reserves what it has not received yet, so two uploads cannot both
 * take the last free gigabytes.
 */
final class Quota
{
    public function __construct(
        private readonly PDO $db,
        private readonly Storage $storage,
    ) {
    }

    /**
     * @return array{quota: int|null, quotaLabel: string, used: int, reserved: int, ratio: float}
     */
    public function usage(int $organizationId): array
    {
        $stmt = $this->db->prepare('SELECT quota_bytes FROM organizations WHERE id = ?');
        $stmt->execute([$organizationId]);
        $value = $stmt->fetchColumn();
        $quota = $value === false || $value === null ? null : (int) $value;

        $used = 0;
        $clients = $this->db->prepare('SELECT id, logo FROM clients WHERE organization_id = ?');
        $clients->execute([$organizationId]);
        foreach ($clients->fetchAll() as $client) {
            $used += $this->sizeOf(Storage::clientMediaDir((int) $client['id']));
            if (is_string($client['logo']) && $client['logo'] !== '') {
                $used += $this->sizeOf($client['logo']);
            }
        }
        $logos = $this->db->prepare('SELECT logo FROM collections WHERE organization_id = ? AND logo IS NOT NULL
            UNION ALL SELECT logo FROM organizations WHERE id = ? AND logo IS NOT NULL');
        $logos->execute([$organizationId, $organizationId]);
        foreach ($logos->fetchAll(PDO::FETCH_COLUMN) as $logo) {
            if (is_string($logo) && $logo !== '') {
                $used += $this->sizeOf($logo);
            }
        }
        $reserved = 0;
        $uploads = $this->db->prepare(
            'SELECT u.id, u.size_bytes, u.bytes_received FROM uploads u JOIN videos v ON v.id = u.video_id
             JOIN projects p ON p.id = v.project_id JOIN clients c ON c.id = p.client_id WHERE c.organization_id = ?'
        );
        $uploads->execute([$organizationId]);
        foreach ($uploads->fetchAll() as $upload) {
            $used += $this->sizeOf(Storage::uploadPart((int) $upload['id']));
            $reserved += max(0, (int) $upload['size_bytes'] - (int) $upload['bytes_received']);
        }

        return [
            'quota' => $quota,
            'quotaLabel' => self::label($quota),
            'used' => $used,
            'reserved' => $reserved,
            'ratio' => $quota === null ? 0.0 : round(($used + $reserved) / $quota, 4),
        ];
    }

    /** Bytes over the quota (0 when it fits, or without a limit). */
    public static function excess(array $usage): int
    {
        return $usage['quota'] === null ? 0 : max(0, $usage['used'] + $usage['reserved'] - $usage['quota']);
    }

    /** "250 Go", or "Sans limite". */
    public static function label(?int $quota): string
    {
        return $quota === null ? 'Sans limite' : self::gigabytes($quota);
    }

    /** Refuses, before the first byte, an import that would go over the quota. */
    public function ensureRoom(int $organizationId, int $bytes): void
    {
        $this->ensureUploadsOpen($organizationId);
        $usage = $this->usage($organizationId);
        if ($usage['quota'] === null) {
            return;
        }
        $free = max(0, $usage['quota'] - $usage['used'] - $usage['reserved']);
        if ($bytes > $free) {
            throw new HttpException(422, sprintf(
                'Espace insuffisant (quota de %s) : %s disponibles, %s nécessaires.',
                $usage['quotaLabel'],
                self::gigabytes($free),
                self::gigabytes($bytes),
            ), ['quota' => true]);
        }
    }

    /**
     * New uploads stay blocked while a downgrade awaits its purge or the
     * organization awaits its deletion (§5.10, §5.12).
     */
    public function ensureUploadsOpen(int $organizationId): void
    {
        $stmt = $this->db->prepare('SELECT purge_at, deletion_at FROM organizations WHERE id = ?');
        $stmt->execute([$organizationId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return;
        }
        if ($row['deletion_at'] !== null) {
            throw new HttpException(422, 'La suppression de votre organisation est programmée le ' . Dates::frenchDay((string) $row['deletion_at'])
                . ' : les nouveaux envois sont bloqués.', ['quota' => true]);
        }
        if ($row['purge_at'] !== null) {
            throw new HttpException(422, 'Le quota de votre organisation a été abaissé : les nouveaux envois sont bloqués jusqu’à ce que le stockage tienne dans le quota (purge prévue le '
                . Dates::frenchDay((string) $row['purge_at']) . ').', ['quota' => true]);
        }
    }

    public static function gigabytes(int $bytes): string
    {
        if ($bytes >= 1e12) {
            return rtrim(rtrim(number_format($bytes / 1e12, 1, ',', ' '), '0'), ',') . ' To';
        }

        if ($bytes < 1e9) {
            return number_format($bytes / 1e6, $bytes < 10e6 ? 1 : 0, ',', ' ') . ' Mo';
        }

        return number_format($bytes / 1e9, $bytes < 10e9 ? 1 : 0, ',', ' ') . ' Go';
    }

    private function sizeOf(string $relative): int
    {
        return $this->storage->treeSize($relative);
    }
}
