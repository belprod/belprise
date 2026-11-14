<?php

declare(strict_types=1);

namespace Belprise\Security;

use Closure;

/**
 * Rules for using a link (§2, §6): active, not expired, and for a
 * password-protected link, a cookie proving the password was given.
 *
 * The cookie is bound to the link's key and password hash: regenerating
 * the key or changing the password invalidates it.
 */
final class LinkAccess
{
    public const COOKIE_PREFIX = 'belprise_link_';
    public const TTL = 7 * 86400;
    public const THROTTLE_SCOPE = 'link_password';

    /** @var Closure(): int */
    private readonly Closure $now;

    /** @param (Closure(): int)|null $now */
    public function __construct(private readonly string $secret, ?Closure $now = null)
    {
        $this->now = $now ?? static fn (): int => time();
    }

    /**
     * @param array{is_active: int, expires_at: string|null} $link
     */
    public function isUsable(array $link): bool
    {
        if ((int) $link['is_active'] !== 1) {
            return false;
        }
        if ($link['expires_at'] === null) {
            return true;
        }

        return strtotime($link['expires_at'] . ' UTC') > ($this->now)();
    }

    /**
     * @param array{id: int, password_hash: string|null} $link
     */
    public function needsPassword(array $link): bool
    {
        return $link['password_hash'] !== null;
    }

    /**
     * @param array{id: int} $link
     */
    public function cookieName(array $link): string
    {
        return self::COOKIE_PREFIX . $link['id'];
    }

    /**
     * @param array{id: int, key: string, password_hash: string|null} $link
     */
    public function issueToken(array $link): string
    {
        $expires = ($this->now)() + self::TTL;

        return $expires . '.' . $this->mac($link, $expires);
    }

    /**
     * @param array{id: int, key: string, password_hash: string|null} $link
     */
    public function hasValidToken(array $link, ?string $token): bool
    {
        if ($token === null || preg_match('/^([0-9]{1,12})\.([A-Za-z0-9_-]+)$/D', $token, $m) !== 1) {
            return false;
        }

        return (int) $m[1] > ($this->now)() && hash_equals($this->mac($link, (int) $m[1]), $m[2]);
    }

    /**
     * CSRF token of the client pages: bound to the link, so only a page
     * served for that link can post to its API.
     *
     * @param array{id: int, key: string} $link
     */
    public function csrfToken(array $link): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', 'csrf|' . $link['id'] . '|' . $link['key'], $this->secret, true)), '+/', '-_'), '=');
    }

    /**
     * @param array{id: int, key: string, password_hash: string|null} $link
     */
    private function mac(array $link, int $expires): string
    {
        $data = 'link|' . $link['id'] . '|' . $link['key'] . '|' . ($link['password_hash'] ?? '') . '|' . $expires;

        return rtrim(strtr(base64_encode(hash_hmac('sha256', $data, $this->secret, true)), '+/', '-_'), '=');
    }
}
