<?php

declare(strict_types=1);

namespace Belprise\Tests;

use Belprise\Demo\Demo;
use Belprise\Demo\DemoSnapshot;
use Belprise\Mail\NullMailer;
use Belprise\Repository\Organizations;
use Belprise\Repository\Users;
use Belprise\Tests\Support\AppTestCase;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Public demo (CAHIER_DES_CHARGES §5.16, criterion 26): shared accounts, no
 * file sent whatever the call, no e-mail, no operator pages, and a reset to
 * the reference state every 6 hours (simulated clock).
 */
final class DemoModeTest extends AppTestCase
{
    private int $org;

    protected function extraConfig(): array
    {
        return ['demo' => true];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $db = $this->app->services()->db(); // with the migrations
        $this->org = (new Organizations($db))->create('Studio Lucarne');
        $users = new Users($db);
        $hash = password_hash(Demo::PASSWORD, PASSWORD_ARGON2ID);
        foreach (Demo::ACCOUNTS as $account) {
            $users->create($this->org, $account['email'], $account['name'], $hash, $account['role']);
        }
    }

    private function signIn(string $role = 'admin'): void
    {
        $this->session->data = [];
        $this->db()->exec('DELETE FROM login_attempts');
        $email = Demo::ACCOUNTS[array_search($role, array_column(Demo::ACCOUNTS, 'role'), true)]['email'];
        $state = $this->decode($this->postJson('/api/admin/login', ['email' => $email, 'password' => Demo::PASSWORD]));
        self::assertTrue($state['authenticated']);
    }

    private static function at(string $paris): int
    {
        return (new DateTimeImmutable($paris, new DateTimeZone('Europe/Paris')))->getTimestamp();
    }

    public function testTheSignInScreenShowsTheAccountsAndTheNextReset(): void
    {
        $state = $this->decode($this->request('GET', '/api/admin/session'));

        self::assertFalse($state['authenticated']);
        self::assertSame(['admin@demo.belprise.fr', 'editeur@demo.belprise.fr', 'lecteur@demo.belprise.fr'], array_column($state['demo']['accounts'], 'email'));
        self::assertSame([Demo::PASSWORD], array_values(array_unique(array_column($state['demo']['accounts'], 'password'))));
        self::assertStringContainsString('CC BY', $state['demo']['credit']);
        self::assertSame(gmdate('Y-m-d\TH:i:s\Z', Demo::nextReset(time())), $state['demo']['resetAt']);
    }

    public function testNoFileCanBeSentWhateverTheCall(): void
    {
        $this->signIn();
        $video = $this->createClientProjectVideo();
        $logo = ['logo' => ['name' => 'logo.png', 'tmp_name' => __FILE__, 'size' => 10, 'error' => 0]];
        $calls = [
            ['POST', '/api/admin/uploads'],
            ['PUT', '/api/admin/uploads/1'],
            ['POST', '/api/admin/uploads/1/complete'],
            ['POST', '/api/admin/versions/1/images'],
            ['POST', '/api/admin/versions/1/hls'],
            ['DELETE', '/api/admin/versions/1/hls'],
            ['POST', '/api/admin/versions/1/encode'],
            ['POST', '/api/admin/organization/logo'],
            ['POST', '/api/admin/clients/' . $video['client']['id'] . '/logo'],
            ['POST', '/api/admin/collections/1/logo'],
        ];
        foreach ($calls as [$method, $path]) {
            $response = $this->request($method, $path, ['X-CSRF-Token' => $this->csrfToken(), 'Content-Type' => 'application/json'], '{"name":"film.mov","size":1000,"videoId":' . $video['video']['id'] . '}', [], $logo);
            self::assertSame(403, $response->status, $method . ' ' . $path);
            self::assertSame(['error' => 'Dans la démo, aucun fichier ne peut être envoyé.', 'demo' => true], $this->decode($response));
        }
        self::assertSame(0, (int) $this->db()->query('SELECT COUNT(*) FROM uploads')->fetchColumn());
        self::assertSame([], glob($this->tmp . '/data/logos/*') ?: []);
        // Even signed out: refused before anything else.
        $this->session->data = [];
        self::assertSame(403, $this->request('POST', '/api/admin/uploads')->status);
    }

