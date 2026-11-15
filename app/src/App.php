<?php

declare(strict_types=1);

namespace Belprise;

use Belprise\Controller\Admin\ClientsController;
use Belprise\Controller\Admin\CollectionsController;
use Belprise\Controller\Admin\CommentsController;
use Belprise\Controller\Admin\DashboardController;
use Belprise\Controller\Admin\LinksController;
use Belprise\Controller\Admin\ProjectsController;
use Belprise\Controller\Admin\TeamController;
use Belprise\Controller\Admin\UploadsController;
use Belprise\Controller\Admin\VersionsController;
use Belprise\Controller\Admin\VideosController;
use Belprise\Controller\AdminController;
use Belprise\Controller\ClientReviewController;
use Belprise\Controller\MediaController;
use Belprise\Controller\PublicController;
use Belprise\Controller\SuperAdminController;
use Belprise\Demo\Demo;
use Belprise\Http\HttpException;
use Belprise\Http\NativeSession;
use Belprise\Http\Request;
use Belprise\Http\Response;
use Belprise\Install\Installer;
use Belprise\Security\AccountReset;
use Belprise\Http\Router;
use Belprise\Http\SessionStore;
use Belprise\Security\BotBlocker;
use Belprise\Security\Roles;
use Belprise\Security\Keys;
use Belprise\Security\SecurityHeaders;
use Belprise\View\ErrorPages;
use Belprise\View\Vite;

final class App
{
    public const BASE_CSS_ENTRY = 'src/styles/base.css';

    /** Admin API routes reachable without being logged in. */
    private const OPEN_ADMIN_API = ['/api/admin/session', '/api/admin/setup', '/api/admin/login', '/api/admin/login/totp', '/api/admin/logout'];

    /** What stays open while two-factor authentication must be set up. */
    private const TOTP_SETUP_API = ['/api/admin/session', '/api/admin/logout', '/api/admin/totp/setup', '/api/admin/totp/enable'];

    private const OPEN_SUPERADMIN_API = ['/api/superadmin/session', '/api/superadmin/login', '/api/superadmin/login/totp', '/api/superadmin/logout'];

    /** Invitation acceptance, reset links and "forgotten password": no session. */
    private const INVITATION_API = '#^/api/admin/(invitation/[A-Za-z0-9_-]{43}|reset/[A-Za-z0-9_-]{43}|forgot)$#';

    private readonly Services $services;

    public function __construct(
        private readonly Config $config,
        string $publicDir,
        string $migrationsDir,
        SessionStore $session,
        private readonly BotBlocker $bots = new BotBlocker(),
    ) {
        $this->services = new Services($config, $session, new Vite($publicDir . '/assets'), $migrationsDir);
    }

    /** Tests: access to the services (mail transport, database). */
    public function services(): Services
    {
        return $this->services;
    }

    /** Front controller entry point (public/index.php). */
    public static function run(string $rootDir): void
    {
        $request = Request::fromGlobals();
        // New server without configuration: the installer, and nothing else (§7.2).
        if (Installer::needed($rootDir)) {
            (new Installer($rootDir))->handle($request)->send($request->method !== 'HEAD');

            return;
        }
        try {
            $config = Config::load(Config::file($rootDir));
            DataDir::prepare($config->dataDir);
            $app = new self(
                $config,
                $rootDir . '/public',
                $rootDir . '/app/migrations',
                new NativeSession($config->dataDir . '/sessions'),
            );
            $response = $app->handle($request);
        } catch (\Throwable $e) {
            error_log('Belprise: startup failed: ' . $e->getMessage());
            $response = Response::html(ErrorPages::unavailable(null), 503);
            SecurityHeaders::apply($response, base64_encode(random_bytes(16)));
        }
        $response->send($request->method !== 'HEAD');
    }

