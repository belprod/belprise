<?php

declare(strict_types=1);

namespace Belprise\Tests;

use Belprise\Database\Database;
use Belprise\Security\RateLimiter;
use Belprise\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    private string $tmp;
    private int $now = 1_800_000_000;
    private RateLimiter $limiter;

    protected function setUp(): void
    {
        $this->tmp = TempDir::create();
        $db = Database::open($this->tmp . '/belprise.sqlite', __DIR__ . '/../../app/migrations');
        $this->limiter = new RateLimiter($db, fn (): int => $this->now);
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->tmp);
    }

    public function testTheDelayDoublesAfterEachFailureUpToTheCap(): void
    {
        self::assertSame(0, RateLimiter::delayFor(0));
        self::assertSame([1, 2, 4, 8, 16, 32, 64, 128, 256, 512], array_map(RateLimiter::delayFor(...), range(1, 10)));
        self::assertSame(RateLimiter::MAX_DELAY, RateLimiter::delayFor(11));
        self::assertSame(RateLimiter::MAX_DELAY, RateLimiter::delayFor(1000));
    }

    public function testAFixedWindowAllowsSoManyRequestsAnHour(): void
    {
        foreach (range(1, 3) as $_) {
            self::assertSame(0, $this->limiter->hit('forgot', 'k', 3, 3600));
        }
        self::assertSame(3600, $this->limiter->hit('forgot', 'k', 3, 3600));
        self::assertSame(0, $this->limiter->hit('forgot', 'other', 3, 3600), 'per key');

        $this->now += 1800;
        self::assertSame(1800, $this->limiter->hit('forgot', 'k', 3, 3600), 'the window does not slide on refused requests');
        $this->now += 1800;
        self::assertSame(0, $this->limiter->hit('forgot', 'k', 3, 3600), 'a new hour, a new count');
    }

    public function testFailuresBlockForAGrowingTime(): void
    {
        self::assertSame(0, $this->limiter->retryAfter('login', 'k'));
        self::assertSame(1, $this->limiter->fail('login', 'k'));
        self::assertSame(1, $this->limiter->retryAfter('login', 'k'));

        $this->now += 1;
        self::assertSame(0, $this->limiter->retryAfter('login', 'k'));
        self::assertSame(2, $this->limiter->fail('login', 'k'));
        self::assertSame(4, $this->limiter->fail('login', 'k'));
        self::assertSame(4, $this->limiter->retryAfter('login', 'k'));
    }

    public function testKeysAndScopesAreIndependent(): void
    {
        $this->limiter->fail('login', 'a');

        self::assertSame(0, $this->limiter->retryAfter('login', 'b'));
        self::assertSame(0, $this->limiter->retryAfter('link', 'a'));
    }

    public function testClearAndOldFailuresAreForgotten(): void
    {
        $this->limiter->fail('login', 'k');
        $this->limiter->clear('login', 'k');
        self::assertSame(1, $this->limiter->fail('login', 'k'));

        $this->now += 86401;
        self::assertSame(1, $this->limiter->fail('login', 'k'));
    }

    public function testAnAttemptIsClaimedBeforeItIsChecked(): void
    {
        // Two requests sent together: the second one is refused straight away.
        self::assertSame(0, $this->limiter->attempt('login', 'k'));
        self::assertSame(1, $this->limiter->attempt('login', 'k'));

        $this->now += 1;
        self::assertSame(0, $this->limiter->attempt('login', 'k'));
        self::assertSame(2, $this->limiter->attempt('login', 'k'));

        // A success clears the count.
        $this->limiter->clear('login', 'k');
        self::assertSame(0, $this->limiter->attempt('login', 'k'));
    }

    public function testDelayIsCappedAfterManyFailures(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $this->limiter->fail('login', 'k');
        }

        self::assertSame(RateLimiter::MAX_DELAY, $this->limiter->retryAfter('login', 'k'));
    }
}
