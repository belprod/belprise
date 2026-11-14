<?php

declare(strict_types=1);

namespace Belprise\Tests;

use Belprise\Config;
use Belprise\Database\Database;
use Belprise\Repository\Settings;
use Belprise\Tests\Support\TempDir;
use Belprise\Update\UpdateCheck;
use PHPUnit\Framework\TestCase;

/**
 * "Nouvelle version disponible" (decision D8, criterion 27): once a day at
 * most, by the scheduled task, nothing but the request; tests never go out.
 */
final class UpdateCheckTest extends TestCase
{
    private string $tmp;
    private Settings $settings;
    private int $now = 1_800_000_000;
    /** @var list<string> */
    private array $requested = [];
    private ?string $answer = '{"version":"3.0.1","url":"https://belprise.fr/versions/"}';

    protected function setUp(): void
    {
        $this->tmp = TempDir::create();
        $this->settings = new Settings(Database::open($this->tmp . '/belprise.sqlite', __DIR__ . '/../../app/migrations'));
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->tmp);
    }

    private function check(): UpdateCheck
    {
        return new UpdateCheck($this->settings, function (string $url): ?string {
            $this->requested[] = $url;

            return $this->answer;
        }, fn (): int => $this->now);
    }

    public function testOnceADayAtMostThenTheNewerVersionIsShown(): void
    {
        self::assertSame('3.0.1', $this->check()->run());
        self::assertSame(['https://belprise.fr/versions.json'], $this->requested);
        $this->now += 3600;
        self::assertNull($this->check()->run(), 'not again the same day');
        self::assertCount(1, $this->requested);
        $this->now += 86400;
        $this->check()->run();
        self::assertCount(2, $this->requested);

        self::assertSame(
            ['installed' => '3.0.0', 'latest' => '3.0.1', 'newer' => true, 'url' => 'https://belprise.fr/versions/', 'checkedAt' => $this->now],
            $this->check()->state('3.0.0'),
        );
        self::assertFalse($this->check()->state('3.0.1')['newer']);
        self::assertFalse($this->check()->state('développement')['newer'], 'a development copy is never told to update');
    }

    public function testAnUnreachableOrOddAnswerChangesNothing(): void
    {
        $this->check()->run();
        foreach ([null, 'pas du json', '{"version":"<script>"}', '{"version":"3.1"}'] as $answer) {
            $this->answer = $answer;
            $this->now += 86400;
            self::assertNull($this->check()->run());
            self::assertSame('3.0.1', $this->check()->state('3.0.0')['latest'], 'the last good answer stays');
        }
        $this->answer = '{"version":"3.2.0","url":"javascript:alert(1)"}';
        $this->now += 86400;
        $this->check()->run();
        self::assertSame(['3.2.0', null], [$this->check()->state('3.0.0')['latest'], $this->check()->state('3.0.0')['url']], 'only https links');
    }

    public function testTheInstalledVersionComesFromTheVersionFile(): void
    {
        self::assertSame('développement', UpdateCheck::installed($this->tmp));
        file_put_contents($this->tmp . '/VERSION', "3.0.0\n");
        self::assertSame('3.0.0', UpdateCheck::installed($this->tmp));
    }

    public function testTheCheckCanBeTurnedOff(): void
    {
        $base = ['data_dir' => $this->tmp, 'secret' => str_repeat('s', 64)];
        self::assertTrue(Config::fromArray($base)->updateCheck);
        self::assertFalse(Config::fromArray($base + ['update_check' => false])->updateCheck);
    }
}
