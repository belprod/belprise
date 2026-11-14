<?php

declare(strict_types=1);

namespace Belprise\Security;

use Belprise\Database\Database;
use Belprise\Http\HttpException;
use Belprise\Http\SessionStore;
use Belprise\Repository\Organizations;
use Belprise\Repository\RecoveryCodes;
use Belprise\Repository\Users;
use Closure;
use PDO;

/**
 * Users of the organizations (CAHIER_DES_CHARGES §2): e-mail + Argon2id
 * password, optional TOTP, session with idle timeout, throttled attempts.
 */
final class AdminAuth
{
    public const MIN_PASSWORD_LENGTH = 12;
    public const IDLE_TIMEOUT = 43200; // 12 h
    public const THROTTLE_SCOPE = 'admin_login';

    public const PASSWORD_SCOPE = 'admin_password';
    /** Seconds allowed between the password and the authenticator code. */
    public const TOTP_PENDING_TTL = 300;
    /** Wrong codes accepted before the password must be entered again. */
    public const TOTP_MAX_FAILURES = 5;

    private const SESSION_KEY = 'admin';
    private const SESSION_PENDING = 'admin_pending';
    private const SESSION_TOTP_SETUP = 'totp_setup';

    /** @var Closure(): int */
    private readonly Closure $now;

    /** @var array<string, mixed>|null|false false: not looked up yet */
    private array|null|false $current = false;

    private static ?string $dummyHash = null;

    /** @param (Closure(): int)|null $now */
    public function __construct(
        private readonly Users $users,
        private readonly Organizations $organizations,
        private readonly PDO $db,
        private readonly SessionStore $session,
        private readonly RateLimiter $limiter,
        ?Closure $now = null,
        private readonly ?SecretBox $box = null,
    ) {
        $this->now = $now ?? static fn (): int => time();
    }

    /**
     * First launch only: no account, no organization and no operator yet.
     * Once the operator account exists (created on the server), organizations
     * are opened from /superadmin: the web setup never reopens.
     */
    public function isSetupRequired(): bool
    {
        return !$this->users->exists()
            && $this->db->query('SELECT EXISTS (SELECT 1 FROM organizations) OR EXISTS (SELECT 1 FROM superadmins)')->fetchColumn() == 0;
    }

    /**
     * First launch: creates the first organization and its administrator,
     * then opens the session.
     */
    public function setup(string $organization, string $name, string $email, string $password, string $confirmation): void
    {
        $organization = self::validateOrganizationName($organization);
        $name = self::validateName($name);
        $email = self::validateEmail($email);
        self::validatePassword($password, $confirmation);
        $hash = password_hash($password, PASSWORD_ARGON2ID);

        $users = $this->users;
        $organizations = $this->organizations;
        $id = Database::transaction($this->db, function () use ($users, $organizations, $organization, $name, $email, $hash): int {
            if (!$this->isSetupRequired()) {
                throw new HttpException(409, 'Le compte administrateur existe déjà.');
            }

            return $users->create($organizations->create($organization), $email, $name, $hash, 'admin');
        });
        $this->openSession((array) $this->users->find($id));
    }

