<?php

declare(strict_types=1);

namespace Belprise\Tests;

use Belprise\Media\ServerEncoder;
use Belprise\Repository\EncodeJobs;
use Belprise\Repository\Settings;
use Belprise\Storage;
use Belprise\Tests\Support\LifecycleTestCase;

/**
 * Version 3.0: the playback versions encoded on the server (CAHIER_DES_CHARGES
 * §5.7 bis, criterion 23), with a fake FFmpeg (tests/fixtures/bin): the
 * queue, the command, a failure, a cut, the switch made by the operator.
 */
final class ServerEncodingTest extends LifecycleTestCase
{
    private const FAKE = __DIR__ . '/../fixtures/bin';

    /** @var array<string, mixed> */
    private array $fixture;

    protected function extraConfig(): array
    {
        return ['ffmpeg' => realpath(self::FAKE . '/ffmpeg')];
    }

    protected function setUp(): void
    {
        parent::setUp();
        putenv('FAKE_FFMPEG');
        putenv('FAKE_AUDIO');
        putenv('FAKE_FFMPEG_LOG=' . $this->tmp . '/ffmpeg-args.txt');
        $this->fixture = $this->createClientProjectVideo();
    }

    protected function tearDown(): void
    {
        putenv('FAKE_FFMPEG');
        putenv('FAKE_AUDIO');
        putenv('FAKE_FFMPEG_LOG');
        parent::tearDown();
    }

    private function serverMode(int $threads = 1): void
    {
        $settings = new Settings($this->db());
        $settings->set(ServerEncoder::MODE_KEY, 'server');
        $settings->set(ServerEncoder::THREADS_KEY, (string) $threads);
    }

    private function encoder(int $threads = 1, ?\Closure $now = null): ServerEncoder
    {
        return new ServerEncoder(
            $this->db(),
            new Storage($this->tmp . '/data'),
            ['path' => realpath(self::FAKE . '/ffmpeg'), 'probe' => realpath(self::FAKE . '/ffprobe')],
            $threads,
            $now,
        );
    }

    /** @return array<string, mixed> the version as the admin API presents it */
    private function version(int $id): array
    {
        $versions = $this->api('GET', '/api/admin/videos/' . $this->fixture['video']['id'] . '/versions');
        $list = $versions['versions'] ?? $versions;

        return array_values(array_filter($list, static fn (array $v) => $v['id'] === $id))[0];
    }

    public function testAnUploadIsEncodedByTheScheduledTaskAndTheAdminCanCloseThePage(): void
    {
        $this->serverMode(threads: 2);
        self::assertSame('server', $this->decode($this->request('GET', '/api/admin/session'))['encoding']);
        $version = $this->versionOf((int) $this->fixture['video']['id'], 2_000_000);
        self::assertSame(['status' => 'queued', 'progress' => 0, 'error' => null, 'attempts' => 0], $version['encoding'] ?? $this->version($version['id'])['encoding']);
        self::assertNull($version['hlsUrl']);

        $done = $this->encoder(2)->runOne();
        self::assertSame(['version' => $version['id'], 'status' => 'done'], array_intersect_key($done, ['version' => 1, 'status' => 1]));
        self::assertNull($this->encoder()->runOne(), 'nothing left in the queue');

        $after = $this->version($version['id']);
        self::assertNull($after['encoding']);
        self::assertNotNull($after['hlsUrl']);
        $dir = $this->tmp . '/data/' . dirname((string) $this->db()->query('SELECT hls_path FROM versions WHERE id = ' . $version['id'])->fetchColumn());
        self::assertSame(['master.m3u8', 'playlist-0.m3u8', 'playlist-0.mp4'], array_values(array_diff(scandir($dir) ?: [], ['.', '..'])));
        self::assertSame([], glob($this->tmp . '/data/tmp/encode-*') ?: [], 'the work folder is gone');

        // The command: 720p at 3 Mbit/s, sound kept, the threads of the setting, same frames.
        $args = file($this->tmp . '/ffmpeg-args.txt', FILE_IGNORE_NEW_LINES) ?: [];
        self::assertContains('0:a:0', $args, 'the sound is kept');
        foreach ([['-threads', '2'], ['-b:v', '3000k'], ['-c:a', 'aac'], ['-fps_mode', 'passthrough'], ['-hls_segment_type', 'fmp4'], ['-hls_time', '2']] as [$flag, $value]) {
            self::assertSame($value, $args[array_search($flag, $args, true) + 1], $flag);
        }
        self::assertContains('scale=-2:min(720\,trunc(ih/2)*2)', $args);
        self::assertNotNull(ServerEncoder::speed(new Settings($this->db())), 'the speed is measured');
    }

