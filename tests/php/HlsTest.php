<?php

declare(strict_types=1);

namespace Belprise\Tests;

use Belprise\Controller\Admin\UploadsController;
use Belprise\Tests\Support\AppTestCase;

/**
 * Version 2.1: playback versions in HLS (CAHIER_DES_CHARGES §5.7 bis). The
 * browser encodes them; the server stores the files and serves the playlists
 * with every address signed.
 */
final class HlsTest extends AppTestCase
{
    private const META = [
        'codec' => 'avc1.64000c', 'width' => 320, 'height' => 180, 'fpsNum' => 25, 'fpsDen' => 1,
        'dropFrame' => false, 'startFrame' => 90000, 'durationFrames' => 1500, 'timecodeSource' => 'tmcd',
    ];

    private const MASTER = "#EXTM3U\n#EXT-X-VERSION:7\n#EXT-X-STREAM-INF:BANDWIDTH=2800000,RESOLUTION=1280x720,CODECS=\"avc1.64001f,mp4a.40.2\"\nplaylist-1.m3u8\n";
    private const PLAYLIST = "#EXTM3U\n#EXT-X-VERSION:7\n#EXT-X-TARGETDURATION:2\n#EXT-X-PLAYLIST-TYPE:VOD\n#EXT-X-MAP:URI=\"segments-1.mp4\",BYTERANGE=\"800@0\"\n#EXTINF:2.0,\n#EXT-X-BYTERANGE:1200@800\nsegments-1.mp4\n#EXT-X-ENDLIST\n";

    /** @var array<string, mixed> */
    private array $fixture;

