<?php

declare(strict_types=1);

namespace Belprise\Tests;

use Belprise\Security\BotBlocker;
use Belprise\Security\SecurityHeaders;
use PHPUnit\Framework\TestCase;

/**
 * public/robots.txt and public/.htaccess repeat values defined in PHP:
 * they must not drift apart.
 */
final class PublicFilesTest extends TestCase
{
    private const PUBLIC = __DIR__ . '/../../public';

    public function testRobotsTxtDisallowsEverythingForEveryone(): void
    {
        $robots = (string) file_get_contents(self::PUBLIC . '/robots.txt');

        self::assertStringContainsString("User-agent: *\nDisallow: /\n", $robots);
        foreach (BotBlocker::DEFAULT_PATTERNS as $bot) {
            self::assertStringContainsString("User-agent: {$bot}\nDisallow: /\n", $robots);
        }
        self::assertStringNotContainsStringIgnoringCase('sitemap', $robots);
    }

    public function testHtaccessBlocksTheSameBotsAsPhp(): void
    {
        $htaccess = (string) file_get_contents(self::PUBLIC . '/.htaccess');
        self::assertSame(1, preg_match('/%\{HTTP_USER_AGENT\} =~ \/([^\/]+)\/i"/', $htaccess, $m));

        self::assertSame(BotBlocker::DEFAULT_PATTERNS, explode('|', $m[1]));
    }

    public function testApacheConfigNeverLogsIpsUrlsOrKeysAndEnablesXSendfile(): void
    {
        $vhost = (string) file_get_contents(__DIR__ . '/../../docker/apache/belprise.conf');
        $security = (string) file_get_contents(__DIR__ . '/../../docker/apache/security.conf');

        self::assertSame(1, preg_match('/^\s*LogFormat "([^\n]+)" belprise$/m', $vhost, $m));
        foreach (['%h', '%a', '%U', '%r', '%q', '%{Referer}'] as $field) {
            self::assertStringNotContainsString($field, $m[1], 'access log must not contain ' . $field);
        }
        self::assertStringNotContainsString('%a', $security);
        self::assertStringContainsString('authz_core:crit', $security, '"client denied" messages carry the URL');
        // XSendFilePath alone does nothing: the header would go out with an empty body.
        self::assertMatchesRegularExpression('/^\s*XSendFile On$/m', $vhost);
    }

    public function testHtaccessSendsTheSameFixedHeadersAsPhp(): void
    {
        $htaccess = (string) file_get_contents(self::PUBLIC . '/.htaccess');

        self::assertStringContainsString('Header always set X-Robots-Tag "' . SecurityHeaders::ROBOTS . '"', $htaccess);
        self::assertStringContainsString('Header always set Permissions-Policy "' . SecurityHeaders::PERMISSIONS_POLICY . '"', $htaccess);
        self::assertStringContainsString('Header always set Referrer-Policy "no-referrer"', $htaccess);
        self::assertStringContainsString('Header always set X-Frame-Options "DENY"', $htaccess);
        self::assertStringContainsString('Header always set X-Content-Type-Options "nosniff"', $htaccess);
    }

    public function testBotBlockerIsCaseInsensitiveAndLetsBrowsersThrough(): void
    {
        $blocker = new BotBlocker();

        self::assertTrue($blocker->isBlocked('mozilla/5.0 (compatible; gptbot/1.0)'));
        self::assertFalse($blocker->isBlocked('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1'));
        self::assertFalse($blocker->isBlocked(''));
    }
}
