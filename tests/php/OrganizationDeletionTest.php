<?php

declare(strict_types=1);

namespace Belprise\Tests;

use Belprise\Repository\Organizations;
use Belprise\Repository\Users;
use Belprise\Storage;
use Belprise\Tests\Support\LifecycleTestCase;

/**
 * V2 phase 7: deletion of an organization, 30 days after the request
 * (CAHIER_DES_CHARGES §5.10, §5.11, criterion 13), simulated clock.
 */
final class OrganizationDeletionTest extends LifecycleTestCase
{
    /** @var array<string, mixed> */
    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = $this->createClientProjectVideo();
        $this->versionOf((int) $this->fixture['video']['id'], 200_000);
    }

    private function deletionAt(): ?string
    {
        $value = $this->db()->query('SELECT deletion_at FROM organizations WHERE id = 1')->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }

    public function testAnAdministratorRequestsWithNameAndPasswordThenCancels(): void
    {
        $this->outbox->messages = [];
        self::assertSame('confirm', $this->api('POST', '/api/admin/organization/deletion', ['confirm' => 'autre', 'password' => self::ADMIN_PASSWORD], 422)['field']);
        self::assertSame('current', $this->api('POST', '/api/admin/organization/deletion', ['confirm' => 'Belprod', 'password' => 'faux'], 422)['field']);
        self::assertNull($this->deletionAt());
        $this->db()->exec('DELETE FROM login_attempts'); // a wrong password is throttled like the login

        $this->api('POST', '/api/admin/organization/deletion', ['confirm' => 'Belprod', 'password' => self::ADMIN_PASSWORD]);
        self::assertNotNull($this->deletionAt());
        self::assertSame(409, $this->request('POST', '/api/admin/organization/deletion', [
            'X-CSRF-Token' => (string) $this->session->data['csrf'], 'Content-Type' => 'application/json',
        ], json_encode(['confirm' => 'belprod', 'password' => self::ADMIN_PASSWORD]))->status, 'already planned');
        self::assertCount(1, $this->outbox->messages);
        self::assertStringContainsString('suppression de « belprod » programmée le', $this->outbox->messages[0]->subject);
        self::assertStringContainsString('Un administrateur peut l’annuler', $this->outbox->messages[0]->text);

        // Meanwhile: the shared links work, the masters can be downloaded, uploads are blocked.
        $session = $this->decode($this->request('GET', '/api/admin/session'));
        self::assertSame(['admin', 'Jules'], [$session['lifecycle']['deletionBy'], $session['lifecycle']['deletionRequestedBy']]);
        $space = $this->fixture['client']['link']['path'];
        $saved = $this->session->data;
        $this->session->data = [];
        self::assertSame(200, $this->request('GET', $space)->status);
        $this->session->data = $saved;
        copy(__DIR__ . '/../fixtures/media/tc25-01000000.mp4', $this->stagingPath('nouveau.mp4'));
        self::assertStringContainsString('suppression de votre organisation est programmée', (string) $this->attach([
            'name' => 'nouveau.mp4', 'videoId' => $this->fixture['video']['id'], 'meta' => self::META,
        ], 422)['error']);

        $this->api('DELETE', '/api/admin/organization/deletion');
        self::assertNull($this->deletionAt());
        self::assertStringContainsString('annulée', end($this->outbox->messages)->subject);
        $log = array_column($this->api('GET', '/api/admin/organization/log'), 'action');
        self::assertSame(['Suppression de l’organisation annulée', 'Suppression de l’organisation demandée'], array_slice($log, 0, 2));
    }

    public function testOnlyAnAdministratorMayAsk(): void
    {
        $this->db()->exec("UPDATE users SET role = 'editor'");
        self::assertSame(403, $this->request('POST', '/api/admin/organization/deletion', [
            'X-CSRF-Token' => (string) $this->session->data['csrf'], 'Content-Type' => 'application/json',
        ], json_encode(['confirm' => 'belprod', 'password' => self::ADMIN_PASSWORD]))->status);
        self::assertNull($this->deletionAt());
    }

    public function testARequestOfTheOperatorIsCancelledByTheOperatorOnly(): void
    {
        $this->asOperator();
        self::assertSame(422, $this->operator('POST', '/api/superadmin/organizations/1/deletion', ['confirm' => 'x'])->status);
        self::assertSame(200, $this->operator('POST', '/api/superadmin/organizations/1/deletion', ['confirm' => 'belprod'])->status);
        self::assertStringContainsString('Seul l’exploitant de belprise peut annuler', end($this->outbox->messages)->text);
        // Nothing is deleted at once: there is no route for that.
        self::assertSame('1', (string) $this->db()->query('SELECT COUNT(*) FROM organizations')->fetchColumn());

        self::assertSame(200, $this->asAdmin()->status, 'the organization keeps its access');
        $refused = $this->request('DELETE', '/api/admin/organization/deletion', ['X-CSRF-Token' => (string) $this->session->data['csrf']]);
        self::assertSame(403, $refused->status);
        self::assertNotNull($this->deletionAt());

        $this->asOperator();
        self::assertSame(200, $this->operator('DELETE', '/api/superadmin/organizations/1/deletion')->status);
        self::assertNull($this->deletionAt());
        self::assertSame('Suppression annulée', $this->decode($this->request('GET', '/api/superadmin/organizations'))['log'][0]['action']);
    }

    public function testAtJ30EverythingOfThatOrganizationGoesAndNothingElse(): void
    {
        // belprod: a collection with a logo, a client logo, an old import folder (V1/2.0), a pending invitation.
        $collection = $this->api('POST', '/api/admin/collections', ['title' => 'Sélection'], 201);
        $this->api('PATCH', '/api/admin/collections/' . $collection['id'], ['videoIds' => [(int) $this->fixture['video']['id']]]);
        $db = $this->db();
        foreach (['clients' => $this->fixture['client']['id'], 'collections' => $collection['id']] as $table => $id) {
            file_put_contents($this->tmp . "/data/logos/{$table}-{$id}.png", 'logo');
            $db->prepare("UPDATE {$table} SET logo = ? WHERE id = ?")->execute(["logos/{$table}-{$id}.png", $id]);
        }
        mkdir($this->tmp . '/data/inbox/1', 0770, true);
        file_put_contents($this->tmp . '/data/inbox/1/en-attente.mp4', 'master');

        // Studio Nord, another organization, with its own client and files.
        $nord = (new Organizations($db))->create('Studio Nord');
        (new Users($db))->create($nord, 'nora@studio-nord.fr', 'Nora', password_hash('mot-de-passe-de-nora', PASSWORD_ARGON2ID), 'admin');
        $db->prepare("INSERT INTO clients (name, organization_id) VALUES ('Client du Nord', ?)")->execute([$nord]);
        $nordClient = (int) $db->lastInsertId();
        mkdir($this->tmp . '/data/' . Storage::clientMediaDir($nordClient) . '/1001', 0770, true);
        file_put_contents($this->tmp . '/data/' . Storage::clientMediaDir($nordClient) . '/1001/master.mp4', 'nord');
        mkdir($this->tmp . "/data/inbox/{$nord}", 0770, true);
        file_put_contents($this->tmp . "/data/inbox/{$nord}/nord.mp4", 'nord');

        $this->asOperator();
        $this->operator('POST', '/api/superadmin/organizations/1/deletion', ['confirm' => 'belprod']);
        $j0 = (string) $this->db()->query('SELECT deletion_requested_at FROM organizations WHERE id = 1')->fetchColumn();
        self::assertSame(self::at($j0, '+30 days')->format('Y-m-d H:i:s'), $this->deletionAt());
        $deletion = $this->app->services()->organizationDeletion();
        $this->outbox->messages = [];

        self::assertSame([], $deletion->run(self::at($j0, '+22 days'), false));
        self::assertSame(['Rappel J-7 (suppression)'], array_column($deletion->run(self::at($j0, '+23 days'), false), 'event'));
        self::assertSame(['Rappel J-1 (suppression)'], array_column($deletion->run(self::at($j0, '+29 days'), false), 'event'));
        self::assertSame([], $deletion->run(self::at($j0, '+29 days 12 hours'), false));
        self::assertCount(2, $this->outbox->messages);

        // Dry run at J+30: the list, nothing deleted.
        $dry = $deletion->run(self::at($j0, '+30 days'), true);
        self::assertSame('1 utilisateur(s), 1 client(s), 1 vidéo(s), 1 collection(s)', $dry[0]['details']);
        self::assertSame('2', (string) $this->db()->query('SELECT COUNT(*) FROM organizations')->fetchColumn());

        $clientDir = $this->tmp . '/data/' . Storage::clientMediaDir((int) $this->fixture['client']['id']);
        self::assertDirectoryExists($clientDir);
        $deletion->run(self::at($j0, '+30 days'), false);

        $db = $this->db();
        self::assertSame([$nord], array_map('intval', $db->query('SELECT id FROM organizations')->fetchAll(\PDO::FETCH_COLUMN)));
        foreach (['clients', 'projects', 'videos', 'versions', 'collections', 'users', 'invitations', 'audit_log', 'activity'] as $table) {
            $count = $table === 'clients' || $table === 'users'
                ? "SELECT COUNT(*) FROM {$table} WHERE organization_id <> {$nord}"
                : "SELECT COUNT(*) FROM {$table}" . (in_array($table, ['collections', 'invitations', 'audit_log', 'activity'], true) ? " WHERE organization_id <> {$nord}" : '');
            self::assertSame('0', (string) $db->query($count)->fetchColumn(), $table);
        }
        self::assertSame('0', (string) $db->query('SELECT COUNT(*) FROM links')->fetchColumn());
        self::assertDirectoryDoesNotExist($clientDir);
        self::assertFileDoesNotExist($this->tmp . '/data/logos/clients-' . $this->fixture['client']['id'] . '.png');
        self::assertFileDoesNotExist($this->tmp . '/data/logos/collections-' . $collection['id'] . '.png');
        self::assertDirectoryDoesNotExist($this->tmp . '/data/inbox/1');

        // Studio Nord is untouched.
        self::assertSame('1', (string) $db->query("SELECT COUNT(*) FROM clients WHERE organization_id = {$nord}")->fetchColumn());
        self::assertFileExists($this->tmp . '/data/' . Storage::clientMediaDir($nordClient) . '/1001/master.mp4');
        self::assertFileExists($this->tmp . "/data/inbox/{$nord}/nord.mp4");

        // The trace, the confirmation e-mail, and no more account.
        $trace = $db->query("SELECT organization_name, details FROM platform_log WHERE action = 'Organisation supprimée'")->fetch();
        self::assertSame(['belprod', '1 utilisateur(s), 1 client(s), 1 vidéo(s), 1 collection(s)'], [$trace['organization_name'], $trace['details']]);
        $last = end($this->outbox->messages);
        self::assertSame([self::ADMIN_EMAIL, true], [$last->to, str_contains($last->subject, 'a été supprimée')]);
        self::assertSame(401, $this->asAdmin()->status);
        self::assertSame([], $deletion->run(self::at($j0, '+31 days'), false));
    }
}
