<?php

declare(strict_types=1);

namespace Belprise;

use Belprise\Controller\Admin\Presenter;
use Belprise\Database\Database;
use Belprise\Http\SessionStore;
use Belprise\Lifecycle\Downgrade;
use Belprise\Lifecycle\Notices;
use Belprise\Lifecycle\OrganizationDeletion;
use Belprise\Mail\Mailer;
use Belprise\Mail\NativeMailer;
use Belprise\Repository\Clients;
use Belprise\Repository\Collections;
use Belprise\Repository\AccountResets;
use Belprise\Repository\Activity;
use Belprise\Repository\AuditLog;
use Belprise\Repository\Comments;
use Belprise\Repository\Invitations;
use Belprise\Repository\Links;
use Belprise\Repository\Organizations;
use Belprise\Repository\PlatformLog;
use Belprise\Media\ServerEncoder;
use Belprise\Repository\EncodeJobs;
use Belprise\Repository\Projects;
use Belprise\Repository\RecoveryCodes;
use Belprise\Repository\Settings;
use Belprise\Repository\Superadmins;
use Belprise\Repository\Tags;
use Belprise\Repository\Uploads;
use Belprise\Repository\Users;
use Belprise\Repository\Versions;
use Belprise\Repository\Videos;
use Belprise\Security\AdminAuth;
use Belprise\Security\Csrf;
use Belprise\Security\LinkAccess;
use Belprise\Security\MediaSigner;
use Belprise\Security\RateLimiter;
use Belprise\Security\SecretBox;
use Belprise\Security\SuperAuth;
use Belprise\View\Shell;
use Belprise\View\Vite;
use PDO;

/**
 * Lazily built services for one request.
 */
final class Services
{
    private ?PDO $db = null;

    /** @var array<string, object> */
    private array $instances = [];

    /** @var array<string, mixed>|null signed-in user (admin API), whose organization limits every query */
    private ?array $user = null;

    public function __construct(
        public readonly Config $config,
        public readonly SessionStore $session,
        public readonly Vite $vite,
        private readonly string $migrationsDir,
    ) {
    }

    /** The installation folder (app/, public/, VERSION…). */
    public function rootDir(): string
    {
        return dirname($this->migrationsDir, 2);
    }

    public function db(): PDO
    {
        return $this->db ??= Database::open($this->config->dataDir . '/belprise.sqlite', $this->migrationsDir);
    }

    public function settings(): Settings
    {
        return $this->once(Settings::class, fn () => new Settings($this->db()));
    }

    /**
     * Start of a request: no user, no scoped repository left from a previous
     * one (one Services serves several requests in tests). The database
     * connection and the mail transport are kept.
     */
    public function beginRequest(): void
    {
        $this->user = null;
        $this->instances = array_filter($this->instances, static fn (string $id) => $id === Mailer::class, ARRAY_FILTER_USE_KEY);
    }

    /**
     * Admin API: from now on, repositories only see this user's organization.
     * Called once, before any repository is built.
     *
     * @param array<string, mixed> $user
     */
    public function scopeTo(array $user): void
    {
        $this->user = $user;
        $this->instances = array_filter(
            $this->instances,
            static fn (object $o) => !str_starts_with($o::class, 'Belprise\\Repository\\'),
        );
    }

    /** @return array<string, mixed>|null */
    public function user(): ?array
    {
        return $this->user;
    }

    public function organizationId(): ?int
    {
        return $this->user === null ? null : (int) $this->user['organization_id'];
    }

    public function organizations(): Organizations
    {
        return $this->once(Organizations::class, fn () => new Organizations($this->db()));
    }

    public function users(): Users
    {
        return $this->once(Users::class, fn () => new Users($this->db()));
    }

    public function invitations(): Invitations
    {
        return $this->once(Invitations::class, fn () => new Invitations($this->db()));
    }

    public function mailer(): Mailer
    {
        return $this->once(Mailer::class, fn () => new NativeMailer($this->config->mailFrom));
    }

    /** Tests and demo: replaces the mail transport. */
    public function useMailer(Mailer $mailer): void
    {
        $this->instances[Mailer::class] = $mailer;
    }

    public function clients(): Clients
    {
        return $this->once(Clients::class, fn () => new Clients($this->db(), $this->organizationId()));
    }

    public function collections(): Collections
    {
        return $this->once(Collections::class, fn () => new Collections($this->db(), $this->organizationId()));
    }

    public function projects(): Projects
    {
        return $this->once(Projects::class, fn () => new Projects($this->db(), $this->organizationId()));
    }

