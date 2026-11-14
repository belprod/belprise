<?php

declare(strict_types=1);

namespace Belprise\Tests;

use Belprise\Repository\Users;
use Belprise\Security\Totp;
use Belprise\Tests\Support\LifecycleTestCase;

/**
 * Version 3.0 (CAHIER_DES_CHARGES §5.7, §5.10, criterion 24): "Mot de passe
 * oublié ?" in self-service, recovery codes of the two-factor
 * authentication, and two-factor authentication required by the organization.
 */
final class AccountRecoveryTest extends LifecycleTestCase
{
    private const LEA_PASSWORD = 'mot-de-passe-de-lea';

    private int $lea;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lea = (new Users($this->db()))->create(1, 'lea@belprod.test', 'Léa Martin', password_hash(self::LEA_PASSWORD, PASSWORD_ARGON2ID), 'editor');
    }

    private function forgot(string $email): \Belprise\Http\Response
    {
        return $this->postJson('/api/admin/forgot', ['email' => $email]);
    }

    /** Two-factor authentication turned on for the signed-in user; returns [secret, recovery codes]. */
    private function enableTotp(string $password): array
    {
        $setup = $this->api('POST', '/api/admin/totp/setup');
        $state = $this->api('POST', '/api/admin/totp/enable', ['code' => Totp::code($setup['secret'], time()), 'password' => $password]);

        return [$setup['secret'], $state['recoveryCodes']];
    }

    // ---- "Mot de passe oublié ?" -------------------------------------------------------

    public function testTheAnswerIsTheSameWhetherTheAddressExistsOrNot(): void
    {
        $this->session->data = [];
        $this->outbox->messages = [];
        $known = $this->forgot('Lea@Belprod.test');
        $unknown = $this->forgot('personne@belprod.test');

        self::assertSame([200, 200], [$known->status, $unknown->status]);
        self::assertSame($known->body, $unknown->body);
        self::assertCount(1, $this->outbox->messages, 'only the existing account gets an e-mail');
        $mail = $this->outbox->messages[0];
        self::assertSame(['lea@belprod.test', 'Belprise : choisissez un nouveau mot de passe'], [$mail->to, $mail->subject]);
        self::assertStringContainsString('depuis l’écran de connexion', $mail->text);
        self::assertSame(422, $this->forgot('pas-une-adresse')->status);
    }

    public function testTheLinkWorksOnceForAnHourAndKeepsTheTwoFactorAuthentication(): void
    {
        $this->asAdmin('lea@belprod.test', self::LEA_PASSWORD);
        [$secret] = $this->enableTotp(self::LEA_PASSWORD);

        $this->session->data = [];
        $this->outbox->messages = [];
        $this->forgot('lea@belprod.test');
        self::assertSame(1, preg_match('#/reinitialisation/([A-Za-z0-9_-]{43})#', end($this->outbox->messages)->text, $m));
        $this->session->data = [];
        self::assertSame('password', $this->decode($this->request('GET', '/api/admin/reset/' . $m[1]))['kind'], 'a password link, never a two-factor one');
        self::assertSame(200, $this->postJson('/api/admin/reset/' . $m[1], ['password' => 'nouveau-mot-de-passe-lea', 'confirmation' => 'nouveau-mot-de-passe-lea'])->status);
        self::assertSame(404, $this->postJson('/api/admin/reset/' . $m[1], ['password' => 'encore-un-autre-mdp', 'confirmation' => 'encore-un-autre-mdp'])->status, 'once');

        // The new password opens the second step only: the code is still asked.
        $step = $this->decode($this->asAdmin('lea@belprod.test', 'nouveau-mot-de-passe-lea'));
        self::assertSame([false, true], [$step['authenticated'], $step['totpRequired']]);
        self::assertNotNull($this->db()->query("SELECT totp_secret FROM users WHERE id = {$this->lea}")->fetchColumn());
        self::assertTrue($this->decode($this->postJson('/api/admin/login/totp', ['code' => Totp::code($secret, time() + 30)]))['authenticated']);
    }

    public function testRequestsAreSlowedDownPerAddressSilentlyAndPerNetwork(): void
    {
        $this->session->data = [];
        $this->outbox->messages = [];
        // The same address: 3 e-mails an hour; then the same answer, but no e-mail.
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(200, $this->forgot('lea@belprod.test')->status);
        }
        self::assertCount(3, $this->outbox->messages);

        // The same network: 10 requests an hour, whatever the address (the 429 says nothing about it).
        $statuses = [];
        for ($i = 0; $i < 7; $i++) {
            $statuses[] = $this->forgot("inconnu{$i}@belprod.test")->status;
        }
        self::assertSame([200, 200, 200, 200, 200, 429, 429], $statuses);
    }

    // ---- Recovery codes ----------------------------------------------------------------

    public function testTenRecoveryCodesEachOpeningOneSession(): void
    {
        $this->asAdmin('lea@belprod.test', self::LEA_PASSWORD);
        [, $codes] = $this->enableTotp(self::LEA_PASSWORD);
        self::assertCount(10, $codes);
        self::assertCount(10, array_unique($codes));
        foreach ($codes as $code) {
            self::assertMatchesRegularExpression('/^[a-hj-km-np-z2-9]{5}-[a-hj-km-np-z2-9]{5}$/', $code);
        }
        self::assertSame(0, (int) $this->db()->query("SELECT COUNT(*) FROM recovery_codes WHERE code_hash LIKE '%-%'")->fetchColumn(), 'stored hashed');
        self::assertSame(10, $this->decode($this->request('GET', '/api/admin/session'))['recoveryCodesLeft']);

        // Phone lost: a recovery code in place of the authenticator code, once.
        $this->asAdmin('lea@belprod.test', self::LEA_PASSWORD);
        self::assertTrue($this->decode($this->postJson('/api/admin/login/totp', ['code' => strtoupper($codes[0])]))['authenticated'], 'case and dash do not matter');
        self::assertSame(9, $this->decode($this->request('GET', '/api/admin/session'))['recoveryCodesLeft']);
        $this->asAdmin('lea@belprod.test', self::LEA_PASSWORD);
        self::assertSame(401, $this->postJson('/api/admin/login/totp', ['code' => $codes[0]])->status, 'single use');
        $this->db()->exec('DELETE FROM login_attempts');
        self::assertSame(401, $this->postJson('/api/admin/login/totp', ['code' => 'abcde-fghjk'])->status);
        $this->db()->exec('DELETE FROM login_attempts');

        // New codes after the password: the old ones stop working.
        self::assertTrue($this->decode($this->postJson('/api/admin/login/totp', ['code' => str_replace('-', '', $codes[1])]))['authenticated']);
        self::assertSame(422, $this->postJson('/api/admin/totp/recovery-codes', ['password' => 'faux'])->status);
        $this->db()->exec('DELETE FROM login_attempts');
        $renewed = $this->decode($this->postJson('/api/admin/totp/recovery-codes', ['password' => self::LEA_PASSWORD]))['recoveryCodes'];
        self::assertCount(10, $renewed);
        $this->asAdmin('lea@belprod.test', self::LEA_PASSWORD);
        self::assertSame(401, $this->postJson('/api/admin/login/totp', ['code' => $codes[2]])->status);
        $this->db()->exec('DELETE FROM login_attempts');
        self::assertTrue($this->decode($this->postJson('/api/admin/login/totp', ['code' => $renewed[0]]))['authenticated']);

        // Turned off: the codes go with it.
        $this->api('POST', '/api/admin/totp/disable', ['password' => self::LEA_PASSWORD]);
        self::assertSame(0, (int) $this->db()->query("SELECT COUNT(*) FROM recovery_codes WHERE user_id = {$this->lea}")->fetchColumn());
    }

    // ---- Two-factor authentication required by the organization -----------------------

    public function testTheOrganizationCanRequireTwoFactorAuthentication(): void
    {
        $this->asAdmin();
        self::assertTrue($this->api('PATCH', '/api/admin/organization', ['totpRequired' => true])['organization']['totpRequired']);

        // Léa has not turned it on: only the set-up is open to her.
        $this->asAdmin('lea@belprod.test', self::LEA_PASSWORD);
        self::assertTrue($this->decode($this->request('GET', '/api/admin/session'))['totpSetupRequired']);
        $blocked = $this->request('GET', '/api/admin/clients');
        self::assertSame(403, $blocked->status);
        self::assertTrue($this->decode($blocked)['totpSetupRequired']);
        $this->enableTotp(self::LEA_PASSWORD);
        self::assertFalse($this->decode($this->request('GET', '/api/admin/session'))['totpSetupRequired']);
        self::assertSame(200, $this->request('GET', '/api/admin/clients')->status);
        self::assertSame(422, $this->postJson('/api/admin/totp/disable', ['password' => self::LEA_PASSWORD])->status, 'cannot be turned off while required');
    }
}
