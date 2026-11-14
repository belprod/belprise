<?php

declare(strict_types=1);

namespace Belprise\Controller\Admin;

use Belprise\Controller\AdminController;
use Belprise\Database\Database;
use Belprise\Http\HttpException;
use Belprise\Http\Request;
use Belprise\Http\Response;
use Belprise\Mail\Message;
use Belprise\Repository\Users;
use Belprise\Security\AccountReset;
use Belprise\Security\AdminAuth;
use Belprise\Security\Roles;
use Belprise\Services;
use Belprise\Support\Dates;
use Belprise\Support\Input;
use Belprise\View\AdminTheme;

/**
 * The organization's team (CAHIER_DES_CHARGES §2.2, §5.7): users, roles,
 * invitations by e-mail. Administrators only (see Roles), except the
 * acceptance of an invitation, open to the invited person.
 *
 * One administrator per organization (3.0): the team is made of editors and
 * viewers; the administrator hands the role over with "transfer".
 */
final class TeamController
{
    public function __construct(
        private readonly Services $services,
        private readonly string $clientKey = '',
    )
    {
    }

    public function list(): Response
    {
        $org = $this->organizationId();
        $now = time();
        $me = (int) ($this->services->user()['id'] ?? 0);

        return Response::json([
            'users' => array_map(static fn (array $u) => [
                'id' => (int) $u['id'],
                'name' => $u['name'],
                'email' => $u['email'],
                'role' => $u['role'],
                'totp' => $u['totp_secret'] !== null,
                'lastLoginAt' => $u['last_login_at'] === null ? null : Dates::toIso((string) $u['last_login_at']),
                'me' => (int) $u['id'] === $me,
            ], $this->services->users()->forOrganization($org)),
            'invitations' => array_map(static fn (array $i) => [
                'id' => (int) $i['id'],
                'email' => $i['email'],
                'role' => $i['role'],
                'expiresAt' => Dates::toIso((string) $i['expires_at']),
                'expired' => strtotime($i['expires_at'] . ' UTC') <= $now,
            ], $this->services->invitations()->pending($org)),
        ]);
    }

    public function invite(Request $request): Response
    {
        $org = $this->organizationId();
        $input = new Input($request->json());
        $email = AdminAuth::validateEmail($input->string('email', 'E-mail', 254));
        $role = self::teamRole($input->string('role', 'Rôle', 10));
        $services = $this->services;
        $now = time();

        $created = Database::transaction($services->db(), static function () use ($services, $org, $email, $role, $now): array {
            if ($services->users()->findByEmail($email) !== null) {
                throw new HttpException(422, 'Cette adresse ne peut pas être invitée : elle a déjà un compte Belprise.', ['field' => 'email']);
            }
            foreach ($services->invitations()->pending($org) as $pending) {
                if (mb_strtolower($pending['email']) === mb_strtolower($email)) {
                    throw new HttpException(422, 'Une invitation est déjà en attente pour cette adresse : renvoyez-la.', ['field' => 'email']);
                }
            }

            return $services->invitations()->create($org, $email, $role, (int) ($services->user()['id'] ?? 0) ?: null, $now);
        });

        return Response::json($this->sent($request, $email, $role, $created['token']), 201);
    }

    /** Sends again, with a new link (the previous one stops working) and a new 7-day delay. */
    public function resend(int $id, Request $request): Response
    {
        $invitation = $this->services->invitations()->find($id, $this->organizationId()) ?? throw HttpException::notFound();
        $token = $this->services->invitations()->renew($id, time());

        return Response::json($this->sent($request, (string) $invitation['email'], (string) $invitation['role'], $token));
    }

    public function cancel(int $id): Response
    {
        $this->services->invitations()->find($id, $this->organizationId()) ?? throw HttpException::notFound();
        $this->services->invitations()->delete($id);

        return Response::json(['deleted' => true]);
    }

    public function updateUser(int $id, Request $request): Response
    {
        $user = $this->member($id);
        $role = self::teamRole((new Input($request->json()))->string('role', 'Rôle', 10));
        if ($user['role'] === Roles::ADMIN) {
            throw new HttpException(422, 'Pour changer d’administrateur, utilisez « Transférer le rôle d’administrateur ».', ['field' => 'role']);
        }
        $this->services->users()->update((int) $user['id'], ['role' => $role]);

        return $this->list();
    }

