<?php

declare(strict_types=1);

namespace Belprise\Media;

use Belprise\Timecode;

/**
 * Metadata read by ffprobe on the server (CAHIER_DES_CHARGES §5.7 bis), for
 * the masters the browser cannot read (MXF): size, frame rate, duration in
 * frames, start timecode. Used by the scheduled task only.
 */
final class MediaProbe
{
    /**
     * @return array{width: int, height: int, fps_num: int, fps_den: int, drop_frame: int, start_frame: int,
     *               duration_frames: int, timecode_source: string, bit_rate: int|null}
     */
    public static function read(string $ffprobe, string $file): array
    {
        $process = proc_open(
            [$ffprobe, '-v', 'error', '-print_format', 'json', '-show_streams', '-show_format', $file],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('ffprobe n’a pas pu être lancé.');
        }
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return self::parse($out);
    }

    /**
     * @return array{width: int, height: int, fps_num: int, fps_den: int, drop_frame: int, start_frame: int,
     *               duration_frames: int, timecode_source: string, bit_rate: int|null}
     */
    public static function parse(string $json): array
    {
        $data = json_decode($json, true);
        $streams = is_array($data) && is_array($data['streams'] ?? null) ? $data['streams'] : [];
        $video = null;
        foreach ($streams as $stream) {
            if (is_array($stream) && ($stream['codec_type'] ?? '') === 'video') {
                $video = $stream;
                break;
            }
        }
        if ($video === null) {
            throw new \RuntimeException('Aucune piste vidéo lisible dans ce master.');
        }
        $width = (int) ($video['width'] ?? 0);
        $height = (int) ($video['height'] ?? 0);
        if ($width < 16 || $height < 16) {
            throw new \RuntimeException('Dimensions de l’image illisibles.');
        }
        if (max($width, $height) > MediaMeta::MAX_LONG_SIDE || min($width, $height) > MediaMeta::MAX_SHORT_SIDE) {
            throw new \RuntimeException('Résolution trop élevée (' . $width . '×' . $height . ') : 4K au maximum.');
        }

        [$num, $den] = self::rate((string) ($video['r_frame_rate'] ?? ''), (string) ($video['avg_frame_rate'] ?? ''));
        $format = is_array($data['format'] ?? null) ? $data['format'] : [];
        $frames = (int) ($video['nb_frames'] ?? 0);
        if ($frames <= 0) {
            $seconds = (float) ($video['duration'] ?? $format['duration'] ?? 0);
            $frames = (int) round($seconds * $num / $den);
        }
        if ($frames <= 0) {
            throw new \RuntimeException('Durée illisible.');
        }

        // Start timecode: on the video stream, the container, or a timecode stream.
        $timecode = null;
        foreach ([$video, $format, ...$streams] as $source) {
            $tags = is_array($source['tags'] ?? null) ? $source['tags'] : [];
            if (is_string($tags['timecode'] ?? null) && $tags['timecode'] !== '') {
                $timecode = $tags['timecode'];
                break;
            }
        }
        $drop = $timecode !== null && str_contains($timecode, ';') && Timecode::supportsDropFrame($num, $den);
        $start = $timecode === null ? null : Timecode::toFrames($timecode, $num, $den, $drop);

        return [
            'width' => $width,
            'height' => $height,
            'fps_num' => $num,
            'fps_den' => $den,
            'drop_frame' => $drop ? 1 : 0,
            'start_frame' => $start ?? 0,
            'duration_frames' => $frames,
            'timecode_source' => $start === null ? 'default' : 'tmcd',
            'bit_rate' => isset($format['bit_rate']) ? (int) $format['bit_rate'] : null,
        ];
    }

    /**
     * "25/1", "30000/1001"…: the nominal rate, else the average one.
     *
     * @return array{int, int}
     */
    private static function rate(string $nominal, string $average): array
    {
        foreach ([$nominal, $average] as $rate) {
            if (preg_match('#^(\d+)/(\d+)$#', $rate, $m) === 1 && (int) $m[2] > 0) {
                $value = (int) $m[1] / (int) $m[2];
                if ($value >= 1 && $value <= 120) {
                    return [(int) $m[1], (int) $m[2]];
                }
            }
        }
        throw new \RuntimeException('Cadence illisible.');
    }
}
