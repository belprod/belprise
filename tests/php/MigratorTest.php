<?php

declare(strict_types=1);

namespace Belprise\Tests;

use Belprise\Database\Database;
use Belprise\Database\Migrator;
use Belprise\Repository\Clients;
use Belprise\Repository\Organizations;
use Belprise\Repository\Projects;
use Belprise\Repository\Videos;
use Belprise\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase
{
    private const MIGRATIONS = __DIR__ . '/../../app/migrations';

    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = TempDir::create();
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->tmp);
    }

    public function testOpeningTheDatabaseCreatesTheFullSchemaInWalMode(): void
    {
        $db = Database::open($this->tmp . '/belprise.sqlite', self::MIGRATIONS);

        self::assertSame('wal', $db->query('PRAGMA journal_mode')->fetchColumn());
        self::assertSame(1, (int) $db->query('PRAGMA foreign_keys')->fetchColumn());

        $tables = $db->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
        foreach (['clients', 'projects', 'videos', 'tags', 'video_tags', 'versions', 'links', 'comments', 'uploads', 'settings', 'login_attempts'] as $table) {
            self::assertContains($table, $tables);
        }

        $versionColumns = $db->query('PRAGMA table_info(versions)')->fetchAll(\PDO::FETCH_COLUMN, 1);
        foreach (['fps_num', 'fps_den', 'start_frame', 'duration_frames'] as $column) {
            self::assertContains($column, $versionColumns);
        }
    }

    public function testMigrationsAreAppliedOnlyOnce(): void
    {
        $db = new \PDO('sqlite:' . $this->tmp . '/belprise.sqlite', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $migrator = new Migrator($db, self::MIGRATIONS);

        $applied = $migrator->migrate();

        self::assertNotEmpty($applied);
        self::assertSame(max($applied), $migrator->currentVersion());
        self::assertSame([], $migrator->migrate());
    }

    public function testANewMigrationIsAppliedOnTopOfTheExistingSchema(): void
    {
        $dir = $this->tmp . '/migrations';
        mkdir($dir);
        copy(self::MIGRATIONS . '/0001_schema.sql', $dir . '/0001_schema.sql');
        $db = new \PDO('sqlite:' . $this->tmp . '/belprise.sqlite', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        (new Migrator($db, $dir))->migrate();

        file_put_contents($dir . '/0002_add_note.sql', 'ALTER TABLE clients ADD COLUMN note TEXT NULL;');

        self::assertSame([2], (new Migrator($db, $dir))->migrate());
        self::assertContains('note', $db->query('PRAGMA table_info(clients)')->fetchAll(\PDO::FETCH_COLUMN, 1));
    }

    public function testAFailingMigrationIsRolledBack(): void
    {
        $dir = $this->tmp . '/migrations';
        mkdir($dir);
        file_put_contents($dir . '/0001_ok.sql', 'CREATE TABLE a (id INTEGER);');
        file_put_contents($dir . '/0002_broken.sql', 'CREATE TABLE b (id INTEGER); THIS IS NOT SQL;');
        $db = new \PDO('sqlite:' . $this->tmp . '/belprise.sqlite', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $migrator = new Migrator($db, $dir);

        try {
            $migrator->migrate();
            self::fail('The broken migration should throw.');
        } catch (\PDOException) {
        }

        self::assertSame(0, $migrator->currentVersion());
        self::assertFalse($db->query("SELECT 1 FROM sqlite_master WHERE name IN ('a', 'b')")->fetchColumn());
    }

    public function testVideoNumbersStartAt1001InEachOrganization(): void
    {
        $db = Database::open($this->tmp . '/belprise.sqlite', self::MIGRATIONS);
        $numbers = [];
        foreach (['belprod', 'Studio Nord', 'belprod'] as $name) {
            $org = (int) ($db->query("SELECT id FROM organizations WHERE name = " . $db->quote($name))->fetchColumn()
                ?: (new Organizations($db))->create($name));
            $client = (new Clients($db, $org))->create(['name' => 'Client', 'bg_color' => '#000000', 'text_color' => '#ffffff', 'accent_color' => '#e8515f', 'welcome_text' => '']);
            $project = (new Projects($db, $org))->create($client, 'Projet');
            $video = (new Videos($db, $org))->create($project, 'Film', true);
            $numbers[] = (int) $db->query('SELECT number FROM videos WHERE id = ' . $video)->fetchColumn();
        }

        self::assertSame([1001, 1001, 1002], $numbers);
    }

    public function testThe30SchemaStartsFromOneFileWithoutTheBeta(): void
    {
        $db = Database::open($this->tmp . '/belprise.sqlite', self::MIGRATIONS);

        self::assertSame('0001_schema.sql', basename((glob(self::MIGRATIONS . '/*.sql') ?: [''])[0]), 'one starting file');
        self::assertSame(count(glob(self::MIGRATIONS . '/*.sql') ?: []), (int) $db->query('PRAGMA user_version')->fetchColumn());
        $tables = $db->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertNotContains('beta_requests', $tables);
        self::assertContains('recovery_codes', $tables);
        $columns = $db->query('PRAGMA table_info(organizations)')->fetchAll(\PDO::FETCH_COLUMN, 1);
        self::assertContains('quota_bytes', $columns);
        self::assertContains('totp_required', $columns);
        self::assertNotContains('plan', $columns);
    }

    public function testTheDatabaseKeepsOneAdministratorPerOrganization(): void
    {
        $db = Database::open($this->tmp . '/belprise.sqlite', self::MIGRATIONS);
        $org = (new Organizations($db))->create('belprod');
        $other = (new Organizations($db))->create('Studio Nord');
        $add = static fn (int $o, string $email, string $role) => $db->prepare('INSERT INTO users (organization_id, email, name, password_hash, role) VALUES (?, ?, ?, ?, ?)')
            ->execute([$o, $email, 'X', 'h', $role]);
        $add($org, 'a@belprise.test', 'admin');
        $add($org, 'e@belprise.test', 'editor');
        $add($other, 'b@belprise.test', 'admin');

        $this->expectException(\PDOException::class);
        $add($org, 'c@belprise.test', 'admin');
    }

    public function testAFreshInstallationHasNoOrganizationYet(): void
    {
        $db = Database::open($this->tmp . '/belprise.sqlite', self::MIGRATIONS);

        self::assertSame('0', (string) $db->query('SELECT COUNT(*) FROM organizations')->fetchColumn());
        self::assertSame('0', (string) $db->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }
}
