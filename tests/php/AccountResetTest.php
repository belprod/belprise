<?php

declare(strict_types=1);

namespace Belprise\Tests;

use Belprise\Repository\Organizations;
use Belprise\Repository\Users;
use Belprise\Tests\Support\LifecycleTestCase;

/**
 * Version 2.1: password and two-factor reset by e-mail (CAHIER_DES_CHARGES §5.10),
 * asked by the organization's administrator or by the operator.
 */
final class AccountResetTest extends LifecycleTestCase
{
    private const LEA_PASSWORD = 'mot-de-passe-de-lea';

    private int $lea;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lea = (new Users($this->db()))->create(1, 'lea@belprod.test', 'Léa Martin', password_hash(self::LEA_PASSWORD, PASSWORD_ARGON2ID), 'editor');
    }

    private function link(): string
    {
        $mail = end($this->outbox->messages);
        self::assertSame(1, preg_match('#/reinitialisation/([A-Za-z0-9_-]{43})#', $mail->text, $m), $mail->text);

        return $m[1];
    }

    /** @param array<string, mixed> $data */
    private function complete(string $token, array $data): \Belprise\Http\Response
    {
        $this->session->data = [];

        return $this->postJson('/api/admin/reset/' . $token, $data);
    }

    public function testTheAdministratorResetsAMembersPasswordByEmail(): void
    {
        $this->outbox->messages = [];
        $answer = $this->api('POST', "/api/admin/users/{$this->lea}/reset", ['kind' => 'password']);
        self::assertSame([true, 'lea@belprod.test'], [$answer['sent'], $answer['email']]);
        self::assertArrayNotHasKey('link', $answer, 'whoever asks never sees the link');

        $mail = $this->outbox->messages[0];
        self::assertSame(['lea@belprod.test', 'Belprise : choisissez un nouveau mot de passe'], [$mail->to, $mail->subject]);
        self::assertStringContainsString('L’administrateur de votre organisation (Jules) a demandé', $mail->text);
        self::assertStringContainsString('valable 1 heure', $mail->text);
        $token = $this->link();

        $this->session->data = [];
        self::assertSame(['password', 'Léa Martin', 'belprod'], array_values(array_intersect_key(
            $this->decode($this->request('GET', '/api/admin/reset/' . $token)),
            ['kind' => 1, 'name' => 1, 'organization' => 1],
        )));
        self::assertSame(422, $this->complete($token, ['password' => 'court', 'confirmation' => 'court'])->status);
        self::assertSame(200, $this->complete($token, ['password' => 'nouveau-mot-de-passe-lea', 'confirmation' => 'nouveau-mot-de-passe-lea'])->status);

        self::assertSame(401, $this->asAdmin('lea@belprod.test', self::LEA_PASSWORD)->status, 'the old password is gone');
        self::assertSame(200, $this->asAdmin('lea@belprod.test', 'nouveau-mot-de-passe-lea')->status);
        self::assertSame(404, $this->complete($token, ['password' => 'encore-un-autre-mdp', 'confirmation' => 'encore-un-autre-mdp'])->status, 'once');
        $log = array_column($this->db()->query('SELECT action FROM audit_log ORDER BY id')->fetchAll(), 'action');
        self::assertSame(['Réinitialisation du mot de passe demandée', 'Mot de passe réinitialisé'], array_slice($log, -2));
    }

    public function testALinkExpiresAfterAnHourAndANewOneReplacesIt(): void
    {
        $this->api('POST', "/api/admin/users/{$this->lea}/reset", ['kind' => 'password']);
        $first = $this->link();
        $this->asAdmin();
        $this->api('POST', "/api/admin/users/{$this->lea}/reset", ['kind' => 'password']);
        $second = $this->link();
        self::assertSame(404, $this->request('GET', '/api/admin/reset/' . $first)->status, 'the new link replaces the old one');

        $this->db()->exec("UPDATE account_resets SET expires_at = datetime('now', '-1 second')");
        self::assertSame(404, $this->request('GET', '/api/admin/reset/' . $second)->status);
        self::assertSame(404, $this->request('GET', '/api/admin/reset/' . str_repeat('a', 43))->status);
    }

    public function testTwoFactorResetNeedsThePassword(): void
    {
        self::assertSame('kind', $this->api('POST', "/api/admin/users/{$this->lea}/reset", ['kind' => 'totp'], 422)['field'], 'not enabled');
        $this->db()->exec("UPDATE users SET totp_secret = 'sealed' WHERE id = {$this->lea}");
        $this->api('POST', "/api/admin/users/{$this->lea}/reset", ['kind' => 'totp']);
        self::assertSame('Belprise : réinitialisez votre double authentification', end($this->outbox->messages)->subject);
        $token = $this->link();

        self::assertSame('password', $this->decode($this->complete($token, ['password' => 'faux-mot-de-passe']))['field']);
        $this->db()->exec('DELETE FROM login_attempts');
        self::assertSame(200, $this->complete($token, ['password' => self::LEA_PASSWORD])->status);
        self::assertNull($this->db()->query("SELECT totp_secret FROM users WHERE id = {$this->lea}")->fetchColumn());
    }

    public function testOnlyAnAdministratorOfTheSameOrganizationAsks(): void
    {
        $me = (int) $this->db()->query("SELECT id FROM users WHERE email = '" . self::ADMIN_EMAIL . "'")->fetchColumn();
        self::assertStringContainsString('Mon compte', $this->api('POST', "/api/admin/users/{$me}/reset", ['kind' => 'password'], 422)['error']);
        self::assertSame('kind', $this->api('POST', "/api/admin/users/{$this->lea}/reset", ['kind' => 'tout'], 422)['field']);

        $db = $this->db();
        $nord = (new Organizations($db))->create('Studio Nord');
        $nora = (new Users($db))->create($nord, 'nora@studio-nord.fr', 'Nora', password_hash('mot-de-passe-de-nora', PASSWORD_ARGON2ID), 'admin');
        self::assertSame(404, $this->request('POST', "/api/admin/users/{$nora}/reset", [
            'X-CSRF-Token' => (string) $this->session->data['csrf'], 'Content-Type' => 'application/json',
        ], '{"kind":"password"}')->status, 'another organization');

        $this->asAdmin('lea@belprod.test', self::LEA_PASSWORD);
        self::assertSame(403, $this->request('POST', "/api/admin/users/{$me}/reset", [
            'X-CSRF-Token' => (string) $this->session->data['csrf'], 'Content-Type' => 'application/json',
        ], '{"kind":"password"}')->status, 'an editor');
    }

    public function testTheOperatorResetsAnAdministratorNeverAnotherMember(): void
    {
        $me = (int) $this->db()->query("SELECT id FROM users WHERE email = '" . self::ADMIN_EMAIL . "'")->fetchColumn();
        $this->asOperator();
        $list = $this->decode($this->request('GET', '/api/superadmin/organizations'));
        self::assertSame([['Jules', self::ADMIN_EMAIL]], array_map(static fn ($a) => [$a['name'], $a['email']], $list['organizations'][0]['admins']));

        $this->outbox->messages = [];
        $answer = $this->operator('POST', "/api/superadmin/organizations/1/users/{$me}/reset", ['kind' => 'password']);
        self::assertSame(200, $answer->status);
        self::assertArrayNotHasKey('link', $this->decode($answer));
        $mail = $this->outbox->messages[0];
        self::assertSame(self::ADMIN_EMAIL, $mail->to, 'the link goes to the administrator, not to the operator');
        self::assertStringContainsString('L’exploitant de belprise a demandé', $mail->text);
        self::assertSame(404, $this->operator('POST', "/api/superadmin/organizations/1/users/{$this->lea}/reset", ['kind' => 'password'])->status, 'not an administrator');
        self::assertSame(404, $this->operator('POST', "/api/superadmin/organizations/2/users/{$me}/reset", ['kind' => 'password'])->status);
        self::assertSame('Réinitialisation demandée', $this->decode($this->request('GET', '/api/superadmin/organizations'))['log'][0]['action']);
        // The organization sees it in its journal.
        self::assertSame('Exploitant belprise', $this->db()->query('SELECT actor FROM audit_log ORDER BY id DESC')->fetchColumn());
    }
}
