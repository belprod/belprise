<?php

declare(strict_types=1);

namespace Belprise\Tests;

use Belprise\Timecode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Same reference file as frontend/src/lib/timecode.test.ts: PHP and
 * TypeScript must give identical results.
 */
final class TimecodeTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function fixture(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/../fixtures/timecode.json'), true, 16, JSON_THROW_ON_ERROR);
    }

    /** @return array{int, int, bool} */
    private static function rate(string $name): array
    {
        $r = self::fixture()['rates'][$name];

        return [$r['num'], $r['den'], $r['dropFrame']];
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function cases(): iterable
    {
        foreach (self::fixture()['cases'] as [$rate, $frames, $tc]) {
            yield "{$rate} {$frames} {$tc}" => [$rate, $frames, $tc];
        }
    }

    #[DataProvider('cases')]
    public function testReferenceCases(string $rate, int $frames, string $tc): void
    {
        self::assertSame($tc, Timecode::fromFrames($frames, ...self::rate($rate)));
        self::assertSame($frames, Timecode::toFrames($tc, ...self::rate($rate)));
    }

    public function testWrapAround(): void
    {
        foreach (self::fixture()['wrap'] as [$rate, $frames, $tc]) {
            self::assertSame($tc, Timecode::fromFrames($frames, ...self::rate($rate)), "{$rate} {$frames}");
        }
    }

    public function testParsing(): void
    {
        foreach (self::fixture()['parse'] as [$rate, $tc, $frames]) {
            self::assertSame($frames, Timecode::toFrames($tc, ...self::rate($rate)), "{$rate} {$tc}");
        }
        foreach (self::fixture()['invalid'] as [$rate, $tc]) {
            self::assertNull(Timecode::toFrames($tc, ...self::rate($rate)), "{$rate} \"{$tc}\"");
        }
    }

    /** @return iterable<string, array{string}> */
    public static function rateNames(): iterable
    {
        foreach (array_keys(self::fixture()['rates']) as $name) {
            yield (string) $name => [(string) $name];
        }
    }

    #[DataProvider('rateNames')]
    public function testRoundTripOnTheFirstAndLastElevenMinutesAndSamples(string $name): void
    {
        [$num, $den, $df] = self::rate($name);
        $day = Timecode::framesPerDay($num, $den, $df);
        $span = (int) round($num / $den * 660);
        $frames = [...range(0, $span - 1), ...range($day - $span, $day - 1)];
        for ($i = 0; $i < 20000; $i++) {
            $frames[] = ($i * 7919 * 131) % $day;
        }
        foreach ($frames as $frame) {
            $tc = Timecode::fromFrames($frame, $num, $den, $df);
            if (Timecode::toFrames($tc, $num, $den, $df) !== $frame) {
                self::fail("{$name}: frame {$frame} → {$tc}");
            }
        }
        $this->addToAssertionCount(1);
    }

    public function testDropFrameIsIgnoredWhereItDoesNotExist(): void
    {
        self::assertSame('01:00:00:00', Timecode::fromFrames(90000, 25, 1, true));
        self::assertFalse(Timecode::supportsDropFrame(24000, 1001));
        self::assertTrue(Timecode::supportsDropFrame(60000, 1001));
    }
}
