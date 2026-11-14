<?php

declare(strict_types=1);

namespace Belprise\Security;

use Belprise\Database\Database;
use Belprise\Http\HttpException;
use Belprise\Mail\Message;
use Belprise\Repository\AccountResets;
use Belprise\Repository\AuditLog;
use Belprise\Services;

/**
 * Password or two-factor reset (CAHIER_DES_CHARGES §5.10). Asked by the
 * organization's administrator, or by the operator for an administrator;
 * the link (1 hour, single use) is e-mailed to the person concerned, so
 * whoever asks never gets into the account.
 */
final class AccountReset
{
    public const SCOPE = 'account_reset';
    /** "Mot de passe oublié ?": requests per hour, per network and per address (silently). */
    public const FORGOT_NETWORK_SCOPE = 'forgot_network';
    public const FORGOT_ADDRESS_SCOPE = 'forgot_address';
    public const FORGOT_PER_NETWORK = 10;
    public const FORGOT_PER_ADDRESS = 3;

    public function __construct(private readonly Services $services)
    {
    }

    /**
     * @param array<string, mixed> $user row of users
     * @param string $requestedBy name for the journal ("Jules", "Exploitant Belprise")
     * @param string $who how the e-mail names the requester
     * @return bool whether the e-mail went out
     */
    public function request(array $user, string $kind, string $requestedBy, string $who, string $baseUrl): bool
    {
        if (!in_array($kind, AccountResets::KINDS, true)) {
            throw new HttpException(422, 'Réinitialisation inconnue.', ['field' => 'kind']);
        }
        if ($kind === 'totp' && $user['totp_secret'] === null) {
            throw new HttpException(422, 'La double authentification n’est pas activée pour ce compte.', ['field' => 'kind']);
        }
        $token = $this->services->accountResets()->create((int) $user['id'], $kind, $requestedBy, time());
        (new AuditLog($this->services->db()))->add(
            (int) $user['organization_id'],
            $requestedBy,
            $kind === 'password' ? 'Réinitialisation du mot de passe demandée' : 'Réinitialisation de la double authentification demandée',
            (string) $user['name'] . ' (lien envoyé à son adresse)',
        );
        $link = $baseUrl . '/reinitialisation/' . $token;
        $what = $kind === 'password' ? 'du mot de passe' : 'de la double authentification';

        return $this->services->mailer()->send(new Message(
            (string) $user['email'],
            $kind === 'password' ? 'Belprise : choisissez un nouveau mot de passe' : 'Belprise : réinitialisez votre double authentification',
            'Bonjour ' . $user['name'] . ",\n\n"
            . ucfirst($who) . ' a demandé la réinitialisation ' . $what . ' de votre compte Belprise (' . $user['email'] . ").\n\n"
            . ($kind === 'password'
                ? "Pour choisir un nouveau mot de passe, ouvrez ce lien (valable 1 heure, une seule fois) :\n"
                : "Ouvrez ce lien (valable 1 heure, une seule fois) et saisissez votre mot de passe : la double authentification sera désactivée. Vous pourrez la réactiver avec votre nouveau téléphone dans « Mon compte ».\n")
            . $link . "\n\n"
            . "Si vous n’êtes pas au courant, ignorez ce message : rien ne change sans ce lien.\n\n"
            . "--\nBelprise, propulsé par belprod — https://belprod.fr\n",
        ));
    }

    /**
     * "Mot de passe oublié ?" (§5.10, 3.0): a password link to the account
     * of this address, if there is one. The caller always answers the same
     * thing; repeated requests are slowed down per network (429, which says
     * nothing about the address) and per address (no e-mail, same answer).
     * Never the two-factor authentication: a recovery code, the
     * administrator or the operator handles a lost phone.
     */
    public function forgot(string $email, string $networkKey, string $baseUrl): void
    {
        $limiter = $this->services->rateLimiter();
        $wait = $limiter->hit(self::FORGOT_NETWORK_SCOPE, $networkKey, self::FORGOT_PER_NETWORK, 3600);
        if ($wait > 0) {
            throw new HttpException(429, 'Trop de demandes. Nouvel essai possible dans ' . ($wait < 60 ? $wait . ' s' : (int) ceil($wait / 60) . ' min') . '.', ['retryAfter' => $wait]);
        }
        $email = AdminAuth::validateEmail($email);
        if ($limiter->hit(self::FORGOT_ADDRESS_SCOPE, $this->services->clientKey('forgot|' . mb_strtolower($email)), self::FORGOT_PER_ADDRESS, 3600) > 0) {
            return;
        }
        $user = $this->services->users()->findByEmail($email);
        if ($user === null) {
            return;
        }
        $this->request($user, 'password', (string) $user['name'], 'quelqu’un (sans doute vous-même, depuis l’écran de connexion)', $baseUrl);
    }

    /**
     * What the reset page shows; the neutral 404 for an unknown, used or expired link.
     *
     * @return array<string, mixed>
     */
    public function show(string $token): array
    {
        $reset = $this->usable($token);
        $organization = $this->services->organizations()->find((int) $reset['organization_id']);

        return [
            'kind' => $reset['kind'],
            'name' => $reset['name'],
            'email' => $reset['email'],
            'organization' => $organization['name'] ?? '',
        ];
    }

    /**
     * @param array<string, mixed> $data password + confirmation (password), or password (two-factor)
     */
    public function complete(string $token, array $data, string $clientKey): void
    {
        $reset = $this->usable($token);
        $password = is_string($data['password'] ?? null) ? $data['password'] : '';
        $users = $this->services->users();

        if ($reset['kind'] === 'password') {
            $confirmation = is_string($data['confirmation'] ?? null) ? $data['confirmation'] : '';
            AdminAuth::validatePassword($password, $confirmation);
            $fields = ['password_hash' => password_hash($password, PASSWORD_ARGON2ID)];
            $action = 'Mot de passe réinitialisé';
        } else {
            // The current password proves it is the account's owner (throttled like the login).
            $limiter = $this->services->rateLimiter();
            $wait = $limiter->attempt(self::SCOPE, $clientKey);
            if ($wait > 0) {
                throw new HttpException(429, 'Trop de tentatives. Nouvel essai possible dans ' . $wait . ' s.', ['retryAfter' => $wait]);
            }
            if (!password_verify($password, (string) $reset['password_hash'])) {
                throw new HttpException(422, 'Mot de passe incorrect.', ['field' => 'password', 'retryAfter' => $limiter->failed(self::SCOPE, $clientKey)]);
            }
            $limiter->clear(self::SCOPE, $clientKey);
            $fields = ['totp_secret' => null, 'totp_last_step' => null];
            $action = 'Double authentification réinitialisée';
            $this->services->recoveryCodes()->delete((int) $reset['user_id']);
        }

        $services = $this->services;
        Database::transaction($services->db(), static function () use ($services, $users, $reset, $fields, $action): void {
            if (!$services->accountResets()->consume((int) $reset['id'], time())) {
                throw HttpException::notFound();
            }
            // New session epoch: every session opened before ends.
            $users->update((int) $reset['user_id'], $fields + ['session_epoch' => bin2hex(random_bytes(8))]);
            (new AuditLog($services->db()))->add((int) $reset['organization_id'], (string) $reset['name'], $action, 'par le lien reçu par e-mail');
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function usable(string $token): array
    {
        return $this->services->accountResets()->findUsable($token, time()) ?? throw HttpException::notFound();
    }
}
