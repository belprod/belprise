<?php

declare(strict_types=1);

namespace Belprise\Tests;

use Belprise\Storage;
use Belprise\Tests\Support\LifecycleTestCase;

/**
 * Downgrade and purge at J+30 (CAHIER_DES_CHARGES §5.12, criterion 16),
 * with a simulated clock: J0, J+23, J+29, J+30. Since 3.0, the operator sets
 * a free quota (gigabytes, or no limit).
 */
final class DowngradeTest extends LifecycleTestCase
{
    private const GB = 1_000_000_000;

    /** @var array<string, array<string, mixed>> */
    private array $videos = [];

    /** @var array<string, mixed> */
    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db()->exec('UPDATE organizations SET quota_bytes = 1000000000000');
        $this->fixture = $this->createClientProjectVideo();
        $project = $this->fixture['project']['id'];
        // 7 Go in all; a quota of 5 Go: 2 Go to free.
        foreach ([
            'archivee' => [1.5, '2025-09-01 10:00:00', true],   // archived: goes first, whatever its activity
            'ancienne' => [2.0, '2024-01-01 10:00:00', false],  // oldest activity among the others
            'moyenne' => [2.0, '2025-06-01 10:00:00', false],
            'recente' => [1.5, '2026-01-01 10:00:00', false],
        ] as $title => [$gb, $activity, $archived]) {
            $video = $this->api('POST', '/api/admin/videos', ['projectId' => $project, 'title' => $title], 201);
            $this->versionOf((int) $video['id'], (int) ($gb * self::GB));
            $db = $this->db();
            $db->prepare('UPDATE activity SET created_at = ? WHERE video_id = ?')->execute([$activity, $video['id']]);
            if ($archived) {
                $db->prepare("UPDATE videos SET archived_at = '2025-10-01 10:00:00' WHERE id = ?")->execute([$video['id']]);
            }
            $this->videos[$title] = $video;
        }
    }

    private function downgrade(?int $gigabytes = 5): array
    {
        $this->asOperator();
        $response = $this->operator('PATCH', '/api/superadmin/organizations/1', ['quotaGb' => $gigabytes]);
        self::assertSame(200, $response->status, $response->body);

        return $this->decode($response);
    }

    /** @return list<string> */
    private function titles(): array
    {
        return array_map('strval', $this->db()->query('SELECT title FROM videos ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function dir(string $title): string
    {
        return $this->tmp . '/data/' . Storage::videoMediaDir((int) $this->fixture['client']['id'], (int) $this->videos[$title]['number']);
    }

    private function attachBlocked(): string
    {
        $name = 'nouveau.mp4';
        copy(__DIR__ . '/../fixtures/media/tc25-01000000.mp4', $this->stagingPath($name));

        return (string) $this->attach(['name' => $name, 'videoId' => $this->fixture['video']['id'], 'meta' => self::META], 422)['error'];
    }

    public function testTheWholeTimeline(): void
    {
        $this->outbox->messages = [];
        // J0: the operator lowers the quota to 5 Go.
        $result = $this->downgrade();
        self::assertSame('downgraded', $result['result']);
        $row = $this->db()->query('SELECT downgraded_at, purge_at, downgraded_from, quota_bytes FROM organizations WHERE id = 1')->fetch();
        self::assertSame(['1 To', 5 * self::GB], [$row['downgraded_from'], (int) $row['quota_bytes']]);
        $j0 = (string) $row['downgraded_at'];
        self::assertSame(self::at($j0, '+30 days')->format('Y-m-d H:i:s'), $row['purge_at']);

        self::assertCount(1, $this->outbox->messages, 'immediate e-mail');
        $mail = $this->outbox->messages[0];
        self::assertSame(self::ADMIN_EMAIL, $mail->to);
        self::assertStringContainsString('à libérer avant le', $mail->subject);
        foreach (['1 To', '5,0 Go', 'il faut libérer', 'Date de purge', 'Télécharger le master', 'd’abord les vidéos archivées'] as $part) {
            self::assertStringContainsString($part, $mail->text);
        }
        self::assertStringNotContainsString('<img', $mail->text);

        // During the 30 days: uploads blocked, reading and deleting still possible, banner data in the session.
        self::assertSame(200, $this->asAdmin()->status);
        self::assertStringContainsString('abaissé', $this->attachBlocked());
        $session = $this->decode($this->request('GET', '/api/admin/session'));
        self::assertSame(['1 To', true], [$session['lifecycle']['downgradedFrom'], $session['lifecycle']['purgeAt'] !== null]);
        self::assertSame(200, $this->request('GET', '/api/admin/videos/' . $this->videos['recente']['id'])->status);
        $versionId = (int) $this->db()->query('SELECT id FROM versions WHERE video_id = ' . (int) $this->videos['recente']['id'])->fetchColumn();
        $download = $this->request('GET', "/api/admin/versions/{$versionId}/download", ['Range' => 'bytes=0-99']);
        self::assertSame(206, $download->status, 'the masters can still be downloaded');
        self::assertStringContainsString('attachment', (string) $download->header('Content-Disposition'));
        self::assertSame(['archivee', 'ancienne', 'moyenne', 'recente'], array_slice($this->titles(), 1));

        $deleted = fn () => (int) $this->db()->query('SELECT COUNT(*) FROM videos')->fetchColumn();
        $downgrade = $this->app->services()->downgrade();
        $this->outbox->messages = [];

        // J+1 … J+22: nothing.
        self::assertSame([], $downgrade->run(self::at($j0, '+1 day'), false));
        self::assertSame([], $downgrade->run(self::at($j0, '+22 days'), false));
        // J+23: reminder J-7, once.
        self::assertSame(['Rappel J-7'], array_column($downgrade->run(self::at($j0, '+23 days'), false), 'event'));
        self::assertSame([], $downgrade->run(self::at($j0, '+23 days 2 hours'), false));
        // J+29: reminder J-1, once.
        self::assertSame(['Rappel J-1'], array_column($downgrade->run(self::at($j0, '+29 days'), false), 'event'));
        self::assertSame([], $downgrade->run(self::at($j0, '+29 days 23 hours'), false));
        self::assertCount(2, $this->outbox->messages);
        self::assertStringContainsString('rappel', $this->subjects()[0]);
        self::assertStringContainsString('demain', $this->outbox->messages[1]->text);

        // J+30, dry run: the list, nothing deleted.
        $dry = $downgrade->run(self::at($j0, '+30 days'), true);
        self::assertSame('Purge', $dry[0]['event']);
        self::assertStringContainsString('2 vidéo(s)', $dry[0]['details']);
        self::assertSame(5, $deleted());
        self::assertCount(2, $this->outbox->messages, 'no e-mail in a dry run');

        // J+30: the archived video first, then the oldest activity — and nothing more.
        $plan = $downgrade->plan(1);
        self::assertSame(['archivee', 'ancienne'], array_column($plan['videos'], 'title'));
        $downgrade->run(self::at($j0, '+30 days'), false);
        self::assertSame(['Film manifeste', 'moyenne', 'recente'], $this->titles());
        self::assertDirectoryDoesNotExist($this->dir('archivee'));
        self::assertDirectoryDoesNotExist($this->dir('ancienne'));
        self::assertDirectoryExists($this->dir('moyenne'));
        self::assertDirectoryExists($this->dir('recente'));
        self::assertSame('0', (string) $this->db()->query("SELECT COUNT(*) FROM links WHERE type IN ('client', 'view') AND target_id IN ("
            . (int) $this->videos['archivee']['id'] . ',' . (int) $this->videos['ancienne']['id'] . ')')->fetchColumn());

        $usage = $this->app->services()->quota()->usage(1);
        self::assertLessThanOrEqual($usage['quota'], $usage['used'] + $usage['reserved']);
        self::assertGreaterThan($usage['quota'] - 2 * self::GB, $usage['used'], 'deleting « moyenne » too would have been more than needed');
        self::assertNull($this->db()->query('SELECT purge_at FROM organizations WHERE id = 1')->fetchColumn());

        // Recap e-mail and journal.
        $recap = end($this->outbox->messages);
        self::assertStringContainsString('purge effectuée', $recap->subject);
        self::assertStringContainsString('archivee (archivée)', $recap->text);
        self::assertStringContainsString('ancienne', $recap->text);
        self::assertStringNotContainsString('moyenne', $recap->text);
        $log = $this->api('GET', '/api/admin/organization/log');
        self::assertSame(['Purge automatique', 'Purge effectuée'], [$log[0]['actor'], $log[0]['action']]);

        // Uploads open again; a later run does nothing.
        $this->app->services()->quota()->ensureUploadsOpen(1);
        self::assertSame([], $downgrade->run(self::at($j0, '+31 days'), false));
    }

    public function testAQuotaThatFitsAgainCancelsThePurge(): void
    {
        $this->downgrade();
        $this->outbox->messages = [];
        self::assertSame('cancelled', $this->downgrade(null)['result'], 'no limit');
        self::assertNull($this->db()->query('SELECT purge_at FROM organizations WHERE id = 1')->fetchColumn());
        self::assertStringContainsString('annulée', $this->subjects()[0]);
        self::assertSame([], $this->app->services()->downgrade()->run(self::at('now', '+31 days'), false));
        self::assertCount(5, $this->titles());
    }

    public function testFreeingRoomBeforeTheDateCancelsThePurge(): void
    {
        $result = $this->downgrade();
        self::assertSame('downgraded', $result['result']);
        $this->asAdmin();
        $this->api('DELETE', '/api/admin/videos/' . $this->videos['ancienne']['id']);
        $this->api('DELETE', '/api/admin/videos/' . $this->videos['moyenne']['id']);

        $report = $this->app->services()->downgrade()->run(self::at('now', '+2 hours'), false);
        self::assertSame('Purge sans objet', $report[0]['event']);
        self::assertNull($this->db()->query('SELECT purge_at FROM organizations WHERE id = 1')->fetchColumn());
        self::assertSame(['Film manifeste', 'archivee', 'recente'], $this->titles(), 'nothing else deleted');
        self::assertStringContainsString('aucune vidéo ne sera supprimée', end($this->outbox->messages)->subject);
    }

    public function testAQuotaThatStillFitsIsAPlainChange(): void
    {
        $this->outbox->messages = [];
        self::assertSame('changed', $this->downgrade(250)['result']);
        self::assertNull($this->db()->query('SELECT purge_at FROM organizations WHERE id = 1')->fetchColumn());
        self::assertSame([], $this->outbox->messages);
        $this->app->services()->quota()->ensureUploadsOpen(1);
    }

    public function testTheOperatorSeesThePendingPurgeAndTheJournal(): void
    {
        $this->downgrade();
        $overview = $this->decode($this->request('GET', '/api/superadmin/organizations'));
        self::assertSame('1 To', $overview['organizations'][0]['downgradedFrom']);
        self::assertSame(5 * self::GB, $overview['organizations'][0]['quota']);
        self::assertNotNull($overview['organizations'][0]['purgeAt']);
        self::assertSame(['belprod', 'Quota abaissé'], [$overview['log'][0]['organization'], $overview['log'][0]['action']]);
    }
}