    /**
     * @param string $clientKey salted fingerprint of the client (never a clear IP)
     * @return bool true when logged in; false when the authenticator code is now required
     */
    public function login(string $email, string $password, string $clientKey): bool
    {
        $wait = $this->limiter->attempt(self::THROTTLE_SCOPE, $clientKey);
        if ($wait > 0) {
            throw self::throttled($wait);
        }
        if ($this->isSetupRequired()) {
            throw new HttpException(409, 'Aucun compte : créez-le d’abord.');
        }

        $user = trim($email) === '' ? null : $this->users->findByEmail($email);
        // Always verify a hash, so an unknown e-mail takes as long as a wrong password.
        $passwordOk = password_verify($password, $user['password_hash'] ?? self::dummyHash());
        if ($user === null || !$passwordOk) {
            $delay = $this->limiter->failed(self::THROTTLE_SCOPE, $clientKey);
            $message = 'E-mail ou mot de passe incorrect.';
            if ($delay > 1) {
                $message .= ' Nouvel essai possible dans ' . self::formatDelay($delay) . '.';
            }
            throw new HttpException(401, $message, ['retryAfter' => $delay]);
        }

        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_ARGON2ID)) {
            $this->users->update((int) $user['id'], ['password_hash' => password_hash($password, PASSWORD_ARGON2ID)]);
        }
        $this->limiter->clear(self::THROTTLE_SCOPE, $clientKey);
        if ($user['totp_secret'] !== null) {
            // Second step: the code from the authenticator app (§5.7).
            $this->session->regenerate();
            $this->session->set(self::SESSION_PENDING, ['uid' => (int) $user['id'], 'at' => ($this->now)(), 'failures' => 0]);

            return false;
        }
        $this->openSession($user);

        return true;
    }

    public function totpEnabled(): bool
    {
        $user = $this->user();

        return $user !== null && $user['totp_secret'] !== null;
    }

    /** Password given, authenticator code awaited. */
    public function totpPending(): bool
    {
        $pending = $this->session->get(self::SESSION_PENDING);

        return is_array($pending) && ($this->now)() - (int) ($pending['at'] ?? 0) <= self::TOTP_PENDING_TTL;
    }

    public function verifyLoginCode(string $code, string $clientKey): void
    {
        $pending = $this->session->get(self::SESSION_PENDING);
        $user = $this->totpPending() && is_array($pending) ? $this->users->find((int) ($pending['uid'] ?? 0)) : null;
        if ($user === null || $user['totp_secret'] === null) {
            $this->session->remove(self::SESSION_PENDING);
            throw new HttpException(401, 'Délai dépassé : saisissez à nouveau votre mot de passe.', ['restart' => true]);
        }
        $wait = $this->limiter->attempt(self::THROTTLE_SCOPE, $clientKey);
        if ($wait > 0) {
            throw self::throttled($wait);
        }
        if (RecoveryCodes::looksLikeCode($code)) {
            // A recovery code (phone lost): single use, consumed atomically.
            $valid = $this->recoveryCodes()->consume((int) $user['id'], $code);
        } else {
            $lastStep = $user['totp_last_step'] === null ? null : (int) $user['totp_last_step'];
            $step = Totp::verify($this->open((string) $user['totp_secret']), $code, ($this->now)(), $lastStep);
            // The step is claimed atomically: one code opens one session, even in parallel.
            $valid = $step !== null && $this->users->claimTotpStep((int) $user['id'], $step);
        }
        if (!$valid) {
            $failures = (int) ($pending['failures'] ?? 0) + 1;
            if ($failures >= self::TOTP_MAX_FAILURES) {
                $this->session->remove(self::SESSION_PENDING);
                throw new HttpException(401, 'Trop de codes incorrects : saisissez à nouveau votre mot de passe.', ['restart' => true]);
            }
            $this->session->set(self::SESSION_PENDING, ['failures' => $failures] + $pending);
            throw new HttpException(401, 'Code incorrect.', ['retryAfter' => $this->limiter->failed(self::THROTTLE_SCOPE, $clientKey)]);
        }
        $this->limiter->clear(self::THROTTLE_SCOPE, $clientKey);
        $this->session->remove(self::SESSION_PENDING);
        $this->openSession($user);
    }

    /**
     * Starts the TOTP set-up: a new secret, kept in the session until a
     * first code confirms that the app has it.
     *
     * @return array{secret: string, uri: string}
     */
    public function beginTotpSetup(): array
    {
        $user = $this->requireUser();
        $secret = Totp::generateSecret();
        $this->session->set(self::SESSION_TOTP_SETUP, $secret);

        return ['secret' => $secret, 'uri' => Totp::uri($secret, (string) $user['email'])];
    }

    /**
     * @return list<string> the recovery codes, shown once
     */
    public function enableTotp(string $code, string $password, string $clientKey): array
    {
        $user = $this->requireUser();
        $this->checkPassword($user, $password, $clientKey);
        $secret = $this->session->get(self::SESSION_TOTP_SETUP);
        if (!is_string($secret)) {
            throw new HttpException(409, 'Recommencez la mise en place.');
        }
        $step = Totp::verify($secret, $code, ($this->now)());
        if ($step === null) {
            throw new HttpException(422, 'Code incorrect : vérifiez l’heure du téléphone et réessayez.', ['field' => 'code']);
        }
        $this->users->update((int) $user['id'], ['totp_secret' => $this->box()->seal($secret), 'totp_last_step' => $step]);
        $this->session->remove(self::SESSION_TOTP_SETUP);
        $this->endOtherSessions($user);

        return $this->recoveryCodes()->replace((int) $user['id']);
    }

    /**
     * New recovery codes after the password; the previous ones stop working.
     *
     * @return list<string>
     */
    public function renewRecoveryCodes(string $password, string $clientKey): array
    {
        $user = $this->requireUser();
        $this->checkPassword($user, $password, $clientKey);
        if ($user['totp_secret'] === null) {
            throw new HttpException(409, 'Activez d’abord la double authentification.');
        }

        return $this->recoveryCodes()->replace((int) $user['id']);
    }

    /** Unused recovery codes of the signed-in user. */
    public function recoveryCodesLeft(): int
    {
        $user = $this->user();

        return $user === null || $user['totp_secret'] === null ? 0 : $this->recoveryCodes()->remaining((int) $user['id']);
    }

    /**
     * The organization asks for two-factor authentication and this user has
     * not turned it on yet: only the set-up stays open (§5.7).
     */
    public function totpSetupRequired(): bool
    {
        $user = $this->user();
        if ($user === null || $user['totp_secret'] !== null) {
            return false;
        }
        $organization = $this->organizations->find((int) $user['organization_id']);

        return (int) ($organization['totp_required'] ?? 0) === 1;
    }

    public function disableTotp(string $password, string $clientKey): void
    {
        $user = $this->requireUser();
        $this->checkPassword($user, $password, $clientKey);
        $organization = $this->organizations->find((int) $user['organization_id']);
        if ((int) ($organization['totp_required'] ?? 0) === 1) {
            throw new HttpException(422, 'Votre organisation exige la double authentification : elle ne peut pas être désactivée.');
        }
        $this->users->update((int) $user['id'], ['totp_secret' => null, 'totp_last_step' => null]);
        $this->recoveryCodes()->delete((int) $user['id']);
        $this->endOtherSessions($user);
    }

    public function changePassword(string $current, string $password, string $confirmation, string $clientKey): void
    {
        $user = $this->requireUser();
        $this->checkPassword($user, $current, $clientKey);
        self::validatePassword($password, $confirmation);
        $this->users->update((int) $user['id'], ['password_hash' => password_hash($password, PASSWORD_ARGON2ID)]);
        $this->endOtherSessions($user);
    }

    /** Password typed again before a serious action (e.g. deleting the organization). */
    public function confirmPassword(string $password, string $clientKey): void
    {
        $this->checkPassword($this->requireUser(), $password, $clientKey);
    }

    /** "Mon compte": name, and e-mail (the login) with the password. */
    public function updateProfile(string $name, string $email, string $password, string $clientKey): void
    {
        $user = $this->requireUser();
        $fields = ['name' => self::validateName($name)];
        $email = trim($email);
        if (mb_strtolower($email) !== mb_strtolower((string) $user['email'])) {
            $email = self::validateEmail($email);
            $this->checkPassword($user, $password, $clientKey);
            $other = $this->users->findByEmail($email);
            if ($other !== null && (int) $other['id'] !== (int) $user['id']) {
                throw new HttpException(422, 'Cette adresse est déjà utilisée par un autre compte.', ['field' => 'email']);
            }
            $fields['email'] = $email;
        }
        $this->users->update((int) $user['id'], $fields);
        $this->current = false;
    }

    /**
     * Signed-in user (row of "users"), or null. Refreshes the idle timer,
     * except for background requests (badge polling), so an open tab alone
     * does not keep the session alive.
     *
     * @return array<string, mixed>|null
     */
    public function user(bool $background = false): ?array
    {
        if ($this->current !== false) {
            return $this->current;
        }
        $data = $this->session->get(self::SESSION_KEY);
        if (!is_array($data) || !isset($data['uid'], $data['seen'])) {
            return $this->current = null;
        }
        $now = ($this->now)();
        $user = $this->users->find((int) $data['uid']);
        // Removed user, idle session, or credentials changed elsewhere.
        if ($user === null || $now - (int) $data['seen'] > self::IDLE_TIMEOUT
            || !hash_equals((string) $user['session_epoch'], (string) ($data['epoch'] ?? ''))) {
            $this->session->remove(self::SESSION_KEY);

            return $this->current = null;
        }
        if (!$background) {
            $this->session->set(self::SESSION_KEY, ['seen' => $now] + $data);
        }

        return $this->current = $user;
    }

    public function logout(): void
    {
        $this->session->destroy();
        $this->current = null;
    }

    /** Ends every session of a user (removed, role changed by an administrator). */
    public function revokeSessions(int $userId): void
    {
        $this->users->update($userId, ['session_epoch' => bin2hex(random_bytes(8))]);
    }

    /**
     * Opens a session for a user (first launch, accepted invitation, login).
     *
     * @param array<string, mixed> $user
     */
    public function openSession(array $user): void
    {
        $this->session->regenerate();
        $this->session->set(self::SESSION_KEY, [
            'uid' => (int) $user['id'],
            'seen' => ($this->now)(),
            'epoch' => (string) $user['session_epoch'],
        ]);
        $this->users->update((int) $user['id'], ['last_login_at' => gmdate('Y-m-d H:i:s', ($this->now)())]);
        $this->current = false;
    }

    /**
     * New epoch: the user's other sessions are refused; this one moves on.
     *
     * @param array<string, mixed> $user
     */
    private function endOtherSessions(array $user): void
    {
        $epoch = bin2hex(random_bytes(8));
        $this->users->update((int) $user['id'], ['session_epoch' => $epoch]);
        $data = $this->session->get(self::SESSION_KEY);
        $this->session->regenerate();
        if (is_array($data)) {
            $this->session->set(self::SESSION_KEY, ['epoch' => $epoch] + $data);
        }
        $this->current = false;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireUser(): array
    {
        return $this->user() ?? throw new HttpException(401, 'Votre session a expiré. Reconnectez-vous.');
    }

    /**
     * Re-authentication before a sensitive change (throttled like the login).
     *
     * @param array<string, mixed> $user
     */
    private function checkPassword(array $user, string $password, string $clientKey): void
    {
        $wait = $this->limiter->attempt(self::PASSWORD_SCOPE, $clientKey);
        if ($wait > 0) {
            throw self::throttled($wait);
        }
        if (!password_verify($password, (string) $user['password_hash'])) {
            $delay = $this->limiter->failed(self::PASSWORD_SCOPE, $clientKey);
            throw new HttpException(422, 'Mot de passe actuel incorrect.', ['field' => 'current', 'retryAfter' => $delay]);
        }
        $this->limiter->clear(self::PASSWORD_SCOPE, $clientKey);
    }

    private function recoveryCodes(): RecoveryCodes
    {
        return new RecoveryCodes($this->db);
    }

    private function open(string $sealed): string
    {
        return $this->box()->open($sealed)
            ?? throw new \RuntimeException('TOTP secret cannot be decrypted (configuration secret changed?).');
    }

    private function box(): SecretBox
    {
        return $this->box ?? throw new \LogicException('SecretBox missing.');
    }

    private static function dummyHash(): string
    {
        return self::$dummyHash ??= password_hash(bin2hex(random_bytes(8)), PASSWORD_ARGON2ID);
    }

    public static function validateEmail(string $email): string
    {
        $email = trim($email);
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new HttpException(422, 'Saisissez une adresse e-mail valide.', ['field' => 'email']);
        }

        return $email;
    }

    public static function validateName(string $name): string
    {
        $name = trim($name);
        $length = mb_strlen($name);
        if ($length < 2 || $length > 80 || preg_match('/[\x00-\x1F\x7F]/u', $name) === 1) {
            throw new HttpException(422, 'Le nom doit compter entre 2 et 80 caractères.', ['field' => 'name']);
        }

        return $name;
    }

    public static function validateOrganizationName(string $name): string
    {
        $name = trim($name);
        $length = mb_strlen($name);
        if ($length < 2 || $length > 120 || preg_match('/[\x00-\x1F\x7F]/u', $name) === 1) {
            throw new HttpException(422, 'Le nom de l’organisation doit compter entre 2 et 120 caractères.', ['field' => 'organization']);
        }

        return $name;
    }

    public static function validatePassword(string $password, string $confirmation): void
    {
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new HttpException(
                422,
                'Le mot de passe doit compter au moins ' . self::MIN_PASSWORD_LENGTH . ' caractères.',
                ['field' => 'password'],
            );
        }
        if (strlen($password) > 4096) {
            throw new HttpException(422, 'Le mot de passe est trop long.', ['field' => 'password']);
        }
        if (!hash_equals($password, $confirmation)) {
            throw new HttpException(422, 'Les deux mots de passe ne correspondent pas.', ['field' => 'confirmation']);
        }
    }

    private static function throttled(int $wait): HttpException
    {
        return new HttpException(
            429,
            'Trop de tentatives. Nouvel essai possible dans ' . self::formatDelay($wait) . '.',
            ['retryAfter' => $wait],
        );
    }

    private static function formatDelay(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ' s';
        }

        return (int) ceil($seconds / 60) . ' min';
    }
}
