<?php

declare(strict_types=1);

namespace Belprise\Controller;

use Belprise\Database\Database;
use Belprise\Http\HttpException;
use Belprise\Http\Request;
use Belprise\Http\Response;
use Belprise\Install\Environment;
use Belprise\Media\ServerEncoder;
use Belprise\Mail\Message;
use Belprise\Quota;
use Belprise\Security\AccountReset;
use Belprise\Security\AdminAuth;
use Belprise\Services;
use Belprise\Support\Dates;
use Belprise\Support\Input;
use Belprise\Update\UpdateCheck;
use DateTimeImmutable;

/**
 * The operator of the installation (CAHIER_DES_CHARGES §5.11): organizations,
 * their quota, their storage. Never their content: no route here reads or
 * changes clients, projects, videos, revisions or links.
 */
final class SuperAdminController
{
    public const ACTOR = 'Exploitant belprise';

    public function __construct(
        private readonly Services $services,
        private readonly string $clientKey,
    ) {
    }

    public function shell(string $nonce): Response
    {
        $response = Response::html($this->services->shell()->render(
            AdminController::ENTRY,
            'Belprise — Exploitation',
            ['page' => 'superadmin', 'session' => $this->state()],
            $nonce,
        ));
        $response->setHeader('Cache-Control', 'no-store');

        return $response;
    }

    public function session(): Response
    {
        return Response::json($this->state());
    }

    public function login(Request $request): Response
    {
        $input = new Input($request->json());
        $this->services->superAuth()->login($input->string('email', 'E-mail', 254), self::raw($request, 'password'), $this->clientKey);

        return Response::json($this->state());
    }

    public function loginCode(Request $request): Response
    {
        $this->services->superAuth()->verifyCode(self::raw($request, 'code'), $this->clientKey);
        $this->services->csrf()->rotate();

        return Response::json($this->state());
    }

    public function logout(): Response
    {
        $this->services->superAuth()->logout();

        return Response::json($this->state());
    }

    public function organizations(): Response
    {
        $rows = $this->services->db()->query(
            "SELECT o.id, o.name, o.quota_bytes, o.created_at, o.downgraded_from, o.purge_at, o.deletion_at, o.deletion_by,
                (SELECT COUNT(*) FROM users u WHERE u.organization_id = o.id) AS users,
                (SELECT COUNT(*) FROM invitations i WHERE i.organization_id = o.id AND i.accepted_at IS NULL) AS invitations,
                (SELECT MAX(u.last_login_at) FROM users u WHERE u.organization_id = o.id) AS last_login_at
             FROM organizations o ORDER BY o.name COLLATE NOCASE, o.id"
        )->fetchAll();
        $quota = $this->services->quota();
        $users = $this->services->users();

        return Response::json([
            'organizations' => array_map(static fn (array $o) => [
                'id' => (int) $o['id'],
                'name' => $o['name'],
                'quota' => $o['quota_bytes'] === null ? null : (int) $o['quota_bytes'],
                'users' => (int) $o['users'],
                'pendingInvitations' => (int) $o['invitations'],
                'createdAt' => Dates::toIso((string) $o['created_at']),
                'lastLoginAt' => $o['last_login_at'] === null ? null : Dates::toIso((string) $o['last_login_at']),
                'storage' => $quota->usage((int) $o['id']),
                'purgeAt' => $o['purge_at'] === null ? null : Dates::toIso((string) $o['purge_at']),
                'downgradedFrom' => $o['downgraded_from'],
                'deletionAt' => $o['deletion_at'] === null ? null : Dates::toIso((string) $o['deletion_at']),
                'deletionBy' => $o['deletion_by'],
                // Administrators (contact and resets); never the content.
                'admins' => array_values(array_map(static fn (array $u) => [
                    'id' => (int) $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'totp' => $u['totp_secret'] !== null,
                ], array_filter($users->forOrganization((int) $o['id']), static fn (array $u) => $u['role'] === 'admin'))),
            ], $rows),
            'disk' => $this->services->storage()->disk(),
            'encoding' => $this->encodingState(),
            // Installed version and, if the check is on, the latest one (D8).
            'version' => (new UpdateCheck($this->services->settings()))->state(UpdateCheck::installed($this->services->rootDir()))
                + ['checking' => $this->services->config->updateCheck],
            'log' => array_map(static fn (array $row) => [
                'organization' => $row['organization_name'],
                'actor' => $row['actor'],
                'action' => $row['action'],
                'details' => $row['details'],
                'at' => Dates::toIso((string) $row['created_at']),
            ], $this->services->platformLog()->latest(30)),
        ]);
    }

