<?php

declare(strict_types=1);

namespace Belprise\Tests;

use Belprise\Security\MediaSigner;
use PHPUnit\Framework\TestCase;

final class MediaSignerTest extends TestCase
{
    private int $now = 1_800_000_000;
    private MediaSigner $signer;

    protected function setUp(): void
    {
        $this->signer = new MediaSigner(str_repeat('k', 64), fn (): int => $this->now);
    }

    /**
     * @return array{string, string, string}
     */
    private function parts(string $url): array
    {
        self::assertSame(1, preg_match('#^/media/(.+)\?e=(\d+)&s=([A-Za-z0-9_-]+)$#', $url, $m));

        return [$m[1], $m[2], $m[3]];
    }

    public function testASignedUrlIsValid(): void
    {
        [$path, $e, $s] = $this->parts($this->signer->url('logos/1-abcdef012345.png'));

        self::assertSame('logos/1-abcdef012345.png', $path);
        self::assertTrue($this->signer->isValid($path, $e, $s));
    }

    public function testTheUrlExpiresAfterTheTtlPlusAtMostOneHour(): void
    {
        [$path, $e, $s] = $this->parts($this->signer->url('logos/1-abcdef012345.png'));
        $lifetime = (int) $e - $this->now;

        self::assertGreaterThan(MediaSigner::TTL, $lifetime);
        self::assertLessThanOrEqual(MediaSigner::TTL + 3600, $lifetime);

        $this->now = (int) $e - 1;
        self::assertTrue($this->signer->isValid($path, $e, $s));
        $this->now = (int) $e;
        self::assertFalse($this->signer->isValid($path, $e, $s));
    }

    public function testTheUrlIsStableWithinTheHour(): void
    {
        $first = $this->signer->url('logos/1-abcdef012345.png');
        $this->now += 60;

        self::assertSame($first, $this->signer->url('logos/1-abcdef012345.png'));
    }

    public function testTamperingIsDetected(): void
    {
        [$path, $e, $s] = $this->parts($this->signer->url('logos/1-abcdef012345.png'));

        self::assertFalse($this->signer->isValid('logos/2-abcdef012345.png', $e, $s));
        self::assertFalse($this->signer->isValid($path, (string) ((int) $e + 3600), $s));
        self::assertFalse($this->signer->isValid($path, $e, substr($s, 0, -1) . ($s[-1] === 'A' ? 'B' : 'A')));
        self::assertFalse($this->signer->isValid($path, null, $s));
        self::assertFalse($this->signer->isValid($path, $e, null));
        self::assertFalse($this->signer->isValid($path, '1e10', $s));
    }

    public function testAnotherSecretDoesNotValidate(): void
    {
        [$path, $e, $s] = $this->parts($this->signer->url('logos/1-abcdef012345.png'));
        $other = new MediaSigner(str_repeat('x', 64), fn (): int => $this->now);

        self::assertFalse($other->isValid($path, $e, $s));
    }
}
