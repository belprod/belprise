<?php

declare(strict_types=1);

namespace Belprise\Media;

use Belprise\Repository\EncodeJobs;
use Belprise\Repository\Settings;
use Belprise\Repository\Versions;
use Belprise\Storage;
use Closure;
use PDO;

/**
 * Playback versions encoded on the server (CAHIER_DES_CHARGES §5.7 bis), when
 * the operator chose it and FFmpeg is there: run by the scheduled task only,
 * never during a web request, one version at a time, at low priority, with
 * few threads (shared hosts cut a process that uses more than one CPU).
 * Same result as in the browser: 720p at 3 Mbit/s, AAC 128 kbit/s, HLS in
 * fragmented MP4 with 2-second segments, one file, published by HlsStore.
 * Before: the metadata the browser could not read (MXF), from ffprobe.
 * After: poster, thumbnail and thumbnail sheet when the version has none
 * (ProRes, MXF sent without an H.264 file), like the browser makes them.
 */
final class ServerEncoder
{
    public const MODE_KEY = 'encoding.mode';
    public const THREADS_KEY = 'encoding.threads';
    public const SPEED_KEY = 'encoding.speed';
    public const MODES = ['browser', 'server'];
    public const MAX_THREADS = 16;

    private const HEIGHT = 720;
    private const VIDEO_KBPS = 3000;
    private const AUDIO_KBPS = 128;
    private const SEGMENT_SECONDS = 2;
    /** Progress and heartbeat written at most this often. */
    private const REPORT_EVERY = 2;
    /** Same images as the browser (frontend/src/admin/media/images.ts). */
    private const TILE = [256, 144];
    private const MAX_TILES = 200;
    private const COLUMNS = 10;

    /** @var Closure(): int */
    private readonly Closure $now;

    /**
     * @param array{path: string, probe: string|null} $ffmpeg
     * @param (Closure(): int)|null $now
     */
    public function __construct(
        private readonly PDO $db,
        private readonly Storage $storage,
        private readonly array $ffmpeg,
        private readonly int $threads = 1,
        ?Closure $now = null,
    ) {
        $this->now = $now ?? static fn (): int => time();
    }

    /** "browser" (default) or "server". */
    public static function mode(Settings $settings): string
    {
        return $settings->get(self::MODE_KEY) === 'server' ? 'server' : 'browser';
    }

    public static function threads(Settings $settings): int
    {
        return max(1, min(self::MAX_THREADS, (int) ($settings->get(self::THREADS_KEY) ?? 1)));
    }

    /**
     * Speed measured on the last encoding: seconds of video per second of work.
     *
     * @return array{ratio: float, threads: int, at: string}|null
     */
    public static function speed(Settings $settings): ?array
    {
        $raw = $settings->get(self::SPEED_KEY);
        $speed = $raw === null ? null : json_decode($raw, true);

        return is_array($speed) && isset($speed['ratio']) ? $speed : null;
    }

    /**
     * Encodes the next version of the queue, if any.
     *
     * @return array{version: int, status: string, details: string}|null
     */
    public function runOne(): ?array
    {
        $jobs = new EncodeJobs($this->db);
        $job = $jobs->next(($this->now)());
        if ($job === null) {
            return null;
        }
        $id = (int) $job['id'];
        $versionId = (int) $job['version_id'];
        $jobs->start($id, ($this->now)());
        $versions = new Versions($this->db);
        $version = $versions->find($versionId);
        if ($version === null || $version['file_path'] === null) {
            $jobs->failed($id, 'Le master de cette version est introuvable.');

            return ['version' => $versionId, 'status' => 'failed', 'details' => 'master introuvable'];
        }
        $master = $this->storage->absolute((string) $version['file_path']);

        // Metadata the browser could not read (MXF): from ffprobe, before anything else.
        if ($version['fps_num'] === null || $version['duration_frames'] === null || $version['width'] === null) {
            try {
                if ($this->ffmpeg['probe'] === null) {
                    throw new \RuntimeException('ffprobe est introuvable à côté de FFmpeg : les informations du master ne peuvent pas être lues.');
                }
                $versions->update($versionId, MediaProbe::read($this->ffmpeg['probe'], $master));
                $version = $versions->find($versionId) ?? $version;
            } catch (\RuntimeException $e) {
                $status = $jobs->failed($id, $e->getMessage());

                return ['version' => $versionId, 'status' => $status === 'failed' ? 'failed' : 'retry', 'details' => $e->getMessage()];
            }
        }

        $work = 'tmp/encode-' . $id;
        $this->storage->deleteTree($work);
        $dir = $this->storage->absolute($work);
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create ' . $dir);
        }
        $seconds = $this->duration($version);
        $started = ($this->now)();
        if (function_exists('proc_nice')) {
            @proc_nice(19); // low priority: the web requests come first
        }

