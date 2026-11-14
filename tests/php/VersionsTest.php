<?php

declare(strict_types=1);

namespace Belprise\Tests;

use Belprise\Http\FileResponse;
use Belprise\Tests\Support\AppTestCase;

/**
 * Versions (§5.4) and Range streaming.
 */
final class VersionsTest extends AppTestCase
{
    private const META = [
        'codec' => 'avc1.640028', 'width' => 1920, 'height' => 1080, 'fpsNum' => 30000, 'fpsDen' => 1001,
        'dropFrame' => true, 'startFrame' => 107892, 'durationFrames' => 1798, 'timecodeSource' => 'tmcd',
    ];

    private array $video;
    private string $mp4;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
        $this->video = $this->createClientProjectVideo()['video'];
        $this->mp4 = (string) file_get_contents(__DIR__ . '/../fixtures/media/tc2997df-01000000.mp4');
    }

    private function stage(string $name, ?string $content = null): string
    {
        $path = $this->stagingPath($name);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content ?? $this->mp4);

        return $path;
    }

    private function attachFixture(string $name, array $meta = []): array
    {
        return $this->attach([
            'name' => $name, 'videoId' => $this->video['id'], 'meta' => $meta + self::META,
        ], 201);
    }

    public function testPublishingAndStatuses(): void
    {
        $this->stage('a.mp4');
        $version = $this->attachFixture('a.mp4');
        $url = '/api/admin/versions/' . $version['id'];

        $published = $this->api('PATCH', $url, ['published' => true]);
        self::assertTrue($published['isPublished']);
        self::assertSame('to_review', $published['status']);
        self::assertNotNull($published['publishedAt']);
        self::assertSame('to_review', $this->api('GET', '/api/admin/videos/' . $this->video['id'])['status'], 'video status = latest published');

        $approved = $this->api('PATCH', $url, ['status' => 'approved']);
        self::assertSame('approved', $approved['status']);
        self::assertSame('belprod', $approved['approvedBy']);

        $unpublished = $this->api('PATCH', $url, ['published' => false]);
        self::assertFalse($unpublished['isPublished']);
        self::assertSame('in_progress', $unpublished['status']);
        self::assertNull($unpublished['approvedAt']);
        self::assertNull($this->api('GET', '/api/admin/videos/' . $this->video['id'])['status']);

        $forced = $this->api('PATCH', $url, ['status' => 'changes_requested']);
        self::assertTrue($forced['isPublished'], 'any status but "En cours" is published');
        self::assertSame('status', $this->api('PATCH', $url, ['status' => 'nope'], 422)['field']);
    }

    public function testManualMetadataCorrection(): void
    {
        $this->stage('a.mp4');
        $version = $this->attachFixture('a.mp4');
        $url = '/api/admin/versions/' . $version['id'];

        $fixed = $this->api('PATCH', $url, [
            'fpsNum' => 25, 'fpsDen' => 1, 'dropFrame' => true, 'startTimecode' => '10:00:00:00', 'width' => 1920, 'height' => 1080,
        ]);
        self::assertSame(25, $fixed['fpsNum']);
        self::assertFalse($fixed['dropFrame'], 'no drop-frame at 25 fps');
        self::assertSame('10:00:00:00', $fixed['startTimecode']);
        self::assertSame(900000, $fixed['startFrame']);
        self::assertSame(1500, $fixed['durationFrames'], '1798 frames at 29.97 = 59.99 s = 1500 frames at 25');
        self::assertSame('manual', $fixed['timecodeSource']);

        $error = $this->api('PATCH', $url, [
            'fpsNum' => 30000, 'fpsDen' => 1001, 'dropFrame' => true, 'startTimecode' => '00:01:00;00', 'width' => 1920, 'height' => 1080,
        ], 422);
        self::assertSame('startTimecode', $error['field']);
    }

    public function testImagesAreStoredAndServedThroughSignedUrls(): void
    {
        $this->stage('a.mp4');
        $version = $this->attachFixture('a.mp4');
        $jpeg = (string) file_get_contents(__DIR__ . '/../fixtures/images/poster.jpg');
        $sprite = (string) file_get_contents(__DIR__ . '/../fixtures/images/sprite.jpg');

        $response = $this->request('POST', '/api/admin/versions/' . $version['id'] . '/images',
            ['X-CSRF-Token' => (string) $this->session->data['csrf']], '', [],
            ['poster' => $this->file($jpeg), 'sprite' => $this->file($sprite)],
            ['posterFrame' => '180', 'spriteMeta' => json_encode(['columns' => 10, 'count' => 10, 'width' => 256, 'height' => 144, 'interval' => 0.2])],
        );
        self::assertSame(200, $response->status, $response->body);
        $updated = $this->decode($response);
        self::assertSame(180, $updated['posterFrame']);
        self::assertSame(10, $updated['sprite']['count']);

        $poster = $this->request('GET', (string) $updated['posterUrl']);
        self::assertSame(200, $poster->status);
        self::assertSame('image/jpeg', $poster->header('Content-Type'));
        self::assertSame(404, $this->request('GET', explode('?', (string) $updated['spriteUrl'])[0])->status);

        $bad = $this->request('POST', '/api/admin/versions/' . $version['id'] . '/images',
            ['X-CSRF-Token' => (string) $this->session->data['csrf']], '', [], ['poster' => $this->file('not a jpeg')]);
        self::assertSame(422, $bad->status);
    }

    public function testDeletingAVersionRemovesItsFiles(): void
    {
        $this->stage('a.mp4');
        $version = $this->attachFixture('a.mp4');
        $dir = $this->tmp . '/data/media/' . $this->video['clientId'] . '/1001/v1';
        self::assertDirectoryExists($dir);

        $this->api('DELETE', '/api/admin/versions/' . $version['id']);

        self::assertDirectoryDoesNotExist($dir);
        self::assertSame([], $this->api('GET', '/api/admin/videos/' . $this->video['id'] . '/versions'));
    }

    public function testVersionFileNeedsTheAdminSession(): void
    {
        $this->stage('a.mp4');
        $version = $this->attachFixture('a.mp4');
        self::assertSame(206, $this->request('GET', '/api/admin/versions/' . $version['id'] . '/file', ['Range' => 'bytes=0-99'])->status);

        $this->api('POST', '/api/admin/logout');
        self::assertSame(401, $this->request('GET', '/api/admin/versions/' . $version['id'] . '/file')->status);
    }

    public function testRangeParsing(): void
    {
        self::assertSame([0, 99], FileResponse::parseRange('bytes=0-99', 1000));
        self::assertSame([900, 999], FileResponse::parseRange('bytes=900-', 1000));
        self::assertSame([900, 999], FileResponse::parseRange('bytes=-100', 1000));
        self::assertSame([0, 999], FileResponse::parseRange('bytes=0-5000', 1000));
        self::assertSame([0, 999], FileResponse::parseRange('bytes=-5000', 1000));
        self::assertFalse(FileResponse::parseRange('bytes=1000-', 1000));
        self::assertNull(FileResponse::parseRange('bytes=5-4', 1000), 'invalid range-spec: ignored (RFC 9110)');
        self::assertSame([0, 1], FileResponse::parseRange('BYTES=0-1', 1000));
        self::assertFalse(FileResponse::parseRange('bytes=-0', 1000));
        self::assertNull(FileResponse::parseRange('bytes=0-1,5-6', 1000));
        self::assertNull(FileResponse::parseRange('items=0-1', 1000));
    }

    public function testUnsatisfiableRangeIs416(): void
    {
        $this->stage('Film.mp4');
        $version = $this->attachFixture('Film.mp4');
        $response = $this->request('GET', '/api/admin/versions/' . $version['id'] . '/file', ['Range' => 'bytes=999999999-']);

        self::assertSame(416, $response->status);
        self::assertSame('bytes */' . strlen($this->mp4), $response->header('Content-Range'));
    }

    /**
     * @return array{name: string, tmp_name: string, size: int, error: int}
     */
    private function file(string $content): array
    {
        $path = $this->tmp . '/upload-' . bin2hex(random_bytes(4));
        file_put_contents($path, $content);

        return ['name' => 'image.jpg', 'tmp_name' => $path, 'size' => strlen($content), 'error' => UPLOAD_ERR_OK];
    }
}
