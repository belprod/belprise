<?php

declare(strict_types=1);

namespace Belprise\Tests;

use Belprise\Tests\Support\TempDir;
use Belprise\View\ClientTheme;
use Belprise\View\Shell;
use Belprise\View\Vite;
use PHPUnit\Framework\TestCase;

final class ShellTest extends TestCase
{
    private string $tmp;
    private Shell $shell;

    protected function setUp(): void
    {
        $this->tmp = TempDir::create();
        mkdir($this->tmp . '/.vite');
        file_put_contents($this->tmp . '/.vite/manifest.json', json_encode([
            'src/client/main.ts' => ['file' => 'client-AAAAAAAA.js', 'isEntry' => true, 'css' => ['client-BBBBBBBB.css'], 'imports' => ['_shared.js']],
            '_shared.js' => ['file' => 'shared-CCCCCCCC.js', 'css' => ['shared-DDDDDDDD.css']],
        ]));
        $this->shell = new Shell(new Vite($this->tmp));
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->tmp);
    }

    public function testClientColoursAreInjectedAfterTheStylesheetsWithTheNonce(): void
    {
        $html = $this->shell->render('src/client/main.ts', 'Revue', [], 'NONCE123', new ClientTheme('#F5EFE6', '#1a1a1a', '#b5651d'));

        $style = '<style nonce="NONCE123">:root{--client-bg:#f5efe6;--client-text:#1a1a1a;--client-accent:#b5651d;--client-on-accent:#0a0a0b;--client-accent-text:#96561c;--status-ink:35%;color-scheme:light}</style>';
        self::assertStringContainsString($style, $html);
        self::assertGreaterThan(strpos($html, 'client-BBBBBBBB.css'), strpos($html, $style));
    }

    public function testStatusColoursAreDarkenedOnlyOnLightBackgrounds(): void
    {
        self::assertTrue((new ClientTheme('#ffffff', '#000000', '#1f6feb'))->isLight());
        self::assertTrue((new ClientTheme('#f3ede4', '#1c1915', '#a8481f'))->isLight());
        self::assertFalse((new ClientTheme('#0a0a0b', '#fafafa', '#e8515f'))->isLight());
        self::assertFalse((new ClientTheme('#1d2b22', '#f1ede2', '#e07a3f'))->isLight());
        self::assertStringContainsString('--status-ink:75%', (new ClientTheme('#0e1a2b', '#e8eef5', '#f2b544'))->css());
        self::assertStringContainsString('--status-ink:35%', (new ClientTheme('#ffffff', '#000000', '#1f6feb'))->css());
    }

    public function testAccentTextReadsAtFiveToOneOnTheBackground(): void
    {
        foreach ([['#f3ede4', '#1c1915', '#a8481f'], ['#ffffff', '#14202b', '#ffd60a'], ['#0e1a2b', '#e8eef5', '#2a3b8f'], ['#0a0a0b', '#fafafa', '#e8515f']] as [$bg, $text, $accent]) {
            $ink = (new ClientTheme($bg, $text, $accent))->accentText();
            [$a, $b] = [ClientTheme::luminance($ink), ClientTheme::luminance($bg)];
            self::assertGreaterThanOrEqual(5.0, (max($a, $b) + 0.05) / (min($a, $b) + 0.05), "{$accent} on {$bg}");
        }
        self::assertSame('#e8515f', (new ClientTheme('#0a0a0b', '#fafafa', '#e8515f'))->accentText(), 'unchanged when readable');
    }

    public function testStylesheetsAreInlinedWithTheNonceWhenBuilt(): void
    {
        file_put_contents($this->tmp . '/client-BBBBBBBB.css', '@font-face{src:url(/assets/fonts/inter-latin-400-normal-AbC123.woff2)}body{color:red}');
        file_put_contents($this->tmp . '/shared-DDDDDDDD.css', '.a{color:blue}');
        $html = $this->shell->render('src/client/main.ts', 'Revue', [], 'NONCE9');

        self::assertStringContainsString('<style nonce="NONCE9">.a{color:blue}' . "\n" . '@font-face{src:url(/assets/fonts/inter-latin-400-normal-AbC123.woff2)}', $html, 'shared chunk first, like the links were');
        self::assertStringContainsString('<link rel="preload" href="/assets/fonts/inter-latin-400-normal-AbC123.woff2" as="font" type="font/woff2" crossorigin>', $html);
        self::assertStringNotContainsString('<link rel="stylesheet"', $html);
    }

    public function testImportedChunksArePreloadedWithTheirCss(): void
    {
        $html = $this->shell->render('src/client/main.ts', 'Revue', [], 'n');

        self::assertStringContainsString('<link rel="stylesheet" href="/assets/shared-DDDDDDDD.css">', $html);
        self::assertStringContainsString('<link rel="modulepreload" href="/assets/shared-CCCCCCCC.js">', $html);
        self::assertStringNotContainsString('<style', $html);
    }

    public function testBootDataCannotBreakOutOfItsScriptTag(): void
    {
        $html = $this->shell->render('src/client/main.ts', '<Titre> & "co"', ['title' => '</script><script>alert(1)</script>'], 'n');

        self::assertStringNotContainsString('</script><script>alert(1)', $html);
        self::assertStringContainsString('<title>&lt;Titre&gt; &amp; &quot;co&quot;</title>', $html);
    }

    public function testInvalidColoursAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ClientTheme('#fff;}body{display:none', '#000000', '#000000');
    }

    public function testDevServerReplacesTheBuild(): void
    {
        file_put_contents($this->tmp . '/.hot', 'http://localhost:5173');
        $html = (new Shell(new Vite($this->tmp)))->render('src/client/main.ts', 'Revue', [], 'n');

        self::assertStringContainsString('src="http://localhost:5173/assets/@vite/client"', $html);
        self::assertStringContainsString('src="http://localhost:5173/assets/src/client/main.ts"', $html);
    }

    public function testDevServerFileIsIgnoredUnlessItPointsToLocalhost(): void
    {
        file_put_contents($this->tmp . '/.hot', 'https://evil.example');

        self::assertNull((new Vite($this->tmp))->devServer());
    }
}