    public function testAMasterWithoutSoundIsEncodedWithoutAudio(): void
    {
        $this->serverMode();
        putenv('FAKE_AUDIO=0');
        $this->versionOf((int) $this->fixture['video']['id'], 1_000_000);
        $this->encoder()->runOne();
        $args = file($this->tmp . '/ffmpeg-args.txt', FILE_IGNORE_NEW_LINES) ?: [];
        self::assertNotContains('0:a:0', $args);
        self::assertSame('v:0', $args[array_search('-var_stream_map', $args, true) + 1]);
    }

    public function testAFailureIsTriedThreeTimesThenShownAndCanBeAskedAgain(): void
    {
        $this->serverMode();
        $version = $this->versionOf((int) $this->fixture['video']['id'], 1_000_000);
        putenv('FAKE_FFMPEG=fail');
        self::assertSame('retry', $this->encoder()->runOne()['status']);
        self::assertSame('retry', $this->encoder()->runOne()['status']);
        $last = $this->encoder()->runOne();
        self::assertSame('failed', $last['status']);
        self::assertStringContainsString('Invalid data found', $last['details']);
        self::assertNull($this->encoder()->runOne(), 'a failed job is not taken again by itself');

        $shown = $this->version($version['id'])['encoding'];
        self::assertSame(['failed', 3], [$shown['status'], $shown['attempts']]);
        self::assertStringStartsWith('FFmpeg a échoué', (string) $shown['error']);

        putenv('FAKE_FFMPEG');
        self::assertSame('queued', $this->api('POST', '/api/admin/versions/' . $version['id'] . '/encode')['encoding']['status']);
        self::assertSame('done', $this->encoder()->runOne()['status']);
    }

    public function testACutEncodingStartsAgainAtTheNextRun(): void
    {
        $this->serverMode();
        $version = $this->versionOf((int) $this->fixture['video']['id'], 1_000_000);
        putenv('FAKE_FFMPEG=killed');
        $cut = $this->encoder()->runOne();
        self::assertSame('retry', $cut['status']);
        self::assertStringContainsString('arrêté', $cut['details']);

        // A job left "running" by a process killed with the cron itself: started again once stale.
        $jobs = new EncodeJobs($this->db());
        $job = $jobs->forVersion($version['id']);
        $jobs->start((int) $job['id'], time());
        self::assertNull($jobs->next(time()), 'still running: not taken twice');
        self::assertSame((int) $job['id'], (int) $jobs->next(time() + EncodeJobs::STALE_AFTER + 1)['id']);
        putenv('FAKE_FFMPEG');
        self::assertSame('done', $this->encoder(1, static fn (): int => time() + EncodeJobs::STALE_AFTER + 1)->runOne()['status']);
    }

