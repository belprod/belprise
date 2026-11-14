<?php

declare(strict_types=1);

namespace Belprise\Security;

/**
 * Allow-list cleaner for client logos in SVG (no DOM extension needed).
 *
 * The file is tokenised and rebuilt from scratch: only known drawing
 * elements and presentation attributes survive, entities are decoded and
 * re-escaped, and anything that could run script or fetch a resource
 * (script, foreignObject, event handlers, external href / url(), at-rules,
 * processing instructions) is dropped. Malformed XML, DOCTYPE and entity
 * declarations make the whole file rejected (null).
 */
final class SvgSanitizer
{
    private const SVG_NS = 'http://www.w3.org/2000/svg';

    /** Allowed elements: lowercase name => name written back. */
    private const ELEMENTS = [
        'svg' => 'svg', 'g' => 'g', 'defs' => 'defs', 'symbol' => 'symbol', 'use' => 'use',
        'title' => 'title', 'desc' => 'desc', 'style' => 'style',
        'path' => 'path', 'rect' => 'rect', 'circle' => 'circle', 'ellipse' => 'ellipse',
        'line' => 'line', 'polyline' => 'polyline', 'polygon' => 'polygon',
        'text' => 'text', 'tspan' => 'tspan',
        'lineargradient' => 'linearGradient', 'radialgradient' => 'radialGradient', 'stop' => 'stop',
        'clippath' => 'clipPath', 'mask' => 'mask',
    ];

    /** Elements whose text content is kept. */
    private const TEXT_ELEMENTS = ['title', 'desc', 'text', 'tspan', 'style'];

    /** Allowed attributes: lowercase name => name written back. */
    private const ATTRIBUTES = [
        'id' => 'id', 'class' => 'class', 'style' => 'style', 'transform' => 'transform',
        'x' => 'x', 'y' => 'y', 'x1' => 'x1', 'y1' => 'y1', 'x2' => 'x2', 'y2' => 'y2',
        'cx' => 'cx', 'cy' => 'cy', 'r' => 'r', 'rx' => 'rx', 'ry' => 'ry', 'fx' => 'fx', 'fy' => 'fy', 'fr' => 'fr',
        'width' => 'width', 'height' => 'height', 'd' => 'd', 'points' => 'points', 'pathlength' => 'pathLength',
        'viewbox' => 'viewBox', 'preserveaspectratio' => 'preserveAspectRatio', 'version' => 'version',
        'xml:space' => 'xml:space',
        'fill' => 'fill', 'fill-opacity' => 'fill-opacity', 'fill-rule' => 'fill-rule',
        'stroke' => 'stroke', 'stroke-width' => 'stroke-width', 'stroke-linecap' => 'stroke-linecap',
        'stroke-linejoin' => 'stroke-linejoin', 'stroke-miterlimit' => 'stroke-miterlimit',
        'stroke-dasharray' => 'stroke-dasharray', 'stroke-dashoffset' => 'stroke-dashoffset',
        'stroke-opacity' => 'stroke-opacity', 'opacity' => 'opacity', 'color' => 'color',
        'display' => 'display', 'visibility' => 'visibility',
        'clip-path' => 'clip-path', 'clip-rule' => 'clip-rule', 'mask' => 'mask',
        'offset' => 'offset', 'stop-color' => 'stop-color', 'stop-opacity' => 'stop-opacity',
        'gradientunits' => 'gradientUnits', 'gradienttransform' => 'gradientTransform',
        'spreadmethod' => 'spreadMethod', 'clippathunits' => 'clipPathUnits',
        'maskunits' => 'maskUnits', 'maskcontentunits' => 'maskContentUnits',
        'font-family' => 'font-family', 'font-size' => 'font-size', 'font-weight' => 'font-weight',
        'font-style' => 'font-style', 'text-anchor' => 'text-anchor', 'letter-spacing' => 'letter-spacing',
        'word-spacing' => 'word-spacing', 'dominant-baseline' => 'dominant-baseline',
        'alignment-baseline' => 'alignment-baseline', 'dx' => 'dx', 'dy' => 'dy', 'rotate' => 'rotate',
        'textlength' => 'textLength', 'lengthadjust' => 'lengthAdjust',
        // Internal references only (checked below); xlink:href is written as href.
        'href' => 'href', 'xlink:href' => 'href',
    ];

    /** <use> elements kept: enough for any logo, too few for a "use bomb". */
    private const MAX_USE = 20;

    private const TAG = '/\G<([A-Za-z_][\w.:-]*+)((?:\s++[^\s=\/>]++\s*+=\s*+(?:"[^"]*+"|\'[^\']*+\'))*+)\s*+(\/?)>/';
    private const CLOSE = '/\G<\/([A-Za-z_][\w.:-]*+)\s*+>/';
    private const ATTRIBUTE = '/([^\s=\/>]++)\s*+=\s*+(?:"([^"]*+)"|\'([^\']*+)\')/';

