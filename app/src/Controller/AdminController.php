<?php

declare(strict_types=1);

namespace Belprise\Controller;

use Belprise\Http\HttpException;
use Belprise\Http\Request;
use Belprise\Http\Response;
use Belprise\Quota;
use Belprise\Repository\Organizations;
use Belprise\Security\AdminAuth;
use Belprise\Security\Csrf;
use Belprise\Security\MediaSigner;
use Belprise\Support\Dates;
use Belprise\View\AdminTheme;
use Belprise\View\Shell;

final class AdminController
{
    public const ENTRY = 'src/admin/main.ts';

    /** Admin pages served by the single-page app (keep in sync with frontend/src/admin/sections.ts). */
    public const SECTIONS = ['revisions', 'clients', 'projects', 'videos', 'collections', 'team', 'settings'];

    /** Sections with a creation page (/new) and detail pages (/{id}). */
    public const RESOURCES = ['clients', 'projects', 'videos', 'collections'];

    public function __construct(
        private readonly AdminAuth $auth,
        private readonly Csrf $csrf,
        private readonly Shell $shell,
        private readonly string $clientKey,
        private readonly Organizations $organizations,
        private readonly Quota $quota,
        private readonly MediaSigner $signer,
        /** @var (\Closure(): string)|null where the playback versions are encoded (§5.7 bis) */
        private readonly ?\Closure $encodingMode = null,
    ) {
    }

    public static function shellPattern(): string
    {
        return '#^(?:/admin(?:/(?:' . implode('|', self::SECTIONS) . ')'
            . '|/(?:' . implode('|', self::RESOURCES) . ')/(?:new|[1-9][0-9]{0,9}))?'
            . '|/invitation/[A-Za-z0-9_-]{43}|/reinitialisation/[A-Za-z0-9_-]{43})$#';
    }

    public function shell(Request $request, string $nonce): Response
    {
        $response = Response::html($this->shell->render(
            self::ENTRY,
            'Belprise — Administration',
            ['page' => 'admin', 'session' => $state = $this->sessionState()],
            $nonce,
            // The organization's colours, before the first paint (signed in only).
            $state['organization'] === null ? null : self::theme($this->organizations->find((int) $state['organization']['id']) ?? []),
        ));
        $response->setHeader('Cache-Control', 'no-store');

        return $response;
    }

    public function session(): Response
    {
        return Response::json($this->sessionState());
    }

    public function setup(Request $request): Response
    {
        $data = $request->json();
        $this->auth->setup(
            self::string($data, 'organization'),
            self::string($data, 'name'),
            self::string($data, 'email'),
            self::string($data, 'password'),
            self::string($data, 'confirmation'),
        );
        $this->csrf->rotate();

        return Response::json($this->sessionState());
    }

    public function login(Request $request): Response
    {
        $data = $request->json();
        if ($this->auth->login(self::string($data, 'email'), self::string($data, 'password'), $this->clientKey)) {
            $this->csrf->rotate();
        }

        return Response::json($this->sessionState());
    }

    /** Second login step when TOTP is enabled. */
    public function loginCode(Request $request): Response
    {
        $this->auth->verifyLoginCode(self::string($request->json(), 'code'), $this->clientKey);
        $this->csrf->rotate();

        return Response::json($this->sessionState());
    }

    public function changePassword(Request $request): Response
    {
        $data = $request->json();
        $this->auth->changePassword(
            self::string($data, 'current'),
            self::string($data, 'password'),
            self::string($data, 'confirmation'),
            $this->clientKey,
        );

        return Response::json($this->sessionState());
    }

    public function totpSetup(): Response
    {
        return Response::json($this->auth->beginTotpSetup());
    }

    /** Turns two-factor authentication on; the recovery codes are shown this once. */
    public function totpEnable(Request $request): Response
    {
        $data = $request->json();
        $codes = $this->auth->enableTotp(self::string($data, 'code'), self::string($data, 'password'), $this->clientKey);

        return Response::json($this->sessionState() + ['recoveryCodes' => $codes]);
    }

