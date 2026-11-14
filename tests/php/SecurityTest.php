<?php

declare(strict_types=1);

namespace Belprise\Tests;

use Belprise\Http\Request;
use Belprise\Security\SecurityHeaders;
use Belprise\Tests\Support\AppTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class SecurityTest extends AppTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidUrls(): iterable
    {
        yield 'root' => ['GET', '/'];
        yield 'space key that does not exist' => ['GET', '/kfmxqtrwpz'];
        yield 'video link that does not exist' => ['GET', '/v/1042kfmxqtrwpz'];
        yield 'unknown admin section' => ['GET', '/admin/unknown'];
        yield 'unknown API route' => ['GET', '/api/whatever'];
        yield 'unsigned media' => ['GET', '/media/1/1042/v1/master.mp4'];
        yield 'front controller itself' => ['GET', '/index.php'];
        yield 'wrong method on a page' => ['DELETE', '/admin'];
        yield 'wrong method on the API' => ['GET', '/api/admin/login'];
    }

    #[DataProvider('invalidUrls')]
    public function testEveryInvalidUrlGetsTheSameNeutral404(string $method, string $path): void
    {
        $reference = $this->request('GET', '/zzzzzzzzzz');
        $response = $this->request($method, $path);

        self::assertSame(404, $response->status);
        self::assertSame($reference->body, $response->body);
        self::assertSame($reference->header('Content-Type'), $response->header('Content-Type'));
        self::assertStringContainsString('Page introuvable', $response->body);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function responses(): iterable
    {
        yield 'admin shell' => ['GET', '/admin'];
        yield 'API' => ['GET', '/api/admin/session'];
        yield '404' => ['GET', '/nope'];
    }

    #[DataProvider('responses')]
    public function testEveryResponseCarriesTheSecurityHeaders(string $method, string $path): void
    {
        $response = $this->request($method, $path);

        self::assertSame(SecurityHeaders::ROBOTS, $response->header('X-Robots-Tag'));
        self::assertSame('no-referrer', $response->header('Referrer-Policy'));
        self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
        self::assertSame('DENY', $response->header('X-Frame-Options'));
        self::assertNotNull($response->header('Permissions-Policy'));
        $csp = (string) $response->header('Content-Security-Policy');
        self::assertStringContainsString("default-src 'self'", $csp);
        self::assertStringContainsString("frame-ancestors 'none'", $csp);
        self::assertStringNotContainsString('unsafe-inline', $csp);
        self::assertNull($response->header('Strict-Transport-Security'));
    }

    public function testHtmlPagesCarryTheRobotsMetaTag(): void
    {
        foreach (['/admin', '/nope'] as $path) {
            self::assertStringContainsString(
                '<meta name="robots" content="' . SecurityHeaders::ROBOTS . '">',
                $this->request('GET', $path)->body,
            );
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function botUserAgents(): iterable
    {
        yield ['GPTBot/1.2'];
        yield ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)'];
        yield ['Mozilla/5.0 (compatible; PerplexityBot/1.0)'];
        yield ['facebookexternalhit/1.1'];
        yield ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'];
    }

    #[DataProvider('botUserAgents')]
    public function testBotsGet403OnEveryUrl(string $userAgent): void
    {
        foreach (['/admin', '/api/admin/session', '/nope'] as $path) {
            $response = $this->request('GET', $path, ['User-Agent' => $userAgent]);
            self::assertSame(403, $response->status, $path);
            self::assertSame(SecurityHeaders::ROBOTS, $response->header('X-Robots-Tag'));
        }
    }

    public function testStateChangingAdminRequestsRequireTheCsrfToken(): void
    {
        $body = ['organization' => 'belprod', 'name' => 'Jules', 'email' => self::ADMIN_EMAIL, 'password' => 'un-mot-de-passe-long', 'confirmation' => 'un-mot-de-passe-long'];

        self::assertSame(403, $this->postJson('/api/admin/setup', $body, 'forged')->status);
        self::assertSame(403, $this->postJson('/api/admin/setup', $body, '')->status);
        self::assertSame(200, $this->postJson('/api/admin/setup', $body)->status);
    }

    public function testShellInjectsTheNonceOnlyInTheCspAndBootDataIsEscaped(): void
    {
        $response = $this->request('GET', '/admin');

        self::assertSame(200, $response->status);
        self::assertSame('no-store', $response->header('Cache-Control'));
        self::assertMatchesRegularExpression("/style-src 'self' 'nonce-[A-Za-z0-9+\\/=]{24}'/", (string) $response->header('Content-Security-Policy'));
        self::assertStringContainsString('<script type="module" src="/assets/admin-AAAAAAAA.js"></script>', $response->body);
        self::assertStringContainsString('<link rel="stylesheet" href="/assets/admin-BBBBBBBB.css">', $response->body);
        self::assertStringContainsString('<script type="application/json" id="belprise-boot">', $response->body);
    }

    public function testHstsIsSentOnlyWhenEnabled(): void
    {
        $app = new \Belprise\App(
            \Belprise\Config::fromArray(['data_dir' => $this->tmp . '/data', 'secret' => str_repeat('s', 64), 'hsts' => true]),
            $this->tmp . '/public',
            dirname(__DIR__, 2) . '/app/migrations',
            $this->session,
        );
        $response = $app->handle(new \Belprise\Http\Request('GET', '/nope', ['User-Agent' => self::BROWSER_UA]));

        self::assertSame('max-age=31536000', $response->header('Strict-Transport-Security'));
    }

    public function testRateLimitsCountAnIpv6ClientByItsSlash64(): void
    {
        self::assertSame('192.0.2.7', Request::network('192.0.2.7'));
        self::assertSame('2001:db8:1:2::/64', Request::network('2001:db8:1:2:aaaa:bbbb:cccc:dddd'));
        self::assertSame('2001:db8:1:2::/64', Request::network('2001:db8:1:2::1'));
        self::assertSame('192.0.2.7', Request::network('::ffff:192.0.2.7'));
    }

    public function testOversizedBodiesAreRefusedWithoutBeingParsed(): void
    {
        $response = $this->request('POST', '/api/admin/login', [
            'Content-Type' => 'application/json',
            'X-CSRF-Token' => $this->csrfToken(),
        ], '{"username":"' . str_repeat('a', Request::MAX_BODY) . '"}');

        self::assertSame(413, $response->status);
    }
}
