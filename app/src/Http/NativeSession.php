<?php

declare(strict_types=1);

namespace Belprise\Http;

/**
 * PHP session, started lazily, stored under data/sessions.
 * Cookie: HttpOnly, Secure, SameSite=Strict.
 */
final class NativeSession implements SessionStore
{
    public const COOKIE_NAME = 'belprise_admin';
    private const MAX_LIFETIME = 43200;

    private bool $started = false;

    public function __construct(private readonly string $savePath)
    {
    }

    public function get(string $key): mixed
    {
        $this->start();

        return $_SESSION[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->start();
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        $this->start();
        unset($_SESSION[$key]);
    }

    public function regenerate(): void
    {
        $this->start();
        session_regenerate_id(true);
    }

    public function destroy(): void
    {
        $this->start();
        $_SESSION = [];
        session_destroy();
        setcookie(self::COOKIE_NAME, '', $this->cookieOptions(time() - 3600));
        $this->started = false;
    }

    private function start(): void
    {
        if ($this->started) {
            return;
        }
        if (!is_dir($this->savePath)) {
            mkdir($this->savePath, 0770, true);
        }
        session_name(self::COOKIE_NAME);
        session_save_path($this->savePath);
        session_set_cookie_params($this->cookieOptions(0));
        session_start([
            'use_strict_mode' => true,
            'use_only_cookies' => true,
            'gc_maxlifetime' => self::MAX_LIFETIME,
            'gc_probability' => 1,
            'gc_divisor' => 100,
        ]);
        $this->started = true;
    }

    /**
     * @return array{expires?: int, lifetime?: int, path: string, secure: bool, httponly: bool, samesite: string}
     */
    private function cookieOptions(int $expires): array
    {
        $options = [
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ];

        return $expires === 0 ? ['lifetime' => 0] + $options : ['expires' => $expires] + $options;
    }
}