    public function handle(Request $request): Response
    {
        $nonce = base64_encode(random_bytes(16));
        $this->services->beginRequest();
        try {
            $response = $this->bots->isBlocked($request->userAgent())
                ? Response::text('Accès refusé.', 403)
                : $this->dispatch($request, $nonce);
        } catch (HttpException $e) {
            $response = $this->errorResponse($request, $e);
        } catch (\Throwable $e) {
            error_log('Belprise: ' . $e::class . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
            $response = $this->errorResponse($request, new HttpException(500, 'Une erreur est survenue.'));
        }

        SecurityHeaders::apply($response, $nonce, $this->services->vite->devServer(), $this->config->hsts);

        return $response;
    }

    private function dispatch(Request $request, string $nonce): Response
    {
        if ($this->config->demo) {
            if (Demo::closed($request)) {
                throw HttpException::notFound();
            }
            $refusal = Demo::refusal($request);
            if ($refusal !== null) {
                throw new HttpException(403, $refusal, ['demo' => true]);
            }
        }
        $match = $this->router($request, $nonce)->match($request) ?? throw HttpException::notFound();
        [$handler, $params] = $match;

        if (str_starts_with($request->path, '/api/admin/')) {
            $this->guardAdminApi($request);
        }
        if (str_starts_with($request->path, '/api/superadmin/')) {
            $this->guardSuperAdminApi($request);
        }

        return $handler($request, $params);
    }

    /**
     * Admin API: a session is required (except to log in), and a valid CSRF
     * token on every state-changing request.
     */
    private function guardAdminApi(Request $request): void
    {
        $open = in_array($request->path, self::OPEN_ADMIN_API, true) || preg_match(self::INVITATION_API, $request->path) === 1;
        // The unread badge is polled every minute: it must not keep an idle session alive.
        $user = $open ? null : $this->services->auth()->user($request->path === '/api/admin/unread');
        if (!$open && $user === null) {
            throw new HttpException(401, 'Votre session a expiré. Reconnectez-vous.');
        }
        if (!$request->isSafeMethod() && !$this->services->csrf()->isValid($request)) {
            throw new HttpException(403, 'Votre session a expiré. Rechargez la page.');
        }
        if ($user !== null) {
            // From here on, every query is limited to the user's organization.
            $this->services->scopeTo($user);
            if (!Roles::allows((string) $user['role'], Roles::required($request))) {
                throw new HttpException(403, 'Votre rôle ne permet pas cette action.');
            }
            // Two-factor authentication required by the organization and not set up yet: only the set-up is open.
            if (!in_array($request->path, self::TOTP_SETUP_API, true) && $this->services->auth()->totpSetupRequired()) {
                throw new HttpException(403, 'Votre organisation exige la double authentification : activez-la pour continuer.', ['totpSetupRequired' => true]);
            }
        }
    }

    /** Platform operator API: its own session (password + code), CSRF on every change. */
    private function guardSuperAdminApi(Request $request): void
    {
        $open = in_array($request->path, self::OPEN_SUPERADMIN_API, true);
        if (!$open && $this->services->superAuth()->user() === null) {
            throw new HttpException(401, 'Votre session a expiré. Reconnectez-vous.');
        }
        if (!$request->isSafeMethod() && !$this->services->csrf()->isValid($request)) {
            throw new HttpException(403, 'Votre session a expiré. Rechargez la page.');
        }
    }

    /** Address for the links in e-mails: app_url, or (development) the host of the request. */
    private function baseUrl(Request $request): string
    {
        if ($this->config->appUrl !== null) {
            return $this->config->appUrl;
        }
        $host = (string) $request->header('host');

        return 'http://' . (preg_match('/^[A-Za-z0-9.-]+(:[0-9]{1,5})?$/D', $host) === 1 ? $host : 'localhost');
    }

    private function router(Request $request, string $nonce): Router
    {
        $s = $this->services;
        // Throttling and abuse limits work per network: one IPv6 holder gets a whole /64.
        $ip = Request::network($request->clientIp($this->config->trustedProxies));
        $id = static fn (array $p): int => (int) $p['id'];
        $r = new Router();

        // Admin pages and session
        $admin = fn () => new AdminController($s->auth(), $s->csrf(), $s->shell(), $s->clientKey($ip), $s->organizations(), $s->quota(), $s->signer(), $s->encodingMode(...), $this->config->demo);
        $r->get(AdminController::shellPattern(), fn (Request $q) => $admin()->shell($q, $nonce));
        $r->get('#^/api/admin/session$#', fn () => $admin()->session());
        $r->post('#^/api/admin/setup$#', fn (Request $q) => $admin()->setup($q));
        $r->post('#^/api/admin/login$#', fn (Request $q) => $admin()->login($q));
        $r->post('#^/api/admin/logout$#', fn () => $admin()->logout());
        $r->post('#^/api/admin/login/totp$#', fn (Request $q) => $admin()->loginCode($q));
        $r->post('#^/api/admin/password$#', fn (Request $q) => $admin()->changePassword($q));
        $r->post('#^/api/admin/totp/setup$#', fn () => $admin()->totpSetup());
        $r->post('#^/api/admin/totp/enable$#', fn (Request $q) => $admin()->totpEnable($q));
        $r->post('#^/api/admin/totp/disable$#', fn (Request $q) => $admin()->totpDisable($q));
        $r->post('#^/api/admin/totp/recovery-codes$#', fn (Request $q) => $admin()->totpRecoveryCodes($q));
        $r->patch('#^/api/admin/account$#', fn (Request $q) => $admin()->updateAccount($q));

        // Admin API: team (users, invitations) and organization
        $team = fn () => new TeamController($s, $s->clientKey($ip));
        $r->get('#^/api/admin/users$#', fn () => $team()->list());
        $r->patch('#^/api/admin/users/(?<id>[0-9]+)$#', fn (Request $q, array $p) => $team()->updateUser($id($p), $q));
        $r->delete('#^/api/admin/users/(?<id>[0-9]+)$#', fn (Request $q, array $p) => $team()->removeUser($id($p)));
        $r->post('#^/api/admin/invitations$#', fn (Request $q) => $team()->invite($q));
        $r->post('#^/api/admin/invitations/(?<id>[0-9]+)/resend$#', fn (Request $q, array $p) => $team()->resend($id($p), $q));
        $r->delete('#^/api/admin/invitations/(?<id>[0-9]+)$#', fn (Request $q, array $p) => $team()->cancel($id($p)));
        $r->patch('#^/api/admin/organization$#', fn (Request $q) => $team()->updateOrganization($q));
        $r->post('#^/api/admin/organization/logo$#', fn (Request $q) => $team()->uploadLogo($q));
        $r->delete('#^/api/admin/organization/logo$#', fn () => $team()->deleteLogo());
        $r->get('#^/api/admin/organization/log$#', fn () => $team()->log());
        $r->post('#^/api/admin/organization/deletion$#', fn (Request $q) => $team()->requestDeletion($q));
        $r->delete('#^/api/admin/organization/deletion$#', fn () => $team()->cancelDeletion());

        // Reset links sent by e-mail (§5.10): no session, the token is the proof.
        $reset = fn () => new AccountReset($s);
        $r->get('#^/api/admin/reset/(?<token>[A-Za-z0-9_-]{43})$#', fn (Request $q, array $p) => Response::json($reset()->show($p['token'])));
        $r->post('#^/api/admin/reset/(?<token>[A-Za-z0-9_-]{43})$#', function (Request $q, array $p) use ($reset, $s, $ip): Response {
            $reset()->complete($p['token'], $q->json(), $s->clientKey($ip . '|reset'));

            return Response::json(['done' => true]);
        });
        // "Mot de passe oublié ?" on the sign-in screen (§5.10): same answer whether the address exists or not.
        $r->post('#^/api/admin/forgot$#', function (Request $q) use ($reset, $s, $ip): Response {
            $reset()->forgot((string) (is_string($q->json()['email'] ?? null) ? $q->json()['email'] : ''), $s->clientKey($ip . '|forgot'), $this->baseUrl($q));

            return Response::json(['done' => true]);
        });
        $r->post('#^/api/admin/users/(?<id>[0-9]+)/reset$#', fn (Request $q, array $p) => $team()->requestReset($id($p), $q));
        $r->post('#^/api/admin/users/(?<id>[0-9]+)/transfer$#', fn (Request $q, array $p) => $team()->transfer($id($p), $q));

        // Admin API: collections (§5.9)
        $collections = fn () => new CollectionsController($s);
        $r->get('#^/api/admin/collections$#', fn () => $collections()->list());
        $r->post('#^/api/admin/collections$#', fn (Request $q) => $collections()->create($q));
        $r->get('#^/api/admin/collections/(?<id>[0-9]+)$#', fn (Request $q, array $p) => $collections()->show($id($p)));
        $r->patch('#^/api/admin/collections/(?<id>[0-9]+)$#', fn (Request $q, array $p) => $collections()->update($id($p), $q));
        $r->delete('#^/api/admin/collections/(?<id>[0-9]+)$#', fn (Request $q, array $p) => $collections()->delete($id($p)));
        $r->post('#^/api/admin/collections/(?<id>[0-9]+)/logo$#', fn (Request $q, array $p) => $collections()->uploadLogo($id($p), $q));
        $r->delete('#^/api/admin/collections/(?<id>[0-9]+)/logo$#', fn (Request $q, array $p) => $collections()->deleteLogo($id($p)));

        // Platform operator: organizations and their quota, never their content.
        $super = fn () => new SuperAdminController($s, $s->clientKey($ip));
        $r->get('#^/superadmin$#', fn () => $super()->shell($nonce));
        $r->get('#^/api/superadmin/session$#', fn () => $super()->session());
        $r->post('#^/api/superadmin/login$#', fn (Request $q) => $super()->login($q));
        $r->post('#^/api/superadmin/login/totp$#', fn (Request $q) => $super()->loginCode($q));
        $r->post('#^/api/superadmin/logout$#', fn () => $super()->logout());
        $r->get('#^/api/superadmin/organizations$#', fn () => $super()->organizations());
        $r->post('#^/api/superadmin/organizations$#', fn (Request $q) => $super()->create($q));
        $r->patch('#^/api/superadmin/encoding$#', fn (Request $q) => $super()->setEncoding($q));
        $r->patch('#^/api/superadmin/organizations/(?<id>[0-9]+)$#', fn (Request $q, array $p) => $super()->setQuota($id($p), $q));
        $r->post('#^/api/superadmin/organizations/(?<id>[0-9]+)/deletion$#', fn (Request $q, array $p) => $super()->requestDeletion($id($p), $q));
        $r->delete('#^/api/superadmin/organizations/(?<id>[0-9]+)/deletion$#', fn (Request $q, array $p) => $super()->cancelDeletion($id($p)));
        $r->post('#^/api/superadmin/organizations/(?<id>[0-9]+)/users/(?<user>[0-9]+)/reset$#', fn (Request $q, array $p) => $super()->requestReset($id($p), (int) $p['user'], $q));
        $r->get('#^/api/admin/invitation/(?<token>[A-Za-z0-9_-]{43})$#', fn (Request $q, array $p) => $team()->showInvitation($p['token']));
        $r->post('#^/api/admin/invitation/(?<token>[A-Za-z0-9_-]{43})$#', fn (Request $q, array $p) => $team()->acceptInvitation($p['token'], $q));

        // Admin API: dashboard
        $dashboard = fn () => new DashboardController($s);
        $r->get('#^/api/admin/dashboard$#', fn () => $dashboard()->stats());
        $r->get('#^/api/admin/unread$#', fn () => $dashboard()->unread());

        // Admin API: clients
        $clients = fn () => new ClientsController($s);
        $r->get('#^/api/admin/clients$#', fn (Request $q) => $clients()->list($q));
        $r->post('#^/api/admin/clients$#', fn (Request $q) => $clients()->create($q));
        $r->get('#^/api/admin/clients/(?<id>[0-9]+)$#', fn (Request $q, array $p) => $clients()->show($id($p)));
        $r->patch('#^/api/admin/clients/(?<id>[0-9]+)$#', fn (Request $q, array $p) => $clients()->update($id($p), $q));
        $r->delete('#^/api/admin/clients/(?<id>[0-9]+)$#', fn (Request $q, array $p) => $clients()->delete($id($p), $q));
        $r->post('#^/api/admin/clients/(?<id>[0-9]+)/logo$#', fn (Request $q, array $p) => $clients()->uploadLogo($id($p), $q));
        $r->delete('#^/api/admin/clients/(?<id>[0-9]+)/logo$#', fn (Request $q, array $p) => $clients()->deleteLogo($id($p)));

        // Admin API: projects
        $projects = fn () => new ProjectsController($s);
        $r->get('#^/api/admin/projects$#', fn (Request $q) => $projects()->list($q));
        $r->post('#^/api/admin/projects$#', fn (Request $q) => $projects()->create($q));
        $r->get('#^/api/admin/projects/(?<id>[0-9]+)$#', fn (Request $q, array $p) => $projects()->show($id($p)));
        $r->patch('#^/api/admin/projects/(?<id>[0-9]+)$#', fn (Request $q, array $p) => $projects()->update($id($p), $q));
        $r->delete('#^/api/admin/projects/(?<id>[0-9]+)$#', fn (Request $q, array $p) => $projects()->delete($id($p)));

        // Admin API: videos and tags
        $videos = fn () => new VideosController($s);
        $r->get('#^/api/admin/videos$#', fn (Request $q) => $videos()->list($q));
        $r->post('#^/api/admin/videos$#', fn (Request $q) => $videos()->create($q));
        $r->get('#^/api/admin/videos/(?<id>[0-9]+)/archive$#', fn (Request $q, array $p) => $videos()->archivePlan($id($p)));
        $r->post('#^/api/admin/videos/(?<id>[0-9]+)/archive$#', fn (Request $q, array $p) => $videos()->archive($id($p)));
        $r->get('#^/api/admin/videos/(?<id>[0-9]+)$#', fn (Request $q, array $p) => $videos()->show($id($p)));
        $r->patch('#^/api/admin/videos/(?<id>[0-9]+)$#', fn (Request $q, array $p) => $videos()->update($id($p), $q));
        $r->delete('#^/api/admin/videos/(?<id>[0-9]+)$#', fn (Request $q, array $p) => $videos()->delete($id($p)));
        $r->get('#^/api/admin/tags$#', fn () => $videos()->tags());

        // Admin API: link options
        $links = fn () => new LinksController($s);
        $r->patch('#^/api/admin/links/(?<id>[0-9]+)$#', fn (Request $q, array $p) => $links()->update($id($p), $q));
        $r->post('#^/api/admin/links/(?<id>[0-9]+)/regenerate$#', fn (Request $q, array $p) => $links()->regenerate($id($p)));

        // Admin API: versions
        $versions = fn () => new VersionsController($s);
        $r->get('#^/api/admin/videos/(?<id>[0-9]+)/versions$#', fn (Request $q, array $p) => $versions()->list($id($p)));
        $r->patch('#^/api/admin/versions/(?<id>[0-9]+)$#', fn (Request $q, array $p) => $versions()->update($id($p), $q));
        $r->delete('#^/api/admin/versions/(?<id>[0-9]+)$#', fn (Request $q, array $p) => $versions()->delete($id($p)));
        $r->post('#^/api/admin/versions/(?<id>[0-9]+)/images$#', fn (Request $q, array $p) => $versions()->images($id($p), $q));
        $r->get('#^/api/admin/versions/(?<id>[0-9]+)/file$#', fn (Request $q, array $p) => $versions()->file($id($p), $q));
        $r->get('#^/api/admin/versions/(?<id>[0-9]+)/download$#', fn (Request $q, array $p) => $versions()->download($id($p), $q));
        $r->delete('#^/api/admin/versions/(?<id>[0-9]+)/hls$#', fn (Request $q, array $p) => $versions()->clearHls($id($p)));
        $r->post('#^/api/admin/versions/(?<id>[0-9]+)/hls$#', fn (Request $q, array $p) => $versions()->publishHls($id($p), $q));
        $r->post('#^/api/admin/versions/(?<id>[0-9]+)/encode$#', fn (Request $q, array $p) => $versions()->encode($id($p)));

        // Admin API: comments and "Révisions"
        $comments = fn () => new CommentsController($s);
        $r->get('#^/api/admin/versions/(?<id>[0-9]+)/comments$#', fn (Request $q, array $p) => $comments()->list($id($p)));
        $r->post('#^/api/admin/versions/(?<id>[0-9]+)/comments$#', fn (Request $q, array $p) => $comments()->create($id($p), $q));
        $r->post('#^/api/admin/versions/(?<id>[0-9]+)/comments/read$#', fn (Request $q, array $p) => $comments()->markRead($id($p)));
        $r->patch('#^/api/admin/comments/(?<id>[0-9]+)$#', fn (Request $q, array $p) => $comments()->update($id($p), $q));
        $r->delete('#^/api/admin/comments/(?<id>[0-9]+)$#', fn (Request $q, array $p) => $comments()->delete($id($p)));
        $r->get('#^/api/admin/feedback$#', fn (Request $q) => $comments()->feedback($q));
        $r->get('#^/api/admin/versions/(?<id>[0-9]+)/edl$#', fn (Request $q, array $p) => $comments()->edl($id($p), $q));

        // Admin API: chunked uploads
        $uploads = fn () => new UploadsController($s);
        $r->get('#^/api/admin/uploads$#', fn (Request $q) => $uploads()->list($q));
        $r->post('#^/api/admin/uploads$#', fn (Request $q) => $uploads()->start($q));
        $r->get('#^/api/admin/uploads/(?<id>[0-9]+)$#', fn (Request $q, array $p) => $uploads()->show($id($p)));
        $r->put('#^/api/admin/uploads/(?<id>[0-9]+)$#', fn (Request $q, array $p) => $uploads()->chunk($id($p), $q));
        $r->post('#^/api/admin/uploads/(?<id>[0-9]+)/complete$#', fn (Request $q, array $p) => $uploads()->complete($id($p), $q));
        $r->delete('#^/api/admin/uploads/(?<id>[0-9]+)$#', fn (Request $q, array $p) => $uploads()->cancel($id($p)));

        // Signed media
        $media = fn (Request $q, array $p) => (new MediaController($s))->serve($q, $p['path']);
        $r->get(MediaController::LOGO_PATTERN, $media);
        $r->get(MediaController::IMAGE_PATTERN, $media);
        $r->get(MediaController::VIDEO_PATTERN, $media);
        $r->get(MediaController::HLS_PATTERN, $media);

        // Client review API: comments and validation, through the client link
        $review = fn () => new ClientReviewController($s, $ip);
        $ref = '/api/v/(?<ref>[0-9]+[a-z]+)';
        $vid = static fn (array $p): int => (int) $p['vid'];
        $r->get('#^' . $ref . '/versions/(?<vid>[0-9]+)/comments$#', fn (Request $q, array $p) => $review()->list($q, $p['ref'], $vid($p)));
        $r->post('#^' . $ref . '/versions/(?<vid>[0-9]+)/comments$#', fn (Request $q, array $p) => $review()->create($q, $p['ref'], $vid($p)));
        $r->post('#^' . $ref . '/versions/(?<vid>[0-9]+)/approve$#', fn (Request $q, array $p) => $review()->approve($q, $p['ref'], $vid($p)));
        $r->get('#^' . $ref . '/versions/(?<vid>[0-9]+)/download$#', fn (Request $q, array $p) => $review()->download($q, $p['ref'], $vid($p)));
        $r->patch('#^' . $ref . '/comments/(?<cid>[0-9]+)$#', fn (Request $q, array $p) => $review()->update($q, $p['ref'], (int) $p['cid']));
        $r->delete('#^' . $ref . '/comments/(?<cid>[0-9]+)$#', fn (Request $q, array $p) => $review()->delete($q, $p['ref'], (int) $p['cid']));

        // Client pages: /{key} and /v/{number}{key} (POST = password form)
        $public = fn () => new PublicController($s, $ip);
        $space = '#^/(?<key>' . Keys::CHAR_CLASS . '{' . Keys::LENGTH . '})$#';
        $video = '#^/v/(?<ref>[0-9]+[a-z]+)$#';
        $r->get($space, fn (Request $q, array $p) => $public()->space($q, $p['key'], $nonce));
        $r->post($space, fn (Request $q, array $p) => $public()->space($q, $p['key'], $nonce));
        $r->get($video, fn (Request $q, array $p) => $public()->video($q, $p['ref'], $nonce));
        $r->post($video, fn (Request $q, array $p) => $public()->video($q, $p['ref'], $nonce));

        return $r;
    }

    private function errorResponse(Request $request, HttpException $e): Response
    {
        // Every 404 is the same neutral page, API included.
        if ($e->status === 404) {
            $response = Response::html(ErrorPages::notFound($this->baseCss()), 404);
            $response->setHeader('Cache-Control', 'no-store');

            return $response;
        }

        if (str_starts_with($request->path, '/api/')) {
            $response = Response::json(['error' => $e->getMessage()] + $e->extra, $e->status);
        } else {
            $response = Response::html(ErrorPages::unavailable($this->baseCss()), $e->status);
        }
        if (isset($e->extra['retryAfter']) && $e->status === 429) {
            $response->setHeader('Retry-After', (string) $e->extra['retryAfter']);
        }

        return $response;
    }

    private function baseCss(): ?string
    {
        try {
            return $this->services->vite->cssUrl(self::BASE_CSS_ENTRY);
        } catch (\Throwable) {
            return null;
        }
    }
}
