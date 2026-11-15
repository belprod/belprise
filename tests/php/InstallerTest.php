<?php

declare(strict_types=1);

namespace Belprise\Tests;

use Belprise\Http\Request;
use Belprise\Install\Installer;
use Belprise\Mail\Outbox;
use Belprise\Security\Totp;
use Belprise\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * Installation assistant in the browser (CAHIER_DES_CHARGES §7.2, criterion
 * 21): only while the configuration is missing, only with the unlock code
 * the server wrote outside the web root, nothing written before the end.
 */
final class InstallerTest extends TestCase
{
    private string $root;
    private Outbox $outbox;

    protected function setUp(): void
    {
        $this->root = TempDir::create();
        mkdir($this->root . '/app', 0700, true);
        mkdir($this->root . '/public', 0700, true);
        symlink(dirname(__DIR__, 2) . '/app/migrations', $this->root . '/app/migrations');
        $this->outbox = new Outbox();
    }

    protected function tearDown(): void
    {
        putenv('BELPRISE_CONFIG');
        @unlink($this->root . '/app/migrations');
        TempDir::remove($this->root);
    }

    /** @param array<string, string> $post */
    private function call(string $method, string $path = '/installation', array $post = []): \Belprise\Http\Response
    {
        return (new Installer($this->root, $this->outbox))->handle(new Request($method, $path, ['Host' => 'app.belprise.fr'], [], '', '203.0.113.7', [], [], $post));
    }

    /** @param array<string, string> $fields */
    private function step(string $step, array $fields = []): \Belprise\Http\Response
    {
        return $this->call('POST', '/installation', ['step' => $step, 'code' => $this->code()] + $fields);
    }

    private function code(string $dataDir = ''): string
    {
        return trim((string) file_get_contents(($dataDir ?: $this->root . '/data') . '/installation-code.txt'));
    }

    /** Steps 1 to 4; returns the TOTP secret. */
    private function upToTheOrganization(): string
    {
        $this->call('GET');
        $this->step('unlock');
        $this->step('config', ['app_url' => 'https://app.belprise.fr/', 'mail_from' => 'belprise <no-reply@belprise.fr>', 'action' => 'next']);
        $page = $this->step('account', ['email' => 'exploitation@belprise.fr', 'name' => 'Camille Martin', 'password' => 'mot-de-passe-exploitant', 'confirmation' => 'mot-de-passe-exploitant']);
        self::assertSame(1, preg_match('#secret=([A-Z2-7]+)#', $page->body, $m));
        self::assertStringContainsString('Première organisation', $this->step('totp', ['totp' => Totp::code($m[1], time())])->body);

        return $m[1];
    }