    public function videos(): Videos
    {
        return $this->once(Videos::class, fn () => new Videos($this->db(), $this->organizationId()));
    }

    public function tags(): Tags
    {
        return $this->once(Tags::class, fn () => new Tags($this->db(), $this->organizationId()));
    }

    public function versions(): Versions
    {
        return $this->once(Versions::class, fn () => new Versions($this->db(), $this->organizationId()));
    }

    public function comments(): Comments
    {
        return $this->once(Comments::class, fn () => new Comments($this->db(), $this->organizationId()));
    }

    public function uploads(): Uploads
    {
        return $this->once(Uploads::class, fn () => new Uploads($this->db(), $this->organizationId()));
    }

    public function links(): Links
    {
        return $this->once(Links::class, fn () => new Links($this->db(), $this->organizationId()));
    }

    public function rateLimiter(): RateLimiter
    {
        return $this->once(RateLimiter::class, fn () => new RateLimiter($this->db()));
    }

    public function auth(): AdminAuth
    {
        return $this->once(AdminAuth::class, fn () => new AdminAuth(
            $this->users(),
            $this->organizations(),
            $this->db(),
            $this->session,
            $this->rateLimiter(),
            null,
            new SecretBox($this->config->secret, 'totp'),
        ));
    }

    public function encodeJobs(): EncodeJobs
    {
        return $this->once(EncodeJobs::class, fn () => new EncodeJobs($this->db()));
    }

    /** Where the playback versions are encoded: "browser" or "server" (§5.7 bis). */
    public function encodingMode(): string
    {
        return ServerEncoder::mode($this->settings());
    }

    public function recoveryCodes(): RecoveryCodes
    {
        return $this->once(RecoveryCodes::class, fn () => new RecoveryCodes($this->db()));
    }

    public function superadmins(): Superadmins
    {
        return $this->once(Superadmins::class, fn () => new Superadmins($this->db()));
    }

    public function activity(): Activity
    {
        return $this->once(Activity::class, fn () => new Activity($this->db(), $this->organizationId()));
    }

    public function auditLog(): AuditLog
    {
        return $this->once(AuditLog::class, fn () => new AuditLog($this->db()));
    }

    public function superAuth(): SuperAuth
    {
        return $this->once(SuperAuth::class, fn () => new SuperAuth(
            $this->superadmins(),
            $this->session,
            $this->rateLimiter(),
            new SecretBox($this->config->secret, 'totp'),
        ));
    }

    public function csrf(): Csrf
    {
        return $this->once(Csrf::class, fn () => new Csrf($this->session));
    }

    public function signer(): MediaSigner
    {
        return $this->once(MediaSigner::class, fn () => new MediaSigner($this->config->secret));
    }

    public function linkAccess(): LinkAccess
    {
        return $this->once(LinkAccess::class, fn () => new LinkAccess($this->config->secret));
    }

    public function storage(): Storage
    {
        return $this->once(Storage::class, fn () => new Storage($this->config->dataDir));
    }

    public function quota(): Quota
    {
        return $this->once(Quota::class, fn () => new Quota($this->db(), $this->storage()));
    }

    public function accountResets(): AccountResets
    {
        return new AccountResets($this->db());
    }

    public function platformLog(): PlatformLog
    {
        return new PlatformLog($this->db());
    }

    /** E-mails to the administrators of an organization (downgrade, deletion). */
    public function notices(): Notices
    {
        return new Notices($this->db(), $this->mailer(), $this->config->appUrl);
    }

    public function downgrade(): Downgrade
    {
        return new Downgrade($this->db(), $this->storage(), $this->notices());
    }

    public function organizationDeletion(): OrganizationDeletion
    {
        return new OrganizationDeletion($this->db(), $this->storage(), $this->notices());
    }

    public function logos(): LogoStore
    {
        return $this->once(LogoStore::class, fn () => new LogoStore($this->storage()));
    }

    public function presenter(): Presenter
    {
        return $this->once(Presenter::class, fn () => new Presenter($this->signer(), $this->linkAccess(), $this->encodeJobs()));
    }

    public function shell(): Shell
    {
        return $this->once(Shell::class, fn () => new Shell($this->vite));
    }

    /** Salted fingerprint of a client IP: the IP itself is never stored. */
    public function clientKey(string $ip): string
    {
        return hash_hmac('sha256', 'ip:' . $ip, $this->config->secret);
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @param callable(): T   $factory
     * @return T
     */
    private function once(string $id, callable $factory): object
    {
        /** @var T */
        return $this->instances[$id] ??= $factory();
    }
}
