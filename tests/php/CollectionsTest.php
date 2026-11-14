<?php

declare(strict_types=1);

namespace Belprise\Tests;

use Belprise\Repository\Organizations;
use Belprise\Repository\Users;
use Belprise\Tests\Support\AppTestCase;

/** Collections for prospects (CAHIER_DES_CHARGES §5.9, criterion 18). */
final class CollectionsTest extends AppTestCase
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

    private function video(string $title): int
    {
        return (int) $this->api('POST', '/api/admin/videos', ['projectId' => $this->fixture['project']['id'], 'title' => $title], 201)['id'];
    }

    /** @return array<string, mixed> */
    private function version(int $videoId, string $status): array
    {
        $name = 'v' . bin2hex(random_bytes(3)) . '.mp4';
        copy(__DIR__ . '/../fixtures/media/tc25-01000000.mp4', $this->stagingPath($name));
        $version = $this->attach(['name' => $name, 'videoId' => $videoId, 'meta' => self::META], 201);

        return $status === 'in_progress' ? $version : $this->api('PATCH', '/api/admin/versions/' . $version['id'], ['status' => $status]);
    }

    /** @return array<string, mixed> */
    private function boot(string $path): array
    {
        $body = $this->request('GET', $path)->body;
        self::assertSame(1, preg_match('#id="belprise-boot">(.*?)</script>#s', $body, $m), $path);

        return json_decode(html_entity_decode($m[1]), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testACollectionShowsItsVideosInOrderReadOnly(): void
    {
        $manifeste = (int) $this->fixture['video']['id'];
        $v1 = $this->version($manifeste, 'approved');
        $this->version($manifeste, 'to_review'); // newer, but not approved: V1 is shown
        $teaser = $this->video('Teaser');
        $this->version($teaser, 'to_review');
        $draft = $this->video('Brouillon');
        $this->version($draft, 'in_progress'); // never published: never shown

        $collection = $this->api('POST', '/api/admin/collections', ['title' => 'Appel d’offres EDF', 'introduction' => 'Nos reportages.'], 201);
        self::assertMatchesRegularExpression('#^/[a-km-np-z]{10}$#', $collection['link']['path']);
        $collection = $this->api('PATCH', '/api/admin/collections/' . $collection['id'], ['videoIds' => [$teaser, $draft, $manifeste]]);
        self::assertSame([[$teaser, 1, false], [$draft, null, false], [$manifeste, 1, true]], array_map(static fn ($v) => [$v['id'], $v['version'], $v['approved']], $collection['videos']));

        $this->session->data = [];
        $page = $this->boot($collection['link']['path']);
        self::assertSame('collection', $page['page']);
        self::assertSame(['Appel d’offres EDF', 'Nos reportages.'], [$page['collection']['title'], $page['collection']['introduction']]);
        self::assertSame(['Teaser', 'Film manifeste'], array_column($page['videos'], 'title'));
        self::assertSame($this->db()->query('SELECT name FROM organizations WHERE id = 1')->fetchColumn(), $page['client']['name'], 'the header names the organization');
        self::assertSame($v1['id'], $page['videos'][1]['version']['id'], 'the approved version, not the newest');
        self::assertArrayNotHasKey('csrf', $page, 'no revisions');
        self::assertStringNotContainsString('download', json_encode($page, JSON_THROW_ON_ERROR), 'no download');
        self::assertSame(206, $this->request('GET', $page['videos'][0]['version']['videoUrl'], ['Range' => 'bytes=0-99'])->status);
    }

    public function testTheLinkFollowsTheUsualRules(): void
    {
        $collection = $this->api('POST', '/api/admin/collections', ['title' => 'Sélection'], 201);
        $path = $collection['link']['path'];
        $linkId = $collection['link']['id'];
        self::assertSame(200, $this->request('GET', $path)->status);

        $this->api('PATCH', '/api/admin/links/' . $linkId, ['isActive' => false]);
        self::assertSame(404, $this->request('GET', $path)->status);
        $this->api('PATCH', '/api/admin/links/' . $linkId, ['isActive' => true, 'expiresOn' => '2020-01-01']);
        self::assertSame(404, $this->request('GET', $path)->status);
        $this->api('PATCH', '/api/admin/links/' . $linkId, ['expiresOn' => null, 'password' => 'prospect2027']);
        $form = $this->request('GET', $path);
        self::assertStringContainsString('name="password"', $form->body);
        self::assertStringNotContainsString('Sélection', $form->body, 'the form says nothing');

        $regenerated = $this->api('POST', '/api/admin/links/' . $linkId . '/regenerate');
        self::assertNotSame($path, $regenerated['path']);
        self::assertSame(404, $this->request('GET', $path)->status, 'the old key stops working');

        $this->api('DELETE', '/api/admin/collections/' . $collection['id']);
        self::assertSame(404, $this->request('GET', $regenerated['path'])->status);
        self::assertSame('0', (string) $this->db()->query("SELECT COUNT(*) FROM links WHERE type = 'collection'")->fetchColumn());
    }

    public function testADeletedVideoLeavesTheCollections(): void
    {
        $collection = $this->api('POST', '/api/admin/collections', ['title' => 'Sélection'], 201);
        $this->api('PATCH', '/api/admin/collections/' . $collection['id'], ['videoIds' => [(int) $this->fixture['video']['id']]]);
        $this->api('DELETE', '/api/admin/videos/' . $this->fixture['video']['id']);

        self::assertSame([], $this->api('GET', '/api/admin/collections/' . $collection['id'])['videos']);
    }

    public function testSelectionChecks(): void
    {
        $collection = $this->api('POST', '/api/admin/collections', ['title' => 'Sélection'], 201);
        $path = '/api/admin/collections/' . $collection['id'];

        self::assertSame('videoIds', $this->api('PATCH', $path, ['videoIds' => [999]], 422)['field']);
        self::assertSame('videoIds', $this->api('PATCH', $path, ['videoIds' => ['1']], 422)['field']);
        self::assertSame('videoIds', $this->api('PATCH', $path, ['videoIds' => range(1, 61)], 422)['field']);
        self::assertSame('title', $this->api('POST', '/api/admin/collections', ['title' => ''], 422)['field']);
    }

    public function testCollectionsAreSealedByOrganizationAndRole(): void
    {
        $collection = $this->api('POST', '/api/admin/collections', ['title' => 'Sélection belprod'], 201);
        $db = $this->db();
        $org = (new Organizations($db))->create('Studio Nord');
        (new Users($db))->create($org, 'nora@studio-nord.fr', 'Nora', password_hash('mot-de-passe-de-nora', PASSWORD_ARGON2ID), 'admin');
        $this->session->data = [];
        $this->postJson('/api/admin/login', ['email' => 'nora@studio-nord.fr', 'password' => 'mot-de-passe-de-nora']);

        self::assertSame([], $this->api('GET', '/api/admin/collections'));
        self::assertSame(404, $this->request('GET', '/api/admin/collections/' . $collection['id'])->status);
        $mine = $this->api('POST', '/api/admin/collections', ['title' => 'Sélection Nord'], 201);
        // A video of belprod cannot enter Studio Nord's collection.
        self::assertSame('videoIds', $this->api('PATCH', '/api/admin/collections/' . $mine['id'], ['videoIds' => [(int) $this->fixture['video']['id']]], 422)['field']);
        self::assertSame(404, $this->request('PATCH', '/api/admin/links/' . $collection['link']['id'], [
            'X-CSRF-Token' => (string) $this->session->data['csrf'], 'Content-Type' => 'application/json',
        ], '{"isActive":false}')->status);

        $db->exec("UPDATE users SET role = 'viewer' WHERE email = 'nora@studio-nord.fr'");
        self::assertSame(200, $this->request('GET', '/api/admin/collections')->status);
        self::assertSame(403, $this->request('POST', '/api/admin/collections', [
            'X-CSRF-Token' => (string) $this->session->data['csrf'], 'Content-Type' => 'application/json',
        ], '{"title":"x"}')->status);
    }
}
