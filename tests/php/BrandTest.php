<?php

declare(strict_types=1);

namespace Belprise\Tests;

use Belprise\Repository\Organizations;
use Belprise\Repository\Users;
use Belprise\Tests\Support\AppTestCase;
use Belprise\View\AdminTheme;
use Belprise\View\ClientTheme;

/**
 * V2 phase 8: visual identity of each organization in its back office
 * (CAHIER_DES_CHARGES §5.7, §8): logo, accent, dark or light background,
 * contrast checked, colours in the shell before the first paint.
 */
final class BrandTest extends AppTestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    /** Status colours of tokens.css: as text on the light background they are mixed with the ink. */
    private const STATUSES = ['#4d8dff', '#a970ff', '#ffd60a', '#2ee59d', '#ff6b6b', '#ff8a95', '#e5484d'];

    private static function mix(string $a, string $b, float $share): string
    {
        $out = '#';
        for ($i = 1; $i <= 5; $i += 2) {
            $out .= sprintf('%02x', (int) round(hexdec(substr($a, $i, 2)) * $share + hexdec(substr($b, $i, 2)) * (1 - $share)));
        }

        return $out;
    }

    // ---- Contrast -------------------------------------------------------------------------

    public function testTheDefaultIdentityChangesNothing(): void
    {
        self::assertSame([], (new AdminTheme())->variables());
        self::assertSame('', (new AdminTheme())->css());
    }

    public function testTheLightPaletteReadsAtAaEverywhere(): void
    {
        $vars = (new AdminTheme('#e8515f', 'light'))->variables();
        foreach (['--bg', '--surface-1', '--surface-2', '--surface-3'] as $surface) {
            foreach (['--text', '--text-soft', '--text-muted'] as $ink) {
                self::assertGreaterThanOrEqual(4.5, AdminTheme::ratio($vars[$ink], $vars[$surface]), "{$ink} on {$surface}");
            }
            // Status colours used as text (pills, notices, errors): mixed at --status-ink with the text colour.
            foreach (self::STATUSES as $status) {
                $ink = self::mix($status, $vars['--text'], (float) $vars['--status-ink'] / 100);
                self::assertGreaterThanOrEqual(4.5, AdminTheme::ratio($ink, $vars[$surface]), "{$status} as text on {$surface}");
            }
        }
        self::assertSame('light', $vars['color-scheme']);
    }

    public function testEveryAcceptedAccentGivesReadableButtonsAndText(): void
    {
        foreach (['dark', 'light'] as $mode) {
            foreach (['#e8515f', '#1f6feb', '#2ee59d', '#ffd60a', '#7a3fd1', '#0b7a4b', '#ff9500', '#c2185b', '#00a3a3', '#5b5b5b'] as $accent) {
                $theme = new AdminTheme($accent, $mode);
                if ($theme->accentContrast() < AdminTheme::MIN_ACCENT_CONTRAST) {
                    continue; // refused by the API, see below
                }
                $vars = $theme->variables();
                self::assertGreaterThanOrEqual(4.5, AdminTheme::ratio($vars['--on-accent'] ?? '#0a0a0b', $accent), "button text on {$accent} ({$mode})");
                self::assertGreaterThanOrEqual(5.0, AdminTheme::ratio($vars['--accent-text'] ?? $accent, $theme->background()), "{$accent} as text ({$mode})");
                foreach ($mode === 'light' ? ['#ffffff', '#e9e9ec', '#e0e0e3'] : ['#0f0f11', '#141416', '#18181b'] as $surface) {
                    self::assertGreaterThanOrEqual(4.5, AdminTheme::ratio($vars['--accent-text'] ?? $accent, $surface), "{$accent} as text on {$surface} ({$mode})");
                }
            }
        }
        self::assertSame('#ffffff', (new AdminTheme('#1f3a8a', 'light'))->onAccent(), 'white on a dark accent');
        self::assertSame('#0a0a0b', (new AdminTheme('#2ee59d', 'dark'))->onAccent());
    }

    // ---- API --------------------------------------------------------------------------------

    public function testTheAdministratorSetsAccentAndBackgroundAndTheShellCarriesThem(): void
    {
        $this->loginAdmin();
        $state = $this->api('PATCH', '/api/admin/organization', ['accentColor' => '#1F6FEB', 'theme' => 'light']);
        self::assertSame(['#1f6feb', 'light'], [$state['organization']['accentColor'], $state['organization']['theme']]);
        self::assertSame(['#f4f4f5', '#1f6feb', '#ffffff'], [$state['theme']['--bg'], $state['theme']['--accent'], $state['theme']['--on-accent']]);

        // On the next load, the colours are in the page before any script runs.
        $html = $this->request('GET', '/admin')->body;
        self::assertMatchesRegularExpression('#<style nonce="[^"]+">:root\{--bg:\#f4f4f5;[^<]*--accent:\#1f6feb;#', $html);
        self::assertStringContainsString('<meta name="color-scheme" content="light">', $html);
        self::assertSame('light', $this->decode($this->request('GET', '/api/admin/session'))['organization']['theme']);

        // The name alone leaves the colours.
        $this->api('PATCH', '/api/admin/organization', ['name' => 'belprod studio']);
        self::assertSame('#1f6feb', $this->db()->query('SELECT accent_color FROM organizations WHERE id = 1')->fetchColumn());
        // Signed out: the login page stays neutral.
        $this->session->data = [];
        self::assertStringNotContainsString('--accent:#1f6feb', $this->request('GET', '/admin')->body);
    }

    public function testAnAccentTooCloseToTheBackgroundIsRefused(): void
    {
        $this->loginAdmin();
        $refused = $this->api('PATCH', '/api/admin/organization', ['accentColor' => '#ffd60a', 'theme' => 'light'], 422);
        self::assertSame('accentColor', $refused['field']);
        self::assertStringContainsString('3:1', $refused['error']);
        self::assertSame('accentColor', $this->api('PATCH', '/api/admin/organization', ['accentColor' => '#1a1a1a'], 422)['field'], 'too dark on the dark background');
        self::assertSame('theme', $this->api('PATCH', '/api/admin/organization', ['theme' => 'bleu'], 422)['field']);
        self::assertSame('accentColor', $this->api('PATCH', '/api/admin/organization', ['accentColor' => 'red'], 422)['field']);
        // Yellow is fine on the dark background; switching to light with it is refused.
        $this->api('PATCH', '/api/admin/organization', ['accentColor' => '#ffd60a']);
        self::assertSame('accentColor', $this->api('PATCH', '/api/admin/organization', ['theme' => 'light'], 422)['field']);
        self::assertSame('dark', $this->db()->query('SELECT theme FROM organizations WHERE id = 1')->fetchColumn());
    }

    public function testTheLogoIsCleanedServedSignedCountedAndReplaced(): void
    {
        $this->loginAdmin();
        $upload = fn (string $name, string $content) => $this->request('POST', '/api/admin/organization/logo', [
            'X-CSRF-Token' => (string) $this->session->data['csrf'],
        ], '', [], ['logo' => $this->file($name, $content)]);

        $png = $upload('logo.png', (string) base64_decode(self::PNG));
        self::assertSame(200, $png->status, $png->body);
        $url = (string) $this->decode($png)['organization']['logoUrl'];
        self::assertMatchesRegularExpression('#^/media/logos/o1-[0-9a-f]{12}\.png\?e=\d+&s=#', $url);
        self::assertSame(200, $this->request('GET', $url)->status);
        self::assertSame(404, $this->request('GET', explode('?', $url)[0])->status, 'signed only');
        self::assertGreaterThan(0, $this->app->services()->quota()->usage(1)['used'], 'the logo counts in the storage');

        $evil = $upload('evil.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(1)</script><rect width="10" height="10"/></svg>');
        self::assertSame(200, $evil->status);
        $served = $this->request('GET', (string) $this->decode($evil)['organization']['logoUrl']);
        self::assertSame('<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10"/></svg>' . "\n", $served->body);
        self::assertCount(1, glob($this->tmp . '/data/logos/o1-*') ?: [], 'the previous logo is deleted');
        self::assertSame(422, $upload('logo.png', 'not an image')->status);

        self::assertNull($this->api('DELETE', '/api/admin/organization/logo')['organization']['logoUrl']);
        self::assertSame([], glob($this->tmp . '/data/logos/o1-*') ?: []);
    }

    public function testOnlyAnAdministratorChangesTheIdentity(): void
    {
        $this->loginAdmin();
        $this->db()->exec("UPDATE users SET role = 'editor'");
        foreach ([['PATCH', '/api/admin/organization'], ['POST', '/api/admin/organization/logo'], ['DELETE', '/api/admin/organization/logo']] as [$method, $path]) {
            self::assertSame(403, $this->request($method, $path, [
                'X-CSRF-Token' => (string) $this->session->data['csrf'], 'Content-Type' => 'application/json',
            ], '{"accentColor":"#1f6feb"}')->status, $method . ' ' . $path);
        }
        self::assertSame('#e8515f', $this->db()->query('SELECT accent_color FROM organizations WHERE id = 1')->fetchColumn());
    }

    public function testTwoOrganizationsEachSeeTheirOwnColours(): void
    {
        $this->loginAdmin();
        $this->api('PATCH', '/api/admin/organization', ['accentColor' => '#2ee59d']);
        $db = $this->db();
        $nord = (new Organizations($db))->create('Studio Nord');
        (new Organizations($db))->setBrand($nord, '#1f3a8a', 'light');
        (new Users($db))->create($nord, 'nora@studio-nord.fr', 'Nora', password_hash('mot-de-passe-de-nora', PASSWORD_ARGON2ID), 'admin');

        $belprod = $this->request('GET', '/admin')->body;
        self::assertStringContainsString('--accent:#2ee59d', $belprod);
        self::assertStringContainsString('content="dark"', $belprod);

        $this->session->data = [];
        $this->postJson('/api/admin/login', ['email' => 'nora@studio-nord.fr', 'password' => 'mot-de-passe-de-nora']);
        $studio = $this->request('GET', '/admin')->body;
        self::assertStringContainsString('--accent:#1f3a8a', $studio);
        self::assertStringContainsString('--bg:#f4f4f5', $studio);
        self::assertStringNotContainsString('#2ee59d', $studio);
    }

    public function testCollectionsTakeTheOrganizationsIdentityByDefault(): void
    {
        $this->loginAdmin();
        $this->api('PATCH', '/api/admin/organization', ['accentColor' => '#1f3a8a', 'theme' => 'light']);
        $this->request('POST', '/api/admin/organization/logo', ['X-CSRF-Token' => (string) $this->session->data['csrf']], '', [], [
            'logo' => $this->file('logo.png', (string) base64_decode(self::PNG)),
        ]);

        $collection = $this->api('POST', '/api/admin/collections', ['title' => 'Sélection'], 201);
        self::assertSame(['#f4f4f5', '#141416', '#1f3a8a'], [$collection['bgColor'], $collection['textColor'], $collection['accentColor']]);
        self::assertNull($collection['logoUrl'], 'no logo of its own');

        // The public page shows the organization's logo.
        $this->session->data = [];
        $body = $this->request('GET', $collection['link']['path'])->body;
        self::assertSame(1, preg_match('#id="belprise-boot">(.*?)</script>#s', $body, $m));
        $boot = json_decode(html_entity_decode($m[1]), true, 512, JSON_THROW_ON_ERROR);
        self::assertMatchesRegularExpression('#^/media/logos/o1-#', (string) $boot['client']['logoUrl']);
        self::assertTrue((new ClientTheme('#f4f4f5', '#141416', '#1f3a8a'))->isLight());
    }

    /**
     * @return array{name: string, tmp_name: string, size: int, error: int}
     */
    private function file(string $name, string $content): array
    {
        $path = $this->tmp . '/upload-' . bin2hex(random_bytes(4));
        file_put_contents($path, $content);

        return ['name' => $name, 'tmp_name' => $path, 'size' => strlen($content), 'error' => UPLOAD_ERR_OK];
    }
}