    /** New organization: its administrator is invited by e-mail. */
    public function create(Request $request): Response
    {
        $input = new Input($request->json());
        $name = AdminAuth::validateOrganizationName($input->string('name', 'Nom', 120));
        $email = AdminAuth::validateEmail($input->string('email', 'E-mail', 254));
        $quota = self::quota($input);

        $services = $this->services;
        $now = time();
        [$id, $token] = Database::transaction($services->db(), static function () use ($services, $name, $email, $quota, $now): array {
            if ($services->users()->findByEmail($email) !== null) {
                throw new HttpException(422, 'Cette adresse a déjà un compte belprise.', ['field' => 'email']);
            }
            $id = $services->organizations()->create($name, $quota);
            $invitation = $services->invitations()->create($id, $email, 'admin', null, $now);
            $details = 'Quota ' . Quota::label($quota) . ', administrateur invité : ' . $email;
            $services->auditLog()->add($id, self::ACTOR, 'Organisation créée', $details);
            $services->platformLog()->add($id, $name, self::ACTOR, 'Organisation créée', $details);

            return [$id, $invitation['token']];
        });

        $link = $this->baseUrl($request) . '/invitation/' . $token;
        $sent = $services->mailer()->send(new Message(
            $email,
            'Votre espace belprise « ' . $name . ' » est prêt',
            "Bonjour,\n\n"
            . 'L’espace « ' . $name . ' » vient d’être ouvert pour vous sur belprise'
            . ($quota === null ? '' : ', avec ' . Quota::gigabytes($quota) . ' de stockage') . ".\n\n"
            . "Vous en êtes l’administrateur. Pour créer votre compte, ouvrez ce lien (valable 7 jours, une seule fois) :\n"
            . $link . "\n\n"
            . "--\nBelprise, propulsé par belprod — https://belprod.fr\n",
        ));

        return Response::json(['id' => $id, 'sent' => $sent, 'link' => $link], 201);
    }

    /**
     * Changes the quota. A quota the storage no longer fits starts the
     * downgrade (purge at J+30, §5.12); a quota it fits again cancels it.
     */
    public function setQuota(int $id, Request $request): Response
    {
        $this->services->organizations()->find($id) ?? throw HttpException::notFound();
        $quota = self::quota(new Input($request->json()));
        $services = $this->services;
        $result = $services->downgrade()->changeQuota($id, $quota, self::ACTOR, new DateTimeImmutable('now'));
        $organization = $services->organizations()->find($id) ?? [];

        return Response::json([
            'id' => $id,
            'quota' => $quota,
            'result' => $result,
            'purgeAt' => ($organization['purge_at'] ?? null) === null ? null : Dates::toIso((string) $organization['purge_at']),
            'storage' => $services->quota()->usage($id),
        ]);
    }

    /**
     * Where the playback versions are encoded (§5.7 bis): in the browser, or
     * on this server when FFmpeg is there; threads for the server.
     */
    public function setEncoding(Request $request): Response
    {
        $input = new Input($request->json());
        $settings = $this->services->settings();
        if ($input->has('mode')) {
            $mode = $input->string('mode', 'Encodage', 10);
            if (!in_array($mode, ServerEncoder::MODES, true)) {
                throw new HttpException(422, 'Encodage inconnu.', ['field' => 'mode']);
            }
            if ($mode === 'server' && Environment::ffmpeg($this->services->config->ffmpeg) === null) {
                throw new HttpException(422, 'FFmpeg est introuvable sur ce serveur : l’encodage ne peut se faire que dans le navigateur.', ['field' => 'mode']);
            }
            $settings->set(ServerEncoder::MODE_KEY, $mode);
            $this->services->platformLog()->add(null, '—', self::ACTOR, 'Encodage des versions de lecture', $mode === 'server' ? 'sur le serveur' : 'dans le navigateur');
        }
        if ($input->has('threads')) {
            $settings->set(ServerEncoder::THREADS_KEY, (string) $input->intRange('threads', 'Fils', 1, ServerEncoder::MAX_THREADS));
        }

        return Response::json($this->encodingState());
    }