    public function testAFreshServerIsInstalledStepByStepWithItsFirstOrganization(): void
    {
        self::assertTrue(Installer::needed($this->root));
        $redirect = $this->call('GET', '/admin');
        self::assertSame([303, '/installation'], [$redirect->status, $redirect->header('Location')]);
        self::assertStringContainsString('noindex', (string) $redirect->header('X-Robots-Tag'));

        $page = $this->call('GET');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('data/installation-code.txt', $page->body);
        self::assertStringContainsString('Étape 1 sur 7', $page->body);
        self::assertStringContainsString('FFmpeg (facultatif)', $page->body);
        self::assertMatchesRegularExpression('/^[A-Z2-9]{5}-[A-Z2-9]{5}$/', $this->code());
        self::assertStringNotContainsString($this->code(), $page->body, 'the code is never shown');
        self::assertSame(403, $this->call('POST', '/installation', ['step' => 'unlock', 'code' => 'AAAAA-BBBBB'])->status);
        self::assertSame(409, $this->step('account', ['email' => 'x@y.fr'])->status, 'no skipping steps');

        // 2. Addresses, with a test e-mail.
        self::assertStringContainsString('Étape 2 sur 7', $this->call('POST', '/installation', ['step' => 'unlock', 'code' => strtolower($this->code())])->body);
        self::assertStringContainsString('app_url', $this->step('config', ['app_url' => 'pas une adresse', 'mail_from' => 'x <x@y.fr>', 'action' => 'next'])->body);
        $test = $this->step('config', ['app_url' => 'https://app.belprise.fr', 'mail_from' => 'belprise <no-reply@belprise.fr>', 'test_to' => 'jules@belprise.fr', 'action' => 'test']);
        self::assertStringContainsString('E-mail de test envoyé à jules@belprise.fr', $test->body);
        self::assertSame(['jules@belprise.fr', 'belprise : e-mail de test de l’installation'], [$this->outbox->messages[0]->to, $this->outbox->messages[0]->subject]);
        self::assertStringContainsString('Étape 3 sur 7', $this->step('config', ['app_url' => 'https://app.belprise.fr/', 'mail_from' => 'belprise <no-reply@belprise.fr>', 'action' => 'next'])->body);

        // 3-4. Operator account and its two-factor authentication.
        self::assertSame(422, $this->step('account', ['email' => 'exploitation@belprise.fr', 'name' => 'Jules', 'password' => 'court', 'confirmation' => 'court'])->status);
        $totpPage = $this->step('account', ['email' => 'exploitation@belprise.fr', 'name' => 'Camille Martin', 'password' => 'mot-de-passe-exploitant', 'confirmation' => 'mot-de-passe-exploitant']);
        self::assertSame(1, preg_match('#secret=([A-Z2-7]+)#', $totpPage->body, $m));
        self::assertStringContainsString('Code incorrect', $this->step('totp', ['totp' => '000000'])->body);
        self::assertStringContainsString('Étape 5 sur 7', $this->step('totp', ['totp' => Totp::code($m[1], time())])->body);

        // 5. First organization: its administrator is not the operator.
        self::assertStringContainsString('Choisissez une autre adresse', $this->step('organization', ['name' => 'belprod', 'admin' => 'exploitation@belprise.fr', 'action' => 'create'])->body);
        self::assertStringContainsString('Quota', $this->step('organization', ['name' => 'belprod', 'admin' => 'jules@belprod.test', 'quota' => 'beaucoup', 'action' => 'create'])->body);
        $encoding = $this->step('organization', ['name' => 'belprod', 'admin' => 'jules@belprod.test', 'quota' => '500', 'action' => 'create']);
        self::assertStringContainsString('Étape 6 sur 7', $encoding->body);
        self::assertStringContainsString('FFmpeg est introuvable', $encoding->body, 'no FFmpeg here: the browser only');
        self::assertStringContainsString('introuvable', $this->step('encoding', ['mode' => 'server'])->body);
        $summary = $this->step('encoding', ['mode' => 'browser']);
        self::assertStringContainsString('belprod — administrateur invité : jules@belprod.test — quota : 500 Go', $summary->body);
        self::assertStringContainsString('encodées dans le navigateur', $summary->body);
        self::assertFileDoesNotExist($this->root . '/app/config.php', 'nothing is written before the last step');

        // 6. Everything is written at once.
        $done = $this->step('summary');
        self::assertStringContainsString('Installation terminée', $done->body);
        self::assertStringContainsString('Invitation envoyée à jules@belprod.test', $done->body);
        self::assertStringContainsString(Installer::cronLine($this->root), html_entity_decode($done->body));
        self::assertFalse(Installer::needed($this->root));
        self::assertSame('600', sprintf('%o', fileperms($this->root . '/app/config.php') & 0o777));
        $config = require $this->root . '/app/config.php';
        self::assertSame(['https://app.belprise.fr', 64, realpath($this->root . '/data')], [$config['app_url'], strlen($config['secret']), realpath($config['data_dir'])]);
        self::assertArrayNotHasKey('operator_email', $config, 'no beta any more (3.0)');
        $db = new \PDO('sqlite:' . $this->root . '/data/belprise.sqlite');
        self::assertSame('exploitation@belprise.fr', $db->query('SELECT email FROM superadmins')->fetchColumn());
        self::assertSame(['belprod', 500000000000], $db->query('SELECT name, quota_bytes FROM organizations')->fetch(\PDO::FETCH_NUM));
        self::assertSame(['jules@belprod.test', 'admin'], $db->query('SELECT email, role FROM invitations')->fetch(\PDO::FETCH_NUM));
        $invitation = end($this->outbox->messages);
        self::assertSame('jules@belprod.test', $invitation->to);
        self::assertMatchesRegularExpression('#https://app\.belprise\.fr/invitation/[A-Za-z0-9_-]{43}#', $invitation->text);
        self::assertFileDoesNotExist($this->root . '/data/installation-code.txt');
        self::assertFileDoesNotExist($this->root . '/data/installation-state.json');
    }

