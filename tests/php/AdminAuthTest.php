<?php

declare(strict_types=1);

namespace Belprise\Tests;

use Belprise\Tests\Support\AppTestCase;

final class AdminAuthTest extends AppTestCase
{
    private const PASSWORD = 'un-mot-de-passe-long';

    public function testFirstLaunchRequiresAccountCreation(): void
    {
        $session = $this->decode($this->request('GET', '/api/admin/session'));

        self::assertTrue($session['setupRequired']);
        self::assertFalse($session['authenticated']);
    }

    public function testSetupCreatesAnArgon2idAccountAndLogsIn(): void
    {
        $response = $this->setUpAccount();
        $state = $this->decode($response);

        self::assertSame(200, $response->status);
        self::assertFalse($state['setupRequired']);
        self::assertTrue($state['authenticated']);
        self::assertSame(['id' => 1, 'name' => 'Jules', 'email' => self::ADMIN_EMAIL, 'role' => 'admin'], $state['user']);
        self::assertSame(['id' => 1, 'name' => 'belprod', 'logoUrl' => null, 'accentColor' => '#e8515f', 'theme' => 'dark', 'totpRequired' => false], $state['organization']);
        self::assertSame([], $state['theme'], 'the default identity adds no variable');
        self::assertSame(1, $this->session->regenerations);

        $hash = $this->db()->query('SELECT password_hash FROM users')->fetchColumn();
        self::assertStringStartsWith('$argon2id$', (string) $hash);
    }

    public function testSetupIsClosedOnceTheOperatorAccountExists(): void
    {
        // Fresh install where the operator was created on the server first (DEPLOIEMENT §1.7):
        // nobody can claim the first launch to get a free organization.
        $this->request('GET', '/api/admin/session'); // creates and migrates the database
        $this->db()->exec("INSERT INTO superadmins (email, name, password_hash, totp_secret) VALUES ('op@belprise.fr', 'Op', 'x', 'x')");
        self::assertFalse($this->decode($this->request('GET', '/api/admin/session'))['setupRequired']);
        $response = $this->postJson('/api/admin/setup', [
            'organization' => 'Intrus', 'name' => 'Intrus', 'email' => 'intrus@example.com',
            'password' => 'autre-mot-de-passe', 'confirmation' => 'autre-mot-de-passe',
        ]);
        self::assertSame(409, $response->status);
        self::assertSame('0', (string) $this->db()->query('SELECT COUNT(*) FROM organizations')->fetchColumn());
    }

    public function testSetupStaysClosedWhenEveryAccountIsGone(): void
    {
        $this->setUpAccount();
        $this->db()->exec('DELETE FROM users');
        self::assertFalse($this->decode($this->request('GET', '/api/admin/session'))['setupRequired'], 'an organization exists');
    }

    public function testSetupCannotRunTwice(): void
    {
        $this->setUpAccount();
        $response = $this->postJson('/api/admin/setup', [
            'organization' => 'Intrus', 'name' => 'Intrus', 'email' => 'intrus@example.com',
            'password' => 'autre-mot-de-passe', 'confirmation' => 'autre-mot-de-passe',
        ]);

        self::assertSame(409, $response->status);
    }

    public function testSetupValidatesThePassword(): void
    {
        $base = ['organization' => 'belprod', 'name' => 'Jules', 'email' => self::ADMIN_EMAIL];
        $short = $this->postJson('/api/admin/setup', $base + ['password' => 'court', 'confirmation' => 'court']);
        self::assertSame(422, $short->status);
        self::assertSame('password', $this->decode($short)['field']);

        $mismatch = $this->postJson('/api/admin/setup', $base + ['password' => self::PASSWORD, 'confirmation' => self::PASSWORD . 'x']);
        self::assertSame(422, $mismatch->status);
        self::assertSame('confirmation', $this->decode($mismatch)['field']);

        $email = $this->postJson('/api/admin/setup', ['email' => 'pas-un-email'] + $base + ['password' => self::PASSWORD, 'confirmation' => self::PASSWORD]);
        self::assertSame('email', $this->decode($email)['field']);
        $organization = $this->postJson('/api/admin/setup', ['organization' => ' '] + $base + ['password' => self::PASSWORD, 'confirmation' => self::PASSWORD]);
        self::assertSame('organization', $this->decode($organization)['field']);
        self::assertTrue($this->decode($this->request('GET', '/api/admin/session'))['setupRequired']);
    }

    public function testLoginAndLogout(): void
    {
        $this->setUpAccount();
        $this->postJson('/api/admin/logout');
        self::assertFalse($this->decode($this->request('GET', '/api/admin/session'))['authenticated']);

        $response = $this->postJson('/api/admin/login', ['email' => ' JULES@belprod.test ', 'password' => self::PASSWORD]);

        self::assertSame(200, $response->status);
        self::assertTrue($this->decode($response)['authenticated']);
    }

    public function testCsrfTokenRotatesOnLogin(): void
    {
        $this->setUpAccount();
        $this->postJson('/api/admin/logout');
        $before = $this->csrfToken();

        $after = $this->decode($this->postJson('/api/admin/login', ['email' => self::ADMIN_EMAIL, 'password' => self::PASSWORD]))['csrf'];

        self::assertNotSame($before, $after);
        self::assertSame(403, $this->postJson('/api/admin/logout', [], $before)->status);
    }

    public function testWrongCredentialsAreThrottledWithAGrowingDelay(): void
    {
        $this->setUpAccount();
        $this->postJson('/api/admin/logout');

        $first = $this->postJson('/api/admin/login', ['email' => self::ADMIN_EMAIL, 'password' => 'mauvais']);
        self::assertSame(401, $first->status);
        self::assertSame(1, $this->decode($first)['retryAfter']);

        // Even the right password is refused while the delay runs.
        $blocked = $this->postJson('/api/admin/login', ['email' => self::ADMIN_EMAIL, 'password' => self::PASSWORD]);
        self::assertSame(429, $blocked->status);
        self::assertSame('1', $blocked->header('Retry-After'));
        self::assertFalse($this->decode($this->request('GET', '/api/admin/session'))['authenticated']);
    }

    public function testUnknownEmailIsRejectedLikeAWrongPassword(): void
    {
        $this->setUpAccount();
        $this->postJson('/api/admin/logout');

        $response = $this->postJson('/api/admin/login', ['email' => 'quelquun@example.com', 'password' => self::PASSWORD]);

        self::assertSame(401, $response->status);
        self::assertStringStartsWith('E-mail ou mot de passe incorrect.', $this->decode($response)['error']);
    }

    public function testSessionExpiresAfterIdleTimeout(): void
    {
        $this->setUpAccount();
        $this->session->data['admin']['seen'] = time() - \Belprise\Security\AdminAuth::IDLE_TIMEOUT - 1;

        self::assertFalse($this->decode($this->request('GET', '/api/admin/session'))['authenticated']);
    }

    public function testMalformedJsonIsABadRequest(): void
    {
        $response = $this->request('POST', '/api/admin/login', [
            'Content-Type' => 'application/json',
            'X-CSRF-Token' => $this->csrfToken(),
        ], '{not json');

        self::assertSame(400, $response->status);
        self::assertSame('Requête invalide.', $this->decode($response)['error']);
    }

    private function setUpAccount(): \Belprise\Http\Response
    {
        return $this->postJson('/api/admin/setup', [
            'organization' => 'belprod', 'name' => 'Jules', 'email' => self::ADMIN_EMAIL,
            'password' => self::PASSWORD, 'confirmation' => self::PASSWORD,
        ]);
    }
}