    public static function clean(string $svg): ?string
    {
        if (str_starts_with($svg, "\xEF\xBB\xBF")) {
            $svg = substr($svg, 3);
        }
        if (!mb_check_encoding($svg, 'UTF-8')) {
            return null;
        }

        $out = '';
        /** @var list<array{name: string, kept: bool}> $stack */
        $stack = [];
        $rootSeen = false;
        $uses = 0;
        $length = strlen($svg);
        $pos = 0;

        while ($pos < $length) {
            $lt = strpos($svg, '<', $pos);
            if ($lt === false || $lt > $pos) {
                $end = $lt === false ? $length : $lt;
                $out .= self::text(substr($svg, $pos, $end - $pos), $stack);
                $pos = $end;
                continue;
            }

            if (substr_compare($svg, '<!--', $pos, 4) === 0) {
                $end = strpos($svg, '-->', $pos + 4);
                if ($end === false) {
                    return null;
                }
                $pos = $end + 3;
                continue;
            }
            if (substr_compare($svg, '<?', $pos, 2) === 0) {
                // XML declaration and processing instructions (xml-stylesheet…): dropped.
                $end = strpos($svg, '?>', $pos + 2);
                if ($end === false) {
                    return null;
                }
                $pos = $end + 2;
                continue;
            }
            if (substr_compare($svg, '<![CDATA[', $pos, 9) === 0) {
                $end = strpos($svg, ']]>', $pos + 9);
                if ($end === false) {
                    return null;
                }
                $out .= self::text(substr($svg, $pos + 9, $end - $pos - 9), $stack, false);
                $pos = $end + 3;
                continue;
            }
            if (substr_compare($svg, '<!', $pos, 2) === 0) {
                return null; // DOCTYPE, ENTITY: never accepted
            }

            if (preg_match(self::CLOSE, $svg, $m, 0, $pos) === 1) {
                $open = array_pop($stack);
                if ($open === null || strtolower($open['name']) !== strtolower($m[1])) {
                    return null;
                }
                if ($open['kept']) {
                    $out .= '</' . self::ELEMENTS[strtolower($open['name'])] . '>';
                }
                $pos += strlen($m[0]);
                continue;
            }

            if (preg_match(self::TAG, $svg, $m, 0, $pos) !== 1) {
                return null;
            }
            $pos += strlen($m[0]);
            $name = strtolower($m[1]);
            $selfClosing = $m[3] === '/';
            $parentKept = $stack === [] || $stack[array_key_last($stack)]['kept'];

            if (!$rootSeen) {
                if ($name !== 'svg' || $stack !== []) {
                    return null;
                }
                $rootSeen = true;
            } elseif ($stack === []) {
                return null; // a second root element
            }

            $kept = $parentKept && isset(self::ELEMENTS[$name]) && ($name !== 'use' || ++$uses <= self::MAX_USE);
            if ($kept) {
                $out .= '<' . self::ELEMENTS[$name] . self::attributes($m[2], $stack === []) . ($selfClosing ? '/>' : '>');
            }
            if (!$selfClosing) {
                $stack[] = ['name' => $m[1], 'kept' => $kept];
            }
        }

        if (!$rootSeen || $stack !== [] || preg_last_error() !== PREG_NO_ERROR) {
            return null;
        }

        return $out . "\n";
    }

    /**
     * @param list<array{name: string, kept: bool}> $stack
     */
    private static function text(string $raw, array $stack, bool $entities = true): string
    {
        $top = $stack === [] ? null : $stack[array_key_last($stack)];
        if ($top === null || !$top['kept'] || !in_array(strtolower($top['name']), self::TEXT_ELEMENTS, true)) {
            return '';
        }
        $text = $entities ? html_entity_decode($raw, ENT_QUOTES | ENT_XML1, 'UTF-8') : $raw;
        if (strtolower($top['name']) === 'style' && !self::safeCss($text, true)) {
            return '';
        }

        return htmlspecialchars($text, ENT_NOQUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function attributes(string $raw, bool $root): string
    {
        $kept = [];
        preg_match_all(self::ATTRIBUTE, $raw, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $name = self::ATTRIBUTES[strtolower($m[1])] ?? null;
            if ($name === null) {
                continue; // event handlers, xmlns*, editor metadata…
            }
            $value = html_entity_decode(($m[2] ?? '') !== '' ? $m[2] : ($m[3] ?? ''), ENT_QUOTES | ENT_XML1, 'UTF-8');
            if ($name === 'href' ? preg_match('/^\s*#[\w.:-]+\s*$/', $value) !== 1 : !self::safeCss($value, false)) {
                continue;
            }
            $kept[$name] = $value;
        }
        $out = $root ? ' xmlns="' . self::SVG_NS . '"' : '';
        foreach ($kept as $name => $value) {
            $out .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }

        return $out;
    }

    /** No external resource, script URL or CSS escape trick in a value or a <style>. */
    private static function safeCss(string $value, bool $stylesheet): bool
    {
        $compact = strtolower((string) preg_replace('/[\s\x00-\x1F\x7F]+/', '', $value));
        if (preg_match('/url\((?![\'"]?#)/', $compact) === 1
            || preg_match('/(?:image-set|image|cross-fade|element|src)\(/', $compact) === 1) {
            return false;
        }
        foreach (['javascript:', 'data:', 'expression(', '\\', '<'] as $needle) {
            if (str_contains($compact, $needle)) {
                return false;
            }
        }

        return !$stylesheet || !str_contains($compact, '@');
    }
}