    public function testTheOrganizationCanWait(): void
    {
        $this->upToTheOrganization();
        $this->step('organization', ['action' => 'skip']);
        self::assertStringContainsString('aucune pour l’instant', $this->step('encoding', ['mode' => 'browser'])->body);
        self::assertStringContainsString('Installation terminée', $this->step('summary')->body);
        $db = new \PDO('sqlite:' . $this->root . '/data/belprise.sqlite');
        self::assertSame('0', (string) $db->query('SELECT COUNT(*) FROM organizations')->fetchColumn());
    }

    public function testWithDockerTheConfigurationLivesInTheDataVolume(): void
    {
        mkdir($this->root . '/volume', 0700);
        putenv('BELPRISE_CONFIG=' . $this->root . '/volume/config.php');
        $this->call('GET');
        $code = $this->code($this->root . '/volume');
        $this->call('POST', '/installation', ['step' => 'unlock', 'code' => $code]);
        $this->call('POST', '/installation', ['step' => 'config', 'code' => $code, 'app_url' => 'https://video.example.org', 'mail_from' => 'x <x@example.org>', 'action' => 'next']);
        $page = $this->call('POST', '/installation', ['step' => 'account', 'code' => $code, 'email' => 'ops@example.org', 'name' => 'Ops Team', 'password' => 'mot-de-passe-exploitant', 'confirmation' => 'mot-de-passe-exploitant']);
        preg_match('#secret=([A-Z2-7]+)#', $page->body, $m);
        $this->call('POST', '/installation', ['step' => 'totp', 'code' => $code, 'totp' => Totp::code($m[1], time())]);
        $this->call('POST', '/installation', ['step' => 'organization', 'code' => $code, 'action' => 'skip']);
        $this->call('POST', '/installation', ['step' => 'encoding', 'code' => $code, 'mode' => 'browser']);
        $this->call('POST', '/installation', ['step' => 'summary', 'code' => $code]);

        self::assertFileExists($this->root . '/volume/config.php');
        self::assertFileDoesNotExist($this->root . '/app/config.php');
        self::assertFalse(Installer::needed($this->root));
        self::assertSame(realpath($this->root . '/volume'), realpath((require $this->root . '/volume/config.php')['data_dir']));
        self::assertFileExists($this->root . '/volume/belprise.sqlite');
    }

    public function testWithFfmpegTheServerCanEncode(): void
    {
        putenv('BELPRISE_FFMPEG=' . realpath(__DIR__ . '/../fixtures/bin/ffmpeg'));
        try {
            $this->upToTheOrganization();
            $page = $this->step('organization', ['action' => 'skip']);
            self::assertStringContainsString('FFmpeg 9.9-fake', $page->body);
            self::assertStringContainsString('encodées sur le serveur', $this->step('encoding', ['mode' => 'server'])->body);
            $this->step('summary');
        } finally {
            putenv('BELPRISE_FFMPEG');
        }
        $db = new \PDO('sqlite:' . $this->root . '/data/belprise.sqlite');
        self::assertSame('server', $db->query("SELECT value FROM settings WHERE key = 'encoding.mode'")->fetchColumn());
    }

    public function testTooManyWrongCodesLockThatNetworkOnlyAndKeepTheCode(): void
    {
        $this->call('GET');
        $code = $this->code();
        for ($i = 0; $i < 10; $i++) {
            self::assertSame(403, $this->call('POST', '/installation', ['step' => 'unlock', 'code' => 'ZZZZZ-ZZZZZ'])->status);
        }
        self::assertSame(429, $this->call('POST', '/installation', ['step' => 'unlock', 'code' => $code])->status, 'that network waits');
        self::assertSame($code, $this->code(), 'the code never changes');
        $other = (new Installer($this->root, $this->outbox))->handle(new Request('POST', '/installation', ['Host' => 'app.belprise.fr'], [], '', '198.51.100.20', [], [], ['step' => 'unlock', 'code' => $code]));
        self::assertSame(200, $other->status, 'the owner, from another network, goes on');
    }
}
