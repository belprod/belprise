<?php

declare(strict_types=1);

namespace Belprise\Security;

use Closure;

/**
 * Signed media URLs: /media/{path}?e={expiry}&s={HMAC}.
 * The expiry is rounded up to the hour, so a URL stays the same (and
 * cacheable) for a while; it is valid between TTL and TTL + 1 h.
 */
final class MediaSigner
{
    public const TTL = 4 * 3600;
    private const ROUNDING = 3600;

    /** @var Closure(): int */
    private readonly Closure $now;

    /** @param (Closure(): int)|null $now */
    public function __construct(private readonly string $secret, ?Closure $now = null)
    {
        $this->now = $now ?? static fn (): int => time();
    }

    /**
     * @param string $path relative to data/, e.g. "logos/12-ab12cd.png"
     */
    public function url(string $path): string
    {
        $expires = (intdiv(($this->now)() + self::TTL, self::ROUNDING) + 1) * self::ROUNDING;

        return '/media/' . $path . '?e=' . $expires . '&s=' . $this->signature($path, $expires);
    }

    public function isValid(string $path, ?string $expires, ?string $signature): bool
    {
        if ($expires === null || $signature === null || preg_match('/^[0-9]{1,12}$/D', $expires) !== 1) {
            return false;
        }

        return (int) $expires > ($this->now)()
            && hash_equals($this->signature($path, (int) $expires), $signature);
    }

    private function signature(string $path, int $expires): string
    {
        $mac = hash_hmac('sha256', 'media|' . $path . '|' . $expires, $this->secret, true);

        return rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
    }
}
