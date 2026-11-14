<?php

declare(strict_types=1);

namespace Belprise\Tests;

use Belprise\Tests\Support\AppTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class AdminApiTest extends AppTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function protectedRoutes(): iterable
    {
        foreach ([
            ['GET', '/api/admin/clients'], ['POST', '/api/admin/clients'], ['GET', '/api/admin/clients/1'],
            ['PATCH', '/api/admin/clients/1'], ['DELETE', '/api/admin/clients/1'],
            ['POST', '/api/admin/clients/1/logo'], ['DELETE', '/api/admin/clients/1/logo'],
            ['GET', '/api/admin/projects'], ['POST', '/api/admin/projects'], ['GET', '/api/admin/projects/1'],
            ['PATCH', '/api/admin/projects/1'], ['DELETE', '/api/admin/projects/1'],
            ['GET', '/api/admin/videos'], ['POST', '/api/admin/videos'], ['GET', '/api/admin/videos/1'],
            ['PATCH', '/api/admin/videos/1'], ['DELETE', '/api/admin/videos/1'], ['GET', '/api/admin/tags'],
            ['PATCH', '/api/admin/links/1'], ['POST', '/api/admin/links/1/regenerate'],
            ['GET', '/api/admin/videos/1/versions'], ['PATCH', '/api/admin/versions/1'], ['DELETE', '/api/admin/versions/1'],
            ['POST', '/api/admin/versions/1/images'], ['GET', '/api/admin/versions/1/file'], ['POST', '/api/admin/versions/1/encode'],
            ['GET', '/api/admin/uploads'], ['POST', '/api/admin/uploads'], ['GET', '/api/admin/uploads/1'],
            ['PUT', '/api/admin/uploads/1'], ['POST', '/api/admin/uploads/1/complete'], ['DELETE', '/api/admin/uploads/1'],
            ['GET', '/api/admin/versions/1/comments'], ['POST', '/api/admin/versions/1/comments'],
            ['POST', '/api/admin/versions/1/comments/read'], ['PATCH', '/api/admin/comments/1'],
            ['DELETE', '/api/admin/comments/1'], ['GET', '/api/admin/feedback'],
            ['GET', '/api/admin/dashboard'], ['GET', '/api/admin/unread'], ['GET', '/api/admin/versions/1/edl'],
            ['POST', '/api/admin/password'], ['POST', '/api/admin/totp/setup'], ['POST', '/api/admin/totp/enable'],
            ['POST', '/api/admin/totp/disable'], ['POST', '/api/admin/totp/recovery-codes'], ['PATCH', '/api/admin/account'],
            ['GET', '/api/admin/users'], ['PATCH', '/api/admin/users/1'], ['DELETE', '/api/admin/users/1'], ['POST', '/api/admin/users/1/transfer'],
            ['POST', '/api/admin/invitations'], ['POST', '/api/admin/invitations/1/resend'], ['DELETE', '/api/admin/invitations/1'],
            ['PATCH', '/api/admin/organization'],
        ] as [$method, $path]) {
            yield $method . ' ' . $path => [$method, $path];
        }
    }

    #[DataProvider('protectedRoutes')]
    public function testAnonymousVisitorsAreRefused(string $method, string $path): void
    {
        $response = $this->request($method, $path, ['X-CSRF-Token' => $this->csrfToken(), 'Content-Type' => 'application/json'], '{}');

        self::assertSame(401, $response->status);
        self::assertSame('Votre session a expiré. Reconnectez-vous.', $this->decode($response)['error']);
    }

    #[DataProvider('protectedRoutes')]
    public function testStateChangesNeedTheCsrfTokenEvenWhenLoggedIn(string $method, string $path): void
    {
        if ($method === 'GET') {
            $this->expectNotToPerformAssertions();

            return;
        }
        $this->loginAdmin();

        $response = $this->request($method, $path, ['X-CSRF-Token' => 'forged', 'Content-Type' => 'application/json'], '{}');

        self::assertSame(403, $response->status);
    }

    public function testCreatingAClientCreatesItsSpaceLink(): void
    {
        $this->loginAdmin();
        $client = $this->api('POST', '/api/admin/clients', [
            'name' => '  Maison Aurore ', 'bgColor' => '#F3EDE4', 'textColor' => '#1c1915', 'accentColor' => '#a8481f',
            'welcomeText' => "Bonjour\r\nà tous",
        ], 201);

        self::assertSame('Maison Aurore', $client['name']);
        self::assertSame('#f3ede4', $client['bgColor']);
        self::assertSame("Bonjour\nà tous", $client['welcomeText']);
        self::assertMatchesRegularExpression('#^/[a-km-np-z]{10}$#', $client['link']['path']);
        self::assertSame('active', $client['link']['status']);
        self::assertFalse($client['link']['hasPassword']);
    }

    public function testClientValidation(): void
    {
        $this->loginAdmin();
        $base = ['name' => 'X', 'bgColor' => '#000000', 'textColor' => '#ffffff', 'accentColor' => '#e8515f'];

        self::assertSame('name', $this->api('POST', '/api/admin/clients', ['name' => ' '] + $base, 422)['field']);
        self::assertSame('bgColor', $this->api('POST', '/api/admin/clients', ['bgColor' => 'red'] + $base, 422)['field']);
        self::assertSame('textColor', $this->api('POST', '/api/admin/clients', ['textColor' => '#fff;}'] + $base, 422)['field']);
    }

    public function testVideoNumbersStartAt1001AndAreNeverReused(): void
    {
        $this->loginAdmin();
        ['project' => $project, 'video' => $first] = $this->createClientProjectVideo();
        $second = $this->api('POST', '/api/admin/videos', ['projectId' => $project['id'], 'title' => 'Deux'], 201);

        self::assertSame(1001, $first['number']);
        self::assertSame(1002, $second['number']);

        $this->api('DELETE', '/api/admin/videos/' . $second['id']);
        $third = $this->api('POST', '/api/admin/videos', ['projectId' => $project['id'], 'title' => 'Trois'], 201);
        self::assertSame(1003, $third['number']);
    }

    public function testCreatingAVideoCreatesDistinctClientAndViewLinks(): void
    {
        $this->loginAdmin();
        ['video' => $video] = $this->createClientProjectVideo();

        self::assertTrue($video['downloadAllowed']);
        self::assertMatchesRegularExpression('#^/v/1001[a-km-np-z]{10}$#', $video['links']['client']['path']);
        self::assertMatchesRegularExpression('#^/v/1001[a-km-np-z]{10}$#', $video['links']['view']['path']);
        self::assertNotSame($video['links']['client']['path'], $video['links']['view']['path']);
    }

    public function testTagsAreDeduplicatedCaseInsensitivelyAndOfferedForAutocomplete(): void
    {
        $this->loginAdmin();
        ['project' => $project, 'video' => $video] = $this->createClientProjectVideo();
        $this->api('POST', '/api/admin/videos', ['projectId' => $project['id'], 'title' => 'B', 'tags' => ['Corporate', 'PUB']], 201);
        $updated = $this->api('PATCH', '/api/admin/videos/' . $video['id'], ['tags' => ['pub', 'Pub', ' captation  live ']]);

        self::assertSame(['captation live', 'pub'], $updated['tags']);
        self::assertSame(['pub', 'captation live', 'Corporate'], $this->api('GET', '/api/admin/tags'));
    }

    public function testArchivingHidesFromListsUntilAsked(): void
    {
        $this->loginAdmin();
        ['client' => $client, 'project' => $project, 'video' => $video] = $this->createClientProjectVideo();

        $this->api('PATCH', '/api/admin/projects/' . $project['id'], ['archived' => true]);

        self::assertSame([], $this->api('GET', '/api/admin/projects'));
        self::assertSame([], $this->api('GET', '/api/admin/videos'));
        self::assertCount(1, $this->api('GET', '/api/admin/projects?archived=1'));
        self::assertTrue($this->api('GET', '/api/admin/videos?archived=1')[0]['parentArchived']);
        self::assertCount(1, $this->api('GET', '/api/admin/clients'));

        $this->api('PATCH', '/api/admin/clients/' . $client['id'], ['archived' => true]);
        self::assertSame([], $this->api('GET', '/api/admin/clients'));
        self::assertTrue($this->api('GET', '/api/admin/clients?archived=1')[0]['archived']);

        // A video is archived for real (one version kept, the rest deleted): not without a published version.
        $this->api('PATCH', '/api/admin/videos/' . $video['id'], ['archived' => true], 422);
        self::assertFalse($this->api('GET', '/api/admin/videos/' . $video['id'])['archived']);
    }

    public function testDeletingAClientNeedsItsNameAndRemovesEverything(): void
    {
        $this->loginAdmin();
        ['client' => $client, 'video' => $video] = $this->createClientProjectVideo();
        mkdir($this->tmp . '/data/media/' . $client['id'] . '/1001/v1', 0777, true);
        file_put_contents($this->tmp . '/data/media/' . $client['id'] . '/1001/v1/master.mp4', 'x');

        $this->api('DELETE', '/api/admin/clients/' . $client['id'], ['confirm' => 'Autre'], 422);
        $this->api('DELETE', '/api/admin/clients/' . $client['id'], ['confirm' => 'maison aurore']);

        $db = $this->db();
        foreach (['clients', 'projects', 'videos', 'links', 'tags', 'video_tags'] as $table) {
            self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn(), $table);
        }
        self::assertDirectoryDoesNotExist($this->tmp . '/data/media/' . $client['id']);
        self::assertSame(404, $this->request('GET', $video['links']['client']['path'])->status);
    }

    public function testDeletingAProjectRemovesItsVideosAndLinks(): void
    {
        $this->loginAdmin();
        ['project' => $project, 'video' => $video] = $this->createClientProjectVideo();

        $this->api('DELETE', '/api/admin/projects/' . $project['id']);

        self::assertSame(404, $this->request('GET', '/api/admin/videos/' . $video['id'])->status);
        self::assertSame(1, (int) $this->db()->query('SELECT COUNT(*) FROM links')->fetchColumn(), 'only the space link');
    }

    public function testUnknownIdsGetTheNeutral404(): void
    {
        $this->loginAdmin();

        self::assertSame(404, $this->request('GET', '/api/admin/clients/999')->status);
        self::assertSame(422, $this->request('POST', '/api/admin/projects', [
            'X-CSRF-Token' => (string) $this->session->data['csrf'], 'Content-Type' => 'application/json',
        ], '{"clientId":999,"name":"X"}')->status);
    }

    public function testLinkOptions(): void
    {
        $this->loginAdmin();
        ['client' => $client] = $this->createClientProjectVideo();
        $linkId = $client['link']['id'];

        $link = $this->api('PATCH', '/api/admin/links/' . $linkId, ['password' => 'secret', 'expiresOn' => '2099-12-31']);
        self::assertTrue($link['hasPassword']);
        self::assertSame('2099-12-31', $link['expiresOn']);
        self::assertSame('2099-12-31 22:59:59', $this->db()->query('SELECT expires_at FROM links WHERE id = ' . $linkId)->fetchColumn());
        self::assertStringStartsWith('$argon2id$', (string) $this->db()->query('SELECT password_hash FROM links WHERE id = ' . $linkId)->fetchColumn());

        self::assertSame('disabled', $this->api('PATCH', '/api/admin/links/' . $linkId, ['isActive' => false])['status']);
        self::assertSame('expired', $this->api('PATCH', '/api/admin/links/' . $linkId, ['isActive' => true, 'expiresOn' => '2020-01-01'])['status']);

        $cleared = $this->api('PATCH', '/api/admin/links/' . $linkId, ['password' => null, 'expiresOn' => null]);
        self::assertFalse($cleared['hasPassword']);
        self::assertNull($cleared['expiresOn']);
        self::assertSame('active', $cleared['status']);

        self::assertSame('password', $this->api('PATCH', '/api/admin/links/' . $linkId, ['password' => 'abc'], 422)['field']);
        self::assertSame('expiresOn', $this->api('PATCH', '/api/admin/links/' . $linkId, ['expiresOn' => '2026-02-30'], 422)['field']);
    }

    public function testRegeneratingALinkKillsTheOldKey(): void
    {
        $this->loginAdmin();
        ['video' => $video] = $this->createClientProjectVideo();
        $old = $video['links']['view'];

        $new = $this->api('POST', '/api/admin/links/' . $old['id'] . '/regenerate');

        self::assertNotSame($old['path'], $new['path']);
        self::assertSame(404, $this->request('GET', $old['path'])->status);
        self::assertSame(200, $this->request('GET', $new['path'])->status);
    }

    public function testLogoUploadAcceptsPngAndSafeSvgOnly(): void
    {
        $this->loginAdmin();
        ['client' => $client] = $this->createClientProjectVideo();
        $id = $client['id'];

        $png = $this->upload($id, $this->file('logo.png', (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        )));
        self::assertSame(200, $png->status);
        $logoUrl = (string) $this->decode($png)['logoUrl'];
        self::assertMatchesRegularExpression('#^/media/logos/' . $id . '-[0-9a-f]{12}\.png\?e=\d+&s=[A-Za-z0-9_-]+$#', $logoUrl);

        $served = $this->request('GET', $logoUrl);
        self::assertSame(200, $served->status);
        self::assertSame('image/png', $served->header('Content-Type'));

        $svg = $this->upload($id, $this->file('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10" fill="#a8481f"/></svg>'));
        self::assertSame(200, $svg->status);
        $served = $this->request('GET', (string) $this->decode($svg)['logoUrl']);
        self::assertSame('image/svg+xml', $served->header('Content-Type'));
        self::assertStringContainsString('sandbox', (string) $served->header('Content-Security-Policy'));
        self::assertCount(1, glob($this->tmp . '/data/logos/*') ?: [], 'the previous logo is deleted');

        // A hostile SVG is cleaned, not stored as sent.
        $evil = $this->upload($id, $this->file('evil.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(1)</script><rect width="10" height="10"/></svg>'));
        self::assertSame(200, $evil->status);
        $served = $this->request('GET', (string) $this->decode($evil)['logoUrl']);
        self::assertSame('<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10"/></svg>' . "\n", $served->body);
        $entities = $this->upload($id, $this->file('evil.svg', '<!DOCTYPE svg [<!ENTITY x "y">]><svg xmlns="http://www.w3.org/2000/svg"/>'));
        self::assertSame(422, $entities->status);
        $text = $this->upload($id, $this->file('logo.png', 'not an image'));
        self::assertSame(422, $text->status);

        $this->api('DELETE', '/api/admin/clients/' . $id . '/logo');
        self::assertSame([], glob($this->tmp . '/data/logos/*') ?: []);
    }

    public function testMediaNeedsAValidSignature(): void
    {
        $this->loginAdmin();
        ['client' => $client] = $this->createClientProjectVideo();
        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $url = (string) $this->decode($this->upload($client['id'], $this->file('logo.png', $png)))['logoUrl'];
        [$path] = explode('?', $url);
        $reference = $this->request('GET', '/nope')->body;

        foreach ([$path, $path . '?e=9999999999&s=forged', preg_replace('/e=\d+/', 'e=9999999999', $url)] as $bad) {
            $response = $this->request('GET', (string) $bad);
            self::assertSame(404, $response->status, (string) $bad);
            self::assertSame($reference, $response->body);
        }
    }

    /**
     * @return array{name: string, tmp_name: string, size: int, error: int}
     */
    private function file(string $name, string $content): array
    {
        $path = $this->tmp . '/upload-' . bin2hex(random_bytes(4));
        file_put_contents($path, $content);

        return ['name' => $name, 'tmp_name' => $path, 'size' => strlen($content), 'error' => UPLOAD_ERR_OK];
    }

    /**
     * @param array{name: string, tmp_name: string, size: int, error: int} $file
     */
    private function upload(int $clientId, array $file): \Belprise\Http\Response
    {
        return $this->request(
            'POST',
            '/api/admin/clients/' . $clientId . '/logo',
            ['X-CSRF-Token' => (string) $this->session->data['csrf']],
            '',
            [],
            ['logo' => $file],
        );
    }
}
