<?php

declare(strict_types=1);

namespace Belprise\Tests;

use Belprise\Config;
use Belprise\ConfigException;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testExampleConfigurationNeedsASecret(): void
    {
        $this->expectException(ConfigException::class);
        Config::load(__DIR__ . '/../../app/config.example.php');
    }

    public function testMissingFileIsReported(): void
    {
        $this->expectException(ConfigException::class);
        Config::load('/nonexistent/config.php');
    }

    public function testValidConfiguration(): void
    {
        $config = Config::fromArray(['data_dir' => '/srv/belprise/data/', 'secret' => str_repeat('a', 64), 'xsendfile' => true]);

        self::assertSame('/srv/belprise/data', $config->dataDir);
        self::assertTrue($config->xsendfile);
        self::assertFalse($config->hsts);
        self::assertSame([], $config->trustedProxies);
    }

    public function testShortSecretIsRejected(): void
    {
        $this->expectException(ConfigException::class);
        Config::fromArray(['data_dir' => '/tmp', 'secret' => 'too-short']);
    }
}
