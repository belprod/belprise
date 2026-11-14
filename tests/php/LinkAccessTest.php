<?php

declare(strict_types=1);

namespace Belprise\Tests;

use Belprise\Security\LinkAccess;
use PHPUnit\Framework\TestCase;

final class LinkAccessTest extends TestCase
{
    private int $now;
    private LinkAccess $access;

    protected function setUp(): void
    {
        $this->now = (int) strtotime('2026-11-06 12:00:00 UTC');
        $this->access = new LinkAccess(str_repeat('k', 64), fn (): int => $this->now);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function link(array $overrides = []): array
    {
        return $overrides + ['id' => 7, 'key' => 'kfmxqtrwpz', 'is_active' => 1, 'expires_at' => null, 'password_hash' => null];
    }

    public function testActiveLinkWithoutExpiryIsUsable(): void
    {
        self::assertTrue($this->access->isUsable($this->link()));
    }

    public function testDisabledLinkIsNotUsable(): void
    {
        self::assertFalse($this->access->isUsable($this->link(['is_active' => 0])));
    }

    public function testExpiryIsChecked(): void
    {
        self::assertTrue($this->access->isUsable($this->link(['expires_at' => '2026-11-06 12:00:01'])));
        self::assertFalse($this->access->isUsable($this->link(['expires_at' => '2026-11-06 12:00:00'])));
        self::assertFalse($this->access->isUsable($this->link(['expires_at' => '2026-01-01 00:00:00'])));
    }

    public function testPasswordTokenIsBoundToTheLinkKeyAndPassword(): void
    {
        $link = $this->link(['password_hash' => '$argon2id$one']);
        $token = $this->access->issueToken($link);

        self::assertTrue($this->access->needsPassword($link));
        self::assertTrue($this->access->hasValidToken($link, $token));
        self::assertFalse($this->access->hasValidToken(['id' => 8] + $link, $token), 'another link');
        self::assertFalse($this->access->hasValidToken(['key' => 'hbnwzcxqre'] + $link, $token), 'regenerated key');
        self::assertFalse($this->access->hasValidToken(['password_hash' => '$argon2id$two'] + $link, $token), 'new password');
        self::assertFalse($this->access->hasValidToken($link, null));
        self::assertFalse($this->access->hasValidToken($link, 'garbage'));
    }

    public function testPasswordTokenExpires(): void
    {
        $link = $this->link(['password_hash' => '$argon2id$one']);
        $token = $this->access->issueToken($link);

        $this->now += LinkAccess::TTL - 1;
        self::assertTrue($this->access->hasValidToken($link, $token));
        $this->now += 1;
        self::assertFalse($this->access->hasValidToken($link, $token));
    }
}
