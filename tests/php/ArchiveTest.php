<?php

declare(strict_types=1);

namespace Belprise\Tests;

use Belprise\Media\AutoArchiver;
use Belprise\Storage;
use Belprise\Tests\Support\AppTestCase;
use DateTimeImmutable;

/** Archiving and the client's library (CAHIER_DES_CHARGES §5.3, §5.13, criterion 17). */
final class ArchiveTest extends AppTestCase
{
    private const META = [
        'codec' => 'avc1.64000c', 'width' => 320, 'height' => 180, 'fpsNum' => 25, 'fpsDen' => 1,
        'dropFrame' => false, 'startFrame' => 90000, 'durationFrames' => 1500, 'timecodeSource' => 'tmcd',
    ];

    /** @var array<string, mixed> */
    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
        $this->fixture = $this->createClientProjectVideo();
    }

    /** @return array<string, mixed> */
    private function addVersion(string $status = 'to_review'): array
    {
        $name = 'v' . bin2hex(random_bytes(3)) . '.mp4';
        copy(__DIR__ . '/../fixtures/media/tc25-01000000.mp4', $this->stagingPath($name));
        $version = $this->attach(['name' => $name, 'videoId' => $this->fixture['video']['id'], 'meta' => self::META], 201);
        if ($status !== 'in_progress') {
            $version = $this->api('PATCH', '/api/admin/versions/' . $version['id'], ['status' => $status]);
        }

        return $version;
    }

    private function videoDir(): string
    {
        return $this->tmp . '/data/' . Storage::videoMediaDir((int) $this->fixture['client']['id'], (int) $this->fixture['video']['number']);
    }

    private function clientRevision(int $versionId): void
    {
        $this->db()->prepare("INSERT INTO comments (version_id, author_name, frame_in, body) VALUES (?, 'Claire', 10, 'Plus tôt')")->execute([$versionId]);
    }

    public function testArchivingKeepsTheLatestApprovedVersionAndDeletesTheRest(): void
    {
        $v1 = $this->addVersion('approved');
        $v2 = $this->addVersion('to_review');
        $this->addVersion('in_progress');
        $this->clientRevision($v1['id']);
        $this->clientRevision($v2['id']);
        $videoId = $this->fixture['video']['id'];

        $plan = $this->api('GET', "/api/admin/videos/{$videoId}/archive");
        self::assertSame([true, ['number' => 1, 'status' => 'approved'], [2, 3], 2], [$plan['possible'], $plan['keep'], $plan['delete'], $plan['revisions']]);
        self::assertGreaterThan(100_000, $plan['freedBytes']);
        self::assertDirectoryExists($this->videoDir() . '/v2', 'the plan changes nothing');

        $video = $this->api('POST', "/api/admin/videos/{$videoId}/archive");
        self::assertTrue($video['archived']);
        self::assertSame([1], array_column($this->api('GET', "/api/admin/videos/{$videoId}/versions"), 'number'));
        self::assertSame('0', (string) $this->db()->query('SELECT COUNT(*) FROM comments')->fetchColumn(), 'every revision goes');
        self::assertDirectoryExists($this->videoDir() . '/v1');
        self::assertDirectoryDoesNotExist($this->videoDir() . '/v2');
        self::assertDirectoryDoesNotExist($this->videoDir() . '/v3');
        $log = $this->api('GET', '/api/admin/organization/log');
        self::assertSame(['Jules', 'Vidéo archivée', 'N° 1001 Film manifeste : V1 gardée, V2, V3 supprimées, 2 révisions supprimées'], [$log[0]['actor'], $log[0]['action'], $log[0]['details']]);
    }

    public function testWithoutApprovalTheLatestPublishedVersionIsKept(): void
    {
        $this->addVersion('to_review');
        $this->addVersion('changes_requested');

        $plan = $this->api('GET', '/api/admin/videos/' . $this->fixture['video']['id'] . '/archive');
        self::assertSame([['number' => 2, 'status' => 'changes_requested'], [1]], [$plan['keep'], $plan['delete']]);
    }

    public function testAVideoWithoutPublishedVersionCannotBeArchived(): void
    {
        $this->addVersion('in_progress');
        $videoId = $this->fixture['video']['id'];

        self::assertFalse($this->api('GET', "/api/admin/videos/{$videoId}/archive")['possible']);
        self::assertStringContainsString('aucune version publiée', $this->api('POST', "/api/admin/videos/{$videoId}/archive", null, 422)['error']);
        // The old switch goes through the same procedure.
        $this->api('PATCH', "/api/admin/videos/{$videoId}", ['archived' => true], 422);
        self::assertNull($this->db()->query('SELECT archived_at FROM videos')->fetchColumn());
    }

    public function testTheSwitchArchivesForRealAndUnarchivingKeepsWhatIsLeft(): void
    {
        $this->addVersion('approved');
        $this->addVersion('to_review');
        $videoId = $this->fixture['video']['id'];

        self::assertTrue($this->api('PATCH', "/api/admin/videos/{$videoId}", ['archived' => true])['archived']);
        self::assertCount(1, $this->api('GET', "/api/admin/videos/{$videoId}/versions"));
        self::assertFalse($this->api('PATCH', "/api/admin/videos/{$videoId}", ['archived' => false])['archived']);
        self::assertCount(1, $this->api('GET', "/api/admin/videos/{$videoId}/versions"));
    }

    public function testTheClientLibrary(): void
    {
        $version = $this->addVersion('approved');
        $this->addVersion('to_review');
        $videoId = $this->fixture['video']['id'];
        $this->api('POST', "/api/admin/videos/{$videoId}/archive");
        $spacePath = $this->fixture['client']['link']['path'];
        $videoPath = $this->fixture['video']['links']['client']['path'];
        $this->session->data = [];

        $space = $this->boot($spacePath);
        self::assertSame([], $space['projects'], 'no longer among the projects in progress');
        self::assertSame([[1001, 'Film manifeste', 1, 'Printemps']], array_map(static fn ($c) => [$c['number'], $c['title'], $c['version'], $c['projectName']], $space['library']));

        $page = $this->boot($videoPath);
        self::assertSame(['library', [1], true], [$page['page'], array_column($page['versions'], 'number'), $page['video']['downloadAllowed']]);
        self::assertArrayNotHasKey('csrf', $page, 'no revisions');

        $ref = substr($videoPath, 3);
        self::assertSame(404, $this->request('GET', "/api/v/{$ref}/versions/{$version['id']}/comments")->status, 'revisions are closed');
        $download = $this->request('GET', "/api/v/{$ref}/versions/{$version['id']}/download");
        self::assertSame(200, $download->status, 'the master stays downloadable');
    }

    public function testAViewerCannotArchive(): void
    {
        $this->addVersion('approved');
        $this->db()->exec("UPDATE users SET role = 'viewer'");

        self::assertSame(403, $this->request('POST', '/api/admin/videos/' . $this->fixture['video']['id'] . '/archive', [
            'X-CSRF-Token' => (string) $this->session->data['csrf'],
        ])->status);
    }

    public function testValidatedVideosIdleForSixMonthsAreArchivedAutomatically(): void
    {
        $version = $this->addVersion('approved');
        $this->addVersion('to_review');
        $this->api('PATCH', '/api/admin/versions/' . $version['id'], ['status' => 'approved']);
        $db = $this->db();
        $archiver = new AutoArchiver($db, new Storage($this->tmp . '/data'));
        $videoId = (int) $this->fixture['video']['id'];

        // V2 "À valider" is the latest published: the video is not validated.
        self::assertSame([], $archiver->candidates(new DateTimeImmutable('+1 year')));
        $db->exec("UPDATE versions SET is_published = 0, status = 'in_progress' WHERE number = 2");

        // Validated, but active recently.
        self::assertSame([], $archiver->candidates(new DateTimeImmutable('+5 months')));
        $in7Months = new DateTimeImmutable('+7 months');
        self::assertSame([$videoId], array_map('intval', array_column($archiver->candidates($in7Months), 'id')));

        // A new revision restarts the clock.
        $db->exec("INSERT INTO activity (organization_id, client_id, project_id, video_id, type, created_at)
                   SELECT 1, p.client_id, p.id, v.id, 'revision', datetime('now', '+3 months') FROM videos v JOIN projects p ON p.id = v.project_id");
        self::assertSame([], $archiver->candidates($in7Months));
        $in10Months = new DateTimeImmutable('+10 months');

        // Dry run: nothing changes.
        $dry = $archiver->run($in10Months, true);
        self::assertSame(1, $dry[0]['plan']['keep']['number']);
        self::assertSame('2', (string) $db->query('SELECT COUNT(*) FROM versions')->fetchColumn());

        $archiver->run($in10Months, false);
        self::assertNotNull($db->query("SELECT archived_at FROM videos WHERE id = {$videoId}")->fetchColumn());
        self::assertSame('1', (string) $db->query('SELECT COUNT(*) FROM versions')->fetchColumn());
        self::assertSame('Archivage automatique', $db->query('SELECT actor FROM audit_log ORDER BY id DESC')->fetchColumn());
        self::assertSame([], $archiver->candidates($in10Months), 'archived once');
    }

    /** @return array<string, mixed> */
    private function boot(string $path): array
    {
        $body = $this->request('GET', $path)->body;
        self::assertSame(1, preg_match('#id="belprise-boot">(.*?)</script>#s', $body, $m), $path);

        return json_decode(html_entity_decode($m[1]), true, 512, JSON_THROW_ON_ERROR);
    }
}