        try {
            $error = $this->encode($this->command($master, $dir, $this->hasAudio($master)), $seconds, $id, $jobs);
            if ($error === null) {
                $this->publish($version, $dir);
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        } finally {
            $this->storage->deleteTree($work);
        }

        if ($error !== null) {
            $status = $jobs->failed($id, $error);

            return ['version' => $versionId, 'status' => $status === 'failed' ? 'failed' : 'retry', 'details' => $error];
        }
        $elapsed = max(1, ($this->now)() - $started);
        $images = '';
        if ($version['poster_path'] === null && $seconds > 0) {
            try {
                $this->images($version, $master, $seconds);
                $images = ', images créées';
            } catch (\RuntimeException $e) {
                $images = ', sans images (' . $e->getMessage() . ')';
            }
        }
        $jobs->done($id, ($this->now)());
        if ($seconds > 0) {
            (new Settings($this->db))->set(self::SPEED_KEY, (string) json_encode([
                'ratio' => round($seconds / $elapsed, 2), 'threads' => $this->threads, 'at' => gmdate('Y-m-d H:i:s', ($this->now)()),
            ]));
        }

        return ['version' => $versionId, 'status' => 'done', 'details' => sprintf('%d s de vidéo en %d s', (int) $seconds, $elapsed) . $images];
    }

    /**
     * Poster (at 10 % of the video), 640-pixel thumbnail and thumbnail sheet
     * (one tile a second, 200 at most, 256×144, 10 columns), as JPEG files of
     * the version, with the same layout as the browser's.
     *
     * @param array<string, mixed> $version
     */
    private function images(array $version, string $master, float $seconds): void
    {
        $dir = Storage::versionDir((int) $version['client_id'], (int) $version['video_number'], (int) $version['number']);
        $absolute = $this->storage->absolute($dir);
        $threads = (string) $this->threads;
        $base = [$this->ffmpeg['path'], '-hide_banner', '-nostdin', '-loglevel', 'error', '-y', '-threads', $threads];

        $posterTime = $seconds * 0.1;
        $poster = array_merge($base, [
            '-ss', sprintf('%.3f', $posterTime), ...self::input($master),
            '-filter_complex', '[0:v]split=2[a][b];[a]scale=w=min(1920\,iw):h=-2[p];[b]scale=640:-2[t]',
            '-map', '[p]', '-frames:v', '1', '-q:v', '3', $absolute . '/poster.jpg',
            '-map', '[t]', '-frames:v', '1', '-q:v', '4', $absolute . '/thumb.jpg',
        ]);
        if ($this->run($poster) !== 0 || !is_file($absolute . '/poster.jpg') || !is_file($absolute . '/thumb.jpg')) {
            throw new \RuntimeException('couverture non créée');
        }

        $count = min(self::MAX_TILES, max(1, (int) floor($seconds)));
        $interval = $seconds / $count;
        $columns = min($count, self::COLUMNS);
        $rows = (int) ceil($count / $columns);
        [$w, $h] = self::TILE;
        $sprite = array_merge($base, [
            // Tile i shows the frame at (i + 0.5) × interval.
            '-ss', sprintf('%.3f', $interval / 2), ...self::input($master),
            '-vf', sprintf('fps=%.6f,scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:(ow-iw)/2:(oh-ih)/2:black,tile=%dx%d', $count / $seconds, $w, $h, $w, $h, $columns, $rows),
            '-frames:v', '1', '-q:v', '5', $absolute . '/sprite.jpg',
        ]);
        $hasSprite = $this->run($sprite) === 0 && is_file($absolute . '/sprite.jpg');

        $num = (int) $version['fps_num'];
        $den = (int) $version['fps_den'];
        (new Versions($this->db))->update((int) $version['id'], [
            'poster_path' => $dir . '/poster.jpg',
            'poster_frame' => min(max(0, (int) $version['duration_frames'] - 1), (int) round($posterTime * $num / $den)),
            'thumb_path' => $dir . '/thumb.jpg',
        ] + ($hasSprite ? [
            'sprite_path' => $dir . '/sprite.jpg',
            'sprite_meta' => json_encode(['columns' => $columns, 'count' => $count, 'width' => $w, 'height' => $h, 'interval' => $interval], JSON_THROW_ON_ERROR),
        ] : []));
        if (!$hasSprite) {
            throw new \RuntimeException('planche de miniatures non créée');
        }
    }

