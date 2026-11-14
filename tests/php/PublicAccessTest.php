<?php

declare(strict_types=1);

namespace Belprise\Tests;

use Belprise\Http\Response;
use Belprise\Tests\Support\AppTestCase;

/**
 * Client space and video links (§2, §4, §6): access rules and the
 * indistinguishable 404.
 */
final class PublicAccessTest extends AppTestCase
{
    /** @var array{client: array<string, mixed>, project: array<string, mixed>, video: array<string, mixed>} */
    private array $fixture;
    private string $notFound;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
        $this->fixture = $this->createClientProjectVideo();
        $this->notFound = $this->request('GET', '/nope')->body;
    }

    private function spacePath(): string
    {
        return $this->fixture['client']['link']['path'];
    }

    private function linkPath(string $type): string
    {
        return $this->fixture['video']['links'][$type]['path'];
    }

    private function boot(Response $response): array
    {
        self::assertSame(1, preg_match('#<script type="application/json" id="belprise-boot">(.*?)</script>#s', $response->body, $m));

        return json_decode($m[1], true, 16, JSON_THROW_ON_ERROR);
    }

    private function assertNeutral404(string $path, string $method = 'GET'): void
    {
        $response = $this->request($method, $path);
        self::assertSame(404, $response->status, $path);
        self::assertSame($this->notFound, $response->body, $path);
    }

    public function testClientSpaceIsServedInTheClientColours(): void
    {
        $response = $this->request('GET', $this->spacePath());

        self::assertSame(200, $response->status);
        self::assertStringContainsString(':root{--client-bg:#f3ede4;--client-text:#1c1915;--client-accent:#a8481f;--client-on-accent:#ffffff;--client-accent-text:#a1461f;--status-ink:35%;color-scheme:light}', $response->body);
        self::assertStringContainsString('<title>Maison Aurore</title>', $response->body);
        $boot = $this->boot($response);
        self::assertSame('space', $boot['page']);
        self::assertSame('Bienvenue', $boot['client']['welcomeText']);
        self::assertSame('no-store', $response->header('Cache-Control'));
    }

    public function testClientLinkOpensTheReviewPageAndViewLinkTheViewPage(): void
    {
        $review = $this->boot($this->request('GET', $this->linkPath('client')));
        $view = $this->boot($this->request('GET', $this->linkPath('view')));

        self::assertSame('review', $review['page']);
        self::assertSame(['number' => 1001, 'title' => 'Film manifeste', 'downloadAllowed' => true], $review['video']);
        self::assertSame('Printemps', $review['project']['name']);
        self::assertSame('view', $view['page']);
        self::assertArrayNotHasKey('project', $view);
    }

    public function testAKeyAlteredByOneCharacterIsANeutral404(): void
    {
        $path = $this->linkPath('client');
        $last = $path[-1];
        $this->assertNeutral404(substr($path, 0, -1) . ($last === 'z' ? 'y' : 'z'));
        $this->assertNeutral404(substr($this->spacePath(), 0, -1) . ($this->spacePath()[-1] === 'z' ? 'y' : 'z'));
    }

    public function testVideoKeyWithAnotherNumberIsANeutral404(): void
    {
        $this->assertNeutral404(str_replace('/v/1001', '/v/1002', $this->linkPath('client')));
    }

    public function testSpaceKeyUsedAsVideoLinkAndViceVersaAreNeutral404s(): void
    {
        $this->assertNeutral404('/v/1001' . substr($this->spacePath(), 1));
        $this->assertNeutral404('/' . substr($this->linkPath('client'), strlen('/v/1001')));
    }

    public function testDisabledLinkIsANeutral404(): void
    {
        foreach ([$this->fixture['client']['link'], $this->fixture['video']['links']['client'], $this->fixture['video']['links']['view']] as $link) {
            $this->api('PATCH', '/api/admin/links/' . $link['id'], ['isActive' => false]);
            $this->assertNeutral404($link['path']);
            $this->assertNeutral404($link['path'], 'POST');
        }
    }

    public function testExpiredLinkIsANeutral404(): void
    {
        $link = $this->fixture['video']['links']['view'];
        $this->api('PATCH', '/api/admin/links/' . $link['id'], ['expiresOn' => '2020-01-01']);

        $this->assertNeutral404($link['path']);
    }

    public function testLinksOfAnArchivedClientKeepWorking(): void
    {
        $this->api('PATCH', '/api/admin/clients/' . $this->fixture['client']['id'], ['archived' => true]);

        self::assertSame(200, $this->request('GET', $this->spacePath())->status);
        self::assertSame(200, $this->request('GET', $this->linkPath('client'))->status);
    }

    public function testPasswordProtectedLink(): void
    {
        $link = $this->fixture['video']['links']['client'];
        $this->api('PATCH', '/api/admin/links/' . $link['id'], ['password' => 'aurore2027']);

        // The form is neutral: nothing about the client or the video.
        $form = $this->request('GET', $link['path']);
        self::assertSame(200, $form->status);
        self::assertStringContainsString('name="password"', $form->body);
        self::assertStringNotContainsString('Maison Aurore', $form->body);
        self::assertStringNotContainsString('Film manifeste', $form->body);
        self::assertStringNotContainsString('--client-', $form->body);

        $wrong = $this->submitPassword($link['path'], 'mauvais');
        self::assertSame(200, $wrong->status);
        self::assertStringContainsString('Mot de passe incorrect.', $wrong->body);
        self::assertSame([], $wrong->cookies());

        $throttled = $this->submitPassword($link['path'], 'aurore2027');
        self::assertSame(429, $throttled->status, 'still throttled after a failure');

        $this->db()->exec('DELETE FROM login_attempts');
        $right = $this->submitPassword($link['path'], 'aurore2027');
        self::assertSame(303, $right->status);
        self::assertSame($link['path'], $right->header('Location'));
        $cookie = $right->cookies()['belprise_link_' . $link['id']];
        self::assertTrue($cookie['options']['httponly']);
        self::assertTrue($cookie['options']['secure']);
        self::assertSame('Lax', $cookie['options']['samesite']);

        $page = $this->request('GET', $link['path'], [], '', ['belprise_link_' . $link['id'] => $cookie['value']]);
        self::assertSame('review', $this->boot($page)['page']);

        // The cookie only opens this link.
        $other = $this->fixture['video']['links']['view'];
        $this->api('PATCH', '/api/admin/links/' . $other['id'], ['password' => 'autre-mdp']);
        $otherPage = $this->request('GET', $other['path'], [], '', ['belprise_link_' . $link['id'] => $cookie['value']]);
        self::assertStringContainsString('name="password"', $otherPage->body);

        // A new password invalidates the cookie.
        $this->api('PATCH', '/api/admin/links/' . $link['id'], ['password' => 'nouveau-mdp']);
        $again = $this->request('GET', $link['path'], [], '', ['belprise_link_' . $link['id'] => $cookie['value']]);
        self::assertStringContainsString('name="password"', $again->body);
    }

    public function testReservedPathsAreNeverClientSpaces(): void
    {
        $this->assertNeutral404('/adminxqtrw');
        $this->assertNeutral404('/assetsqtrw');
        $this->assertNeutral404('/robots.txt');
    }

    private function submitPassword(string $path, string $password): Response
    {
        return $this->request('POST', $path, ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query(['password' => $password]));
    }
}