    /** New recovery codes (the previous ones stop working), after the password. */
    public function totpRecoveryCodes(Request $request): Response
    {
        $codes = $this->auth->renewRecoveryCodes(self::string($request->json(), 'password'), $this->clientKey);

        return Response::json($this->sessionState() + ['recoveryCodes' => $codes]);
    }

    public function totpDisable(Request $request): Response
    {
        $this->auth->disableTotp(self::string($request->json(), 'password'), $this->clientKey);

        return Response::json($this->sessionState());
    }

    /** "Mon compte": name, e-mail (the password is needed to change the e-mail). */
    public function updateAccount(Request $request): Response
    {
        $data = $request->json();
        $this->auth->updateProfile(
            self::string($data, 'name'),
            self::string($data, 'email'),
            self::string($data, 'password'),
            $this->clientKey,
        );

        return Response::json($this->sessionState());
    }

    public function logout(): Response
    {
        $this->auth->logout();

        return Response::json($this->sessionState());
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionState(): array
    {
        $user = $this->auth->user();
        $organization = $user === null ? null : $this->organizations->find((int) $user['organization_id']);

        return [
            'setupRequired' => $this->auth->isSetupRequired(),
            'authenticated' => $user !== null,
            'user' => $user === null ? null : [
                'id' => (int) $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role'],
            ],
            'organization' => $organization === null ? null : self::presentOrganization($organization, $this->signer),
            // CSS variables of the organization's identity, applied again by the front end after signing in.
            'theme' => $organization === null ? null : self::theme($organization)->variables(),
            // Plan and storage: the menu warns from 80 % and says when uploads are blocked.
            'storage' => $organization === null ? null : $this->quota->usage((int) $organization['id']),
            // Downgrade awaiting its purge, deletion awaiting its date: a banner reminds every user (§5.10, §5.12).
            'lifecycle' => $organization === null ? null : [
                'purgeAt' => $organization['purge_at'] === null ? null : Dates::toIso((string) $organization['purge_at']),
                'downgradedFrom' => $organization['downgraded_from'],
                'deletionAt' => $organization['deletion_at'] === null ? null : Dates::toIso((string) $organization['deletion_at']),
                'deletionBy' => $organization['deletion_by'],
                'deletionRequestedBy' => $organization['deletion_requested_by'],
            ],
            'totpRequired' => $user === null && $this->auth->totpPending(),
            'totpEnabled' => $user !== null && $user['totp_secret'] !== null,
            // "browser" or "server": after an upload, the browser encodes, or the server's queue does (§5.7 bis).
            'encoding' => $user === null || $this->encodingMode === null ? null : ($this->encodingMode)(),
            // The organization asks for two-factor authentication: set it up before anything else (§5.7).
            'totpSetupRequired' => $this->auth->totpSetupRequired(),
            'recoveryCodesLeft' => $this->auth->recoveryCodesLeft(),
            'csrf' => $this->csrf->token(),
        ];
    }

    /**
     * @param array<string, mixed> $organization
     */
    public static function theme(array $organization): AdminTheme
    {
        return new AdminTheme((string) ($organization['accent_color'] ?? AdminTheme::DEFAULT_ACCENT), (string) ($organization['theme'] ?? 'dark'));
    }

    /**
     * @param array<string, mixed> $organization
     * @return array{id: int, name: string, logoUrl: string|null, accentColor: string, theme: string, totpRequired: bool}
     */
    public static function presentOrganization(array $organization, MediaSigner $signer): array
    {
        return [
            'id' => (int) $organization['id'],
            'name' => (string) $organization['name'],
            'logoUrl' => $organization['logo'] === null ? null : $signer->url((string) $organization['logo']),
            'accentColor' => (string) $organization['accent_color'],
            'theme' => (string) $organization['theme'],
            'totpRequired' => (int) ($organization['totp_required'] ?? 0) === 1,
        ];
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