    /**
     * Speed test (php app/bin/encode-test.php): a 20-second 1080p H.264 clip,
     * made first (not timed), then encoded exactly like a version. Returns
     * the speed: seconds of video per second of work.
     */
    public function benchmark(int $seconds = 20): float
    {
        $work = 'tmp/encode-test';
        $this->storage->deleteTree($work);
        $dir = $this->storage->absolute($work);
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create ' . $dir);
        }
        try {
            $clip = $dir . '/clip.mp4';
            $make = [$this->ffmpeg['path'], '-hide_banner', '-nostdin', '-loglevel', 'error', '-y',
                '-f', 'lavfi', '-i', 'testsrc2=size=1920x1080:rate=25:duration=' . $seconds,
                '-c:v', 'libx264', '-preset', 'ultrafast', '-b:v', '8000k', '-pix_fmt', 'yuv420p', $clip];
            if ($this->run($make) !== 0) {
                throw new \RuntimeException('FFmpeg n’a pas pu fabriquer la vidéo d’essai.');
            }
            if (function_exists('proc_nice')) {
                @proc_nice(19);
            }
            $started = microtime(true);
            if ($this->run($this->command($clip, $dir, false)) !== 0) {
                throw new \RuntimeException('L’encodage d’essai a échoué (limite de l’hébergement ?).');
            }
            $ratio = round($seconds / max(0.01, microtime(true) - $started), 2);
        } finally {
            $this->storage->deleteTree($work);
        }
        (new Settings($this->db))->set(self::SPEED_KEY, (string) json_encode([
            'ratio' => $ratio, 'threads' => $this->threads, 'at' => gmdate('Y-m-d H:i:s', ($this->now)()),
        ]));