    public function removeUser(int $id): Response
    {
        $user = $this->member($id);
        if ($user['role'] === Roles::ADMIN) {
            throw new HttpException(422, 'L’administrateur ne peut pas être retiré : transférez d’abord son rôle à un membre de l’équipe.');
        }
        $this->services->users()->delete((int) $user['id']);

        return $this->list();
    }

    /**
     * Hands the administrator role to a member of the team (§2.2), after the
     * password: the member becomes the administrator, the current
     * administrator an editor. One administrator at any time.
     */
    public function transfer(int $id, Request $request): Response
    {
        $target = $this->member($id);
        $me = $this->services->user() ?? throw new HttpException(401, 'Votre session a expiré. Reconnectez-vous.');
        if ((int) $target['id'] === (int) $me['id'] || $target['role'] === Roles::ADMIN) {
            throw new HttpException(422, 'Choisissez un autre membre de l’équipe.');
        }
        $password = $request->json()['password'] ?? '';
        $this->services->auth()->confirmPassword(is_string($password) ? $password : '', $this->clientKey);
        $services = $this->services;
        $org = $this->organizationId();
        Database::transaction($services->db(), static function () use ($services, $me, $target, $org): void {
            // In this order: the unique index allows one administrator per organization.
            $services->users()->update((int) $me['id'], ['role' => Roles::EDITOR]);
            $services->users()->update((int) $target['id'], ['role' => Roles::ADMIN]);
            $services->auditLog()->add($org, (string) $me['name'], 'Rôle d’administrateur transféré', 'à ' . $target['name'] . ' (' . $target['email'] . ')');
        });
        $organization = $services->organizations()->find($org);
        $services->mailer()->send(new Message(
            (string) $target['email'],
            'Vous êtes maintenant administrateur de ' . ($organization['name'] ?? '') . ' sur Belprise',
            "Bonjour,\n\n"
            . $me['name'] . ' vous a transféré le rôle d’administrateur de l’organisation « ' . ($organization['name'] ?? '') . " » sur Belprise.\n\n"
            . "Vous gérez désormais l’équipe, les réglages de l’organisation et les suppressions définitives.\n\n"
            . "--\nBelprise, propulsé par belprod — https://belprod.fr\n",
        ));

        return Response::json(['transferred' => true]);
    }

    /**
     * Name and back-office identity (§5.7): accent colour and dark or light
     * background. The accent must stand out from the background (3:1).
     */
    public function updateOrganization(Request $request): Response
    {
        $input = new Input($request->json());
        $organizations = $this->services->organizations();
        $id = $this->organizationId();
        if ($input->has('name')) {
            $organizations->rename($id, AdminAuth::validateOrganizationName($input->string('name', 'Nom', 120)));
        }
        if ($input->has('totpRequired')) {
            // Asks every member for two-factor authentication at the next request (§5.7).
            $organizations->setTotpRequired($id, $input->bool('totpRequired', 'Double authentification obligatoire'));
        }
        if ($input->has('accentColor') || $input->has('theme')) {
            $current = $organizations->find($id) ?? throw HttpException::notFound();
            $accent = $input->has('accentColor') ? strtolower($input->color('accentColor', 'Accent')) : (string) $current['accent_color'];
            $theme = $input->has('theme') ? $input->string('theme', 'Fond', 10) : (string) $current['theme'];
            if (!in_array($theme, AdminTheme::THEMES, true)) {
                throw new HttpException(422, 'Fond inconnu : clair ou sombre.', ['field' => 'theme']);
            }
            $candidate = new AdminTheme($accent, $theme);
            if ($candidate->accentContrast() < AdminTheme::MIN_ACCENT_CONTRAST) {
                throw new HttpException(422, sprintf(
                    'Accent trop proche du fond %s (%s:1) : il faut au moins 3:1 pour voir les boutons, le focus et les sélections.',
                    $theme === 'light' ? 'clair' : 'sombre',
                    number_format(floor($candidate->accentContrast() * 10) / 10, 1, ',', ''),
                ), ['field' => 'accentColor']);
            }
            $organizations->setBrand($id, $accent, $theme);
        }

        return Response::json($this->organizationState());
    }

