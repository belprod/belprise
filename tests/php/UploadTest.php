<?php

declare(strict_types=1);

namespace Belprise\Tests;

use Belprise\Controller\Admin\UploadsController;
use Belprise\Http\Response;
use Belprise\Tests\Support\AppTestCase;

/**
 * Chunked upload (§5.7): resume, duplicate chunk, corrupt chunk,
 * fingerprint, and the version it produces.
 */
final class UploadTest extends AppTestCase
{
    private const META = [
        'codec' => 'avc1.640028', 'width' => 1920, 'height' => 1080, 'fpsNum' => 25, 'fpsDen' => 1,
        'dropFrame' => false, 'startFrame' => 90000, 'durationFrames' => 1500, 'bitRate' => 20000000,
        'timecodeSource' => 'tmcd',
    ];

    private string $content;
    private array $video;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
        $this->video = $this->createClientProjectVideo()['video'];
        // A fake MP4: "ftyp" box then random bytes, a bit over 9 MiB (three 4 MiB chunks in tests).
        $this->content = "\x00\x00\x00\x18ftypisom" . random_bytes(9 * 1024 * 1024 + 123);
    }

    private function fingerprint(string $content, string $name): string
    {
        $sample = min(strlen($content), UploadsController::FINGERPRINT_SAMPLE);

        return hash('sha256', $name . "\n" . strlen($content) . "\n"
            . hash('sha256', substr($content, 0, $sample)) . "\n" . hash('sha256', substr($content, -$sample)));
    }

    private function start(string $name = 'Master_V1.mp4'): array
    {
        return $this->api('POST', '/api/admin/uploads', [
            'videoId' => $this->video['id'], 'name' => $name, 'size' => strlen($this->content),
            'fingerprint' => $this->fingerprint($this->content, $name),
        ], 201);
    }

    private function put(int $id, int $offset, string $chunk, ?string $hash = null): Response
    {
        return $this->request('PUT', '/api/admin/uploads/' . $id . '?offset=' . $offset, [
            'X-CSRF-Token' => (string) $this->session->data['csrf'],
            'Content-Type' => 'application/octet-stream',
            'X-Chunk-Sha256' => $hash ?? hash('sha256', $chunk),
        ], $chunk);
    }

    private function sendAll(int $id, int $from = 0, int $chunkSize = 4 * 1024 * 1024): void
    {
        for ($offset = $from; $offset < strlen($this->content); $offset += $chunkSize) {
            $response = $this->put($id, $offset, substr($this->content, $offset, $chunkSize));
            self::assertSame(200, $response->status, $response->body);
        }
    }

    private function part(int $id): string
    {
        return $this->tmp . '/data/uploads/' . $id . '.part';
    }

    public function testDeletingAVideoRemovesItsUnfinishedUploadsAndIdsAreNeverReused(): void
    {
        $upload = $this->start();
        $this->put((int) $upload['id'], 0, substr($this->content, 0, 4 * 1024 * 1024));
        self::assertFileExists($this->part((int) $upload['id']));

        $this->api('DELETE', '/api/admin/videos/' . $this->video['id']);
        self::assertFileDoesNotExist($this->part((int) $upload['id']), 'no part left behind');

        // Ids in file paths are never handed out twice (security review, phase 10).
        $client = $this->db()->query('SELECT MAX(id) FROM clients')->fetchColumn();
        $this->api('DELETE', '/api/admin/clients/' . $client, ['confirm' => 'Maison Aurore']);
        $again = $this->createClientProjectVideo();
        self::assertGreaterThan((int) $client, (int) $again['client']['id']);
        $this->video = $again['video'];
        self::assertGreaterThan((int) $upload['id'], (int) $this->start()['id']);
    }

    public function testChunksAreWrittenInPlaceAndCompletionCreatesVersion1(): void
    {
        $upload = $this->start();
        self::assertSame(0, $upload['bytesReceived']);
        $this->sendAll($upload['id']);

        self::assertSame($this->content, file_get_contents($this->part($upload['id'])));
        $version = $this->api('POST', '/api/admin/uploads/' . $upload['id'] . '/complete', ['meta' => self::META], 201);

        self::assertSame(1, $version['number']);
        self::assertSame('in_progress', $version['status']);
        self::assertFalse($version['isPublished']);
        self::assertSame('01:00:00:00', $version['startTimecode']);
        self::assertSame('Master_V1.mp4', $version['originalName']);
        $master = $this->tmp . '/data/media/' . $this->video['clientId'] . '/1001/v1/master.mp4';
        self::assertSame($this->content, file_get_contents($master));
        self::assertFileDoesNotExist($this->part($upload['id']), 'moved, not copied');
        self::assertSame([], $this->api('GET', '/api/admin/uploads'));
    }

    public function testTheNextUploadBecomesVersion2(): void
    {
        $first = $this->start();
        $this->sendAll($first['id']);
        $this->api('POST', '/api/admin/uploads/' . $first['id'] . '/complete', ['meta' => self::META], 201);

        $this->content = "\x00\x00\x00\x18ftypisom" . random_bytes(1000);
        $second = $this->start('Master_V2.mp4');
        $this->sendAll($second['id']);
        $version = $this->api('POST', '/api/admin/uploads/' . $second['id'] . '/complete', ['meta' => self::META], 201);

        self::assertSame(2, $version['number']);
        self::assertCount(2, $this->api('GET', '/api/admin/videos/' . $this->video['id'] . '/versions'));
    }

    public function testResumeAfterTheTabWasClosed(): void
    {
        $upload = $this->start();
        $this->put($upload['id'], 0, substr($this->content, 0, 4 * 1024 * 1024));

        // Same file selected again: same fingerprint → same upload, where it stopped.
        $resumed = $this->api('POST', '/api/admin/uploads', [
            'videoId' => $this->video['id'], 'name' => 'Master_V1.mp4', 'size' => strlen($this->content),
            'fingerprint' => $this->fingerprint($this->content, 'Master_V1.mp4'),
        ]);
        self::assertSame($upload['id'], $resumed['id']);
        self::assertSame(4 * 1024 * 1024, $resumed['bytesReceived']);
        self::assertSame(4 * 1024 * 1024, $this->api('GET', '/api/admin/uploads?video=' . $this->video['id'])[0]['bytesReceived']);

        $this->sendAll($upload['id'], 4 * 1024 * 1024);
        $this->api('POST', '/api/admin/uploads/' . $upload['id'] . '/complete', ['meta' => self::META], 201);
    }

    public function testADuplicateChunkIsAcceptedAndChangesNothing(): void
    {
        $upload = $this->start();
        $chunk = substr($this->content, 0, 4 * 1024 * 1024);
        $this->put($upload['id'], 0, $chunk);
        $again = $this->put($upload['id'], 0, $chunk);

        self::assertSame(200, $again->status);
        self::assertSame(4 * 1024 * 1024, $this->decode($again)['bytesReceived']);
        self::assertSame(4 * 1024 * 1024, filesize($this->part($upload['id'])));
    }

    public function testACorruptChunkIsRefusedAndNotCounted(): void
    {
        $upload = $this->start();
        $chunk = substr($this->content, 0, 4 * 1024 * 1024);

        $bad = $this->put($upload['id'], 0, $chunk, hash('sha256', 'something else'));
        self::assertSame(422, $bad->status);
        self::assertSame(0, $this->decode($bad)['bytesReceived']);

        $flipped = $chunk;
        $flipped[100] = chr(ord($flipped[100]) ^ 1);
        self::assertSame(422, $this->put($upload['id'], 0, $flipped, hash('sha256', $chunk))->status);
        self::assertSame(0, $this->api('GET', '/api/admin/uploads/' . $upload['id'])['bytesReceived']);
    }

    public function testChunksMustBeSentInOrder(): void
    {
        $upload = $this->start();
        $gap = $this->put($upload['id'], 4 * 1024 * 1024, substr($this->content, 4 * 1024 * 1024, 1024));

        self::assertSame(409, $gap->status);
        self::assertSame(0, $this->decode($gap)['bytesReceived']);
    }

    public function testChunkSizeLimits(): void
    {
        $upload = $this->start();
        $tooBig = str_repeat('x', UploadsController::CHUNK_SIZE + 1);

        self::assertSame(413, $this->put($upload['id'], 0, $tooBig)->status);
        self::assertSame(400, $this->put($upload['id'], 0, '')->status);
        self::assertSame(400, $this->put($upload['id'], 0, $this->content . 'extra')->status, 'past the end of the file');
    }

    public function testCompletionNeedsEveryByteAndTheRightFile(): void
    {
        $upload = $this->start();
        $this->put($upload['id'], 0, substr($this->content, 0, 1024));
        $this->api('POST', '/api/admin/uploads/' . $upload['id'] . '/complete', ['meta' => self::META], 409);

        // Claimed fingerprint of another file: the received bytes do not match.
        $other = $this->api('POST', '/api/admin/uploads', [
            'videoId' => $this->video['id'], 'name' => 'Autre.mp4', 'size' => strlen($this->content),
            'fingerprint' => str_repeat('a', 64),
        ], 201);
        $this->sendAll($other['id']);
        $error = $this->api('POST', '/api/admin/uploads/' . $other['id'] . '/complete', ['meta' => self::META], 422);
        self::assertStringContainsString('ne correspond pas', $error['error']);
    }

    public function testOnlyAcceptedFormatsWithinFourK(): void
    {
        foreach (['Master.avi', 'Master.mkv', 'Master'] as $name) {
            self::assertSame('name', $this->api('POST', '/api/admin/uploads', [
                'videoId' => $this->video['id'], 'name' => $name, 'size' => 10, 'fingerprint' => str_repeat('a', 64),
            ], 422)['field'], $name);
        }

        $upload = $this->start();
        $this->sendAll($upload['id']);
        $complete = fn (array $meta) => $this->api('POST', '/api/admin/uploads/' . $upload['id'] . '/complete', ['meta' => $meta + self::META], 422);

        self::assertSame('codec', $complete(['codec' => 'vp09.00.10.08'])['field']);
        self::assertSame('width', $complete(['width' => 7680, 'height' => 4320])['field']);
        self::assertSame('fpsNum', $complete(['fpsNum' => 1000, 'fpsDen' => 1])['field']);
        self::assertSame('startFrame', $complete(['startFrame' => 2160000])['field']);
    }

    public function testMovProResAndMxfMastersAreKeptWithTheirExtension(): void
    {
        copy(__DIR__ . '/../fixtures/media/tc25-01000000.mp4', $this->stagingPath('Plan.mov'));
        $mov = $this->attach(['name' => 'Plan.mov', 'videoId' => $this->video['id'], 'meta' => self::META]);
        file_put_contents($this->stagingPath('Plan_A001.mxf'), "\x06\x0e\x2b\x34" . random_bytes(2000));
        $mxf = $this->attach(['name' => 'Plan_A001.mxf', 'videoId' => $this->video['id'], 'meta' => ['codec' => 'mxf'] + self::META]);
        file_put_contents($this->stagingPath('ProRes.mov'), substr((string) file_get_contents(__DIR__ . '/../fixtures/media/tc25-01000000.mp4'), 0, 5000));
        $this->attach(['name' => 'ProRes.mov', 'videoId' => $this->video['id'], 'meta' => ['codec' => 'apch'] + self::META]);

        $paths = $this->db()->query('SELECT file_path FROM versions ORDER BY number')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['master.mov', 'master.mxf', 'master.mov'], array_map('basename', $paths));
        self::assertSame('video/quicktime', $this->request('GET', (string) $mov['videoUrl'], ['Range' => 'bytes=0-9'])->header('Content-Type'));
        self::assertSame('application/mxf', $this->request('GET', (string) $mxf['videoUrl'], ['Range' => 'bytes=0-9'])->header('Content-Type'));
        $download = $this->request('GET', '/api/admin/versions/' . $mxf['id'] . '/download');
        self::assertStringContainsString('_V2.mxf', (string) $download->header('Content-Disposition'));

        // The container must match the extension.
        file_put_contents($this->stagingPath('Faux.mxf'), substr((string) file_get_contents(__DIR__ . '/../fixtures/media/tc25-01000000.mp4'), 0, 5000));
        $wrong = $this->attach(['name' => 'Faux.mxf', 'videoId' => $this->video['id'], 'meta' => ['codec' => 'mxf'] + self::META], 422);
        self::assertSame('Ce fichier ne correspond pas à son extension (.mxf).', $wrong['error']);
    }

    public function testANonMp4FileIsRefusedAtCompletion(): void
    {
        $this->content = random_bytes(5000);
        $upload = $this->start();
        $this->sendAll($upload['id']);

        $error = $this->api('POST', '/api/admin/uploads/' . $upload['id'] . '/complete', ['meta' => self::META], 422);
        self::assertSame('Ce fichier ne correspond pas à son extension (.mp4).', $error['error']);
    }

    public function testCancelRemovesThePartFile(): void
    {
        $upload = $this->start();
        $this->put($upload['id'], 0, substr($this->content, 0, 1024));
        $this->api('DELETE', '/api/admin/uploads/' . $upload['id']);

        self::assertFileDoesNotExist($this->part($upload['id']));
        self::assertSame([], $this->api('GET', '/api/admin/uploads'));
    }

    public function testFingerprintFormulaMatchesTheFrontEnd(): void
    {
        $file = $this->tmp . '/fp.bin';
        file_put_contents($file, 'abc');

        // sha256("Fichier é.mp4\n3\n" . sha256("abc") . "\n" . sha256("abc")), also checked in uploads.test.ts.
        self::assertSame(
            hash('sha256', "Fichier é.mp4\n3\nba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad\nba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad"),
            UploadsController::fingerprint($file, 'Fichier é.mp4'),
        );
    }
}
