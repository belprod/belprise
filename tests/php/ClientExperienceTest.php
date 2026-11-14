<?php

declare(strict_types=1);

namespace Belprise\Tests;

use Belprise\Controller\ClientReviewController;
use Belprise\Http\FileResponse;
use Belprise\Tests\Support\AppTestCase;
use Belprise\View\ClientTheme;

/**
 * Client space (§5.3), master download (§5.1), theming (§5.8).
 */
final class ClientExperienceTest extends AppTestCase
{
    private const META = [
        'codec' => 'avc1.64000c', 'width' => 320, 'height' => 180, 'fpsNum' => 25, 'fpsDen' => 1,
        'dropFrame' => false, 'startFrame' => 90000, 'durationFrames' => 1500, 'timecodeSource' => 'tmcd',
    ];

    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
        $this->fixture = $this->createClientProjectVideo();
    }

    private function addVersion(int $videoId, ?string $status = 'to_review'): array
    {
        $name = 'v' . bin2hex(random_bytes(3)) . '.mp4';
        copy(__DIR__ . '/../fixtures/media/tc25-01000000.mp4', $this->stagingPath($name));
        $version = $this->attach(['name' => $name, 'videoId' => $videoId, 'meta' => self::META], 201);

        return $status === null ? $version : $this->api('PATCH', '/api/admin/versions/' . $version['id'], ['status' => $status]);
    }

    private function boot(string $path, array $cookies = []): array
    {
        $response = $this->request('GET', $path, [], '', $cookies);
        self::assertSame(1, preg_match('#id="belprise-boot">(.*?)</script>#s', $response->body, $m), $response->body);

        return json_decode($m[1], true, 16, JSON_THROW_ON_ERROR);
    }

    public function testTheSpaceListsPublishedVideosByProjectMostRecentFirst(): void
    {
        $video = $this->fixture['video'];
        $this->addVersion($video['id'], 'approved');
        $this->addVersion($video['id'], 'to_review');

        $draftOnly = $this->api('POST', '/api/admin/videos', ['projectId' => $this->fixture['project']['id'], 'title' => 'Brouillon'], 201);
        $this->addVersion($draftOnly['id'], null);

        $hiddenLink = $this->api('POST', '/api/admin/videos', ['projectId' => $this->fixture['project']['id'], 'title' => 'Lien coupé'], 201);
        $this->addVersion($hiddenLink['id']);
        $this->api('PATCH', '/api/admin/links/' . $hiddenLink['links']['client']['id'], ['isActive' => false]);

        $archived = $this->api('POST', '/api/admin/projects', ['clientId' => $this->fixture['client']['id'], 'name' => 'Ancien'], 201);
        $old = $this->api('POST', '/api/admin/videos', ['projectId' => $archived['id'], 'title' => 'Archive'], 201);
        $this->addVersion($old['id']);
        $this->api('PATCH', '/api/admin/projects/' . $archived['id'], ['archived' => true]);

        $later = $this->api('POST', '/api/admin/projects', ['clientId' => $this->fixture['client']['id'], 'name' => 'Récent'], 201);
        $recent = $this->api('POST', '/api/admin/videos', ['projectId' => $later['id'], 'title' => 'Nouveau film'], 201);
        $this->addVersion($recent['id']);
        $this->db()->exec("UPDATE versions SET published_at = '2099-01-01 10:00:00' WHERE video_id = " . $recent['id']);

        $projects = $this->boot($this->fixture['client']['link']['path'])['projects'];

        self::assertSame(['Récent', 'Printemps'], array_column($projects, 'name'));
        $card = $projects[1]['videos'][0];
        self::assertCount(1, $projects[1]['videos'], 'draft-only and disabled-link videos are hidden');
        self::assertSame(2, $card['version']);
        self::assertSame('to_review', $card['status']);
        self::assertSame('1:00', $card['duration']);
        self::assertSame($video['links']['client']['path'], $card['href'], 'cards open with the client link rights');
        self::assertStringNotContainsString('Brouillon', json_encode($projects));
    }

    public function testTheMasterDownloadIsAnAttachmentWithRanges(): void
    {
        $version = $this->addVersion($this->fixture['video']['id']);
        $url = '/api/v/' . substr($this->fixture['video']['links']['client']['path'], 3) . '/versions/' . $version['id'] . '/download';

        $response = $this->request('GET', $url, ['Range' => 'bytes=4-11']);
        self::assertInstanceOf(FileResponse::class, $response);
        self::assertSame(206, $response->status);
        self::assertSame('ftypisom', $response->contents());
        self::assertSame(
            'attachment; filename="1001_Film manifeste_V1.mp4"; filename*=UTF-8\'\'1001_Film%20manifeste_V1.mp4',
            $response->header('Content-Disposition'),
        );
        self::assertTrue($this->boot($this->fixture['video']['links']['client']['path'])['video']['downloadAllowed']);

        $this->api('PATCH', '/api/admin/videos/' . $this->fixture['video']['id'], ['downloadAllowed' => false]);
        self::assertSame(404, $this->request('GET', $url)->status, 'download not allowed');
        self::assertFalse($this->boot($this->fixture['video']['links']['client']['path'])['video']['downloadAllowed']);
    }

    public function testTheViewLinkHasNoDownload(): void
    {
        $version = $this->addVersion($this->fixture['video']['id']);
        $viewRef = substr($this->fixture['video']['links']['view']['path'], 3);

        self::assertSame(404, $this->request('GET', '/api/v/' . $viewRef . '/versions/' . $version['id'] . '/download')->status);
        self::assertArrayNotHasKey('downloadAllowed', $this->boot($this->fixture['video']['links']['view']['path'])['video']);
    }

    public function testUnpublishedOrProtectedVersionsCannotBeDownloaded(): void
    {
        $draft = $this->addVersion($this->fixture['video']['id'], null);
        $ref = substr($this->fixture['video']['links']['client']['path'], 3);
        self::assertSame(404, $this->request('GET', '/api/v/' . $ref . '/versions/' . $draft['id'] . '/download')->status);

        $published = $this->addVersion($this->fixture['video']['id']);
        $this->api('PATCH', '/api/admin/links/' . $this->fixture['video']['links']['client']['id'], ['password' => 'secret1']);
        self::assertSame(404, $this->request('GET', '/api/v/' . $ref . '/versions/' . $published['id'] . '/download')->status);
    }

    public function testDownloadNamesAreSafe(): void
    {
        self::assertSame('1042_Film manifeste 60 s_V3.mp4', ClientReviewController::downloadName(1042, 'Film manifeste 60 s', 3));
        self::assertSame('1042_a b c_V1.mp4', ClientReviewController::downloadName(1042, 'a/b\\c', 1));
        self::assertSame('1042_video_V1.mp4', ClientReviewController::downloadName(1042, '"""', 1));
    }

    public function testThemeChoosesReadableButtonTextAndColourScheme(): void
    {
        self::assertSame('#ffffff', (new ClientTheme('#f3ede4', '#1c1915', '#a8481f'))->onAccent());
        self::assertSame('#0a0a0b', (new ClientTheme('#0a0a0b', '#fafafa', '#e8515f'))->onAccent());
        self::assertStringContainsString('color-scheme:light', (new ClientTheme('#ffffff', '#14202b', '#1f6feb'))->css());
        self::assertStringContainsString('color-scheme:dark', (new ClientTheme('#0e1a2b', '#e8eef5', '#f2b544'))->css());
    }
}
