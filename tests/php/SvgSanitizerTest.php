<?php

declare(strict_types=1);

namespace Belprise\Tests;

use Belprise\Security\SvgSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SvgSanitizerTest extends TestCase
{
    public function testKeepsAnIllustratorLogo(): void
    {
        $svg = <<<'SVG'
            <?xml version="1.0" encoding="UTF-8"?>
            <!-- Generator: Adobe Illustrator 28.0.0 -->
            <svg id="Calque_1" data-name="Calque 1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 120 40">
              <defs>
                <style>
                  .cls-1 { fill: url(#Degrade_1); }
                  .cls-2 { fill: #1d1d1b; }
                </style>
                <linearGradient id="Degrade_1" x1="0" y1="20" x2="120" y2="20" gradientUnits="userSpaceOnUse">
                  <stop offset="0" stop-color="#a8481f"/>
                  <stop offset="1" stop-color="#e3a06b"/>
                </linearGradient>
              </defs>
              <title>Maison Aurore</title>
              <path class="cls-1" d="M10 30 L20 10 L30 30 Z"/>
              <text class="cls-2" x="40" y="26" font-family="Inter" font-size="14">Aurore &amp; fils</text>
              <use xlink:href="#Degrade_1"/>
            </svg>
            SVG;
        $clean = SvgSanitizer::clean($svg);

        self::assertNotNull($clean);
        self::assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg" id="Calque_1" viewBox="0 0 120 40">', $clean);
        self::assertStringContainsString('.cls-1 { fill: url(#Degrade_1); }', $clean);
        self::assertStringContainsString('<linearGradient id="Degrade_1" x1="0" y1="20" x2="120" y2="20" gradientUnits="userSpaceOnUse">', $clean);
        self::assertStringContainsString('<stop offset="0" stop-color="#a8481f"/>', $clean);
        self::assertStringContainsString('<title>Maison Aurore</title>', $clean);
        self::assertStringContainsString('>Aurore &amp; fils</text>', $clean);
        self::assertStringContainsString('<use href="#Degrade_1"/>', $clean);
        self::assertStringNotContainsString('Generator', $clean);
        self::assertStringNotContainsString('<?xml', $clean);
        self::assertStringNotContainsString('data-name', $clean);
    }

    public function testDropsInkscapeMetadata(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:sodipodi="http://sodipodi.sourceforge.net/DTD/sodipodi-0.dtd"'
            . ' xmlns:inkscape="http://www.inkscape.org/namespaces/inkscape" width="10" height="10" inkscape:version="1.3">'
            . '<sodipodi:namedview id="nv" pagecolor="#ffffff"><inkscape:page x="0" y="0"/></sodipodi:namedview>'
            . '<metadata><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"><rdf:Description/></rdf:RDF></metadata>'
            . '<g inkscape:label="Calque 1" inkscape:groupmode="layer"><rect width="10" height="10" style="fill:#a8481f;stroke:none"/></g>'
            . '</svg>';

        self::assertSame(
            '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><g><rect width="10" height="10" style="fill:#a8481f;stroke:none"/></g></svg>' . "\n",
            SvgSanitizer::clean($svg),
        );
    }

    /**
     * Payloads that must come out without anything active.
     *
     * @return iterable<string, array{string}>
     */
    public static function hostile(): iterable
    {
        $open = '<svg xmlns="http://www.w3.org/2000/svg">';
        yield 'script' => [$open . '<script>alert(1)</script><rect width="1" height="1"/></svg>'];
        yield 'script with CDATA' => [$open . '<script><![CDATA[alert(1)]]></script></svg>'];
        yield 'prefixed script' => ['<svg xmlns="http://www.w3.org/2000/svg" xmlns:s="http://www.w3.org/2000/svg"><s:script>alert(1)</s:script></svg>'];
        yield 'uppercase script' => [$open . '<SCRIPT>alert(1)</SCRIPT></svg>'];
        yield 'onload on root' => ['<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>'];
        yield 'onclick with entities' => [$open . '<rect width="1" height="1" &#111;nclick="alert(1)" onclick="alert(1)"/></svg>'];
        yield 'foreignObject' => [$open . '<foreignObject><iframe src="https://example.com"></iframe></foreignObject></svg>'];
        yield 'link' => [$open . '<a href="javascript:alert(1)"><rect width="1" height="1"/></a></svg>'];
        yield 'external use' => [$open . '<use href="https://example.com/x.svg#a"/></svg>'];
        yield 'javascript href with entities' => [$open . '<use xlink:href="&#106;avascript:alert(1)"/></svg>'];
        yield 'external image' => [$open . '<image href="https://example.com/track.png"/></svg>'];
        yield 'animate to javascript' => [$open . '<a><animate attributeName="href" values="javascript:alert(1)"/></a></svg>'];
        yield 'set' => [$open . '<set attributeName="onmouseover" to="alert(1)"/></svg>'];
        yield 'style import' => [$open . '<style>@import url(https://example.com/a.css);</style></svg>'];
        yield 'style external url' => [$open . '<style>.a{fill:url( "https://example.com/x" )}</style></svg>'];
        yield 'style escape' => [$open . '<style>.a{background:u\\72l(https://example.com)}</style></svg>'];
        yield 'style font-face' => [$open . '<style>@font-face{font-family:x;src:url(https://example.com/f.woff2)}</style></svg>'];
        yield 'attribute url' => [$open . '<rect width="1" height="1" fill="url(https://example.com/x)"/></svg>'];
        yield 'style attribute url' => [$open . '<rect width="1" height="1" style="background-image:url(&quot;https://example.com/x&quot;)"/></svg>'];
        yield 'data url' => [$open . '<rect width="1" height="1" style="fill:url(data:image/svg+xml;base64,AAAA)"/></svg>'];
        yield 'xml-stylesheet' => ['<?xml-stylesheet href="https://example.com/a.css"?>' . $open . '<rect width="1" height="1"/></svg>'];
        yield 'image-set in style' => [$open . '<style>.a{background:-webkit-image-set("https://example.com/t.png" 1x)}</style></svg>'];
        yield 'image-set in attribute' => [$open . '<rect width="1" height="1" style="fill:image-set(\'https://example.com/t.png\' 1x)"/></svg>'];
        yield 'iframe' => [$open . '<iframe src="https://example.com"/></svg>'];
        yield 'closing tag inside text' => [$open . '<title>&lt;/title&gt;&lt;script&gt;alert(1)&lt;/script&gt;</title></svg>'];
    }

    #[DataProvider('hostile')]
    public function testNeutralisesHostileContent(string $svg): void
    {
        $clean = (string) SvgSanitizer::clean($svg);

        self::assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg"', $clean);
        foreach (['<script', '<SCRIPT', 'script>', 'foreignObject', '<iframe', '<a', '<animate', '<set', '<image', 'href="h', 'href="j', 'javascript', '@import', 'example.com', 'onload', 'onclick', 'data:', '<?'] as $needle) {
            self::assertStringNotContainsString($needle, $clean, $needle);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejected(): iterable
    {
        yield 'doctype with entities' => ['<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg"><text>&x;</text></svg>'];
        yield 'not svg' => ['<html><body><svg></svg></body></html>'];
        yield 'unclosed' => ['<svg xmlns="http://www.w3.org/2000/svg"><g>'];
        yield 'mismatched' => ['<svg xmlns="http://www.w3.org/2000/svg"><g></rect></svg>'];
        yield 'two roots' => ['<svg xmlns="http://www.w3.org/2000/svg"/><svg xmlns="http://www.w3.org/2000/svg"/>'];
        yield 'broken tag' => ['<svg xmlns="http://www.w3.org/2000/svg"><rect width=1/></svg>'];
        yield 'unterminated comment' => ['<svg xmlns="http://www.w3.org/2000/svg"><!-- </svg>'];
        yield 'invalid utf-8' => ["<svg xmlns=\"http://www.w3.org/2000/svg\"><title>\xC3\x28</title></svg>"];
        yield 'utf-16' => [mb_convert_encoding('<svg xmlns="http://www.w3.org/2000/svg"/>', 'UTF-16')];
        yield 'empty' => [''];
    }

    #[DataProvider('rejected')]
    public function testRejectsMalformedOrDangerousDeclarations(string $svg): void
    {
        self::assertNull(SvgSanitizer::clean($svg));
    }

    public function testAddsTheNamespaceAndEscapesValues(): void
    {
        self::assertSame(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><text font-family="&quot;Inter&quot;, sans-serif">a &lt; b</text></svg>' . "\n",
            SvgSanitizer::clean("\xEF\xBB\xBF<svg viewBox='0 0 1 1'><text font-family='\"Inter\", sans-serif'>a &lt; b</text></svg>"),
        );
    }

    public function testLimitsUseElements(): void
    {
        $uses = str_repeat('<use href="#a"/>', 50);
        $clean = (string) SvgSanitizer::clean('<svg xmlns="http://www.w3.org/2000/svg"><g id="a"><rect width="1" height="1"/></g>' . $uses . '</svg>');

        self::assertSame(20, substr_count($clean, '<use '));
    }

    public function testCleaningIsStable(): void
    {
        $once = (string) SvgSanitizer::clean('<svg xmlns="http://www.w3.org/2000/svg"><style>.a>.b{fill:#000}</style><g class="a"><path class="b" d="M0 0h1v1z"/></g></svg>');

        self::assertStringContainsString('.a&gt;.b{fill:#000}', $once);
        self::assertSame($once, SvgSanitizer::clean($once));
    }
}