    /** @var array<string, mixed> */
    private array $version;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
        $this->fixture = $this->createClientProjectVideo();
        copy(__DIR__ . '/../fixtures/media/tc25-01000000.mp4', $this->stagingPath('Film.mp4'));
        $this->version = $this->attach(['name' => 'Film.mp4', 'videoId' => $this->fixture['video']['id'], 'meta' => self::META]);
        $this->api('PATCH', '/api/admin/versions/' . $this->version['id'], ['status' => 'to_review']);
    }

    /** Uploads a playback file the way the browser does, for the version. */
    private function sendFile(string $target, string $content, ?int $versionId = null, int $expected = 201): array
    {
        $path = $this->tmp . '/' . bin2hex(random_bytes(4));
        file_put_contents($path, $content);
        $name = basename($target);
        $start = $this->request('POST', '/api/admin/uploads', [
            'X-CSRF-Token' => (string) $this->session->data['csrf'], 'Content-Type' => 'application/json',
        ], json_encode([
            'videoId' => (int) $this->fixture['video']['id'], 'versionId' => $versionId ?? (int) $this->version['id'], 'target' => $target,
            'name' => $name, 'size' => strlen($content), 'fingerprint' => UploadsController::fingerprint($path, $name),
        ], JSON_THROW_ON_ERROR));
        if ($start->status !== 201) {
            self::assertSame($expected, $start->status, $start->body);

            return $this->decode($start);
        }
        $id = (int) $this->decode($start)['id'];
        self::assertSame(200, $this->request('PUT', "/api/admin/uploads/{$id}?offset=0", [
            'X-CSRF-Token' => (string) $this->session->data['csrf'], 'Content-Type' => 'application/octet-stream', 'X-Chunk-Sha256' => hash('sha256', $content),
        ], $content)->status);

        return $this->api('POST', "/api/admin/uploads/{$id}/complete", [], $expected);
    }

    private function hlsDir(): string
    {
        return $this->tmp . '/data/media/' . $this->fixture['client']['id'] . '/' . $this->fixture['video']['number'] . '/v1/hls';
    }

    public function testThePlaybackVersionIsStoredPublishedAndServedSigned(): void
    {
        $segments = (string) file_get_contents(__DIR__ . '/../fixtures/media/tc25-01000000.mp4');
        self::assertSame(['stored' => 'hls/segments-1.mp4'], $this->sendFile('hls/segments-1.mp4', $segments));
        self::assertFileExists($this->hlsDir() . '/segments-1.mp4');
        self::assertNull($this->api('GET', '/api/admin/videos/' . $this->fixture['video']['id'] . '/versions')[0]['hlsUrl'], 'not before the playlists');

        $version = $this->api('POST', '/api/admin/versions/' . $this->version['id'] . '/hls', ['playlists' => ['master.m3u8' => self::MASTER, 'playlist-1.m3u8' => self::PLAYLIST]]);
        self::assertMatchesRegularExpression('#^/media/media/\d+/1001/v1/hls/master\.m3u8\?e=\d+&s=#', (string) $version['hlsUrl']);

        $master = $this->request('GET', (string) $version['hlsUrl']);
        self::assertSame(['application/vnd.apple.mpegurl', 200], [$master->header('Content-Type'), $master->status]);
        self::assertSame(1, preg_match('#^(/media/media/\d+/1001/v1/hls/playlist-1\.m3u8\?e=\d+&s=[A-Za-z0-9_-]+)$#m', $master->body, $m), $master->body);
        $playlist = $this->request('GET', $m[1])->body;
        self::assertMatchesRegularExpression('#EXT-X-MAP:URI="/media/media/\d+/1001/v1/hls/segments-1\.mp4\?e=\d+&s=[^"]+",BYTERANGE="800@0"#', $playlist);
        self::assertSame(1, preg_match('#^(/media/\S+segments-1\.mp4\?\S+)$#m', $playlist, $s));
        $part = $this->request('GET', $s[1], ['Range' => 'bytes=800-1999']);
        self::assertSame([206, '1200'], [$part->status, $part->header('Content-Length')]);
        self::assertSame(404, $this->request('GET', explode('?', $s[1])[0])->status, 'signed only');

        // The client's review page gets it.
        $this->session->data = [];
        $body = $this->request('GET', $this->fixture['video']['links']['client']['path'])->body;
        self::assertStringContainsString('"hlsUrl":"\\/media\\/media\\/', $body);
    }

    public function testPlaylistsCanOnlyPointToTheirOwnFiles(): void
    {
        $path = '/api/admin/versions/' . $this->version['id'] . '/hls';
        self::assertStringContainsString('fichier absent', $this->api('POST', $path, ['playlists' => ['master.m3u8' => self::MASTER, 'playlist-1.m3u8' => self::PLAYLIST]], 422)['error']);
        $this->sendFile('hls/segments-1.mp4', str_repeat('x', 3000));
        foreach ([
            ['../master.m3u8' => self::MASTER],
            ['master.m3u8' => "#EXTM3U\n../../../app/config.php\n"],
            ['master.m3u8' => "#EXTM3U\n#EXT-X-MAP:URI=\"/etc/passwd\"\n"],
            ['playlist-1.m3u8' => self::PLAYLIST],
        ] as $playlists) {
            self::assertSame('playlists', $this->api('POST', $path, ['playlists' => $playlists], 422)['field']);
        }
        // Upload targets stay in the version's hls/ folder, for a version of this very video.
        $this->sendFile('hls/../master.mp4', 'x', null, 422);
        $this->sendFile('segments-1.mp4', 'x', null, 422);
        $other = $this->api('POST', '/api/admin/videos', ['projectId' => $this->fixture['project']['id'], 'title' => 'Autre'], 201);
        $refused = $this->request('POST', '/api/admin/uploads', [
            'X-CSRF-Token' => (string) $this->session->data['csrf'], 'Content-Type' => 'application/json',
        ], (string) json_encode(['videoId' => $other['id'], 'versionId' => $this->version['id'], 'target' => 'hls/a.mp4', 'name' => 'a.mp4', 'size' => 1, 'fingerprint' => str_repeat('b', 64)]));
        self::assertSame([422, 'versionId'], [$refused->status, $this->decode($refused)['field']]);
    }

    public function testClearingRemovesThePlaybackVersion(): void
    {
        $this->sendFile('hls/segments-1.mp4', str_repeat('x', 3000));
        $this->api('POST', '/api/admin/versions/' . $this->version['id'] . '/hls', ['playlists' => ['master.m3u8' => self::MASTER, 'playlist-1.m3u8' => self::PLAYLIST]]);
        self::assertNull($this->api('DELETE', '/api/admin/versions/' . $this->version['id'] . '/hls')['hlsUrl']);
        self::assertDirectoryDoesNotExist($this->hlsDir());
    }

    public function testAViewerCannotChangeIt(): void
    {
        $this->db()->exec("UPDATE users SET role = 'viewer'");
        foreach (['POST', 'DELETE'] as $method) {
            self::assertSame(403, $this->request($method, '/api/admin/versions/' . $this->version['id'] . '/hls', [
                'X-CSRF-Token' => (string) $this->session->data['csrf'], 'Content-Type' => 'application/json',
            ], '{"playlists":{}}')->status, $method);
        }
    }
}