    /** Organization logo, at the top of the menu (same checks and SVG cleaning as client logos). */
    public function uploadLogo(Request $request): Response
    {
        $upload = $request->files['logo'] ?? throw new HttpException(422, 'Choisissez un fichier.', ['field' => 'logo']);
        $id = $this->organizationId();
        $previous = $this->services->organizations()->find($id)['logo'] ?? null;
        $this->services->organizations()->setLogo($id, $this->services->logos()->store('o' . $id, $upload));
        $this->services->storage()->deleteFile($previous === null ? null : (string) $previous);

        return Response::json($this->organizationState());
    }

    public function deleteLogo(): Response
    {
        $id = $this->organizationId();
        $previous = $this->services->organizations()->find($id)['logo'] ?? null;
        $this->services->organizations()->setLogo($id, null);
        $this->services->storage()->deleteFile($previous === null ? null : (string) $previous);

        return Response::json($this->organizationState());
    }

    /**
     * @return array{organization: array<string, mixed>, theme: array<string, string>}
     */
    private function organizationState(): array
    {
        $organization = $this->services->organizations()->find($this->organizationId()) ?? throw HttpException::notFound();

        return [
            'organization' => AdminController::presentOrganization($organization, $this->services->signer()),
            'theme' => AdminController::theme($organization)->variables(),
        ];
    }

    /** What the platform operator did to the organization (§5.11). */
    public function log(): Response
    {
        return Response::json(array_map(static fn (array $row) => [
            'actor' => $row['actor'],
            'action' => $row['action'],
            'details' => $row['details'],
            'at' => Dates::toIso((string) $row['created_at']),
        ], $this->services->auditLog()->forOrganization($this->organizationId())));
    }

    /**
     * "Supprimer l'organisation" (§5.10): name typed again and password; the
     * deletion itself happens 30 days later.
     */
    public function requestDeletion(Request $request): Response
    {
        $input = new Input($request->json());
        $organization = $this->services->organizations()->find($this->organizationId()) ?? throw HttpException::notFound();
        if (mb_strtolower(trim($input->text('confirm', 'Confirmation', 200))) !== mb_strtolower((string) $organization['name'])) {
            throw new HttpException(422, 'Le nom saisi ne correspond pas à l’organisation.', ['field' => 'confirm']);
        }
        $password = $request->json()['password'] ?? '';
        $this->services->auth()->confirmPassword(is_string($password) ? $password : '', $this->clientKey);
        $user = $this->services->user() ?? throw new HttpException(401, 'Votre session a expiré. Reconnectez-vous.');
        $at = $this->services->organizationDeletion()->request($this->organizationId(), 'admin', (string) $user['name'], new \DateTimeImmutable('now'));

        return Response::json(['deletionAt' => Dates::toIso($at)]);
    }

    /** Only a deletion an administrator asked for; the operator's own request stays (§5.10). */
    public function cancelDeletion(): Response
    {
        $user = $this->services->user() ?? throw new HttpException(401, 'Votre session a expiré. Reconnectez-vous.');
        $this->services->organizationDeletion()->cancel($this->organizationId(), 'admin', (string) $user['name']);

        return Response::json(['deletionAt' => null]);
    }

    /** "Réinitialiser le mot de passe / la double authentification" of a member (§5.10). */
    public function requestReset(int $id, Request $request): Response
    {
        $user = $this->member($id);
        if ((int) $user['id'] === (int) ($this->services->user()['id'] ?? 0)) {
            throw new HttpException(422, 'Pour votre propre compte, passez par « Mon compte ».');
        }
        $kind = (new Input($request->json()))->string('kind', 'Réinitialisation', 10);
        $me = (string) ($this->services->user()['name'] ?? '');
        $sent = (new AccountReset($this->services))->request($user, $kind, $me, 'l’administrateur de votre organisation (' . $me . ')', $this->baseUrl($request));

        return Response::json(['sent' => $sent, 'email' => $user['email']]);
    }

    // ---- Invited person (no session) -------------------------------------------------------

    /** What the invitation page shows; the neutral 404 when the link is not (or no longer) valid. */
    public function showInvitation(string $token): Response
    {
        $invitation = $this->usable($token);

        return Response::json([
            'organization' => $invitation['organization_name'],
            'email' => $invitation['email'],
            'role' => $invitation['role'],
        ]);
    }