    public function testTheSharedAccountsCannotBeChanged(): void
    {
        $this->signIn();
        $editor = (int) $this->db()->query("SELECT id FROM users WHERE role = 'editor'")->fetchColumn();
        $calls = [
            ['POST', '/api/admin/password', ['current' => Demo::PASSWORD, 'password' => 'un-autre-mot-de-passe']],
            ['PATCH', '/api/admin/account', ['name' => 'Pirate', 'email' => 'pirate@exemple.test', 'password' => Demo::PASSWORD]],
            ['POST', '/api/admin/totp/setup', []],
            ['POST', '/api/admin/totp/enable', ['code' => '123456']],
            ['POST', '/api/admin/totp/disable', []],
            ['POST', '/api/admin/totp/recovery-codes', []],
            ['PATCH', '/api/admin/users/' . $editor, ['role' => 'viewer']],
            ['DELETE', '/api/admin/users/' . $editor, null],
            ['POST', '/api/admin/users/' . $editor . '/reset', ['kind' => 'password']],
            ['POST', '/api/admin/users/' . $editor . '/transfer', ['password' => Demo::PASSWORD]],
            ['PATCH', '/api/admin/organization', ['totpRequired' => true]],
            ['POST', '/api/admin/organization/deletion', ['password' => Demo::PASSWORD]],
            ['POST', '/api/admin/invitations', ['email' => 'nouveau@exemple.test', 'role' => 'editor']],
        ];
        foreach ($calls as [$method, $path, $data]) {
            self::assertTrue($this->api($method, $path, $data, 403)['demo'], $method . ' ' . $path);
        }
        $this->session->data = [];
        self::assertSame(403, $this->postJson('/api/admin/forgot', ['email' => 'admin@demo.belprise.fr'])->status);
        self::assertSame(403, $this->postJson('/api/admin/setup', [])->status);

        $rows = $this->db()->query('SELECT email, role, totp_secret FROM users ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame(array_column(Demo::ACCOUNTS, 'email'), array_column($rows, 'email'));
        self::assertSame(['admin', 'editor', 'viewer'], array_column($rows, 'role'));
        self::assertSame([null, null, null], array_column($rows, 'totp_secret'));
        self::assertSame(0, (int) $this->db()->query('SELECT totp_required FROM organizations')->fetchColumn());
        self::assertSame([], $this->outbox->messages);
        self::assertInstanceOf(NullMailer::class, $this->app->services()->mailer());
    }

    public function testEverythingElseWorks(): void
    {
        $this->signIn('editor');
        $ids = $this->createClientProjectVideo();
        $this->api('PATCH', '/api/admin/projects/' . $ids['project']['id'], ['name' => 'Projet renommé']);
        $this->api('POST', '/api/admin/collections', ['title' => 'Sélection'], 201);
        $this->signIn('admin');
        $this->api('PATCH', '/api/admin/organization', ['name' => 'Studio renommé']);
        self::assertSame('Studio renommé', $this->db()->query('SELECT name FROM organizations')->fetchColumn());
    }

    public function testTheDemoAddressLeadsToTheSignInScreen(): void
    {
        $response = $this->request('GET', '/');
        self::assertSame(303, $response->status);
        self::assertSame('/admin', $response->headers()['Location'] ?? null);
    }

    public function testTheOperatorPagesAreClosed(): void
    {
        foreach (['/superadmin', '/api/superadmin/session'] as $path) {
            self::assertSame(404, $this->request('GET', $path)->status, $path);
        }
        self::assertSame(404, $this->postJson('/api/superadmin/login', ['email' => 'x@exemple.test', 'password' => 'x'])->status);
    }

    public function testTheResetComesEverySixHoursInParisTime(): void
    {
        self::assertSame(self::at('2026-11-15 12:00'), Demo::slotStart(self::at('2026-11-15 17:59')));
        self::assertSame(self::at('2026-11-15 18:00'), Demo::nextReset(self::at('2026-11-15 17:59')));
        self::assertSame(self::at('2026-11-16 00:00'), Demo::nextReset(self::at('2026-11-15 18:00')));
        // Summer time: still at 0 h, 6 h, 12 h and 18 h in Paris.
        self::assertSame(self::at('2027-07-01 06:00'), Demo::nextReset(self::at('2027-07-01 03:10')));
    }

    public function testTheResetPutsTheReferenceBackOncePerSlot(): void
    {
        $data = $this->tmp . '/data';
        $db = $this->db();
        $this->signIn();
        $ids = $this->createClientProjectVideo();
        $media = $data . '/media/' . $ids['client']['id'] . '/' . $ids['video']['number'] . '/v1';
        mkdir($media, 0770, true);
        file_put_contents($media . '/master.mp4', 'master');
        file_put_contents($data . '/logos/1-abc.svg', '<svg/>');
        $db->exec("UPDATE clients SET created_at = '2026-11-15 08:00:00'");

        $snapshot = new DemoSnapshot($data, dirname(__DIR__, 2) . '/app/migrations');
        $t0 = self::at('2026-11-15 10:30');
        self::assertFalse($snapshot->due($t0), 'no snapshot yet');
        $snapshot->take($db, $t0);
        self::assertFalse($snapshot->due(self::at('2026-11-15 11:59')), 'same slot');

        // The visitors change everything.
        $db->exec("UPDATE clients SET name = 'Effacé'");
        $db->exec("INSERT INTO clients (organization_id, name) VALUES ({$this->org}, 'Ajouté')");
        $db->exec('DELETE FROM projects');
        unlink($media . '/master.mp4');
        file_put_contents($data . '/logos/2-stray.svg', '<svg/>');
        mkdir($data . '/uploads/x');
        file_put_contents($data . '/uploads/x/9.part', 'part');

        $t1 = self::at('2026-11-15 12:05');
        self::assertTrue($snapshot->due($t1));
        $done = $snapshot->restore($db, $t1);
        self::assertSame([1, 1], [$done['copied'], $done['deleted']]);
        self::assertFalse($snapshot->due(self::at('2026-11-15 17:59')));
        self::assertTrue($snapshot->due(self::at('2026-11-15 18:00')));

        self::assertSame([['Maison Aurore']], $db->query('SELECT name FROM clients')->fetchAll(\PDO::FETCH_NUM));
        self::assertSame(1, (int) $db->query('SELECT COUNT(*) FROM projects')->fetchColumn());
        self::assertSame('master', file_get_contents($media . '/master.mp4'));
        self::assertFileDoesNotExist($data . '/logos/2-stray.svg');
        self::assertFileExists($data . '/logos/1-abc.svg');
        self::assertSame([], glob($data . '/uploads/*') ?: []);
        // Dates move forward by the time since the snapshot (1 h 35), so the demo looks recent.
        self::assertSame('2026-11-15 09:35:00', $db->query('SELECT created_at FROM clients')->fetchColumn());
        // The application still works on the restored base.
        $this->signIn();
        self::assertSame('Maison Aurore', $this->api('GET', '/api/admin/clients')[0]['name'] ?? null);
    }
}