        return $ratio;
    }

    /** @param list<string> $command */
    private function run(array $command): int
    {
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);

        return is_resource($process) ? proc_close($process) : -1;
    }

    /**
     * The FFmpeg command (no shell: every argument stays as it is).
     *
     * @return list<string>
     */
    public function command(string $master, string $dir, bool $audio): array
    {
        $kbps = self::VIDEO_KBPS;
        $threads = (string) $this->threads;

        return array_merge(
            [$this->ffmpeg['path'], '-hide_banner', '-nostdin', '-nostats', '-loglevel', 'error', '-y', '-threads', $threads, ...self::input($master)],
            ['-map', '0:v:0'],
            $audio ? ['-map', '0:a:0'] : [],
            [
                // 720p (the master's height when smaller), same frames as the master: the timecode stays exact.
                '-vf', 'scale=-2:min(' . self::HEIGHT . '\,trunc(ih/2)*2)', '-fps_mode', 'passthrough', '-filter_threads', '1',
                '-c:v', 'libx264', '-preset', 'veryfast', '-profile:v', 'high', '-pix_fmt', 'yuv420p', '-threads', $threads,
                '-x264-params', 'rc-lookahead=10',
                '-b:v', $kbps . 'k', '-maxrate', (int) ($kbps * 1.1) . 'k', '-bufsize', ($kbps * 2) . 'k',
                '-sc_threshold', '0', '-force_key_frames', 'expr:gte(t,n_forced*' . self::SEGMENT_SECONDS . ')',
            ],
            $audio ? ['-c:a', 'aac', '-b:a', self::AUDIO_KBPS . 'k', '-ac', '2'] : [],
            [
                '-f', 'hls', '-hls_time', (string) self::SEGMENT_SECONDS, '-hls_playlist_type', 'vod',
                '-hls_segment_type', 'fmp4', '-hls_flags', 'single_file',
                '-hls_segment_filename', $dir . '/playlist-%v.mp4', '-master_pl_name', 'master.m3u8',
                '-var_stream_map', $audio ? 'v:0,a:0' : 'v:0',
                '-progress', 'pipe:1',
                $dir . '/playlist-%v.m3u8',
            ],
        );
    }

    /**
     * Runs FFmpeg, reporting the progress; null when it went well, else the error.
     *
     * @param list<string> $command
     */
    private function encode(array $command, float $seconds, int $jobId, EncodeJobs $jobs): ?string
    {
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return 'FFmpeg n’a pas pu être lancé.';
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $buffer = '';
        $errors = '';
        $reported = 0;
        while (!feof($pipes[1]) || !feof($pipes[2])) {
            $read = array_filter([$pipes[1], $pipes[2]], static fn ($p) => !feof($p));
            $write = $except = null;
            if (@stream_select($read, $write, $except, 1) === false) {
                break;
            }
            foreach ($read as $stream) {
                $chunk = (string) fread($stream, 8192);
                if ($stream === $pipes[2]) {
                    $errors = substr($errors . $chunk, -2000);
                    continue;
                }
                $buffer .= $chunk;
                while (($nl = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $nl);
                    $buffer = substr($buffer, $nl + 1);
                    if ($seconds > 0 && preg_match('/^out_time_us=(\d+)/', $line, $m) === 1 && ($this->now)() - $reported >= self::REPORT_EVERY) {
                        $reported = ($this->now)();
                        $jobs->progress($jobId, ((int) $m[1] / 1e6) / $seconds, $reported);
                    }
                }
            }
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        // The status says whether a signal stopped it (the exit code alone does not).
        do {
            $status = proc_get_status($process);
            if ($status['running']) {
                usleep(50_000);
            }
        } while ($status['running']);
        proc_close($process);
        if ($status['signaled'] || $status['exitcode'] > 128) {
            return 'FFmpeg a été arrêté (limite de l’hébergement ?).';
        }
        if ($status['exitcode'] !== 0) {
            $last = trim((string) preg_replace('/\s+/', ' ', $errors));

            return 'FFmpeg a échoué' . ($last === '' ? '.' : ' : ' . mb_substr($last, -300));
        }

        return null;
    }

    /** The files of FFmpeg become the playback version of the version (same checks as the browser's). */
    private function publish(array $version, string $dir): void
    {
        foreach (['master.m3u8', 'playlist-0.m3u8', 'playlist-0.mp4'] as $file) {
            if (!is_file($dir . '/' . $file)) {
                throw new \RuntimeException('FFmpeg n’a pas produit ' . $file . '.');
            }
        }
        $store = new HlsStore($this->db, $this->storage);
        $store->clear($version);
        $store->storeFile($version, $dir . '/playlist-0.mp4', HlsStore::DIR . '/playlist-0.mp4');
        $store->publish($version, [
            'master.m3u8' => (string) file_get_contents($dir . '/master.m3u8'),
            'playlist-0.m3u8' => (string) file_get_contents($dir . '/playlist-0.m3u8'),
        ]);
    }

    /**
     * The master as input of ffmpeg / ffprobe: its format is the one of its
     * extension (never guessed from the bytes), and only local files are read
     * (an uploaded file posing as a playlist cannot pull in another file).
     *
     * @return list<string>
     */
    public static function input(string $master): array
    {
        $format = strtolower(pathinfo($master, PATHINFO_EXTENSION)) === 'mxf' ? 'mxf' : 'mov';

        return ['-protocol_whitelist', 'file', '-f', $format, '-i', $master];
    }

    private function hasAudio(string $master): bool
    {
        if ($this->ffmpeg['probe'] === null) {
            return false;
        }
        $process = proc_open(
            [$this->ffmpeg['probe'], '-v', 'error', '-select_streams', 'a', '-show_entries', 'stream=index', '-of', 'csv=p=0', ...self::input($master)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            return false;
        }
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return trim($out) !== '';
    }

    /** Seconds of the version, from its frames and frame rate (0 when unknown). */
    private function duration(array $version): float
    {
        $frames = (int) ($version['duration_frames'] ?? 0);
        $num = (int) ($version['fps_num'] ?? 0);
        $den = (int) ($version['fps_den'] ?? 0);

        return $frames > 0 && $num > 0 && $den > 0 ? $frames * $den / $num : 0.0;
    }
}