    public function testAJobCutAtEveryTryFailsInsteadOfHoldingUpTheQueue(): void
    {
        $this->serverMode();
        $first = $this->versionOf((int) $this->fixture['video']['id'], 1_000_000);
        $jobs = new EncodeJobs($this->db());
        $job = (int) $jobs->forVersion($first['id'])['id'];
        $now = time();
        for ($try = 1; $try <= EncodeJobs::MAX_ATTEMPTS; $try++) {
            // Each run takes it again, then the process is killed with the cron.
            $now += EncodeJobs::STALE_AFTER + 1;
            self::assertSame($job, (int) $jobs->next($now)['id'], 'try ' . $try);
            $jobs->start($job, $now);
        }
        self::assertNull($jobs->next($now + EncodeJobs::STALE_AFTER + 1), 'given up after the last try');
        $row = $jobs->forVersion($first['id']);
        self::assertSame('failed', $row['status']);
        self::assertStringContainsString('interrompu', (string) $row['error']);
    }

    public function testFfmpegReadsTheMasterWithTheFormatOfItsExtensionAndLocalFilesOnly(): void
    {
        self::assertSame(['-protocol_whitelist', 'file', '-f', 'mov', '-i', '/d/m/master.mp4'], ServerEncoder::input('/d/m/master.mp4'));
        self::assertSame(['-protocol_whitelist', 'file', '-f', 'mov', '-i', '/d/m/master.MOV'], ServerEncoder::input('/d/m/master.MOV'));
        self::assertSame(['-protocol_whitelist', 'file', '-f', 'mxf', '-i', '/d/m/master.mxf'], ServerEncoder::input('/d/m/master.mxf'));
        $this->serverMode();
        $this->versionOf((int) $this->fixture['video']['id'], 1_000_000);
        $log = $this->tmp . '/ffmpeg-args.log';
        putenv('FAKE_FFMPEG_LOG=' . $log);
        try {
            $this->encoder()->runOne();
        } finally {
            putenv('FAKE_FFMPEG_LOG');
        }
        $args = file($log, FILE_IGNORE_NEW_LINES);
        $i = array_search('-i', $args, true);
        self::assertSame(['-protocol_whitelist', 'file', '-f', 'mov'], array_slice($args, $i - 4, 4));
    }

    public function testTheServerMakesThePosterThumbnailAndSheetWhenTheVersionHasNone(): void
    {
        $this->serverMode();
        $version = $this->versionOf((int) $this->fixture['video']['id'], 1_000_000);
        self::assertNull($version['posterUrl']);
        self::assertStringContainsString('images créées', $this->encoder()->runOne()['details']);

        $row = $this->db()->query('SELECT poster_path, poster_frame, thumb_path, sprite_path, sprite_meta FROM versions WHERE id = ' . $version['id'])->fetch();
        foreach (['poster_path', 'thumb_path', 'sprite_path'] as $column) {
            self::assertFileExists($this->tmp . '/data/' . $row[$column], $column);
        }
        // 60 s at 25 frames a second: the poster at 10 %, one tile a second in 10 columns, like the browser.
        self::assertSame(150, (int) $row['poster_frame']);
        self::assertSame(['columns' => 10, 'count' => 60, 'width' => 256, 'height' => 144, 'interval' => 1], json_decode((string) $row['sprite_meta'], true));
        self::assertNotNull($this->version($version['id'])['spriteUrl']);

        // Images already there (made by the browser): kept as they are.
        $this->db()->exec("UPDATE versions SET poster_path = 'mine.jpg' WHERE id = " . $version['id']);
        $this->api('POST', '/api/admin/versions/' . $version['id'] . '/encode');
        self::assertStringNotContainsString('images', $this->encoder()->runOne()['details']);
        self::assertSame('mine.jpg', $this->db()->query('SELECT poster_path FROM versions WHERE id = ' . $version['id'])->fetchColumn());
    }

