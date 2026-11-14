<?php

declare(strict_types=1);

namespace Belprise\Tests;

use Belprise\App;
use Belprise\Config;
use Belprise\Controller\MediaController;
use Belprise\Http\FileResponse;
use Belprise\Http\Request;
use Belprise\Http\Response;
use Belprise\Security\MediaSigner;
use Belprise\Tests\Support\AppTestCase;

/**
 * Masters through signed URLs (§6) and the versions each link exposes (§5.1, §5.2).
 */
final class MediaAccessTest extends AppTestCase
{
    private const META = [
        'codec' => 'avc1.64000c', 'width' => 320, 'height' => 180, 'fpsNum' => 25, 'fpsDen' => 1,
        'dropFrame' => false, 'startFrame' => 90000, 'durationFrames' => 50, 'timecodeSource' => 'tmcd',
    ];

    private array $fixture;
    private string $mp4;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
        $this->fixture = $this->createClientProjectVideo();
        $this->mp4 = (string) file_get_contents(__DIR__ . '/../fixtures/media/tc25-01000000.mp4');
    }

    /** Sends a version like the browser does, optionally published with a status. */
    private function addVersion(?string $status = null): array
    {
        $name = 'v' . bin2hex(random_bytes(3)) . '.mp4';
        file_put_contents($this->stagingPath($name), $this->mp4);
        $version = $this->attach([
            'name' => $name, 'videoId' => $this->fixture['video']['id'], 'meta' => self::META,
        ], 201);
        if ($status !== null) {
            $version = $this->api('PATCH', '/api/admin/versions/' . $version['id'], ['status' => $status]);
        }

        return $version;
    }

    private function boot(string $path): array
    {
        $response = $this->request('GET', $path);
        self::assertSame(200, $response->status);
        self::assertSame(1, preg_match('#<script type="application/json" id="belprise-boot">(.*?)</script>#s', $response->body, $m));

        return json_decode($m[1], true, 16, JSON_THROW_ON_ERROR);
    }

    private function assertNeutral404(Response $response, string $message = ''): void
    {
        self::assertSame(404, $response->status, $message);
        self::assertSame($this->request('GET', '/nope')->body, $response->body, $message);
    }

    public function testTheReviewPageGetsEveryPublishedVersionNewestFirst(): void
    {
        $this->addVersion('changes_requested');
        $this->addVersion();                      // V2 still "En cours": hidden
        $this->addVersion('to_review');

        $versions = $this->boot($this->fixture['video']['links']['client']['path'])['versions'];

        self::assertSame([3, 1], array_column($versions, 'number'));
        self::assertSame('to_review', $versions[0]['status']);
        self::assertSame(90000, $versions[0]['startFrame']);
        self::assertSame(strlen($this->mp4), $versions[0]['size']);
        self::assertMatchesRegularExpression('#^/media/media/\d+/1001/v3/master\.mp4\?e=\d+&s=#', $versions[0]['videoUrl']);
        self::assertStringNotContainsString('file_path', json_encode($versions));
    }

    public function testTheViewPageShowsTheLatestApprovedVersionElseTheLatestPublished(): void
    {
        $view = $this->fixture['video']['links']['view']['path'];
        self::assertSame([], $this->boot($view)['versions'], 'nothing published yet');

        $this->addVersion('to_review');
        self::assertSame([1], array_column($this->boot($view)['versions'], 'number'));

        $this->addVersion('approved');
        $this->addVersion('to_review');
        $shown = $this->boot($view)['versions'];
        self::assertSame([2], array_column($shown, 'number'), 'V2 approved wins over the newer V3');
        self::assertArrayNotHasKey('status', $shown[0]);
        self::assertArrayNotHasKey('size', $shown[0]);
    }

    public function testASignedMasterIsServedWithRanges(): void
    {
        $this->addVersion('to_review');
        $url = $this->boot($this->fixture['video']['links']['client']['path'])['versions'][0]['videoUrl'];

        $response = $this->request('GET', $url, ['Range' => 'bytes=4-11']);
        self::assertInstanceOf(FileResponse::class, $response);
        self::assertSame(206, $response->status);
        self::assertSame('ftypisom', $response->contents());
        self::assertSame('video/mp4', $response->header('Content-Type'));
        self::assertSame('bytes', $response->header('Accept-Ranges'));
        self::assertMatchesRegularExpression('/^private, max-age=\d+$/', (string) $response->header('Cache-Control'));

        $whole = $this->request('GET', $url);
        self::assertSame(200, $whole->status);
        self::assertSame((string) strlen($this->mp4), $whole->header('Content-Length'));

        self::assertSame(416, $this->request('GET', $url, ['Range' => 'bytes=99999999-'])->status);

        // An open range is answered in parts: a seek never makes PHP read the whole master.
        $big = $this->tmp . '/data/' . (string) $this->db()->query('SELECT file_path FROM versions LIMIT 1')->fetchColumn();
        $handle = fopen($big, 'r+');
        ftruncate($handle, 3 * MediaController::PLAYBACK_CHUNK);
        fclose($handle);
        $part = $this->request('GET', $url, ['Range' => 'bytes=100-']);
        self::assertSame([206, (string) MediaController::PLAYBACK_CHUNK], [$part->status, $part->header('Content-Length')]);
        self::assertSame('bytes 100-' . (MediaController::PLAYBACK_CHUNK + 99) . '/' . (3 * MediaController::PLAYBACK_CHUNK), $part->header('Content-Range'));
        self::assertSame('bytes 0-1/' . (3 * MediaController::PLAYBACK_CHUNK), $this->request('GET', $url, ['Range' => 'bytes=0-1'])->header('Content-Range'));
        self::assertSame(200, $this->request('GET', $url)->status, 'no Range, no 206');

        // No server path anywhere in the response.
        foreach ($response->headers() as $value) {
            self::assertStringNotContainsString($this->tmp, $value);
            self::assertStringNotContainsString('/data/', $value);
        }
    }

    public function testMastersAreUnreachableWithoutAValidSignature(): void
    {
        $this->addVersion('to_review');
        $url = $this->boot($this->fixture['video']['links']['client']['path'])['versions'][0]['videoUrl'];
        [$path, $query] = explode('?', $url);
        parse_str($query, $q);

        $this->assertNeutral404($this->request('GET', $path));
        $this->assertNeutral404($this->request('GET', $path . '?e=' . $q['e']));
        $this->assertNeutral404($this->request('GET', $path . '?e=' . $q['e'] . '&s=' . strrev($q['s'])));
        $this->assertNeutral404($this->request('GET', $path . '?e=' . ((int) $q['e'] + 3600) . '&s=' . $q['s']));
        $this->assertNeutral404($this->request('GET', str_replace('/v1/', '/v2/', $url)), 'signature bound to the path');
    }

    public function testAnExpiredSignatureIsRefused(): void
    {
        $this->addVersion('to_review');
        $path = parse_url($this->boot($this->fixture['video']['links']['client']['path'])['versions'][0]['videoUrl'], PHP_URL_PATH);

        // Signed with the right secret, five hours ago: expired now.
        $old = (new MediaSigner(str_repeat('s', 64), static fn (): int => time() - 5 * 3600 - 3601))->url(substr($path, strlen('/media/')));
        self::assertLessThan(time(), (int) explode('&', explode('e=', $old)[1])[0]);

        $this->assertNeutral404($this->request('GET', $old));
    }

    public function testUnpublishedVersionsGetNoClientUrls(): void
    {
        $this->addVersion();
        $boot = $this->boot($this->fixture['video']['links']['client']['path']);

        self::assertSame([], $boot['versions']);
        self::assertStringNotContainsString('/media/media/', json_encode($boot));
    }

    public function testXSendfileHandsTheFileToApache(): void
    {
        $this->addVersion('to_review');
        $url = $this->boot($this->fixture['video']['links']['client']['path'])['versions'][0]['videoUrl'];
        $app = new App(
            Config::fromArray(['data_dir' => $this->tmp . '/data', 'secret' => str_repeat('s', 64), 'xsendfile' => true]),
            $this->tmp . '/public',
            dirname(__DIR__, 2) . '/app/migrations',
            $this->session,
        );
        [$path, $query] = explode('?', $url);
        parse_str($query, $q);

        $response = $app->handle(new Request('GET', $path, ['User-Agent' => self::BROWSER_UA], $q));
        self::assertSame(200, $response->status);
        self::assertStringEndsWith('/v1/master.mp4', (string) $response->header('X-Sendfile'));
        self::assertSame('', $response->contents());
    }
}
