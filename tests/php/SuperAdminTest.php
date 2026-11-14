<?php

declare(strict_types=1);

namespace Belprise\Tests;

use Belprise\Repository\Superadmins;
use Belprise\Security\SecretBox;
use Belprise\Security\Totp;
use Belprise\Tests\Support\AppTestCase;

/**
 * The operator of the installation and the quotas (CAHIER_DES_CHARGES §5.11,
 * §5.12, §10.2 criteria 13 and 15). Since 3.0: a free quota per organization.
 */
final class SuperAdminTest extends AppTestCase
{
    private const SUPER_EMAIL = 'exploitation@belprise.fr';
    private const SUPER_PASSWORD = 'mot-de-passe-exploitant';

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->secret = Totp::generateSecret();
        $box = new SecretBox(str_repeat('s', 64), 'totp');
        $this->loginAdmin();
        // These quota tests are written for a 5 Go quota (a new organization has no limit).
        $this->db()->exec('UPDATE organizations SET quota_bytes = 5000000000');
        (new Superadmins($this->db()))->create(self::SUPER_EMAIL, 'Exploitant', password_hash(self::SUPER_PASSWORD, PASSWORD_ARGON2ID), $box->seal($this->secret), 0);
    }

    private function superLogin(): void
    {
        $this->session->data = [];
        $this->db()->exec('DELETE FROM login_attempts');
        $csrf = (string) $this->decode($this->request('GET', '/api/superadmin/session'))['csrf'];
        $this->superPost('/api/superadmin/login', ['email' => self::SUPER_EMAIL, 'password' => self::SUPER_PASSWORD], $csrf);
        $state = $this->decode($this->superPost('/api/superadmin/login/totp', ['code' => Totp::code($this->secret, time())], $csrf));
        self::assertTrue($state['authenticated']);
    }

    private function superPost(string $path, array $data, ?string $csrf = null, string $method = 'POST'): \Belprise\Http\Response
    {
        return $this->request($method, $path, [
            'X-CSRF-Token' => $csrf ?? (string) ($this->session->data['csrf'] ?? ''), 'Content-Type' => 'application/json',
        ], json_encode($data, JSON_THROW_ON_ERROR));
    }

    // ---- Sign-in -----------------------------------------------------------------------

    public function testTheOperatorAlwaysNeedsThePasswordAndTheCode(): void
    {
        $csrf = (string) $this->decode($this->request('GET', '/api/superadmin/session'))['csrf'];
        self::assertSame(401, $this->superPost('/api/superadmin/login', ['email' => self::SUPER_EMAIL, 'password' => 'faux'], $csrf)->status);
        $this->db()->exec('DELETE FROM login_attempts');

        $step1 = $this->decode($this->superPost('/api/superadmin/login', ['email' => self::SUPER_EMAIL, 'password' => self::SUPER_PASSWORD], $csrf));
        self::assertSame([false, true], [$step1['authenticated'], $step1['codeRequired']]);
        self::assertSame(401, $this->request('GET', '/api/superadmin/organizations')->status, 'not before the code');

        self::assertSame(401, $this->superPost('/api/superadmin/login/totp', ['code' => '000000'], $csrf)->status);
        $this->db()->exec('DELETE FROM login_attempts');
        $state = $this->decode($this->superPost('/api/superadmin/login/totp', ['code' => Totp::code($this->secret, time())], $csrf));
        self::assertTrue($state['authenticated']);
        self::assertSame(200, $this->request('GET', '/api/superadmin/organizations')->status);
        // Every change needs the CSRF token.
        self::assertSame(403, $this->superPost('/api/superadmin/organizations', ['name' => 'X', 'email' => 'x@y.fr', 'quotaGb' => 5], 'forged')->status);
    }

    public function testTheOperatorSessionOpensNoContent(): void
    {
        // belprod has content.
        $client = $this->api('POST', '/api/admin/clients', ['name' => 'Maison Aurore', 'bgColor' => '#101820', 'textColor' => '#f2efe9', 'accentColor' => '#e3a06b', 'welcomeText' => ''], 201);
        $this->superLogin();

        foreach ([
            ['GET', '/api/admin/clients'], ['GET', '/api/admin/clients/' . $client['id']], ['PATCH', '/api/admin/clients/' . $client['id']],
            ['DELETE', '/api/admin/clients/' . $client['id']], ['GET', '/api/admin/videos'], ['GET', '/api/admin/feedback'],
            ['GET', '/api/admin/users'], ['POST', '/api/admin/invitations'], ['GET', '/api/admin/dashboard'],
            ['POST', '/api/admin/organization/deletion'], ['DELETE', '/api/admin/organization/deletion'], ['GET', '/api/admin/versions/1/download'],
            ['PATCH', '/api/admin/organization'], ['POST', '/api/admin/organization/logo'], ['DELETE', '/api/admin/organization/logo'],
            ['POST', '/api/admin/users/1/reset'], ['POST', '/api/admin/users/1/transfer'], ['POST', '/api/admin/versions/1/encode'], ['POST', '/api/admin/totp/recovery-codes'],
        ] as [$method, $path]) {
            $response = $this->superPost($path, ['name' => 'Intrus'], null, $method);
            self::assertSame(401, $response->status, $method . ' ' . $path . ': the operator is an anonymous visitor here');
        }
        self::assertSame('Maison Aurore', $this->db()->query('SELECT name FROM clients')->fetchColumn());
        // And the operator API has no content route at all.
        self::assertSame(404, $this->request('GET', '/api/superadmin/organizations/1/clients')->status);
        self::assertSame(404, $this->superPost('/api/superadmin/organizations/1', [], null, 'DELETE')->status, 'no immediate deletion');
    }

    public function testAnOrganizationAdministratorCannotUseTheOperatorApi(): void
    {
        self::assertSame(401, $this->request('GET', '/api/superadmin/organizations')->status);
        self::assertSame(401, $this->superPost('/api/superadmin/organizations/1', ['quotaGb' => 100], null, 'PATCH')->status);
        self::assertSame(401, $this->superPost('/api/superadmin/organizations/1/deletion', ['confirm' => 'belprod'])->status);
        self::assertSame(401, $this->superPost('/api/superadmin/organizations/1/deletion', [], null, 'DELETE')->status);
        self::assertSame(401, $this->superPost('/api/superadmin/encoding', ['mode' => 'server'], null, 'PATCH')->status);
        self::assertSame(200, $this->request('GET', '/superadmin')->status, 'the sign-in page itself is public');
    }

    // ---- Organizations and quotas -------------------------------------------------------

    public function testTheOperatorCreatesAnOrganizationAndInvitesItsAdministrator(): void
    {
        $this->superLogin();
        $created = $this->decode($this->superPost('/api/superadmin/organizations', ['name' => 'Studio Nord', 'email' => 'nora@studio-nord.fr', 'quotaGb' => 250]));

        self::assertTrue($created['sent']);
        $mail = end($this->outbox->messages);
        self::assertSame('nora@studio-nord.fr', $mail->to);
        self::assertSame('Votre espace Belprise « Studio Nord » est prêt', $mail->subject);
        self::assertStringContainsString('avec 250 Go de stockage', $mail->text);
        self::assertStringContainsString($created['link'], $mail->text);
        self::assertSame('email', $this->decode($this->superPost('/api/superadmin/organizations', ['name' => 'Doublon', 'email' => self::ADMIN_EMAIL, 'quotaGb' => 5]))['field']);
        self::assertSame('quotaGb', $this->decode($this->superPost('/api/superadmin/organizations', ['name' => 'Studio X', 'email' => 'x@y.fr', 'quotaGb' => 0]))['field']);
        self::assertSame('quotaGb', $this->decode($this->superPost('/api/superadmin/organizations', ['name' => 'Studio X', 'email' => 'x@y.fr', 'quotaGb' => 'beaucoup']))['field']);
        // No quota: no limit (the disk is the bound).
        $this->decode($this->superPost('/api/superadmin/organizations', ['name' => 'Studio Sud', 'email' => 'sam@studio-sud.fr', 'quotaGb' => null]));

        $list = $this->api('GET', '/api/superadmin/organizations');
        $nord = array_values(array_filter($list['organizations'], static fn ($o) => $o['name'] === 'Studio Nord'))[0];
        self::assertSame([250_000_000_000, 0, 1], [$nord['quota'], $nord['users'], $nord['pendingInvitations']]);
        self::assertSame('250 Go', $nord['storage']['quotaLabel']);
        $sud = array_values(array_filter($list['organizations'], static fn ($o) => $o['name'] === 'Studio Sud'))[0];
        self::assertSame([null, null, 'Sans limite'], [$sud['quota'], $sud['storage']['quota'], $sud['storage']['quotaLabel']]);
        self::assertArrayNotHasKey('plans', $list);
        self::assertArrayNotHasKey('betaRequests', $list);

        // Nora accepts: she is the administrator, and sees what the operator did.
        $this->session->data = [];
        $this->csrfToken();
        $this->api('POST', '/api/admin/invitation/' . substr($created['link'], -43), ['name' => 'Nora', 'password' => 'mot-de-passe-de-nora', 'confirmation' => 'mot-de-passe-de-nora']);
        $state = $this->decode($this->request('GET', '/api/admin/session'));
        self::assertSame(['admin', 'Studio Nord', 250_000_000_000], [$state['user']['role'], $state['organization']['name'], $state['storage']['quota']]);
        self::assertSame(['Organisation créée'], array_column($this->api('GET', '/api/admin/organization/log'), 'action'));
    }

    public function testQuotaChangesAreJournaledForTheOrganization(): void
    {
        $this->superLogin();
        $result = $this->decode($this->superPost('/api/superadmin/organizations/1', ['quotaGb' => 1000], null, 'PATCH'));
        self::assertSame(1_000_000_000_000, $result['storage']['quota']);
        self::assertSame(404, $this->superPost('/api/superadmin/organizations/99', ['quotaGb' => 1000], null, 'PATCH')->status);

        $this->session->data = [];
        $this->db()->exec('DELETE FROM login_attempts');
        $this->postJson('/api/admin/login', ['email' => self::ADMIN_EMAIL, 'password' => self::ADMIN_PASSWORD]);
        $log = $this->api('GET', '/api/admin/organization/log');
        self::assertSame([['Exploitant Belprise', 'Quota changé', '5,0 Go → 1 To']], array_map(static fn ($r) => [$r['actor'], $r['action'], $r['details']], $log));
    }

    // ---- Quotas ------------------------------------------------------------------------

    public function testAnUploadThatWouldGoOverTheQuotaIsRefusedBeforeTheFirstByte(): void
    {
        $client = $this->api('POST', '/api/admin/clients', ['name' => 'Maison Aurore', 'bgColor' => '#101820', 'textColor' => '#f2efe9', 'accentColor' => '#e3a06b', 'welcomeText' => ''], 201);
        $project = $this->api('POST', '/api/admin/projects', ['clientId' => $client['id'], 'name' => 'Lancement'], 201);
        $video = $this->api('POST', '/api/admin/videos', ['projectId' => $project['id'], 'title' => 'Film'], 201);
        $quota = 5_000_000_000;

        $refused = $this->api('POST', '/api/admin/uploads', ['videoId' => $video['id'], 'name' => 'master.mp4', 'size' => $quota + 1, 'fingerprint' => str_repeat('b', 64)], 422);
        self::assertTrue($refused['quota']);
        self::assertSame('Espace insuffisant (quota de 5,0 Go) : 5,0 Go disponibles, 5,0 Go nécessaires.', $refused['error']);
        self::assertSame('0', (string) $this->db()->query('SELECT COUNT(*) FROM uploads')->fetchColumn());

        // An upload in progress reserves its full size: a second one no longer fits.
        $this->api('POST', '/api/admin/uploads', ['videoId' => $video['id'], 'name' => 'a.mp4', 'size' => 3_000_000_000, 'fingerprint' => str_repeat('c', 64)], 201);
        $this->api('POST', '/api/admin/uploads', ['videoId' => $video['id'], 'name' => 'b.mp4', 'size' => 3_000_000_000, 'fingerprint' => str_repeat('d', 64)], 422);
        $storage = $this->decode($this->request('GET', '/api/admin/session'))['storage'];
        self::assertSame([5_000_000_000, 3_000_000_000, 0.6], [$storage['quota'], $storage['reserved'], $storage['ratio']]);

        // A bigger quota makes room; so does no limit.
        $this->db()->exec('UPDATE organizations SET quota_bytes = 250000000000');
        $this->api('POST', '/api/admin/uploads', ['videoId' => $video['id'], 'name' => 'b.mp4', 'size' => 3_000_000_000, 'fingerprint' => str_repeat('d', 64)], 201);
    }

    public function testTheOperatorKeyIsNeverALinkKey(): void
    {
        self::assertContains('superadmin', \Belprise\Security\Keys::RESERVED_PREFIXES);
    }
}