    public function testAnMxfWithoutItsH264FileIsReadAndEncodedByTheServer(): void
    {
        file_put_contents($this->stagingPath('Plan.mxf'), "\x06\x0e\x2b\x34" . str_repeat("\0", 4096));
        // In the browser, an MXF needs its H.264 file: no metadata, no version.
        $this->attach(['name' => 'Plan.mxf', 'videoId' => $this->fixture['video']['id'], 'meta' => null], 422);

        $this->serverMode();
        file_put_contents($this->stagingPath('Plan.mxf'), "\x06\x0e\x2b\x34" . str_repeat("\0", 4096));
        $version = $this->attach(['name' => 'Plan.mxf', 'videoId' => $this->fixture['video']['id'], 'meta' => null]);
        self::assertSame(['mxf', null, null, 'queued'], [$version['codec'], $version['fpsNum'], $version['durationFrames'], $version['encoding']['status']]);

        self::assertSame('done', $this->encoder()->runOne()['status']);
        $after = $this->version($version['id']);
        self::assertSame([1920, 1080, 25, 1, 250, '10:00:00:00', 'tmcd'], [
            $after['width'], $after['height'], $after['fpsNum'], $after['fpsDen'], $after['durationFrames'], $after['startTimecode'], $after['timecodeSource'],
        ]);
        self::assertNotNull($after['hlsUrl']);
        self::assertNotNull($after['posterUrl']);
    }

    public function testAnUnreadableMasterIsReported(): void
    {
        $this->serverMode();
        file_put_contents($this->stagingPath('Plan.mxf'), "\x06\x0e\x2b\x34" . str_repeat("\0", 4096));
        $version = $this->attach(['name' => 'Plan.mxf', 'videoId' => $this->fixture['video']['id'], 'meta' => null]);
        putenv('FAKE_PROBE_JSON={"streams":[{"codec_type":"audio"}]}');
        try {
            $result = $this->encoder()->runOne();
        } finally {
            putenv('FAKE_PROBE_JSON');
        }
        self::assertSame('retry', $result['status']);
        self::assertSame('Aucune piste vidéo lisible dans ce master.', $this->version($version['id'])['encoding']['error']);
    }

    public function testInBrowserModeNothingIsQueued(): void
    {
        self::assertSame('browser', $this->decode($this->request('GET', '/api/admin/session'))['encoding']);
        $version = $this->versionOf((int) $this->fixture['video']['id'], 1_000_000);
        self::assertNull($this->version($version['id'])['encoding']);
        self::assertNull($this->encoder()->runOne());
        self::assertSame(409, $this->request('POST', '/api/admin/versions/' . $version['id'] . '/encode', [
            'X-CSRF-Token' => (string) ($this->session->data['csrf'] ?? ''), 'Content-Type' => 'application/json',
        ], '{}')->status);
    }

    public function testTheOperatorChoosesTheModeAndTheThreads(): void
    {
        $this->asOperator();
        $state = $this->decode($this->request('GET', '/api/superadmin/organizations'))['encoding'];
        self::assertSame(['browser', 1, 'FFmpeg 9.9-fake', true], [$state['mode'], $state['threads'], $state['ffmpeg']['version'], $state['ffmpeg']['probe']]);
        self::assertNull($state['speed']);
        $version = $this->decode($this->request('GET', '/api/superadmin/organizations'))['version'];
        self::assertSame(['développement', null, false, true], [$version['installed'], $version['latest'], $version['newer'], $version['checking']]);

        self::assertSame(422, $this->operator('PATCH', '/api/superadmin/encoding', ['mode' => 'gpu'])->status);
        self::assertSame(422, $this->operator('PATCH', '/api/superadmin/encoding', ['threads' => 0])->status);
        $set = $this->decode($this->operator('PATCH', '/api/superadmin/encoding', ['mode' => 'server', 'threads' => 2]));
        self::assertSame(['server', 2], [$set['mode'], $set['threads']]);
        self::assertSame('server', ServerEncoder::mode(new Settings($this->db())));

        // An organization's administrator cannot change it.
        $this->asAdmin();
        self::assertSame(401, $this->operator('PATCH', '/api/superadmin/encoding', ['mode' => 'browser'])->status);
    }
}