    /**
     * @return array<string, mixed>
     */
    private function encodingState(): array
    {
        $settings = $this->services->settings();
        $ffmpeg = Environment::ffmpeg($this->services->config->ffmpeg);
        $speed = ServerEncoder::speed($settings);

        return [
            'mode' => ServerEncoder::mode($settings),
            'threads' => ServerEncoder::threads($settings),
            'ffmpeg' => $ffmpeg === null ? null : ['version' => $ffmpeg['version'], 'path' => $ffmpeg['path'], 'probe' => $ffmpeg['probe'] !== null],
            // Last measured speed: seconds of video per second of work, and minutes for an hour of video.
            'speed' => $speed === null ? null : array_merge($speed, ['minutesPerHour' => (int) ceil(60 / max(0.01, (float) $speed['ratio'])), 'at' => Dates::toIso((string) $speed['at'])]),
            'queue' => $this->services->encodeJobs()->counts(),
        ];
    }

    /** Deletion of the organization, executed only 30 days later (§5.10, §5.11): the name must be typed again. */
    public function requestDeletion(int $id, Request $request): Response
    {
        $organization = $this->services->organizations()->find($id) ?? throw HttpException::notFound();
        $typed = (new Input($request->json()))->text('confirm', 'Confirmation', 200);
        if (mb_strtolower(trim($typed)) !== mb_strtolower((string) $organization['name'])) {
            throw new HttpException(422, 'Le nom saisi ne correspond pas à l’organisation.', ['field' => 'confirm']);
        }
        $at = $this->services->organizationDeletion()->request($id, 'operator', self::ACTOR, new DateTimeImmutable('now'));

        return Response::json(['id' => $id, 'deletionAt' => Dates::toIso($at)]);
    }

    /** Reset of an organization's administrator (§5.10): the link goes to the administrator, never to the operator. */
    public function requestReset(int $organizationId, int $userId, Request $request): Response
    {
        $user = $this->services->users()->find($userId);
        if ($user === null || (int) $user['organization_id'] !== $organizationId || $user['role'] !== 'admin') {
            throw HttpException::notFound();
        }
        $kind = (new Input($request->json()))->string('kind', 'Réinitialisation', 10);
        $sent = (new AccountReset($this->services))->request($user, $kind, self::ACTOR, 'l’exploitant de belprise', $this->baseUrl($request));
        $organization = $this->services->organizations()->find($organizationId);
        $this->services->platformLog()->add($organizationId, (string) ($organization['name'] ?? ''), self::ACTOR, 'Réinitialisation demandée', ($kind === 'password' ? 'mot de passe' : 'double authentification') . ' de ' . $user['name']);

        return Response::json(['sent' => $sent, 'email' => $user['email']]);
    }

    public function cancelDeletion(int $id): Response
    {
        $this->services->organizations()->find($id) ?? throw HttpException::notFound();
        $this->services->organizationDeletion()->cancel($id, 'operator', self::ACTOR);

        return Response::json(['id' => $id, 'deletionAt' => null]);
    }

    /**
     * @return array<string, mixed>
     */
    private function state(): array
    {
        $auth = $this->services->superAuth();
        $admin = $auth->user();

        return [
            'authenticated' => $admin !== null,
            'name' => $admin['name'] ?? null,
            'codeRequired' => $admin === null && $auth->codePending(),
            'csrf' => $this->services->csrf()->token(),
        ];
    }

    private function baseUrl(Request $request): string
    {
        return $this->services->config->linkBase($request->header('host'));
    }

    /** "quotaGb": a number of gigabytes (1 Go = 10^9 bytes), or null for no limit. */
    private static function quota(Input $input): ?int
    {
        if (!$input->has('quotaGb') || $input->optionalInt('quotaGb') === null) {
            return null;
        }

        return $input->intRange('quotaGb', 'Quota', 1, 1_000_000) * 1_000_000_000;
    }

    private static function raw(Request $request, string $key): string
    {
        $value = $request->json()[$key] ?? '';
        if (!is_string($value)) {
            throw new HttpException(400, 'Requête invalide.');
        }

        return $value;
    }
}
