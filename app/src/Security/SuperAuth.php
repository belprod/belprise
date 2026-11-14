<?php

declare(strict_types=1);

namespace Belprise\Security;

use Belprise\Http\HttpException;
use Belprise\Http\SessionStore;
use Belprise\Repository\Superadmins;
use Closure;

/**
 * Sign-in of the platform operator (CAHIER_DES_CHARGES §5.11): password,
 * then always the authenticator code; short idle timeout. Its session has
 * its own key: it opens nothing in the organizations' administration.
 */
final class SuperAuth
{
    public const THROTTLE_SCOPE = 'superadmin_login';
    public const IDLE_TIMEOUT = 7200; // 2 h
    public const PENDING_TTL = 300;
    public const MAX_CODE_FAILURES = 5;

    private const SESSION_KEY = 'superadmin';
    private const SESSION_PENDING = 'superadmin_pending';

    /** @var Closure(): int */
    private readonly Closure $now;

    /** @var array<string, mixed>|null|false */
    private array|null|false $current = false;

    private static ?string $dummyHash = null;

    /** @param (Closure(): int)|null $now */
    public function __construct(
        private readonly Superadmins $superadmins,
        private readonly SessionStore $session,
        private readonly RateLimiter $limiter,
        private readonly SecretBox $box,
        ?Closure $now = null,
    ) {
        $this->now = $now ?? static fn (): int => time();
    }

    /** First step: e-mail and password; the code is always asked next. */
    public function login(string $email, string $password, string $clientKey): void
    {
        $wait = $this->limiter->attempt(self::THROTTLE_SCOPE, $clientKey);
        if ($wait > 0) {
            throw new HttpException(429, 'Trop de tentatives. Nouvel essai possible dans ' . $wait . ' s.', ['retryAfter' => $wait]);
        }
        $admin = trim($email) === '' ? null : $this->superadmins->findByEmail($email);
        $ok = password_verify($password, $admin['password_hash'] ?? (self::$dummyHash ??= password_hash(bin2hex(random_bytes(8)), PASSWORD_ARGON2ID)));
        if ($admin === null || !$ok) {
            $delay = $this->limiter->failed(self::THROTTLE_SCOPE, $clientKey);
            throw new HttpException(401, 'E-mail ou mot de passe incorrect.', ['retryAfter' => $delay]);
        }
        $this->limiter->clear(self::THROTTLE_SCOPE, $clientKey);
        $this->session->regenerate();
        $this->session->set(self::SESSION_PENDING, ['id' => (int) $admin['id'], 'at' => ($this->now)(), 'failures' => 0]);
    }

    public function codePending(): bool
    {
        $pending = $this->session->get(self::SESSION_PENDING);

        return is_array($pending) && ($this->now)() - (int) ($pending['at'] ?? 0) <= self::PENDING_TTL;
    }

    public function verifyCode(string $code, string $clientKey): void
    {
        $pending = $this->session->get(self::SESSION_PENDING);
        $admin = $this->codePending() && is_array($pending) ? $this->superadmins->find((int) $pending['id']) : null;
        if ($admin === null) {
            $this->session->remove(self::SESSION_PENDING);
            throw new HttpException(401, 'Délai dépassé : saisissez à nouveau votre mot de passe.', ['restart' => true]);
        }
        $wait = $this->limiter->attempt(self::THROTTLE_SCOPE, $clientKey);
        if ($wait > 0) {
            throw new HttpException(429, 'Trop de tentatives. Nouvel essai possible dans ' . $wait . ' s.', ['retryAfter' => $wait]);
        }
        $secret = $this->box->open((string) $admin['totp_secret']) ?? throw new \RuntimeException('Superadmin TOTP secret cannot be decrypted.');
        $last = $admin['totp_last_step'] === null ? null : (int) $admin['totp_last_step'];
        $step = Totp::verify($secret, $code, ($this->now)(), $last);
        if ($step === null || !$this->superadmins->claimTotpStep((int) $admin['id'], $step)) {
            $failures = (int) ($pending['failures'] ?? 0) + 1;
            if ($failures >= self::MAX_CODE_FAILURES) {
                $this->session->remove(self::SESSION_PENDING);
                throw new HttpException(401, 'Trop de codes incorrects : saisissez à nouveau votre mot de passe.', ['restart' => true]);
            }
            $this->session->set(self::SESSION_PENDING, ['failures' => $failures] + $pending);
            throw new HttpException(401, 'Code incorrect.', ['retryAfter' => $this->limiter->failed(self::THROTTLE_SCOPE, $clientKey)]);
        }
        $this->limiter->clear(self::THROTTLE_SCOPE, $clientKey);
        $this->session->remove(self::SESSION_PENDING);
        $this->session->regenerate();
        $this->session->set(self::SESSION_KEY, ['id' => (int) $admin['id'], 'seen' => ($this->now)(), 'epoch' => (string) $admin['session_epoch']]);
        $this->superadmins->touch((int) $admin['id'], gmdate('Y-m-d H:i:s', ($this->now)()));
        $this->current = false;
    }

    /** @return array<string, mixed>|null */
    public function user(): ?array
    {
        if ($this->current !== false) {
            return $this->current;
        }
        $data = $this->session->get(self::SESSION_KEY);
        if (!is_array($data) || !isset($data['id'], $data['seen'])) {
            return $this->current = null;
        }
        $admin = $this->superadmins->find((int) $data['id']);
        $now = ($this->now)();
        if ($admin === null || $now - (int) $data['seen'] > self::IDLE_TIMEOUT
            || !hash_equals((string) $admin['session_epoch'], (string) ($data['epoch'] ?? ''))) {
            $this->session->remove(self::SESSION_KEY);

            return $this->current = null;
        }
        $this->session->set(self::SESSION_KEY, ['seen' => $now] + $data);

        return $this->current = $admin;
    }

    public function logout(): void
    {
        $this->session->remove(self::SESSION_KEY);
        $this->session->remove(self::SESSION_PENDING);
        $this->session->regenerate();
        $this->current = null;
    }
}