    /** Creates the account, uses up the invitation, opens the session. */
    public function acceptInvitation(string $token, Request $request): Response
    {
        $invitation = $this->usable($token);
        $data = $request->json();
        $name = AdminAuth::validateName(self::string($data, 'name'));
        AdminAuth::validatePassword(self::string($data, 'password'), self::string($data, 'confirmation'));
        $hash = password_hash(self::string($data, 'password'), PASSWORD_ARGON2ID);
        $services = $this->services;

        $id = Database::transaction($services->db(), static function () use ($services, $invitation, $name, $hash): int {
            $org = (int) $invitation['organization_id'];
            if ($services->users()->findByEmail((string) $invitation['email']) !== null) {
                throw new HttpException(409, 'Cette adresse a déjà un compte Belprise : connectez-vous.');
            }
            if ($invitation['role'] === Roles::ADMIN && $services->users()->count($org, Roles::ADMIN) > 0) {
                throw new HttpException(409, 'Cette organisation a déjà un administrateur.');
            }
            if (!$services->invitations()->accept((int) $invitation['id'], time())) {
                throw HttpException::notFound();
            }

            return $services->users()->create($org, (string) $invitation['email'], $name, $hash, (string) $invitation['role']);
        });
        $services->auth()->openSession((array) $services->users()->find($id));
        $services->csrf()->rotate();

        return Response::json(['created' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function usable(string $token): array
    {
        return $this->services->invitations()->findUsable($token, time()) ?? throw HttpException::notFound();
    }

    /**
     * Sends the invitation e-mail. The link is also returned once to the
     * administrator, who can pass it on if the e-mail does not arrive.
     *
     * @return array<string, mixed>
     */
    private function sent(Request $request, string $email, string $role, string $token): array
    {
        $organization = $this->services->organizations()->find($this->organizationId());
        $inviter = (string) ($this->services->user()['name'] ?? '');
        $link = $this->baseUrl($request) . '/invitation/' . $token;
        $orgName = (string) ($organization['name'] ?? '');
        $sent = $this->services->mailer()->send(new Message(
            $email,
            $inviter . ' vous invite à rejoindre ' . $orgName . ' sur Belprise',
            "Bonjour,\n\n"
            . $inviter . ' vous invite à rejoindre l’organisation « ' . $orgName . ' » sur Belprise, en tant que '
            . mb_strtolower(Roles::LABELS[$role]) . ".\n\n"
            . "Pour créer votre compte, ouvrez ce lien (valable 7 jours, une seule fois) :\n"
            . $link . "\n\n"
            . "Si vous n’attendiez pas cette invitation, ignorez simplement ce message.\n\n"
            . "--\nBelprise, propulsé par belprod — https://belprod.fr\n",
        ));

        return ['sent' => $sent, 'link' => $link, 'email' => $email];
    }

    private function baseUrl(Request $request): string
    {
        if ($this->services->config->appUrl !== null) {
            return $this->services->config->appUrl;
        }
        // Development only (app_url not set): the host of the administrator's own request.
        $host = (string) $request->header('host');

        return 'http://' . (preg_match('/^[A-Za-z0-9.-]+(:[0-9]{1,5})?$/D', $host) === 1 ? $host : 'localhost');
    }

    /**
     * @return array<string, mixed>
     */
    private function member(int $id): array
    {
        $user = $this->services->users()->find($id);
        if ($user === null || (int) $user['organization_id'] !== $this->organizationId()) {
            throw HttpException::notFound();
        }

        return $user;
    }

    private function organizationId(): int
    {
        return $this->services->organizationId() ?? throw HttpException::notFound();
    }

    /** Role of a team member: editor or viewer (the administrator is the only one, §2.2). */
    private static function teamRole(string $role): string
    {
        if ($role === Roles::ADMIN) {
            throw new HttpException(422, 'Une organisation n’a qu’un administrateur : invitez un éditeur ou un lecteur, puis transférez-lui le rôle si besoin.', ['field' => 'role']);
        }
        if (!in_array($role, Users::ROLES, true)) {
            throw new HttpException(422, 'Rôle invalide.', ['field' => 'role']);
        }

        return $role;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? '';
        if (!is_string($value)) {
            throw new HttpException(400, 'Requête invalide.');
        }

        return $value;
    }
}
