<?php

declare(strict_types=1);

namespace Belprise\Media;

use Belprise\Http\HttpException;

/**
 * Masters accepted (CAHIER_DES_CHARGES §5.7): MP4 / MOV (H.264, HEVC,
 * ProRes) and MXF. H.264 and HEVC are played by browsers; ProRes and MXF
 * are kept for download and reviewed through their playback versions.
 */
final class MasterFormat
{
    public const TYPES = ['mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'mxf' => 'application/mxf'];

    /** Codec strings as measured by mp4box.js; "mxf" when only the playback file could be analysed. */
    public const CODEC_PATTERN = '/^(avc[13]\.[0-9a-fA-F]{6}|(hvc1|hev1)\.[0-9A-Za-z.]{1,40}|apch|apcn|apcs|apco|ap4h|ap4x|mxf)$/D';

    public static function extension(string $name): string
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$extension])) {
            throw new HttpException(422, 'Formats acceptés : .mp4, .mov (H.264, HEVC, ProRes) et .mxf.', ['field' => 'name']);
        }

        return $extension;
    }

    public static function contentType(string $path): ?string
    {
        return self::TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;
    }

    /** Cheap server-side check of the container: ISO / QuickTime box, or MXF partition pack. */
    public static function assertContainer(string $file, string $extension): void
    {
        $head = @file_get_contents($file, false, null, 0, 16);
        $ok = is_string($head) && strlen($head) >= 12 && match ($extension) {
            'mxf' => str_starts_with($head, "\x06\x0e\x2b\x34"),
            // MP4 starts with "ftyp"; old QuickTime files may start with wide / free / mdat / moov.
            default => in_array(substr($head, 4, 4), ['ftyp', 'wide', 'free', 'skip', 'mdat', 'moov', 'pnot'], true),
        };
        if (!$ok) {
            throw new HttpException(422, 'Ce fichier ne correspond pas à son extension (.' . $extension . ').');
        }
    }

    /** H.264 or HEVC: playable as is by browsers. */
    public static function isPlayable(string $codec): bool
    {
        return preg_match('/^(avc[13]|hvc1|hev1)\./', $codec) === 1;
    }
}
