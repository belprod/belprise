<?php

declare(strict_types=1);

namespace Belprise\Tests;

use Belprise\Controller\AdminController;
use Belprise\Http\Request;
use Belprise\Http\Response;
use Belprise\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testMatchesMethodAndPathAndExtractsNamedGroups(): void
    {
        $router = new Router();
        $router->get('#^/v/(?<number>\d+)(?<key>[a-z]{10})$#', static fn () => new Response());

        $match = $router->match(new Request('GET', '/v/1042kfmxqtrwpz'));

        self::assertNotNull($match);
        self::assertSame(['number' => '1042', 'key' => 'kfmxqtrwpz'], $match[1]);
        self::assertNull($router->match(new Request('POST', '/v/1042kfmxqtrwpz')));
        self::assertNull($router->match(new Request('GET', '/v/1042kfmxqtrwp')));
    }

    public function testGetRoutesAnswerHead(): void
    {
        $router = new Router();
        $router->get('#^/admin$#', static fn () => new Response());

        self::assertNotNull($router->match(new Request('HEAD', '/admin')));
    }

    public function testAdminShellPatternAcceptsOnlyKnownSections(): void
    {
        $pattern = AdminController::shellPattern();

        foreach (['/admin', '/admin/clients', '/admin/projects', '/admin/videos', '/admin/settings'] as $path) {
            self::assertMatchesRegularExpression($pattern, $path);
        }
        foreach (['/admin/', '/admin/unknown', '/administration', '/admin/clients/../x'] as $path) {
            self::assertDoesNotMatchRegularExpression($pattern, $path);
        }
    }
}
