<?php

declare(strict_types=1);

namespace Belprise\View;

/**
 * Resolves front-end entries to built files (public/assets/.vite/manifest.json),
 * or to the Vite dev server when public/assets/.hot exists (npm run dev).
 */
final class Vite
{
    public const BASE = '/assets/';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $manifest = null;
    private ?string $devServer;

    public function __construct(private readonly string $assetsDir)
    {
        $hot = $assetsDir . '/.hot';
        $origin = is_file($hot) ? trim((string) file_get_contents($hot)) : '';
        $this->devServer = preg_match('#^http://(localhost|127\.0\.0\.1):\d+$#', $origin) === 1 ? $origin : null;
    }

    public function devServer(): ?string
    {
        return $this->devServer;
    }

    public function isBuilt(): bool
    {
        return $this->devServer !== null || is_file($this->manifestFile());
    }

    /** Inlined stylesheets above this size are linked instead. */
    private const INLINE_CSS_MAX = 64 * 1024;

    /** Fonts used above the fold, preloaded (Inter and Instrument Serif, Latin). */
    private const PRELOAD_FONTS = '#/assets/fonts/(?:inter-latin-400-normal|instrument-serif-latin-400-normal)-[A-Za-z0-9_-]+\.woff2#';

    /**
     * <link>/<script> tags for an entry (e.g. "src/admin/main.ts"). With a
     * nonce, the stylesheets are inlined in <style nonce> blocks: no
     * render-blocking request, and the page paints in one round trip.
     */
    public function tags(string $entry, ?string $nonce = null): string
    {
        if ($this->devServer !== null) {
            $base = $this->devServer . self::BASE;

            return '<script type="module" src="' . self::e($base . '@vite/client') . '"></script>' . "\n"
                . '<script type="module" src="' . self::e($base . $entry) . '"></script>';
        }

        $chunk = $this->chunk($entry);
        $tags = [];
        $inline = '';
        foreach ($this->cssFiles($entry) as $css) {
            $content = $nonce === null ? false : @file_get_contents($this->assetsDir . '/' . $css);
            if ($content !== false && strlen($inline) + strlen($content) <= self::INLINE_CSS_MAX && !str_contains($content, '</')) {
                $inline .= $content . "\n";
            } else {
                $tags[] = '<link rel="stylesheet" href="' . self::e(self::BASE . $css) . '">';
            }
        }
        if ($inline !== '') {
            preg_match_all(self::PRELOAD_FONTS, $inline, $fonts);
            foreach (array_unique($fonts[0]) as $font) {
                $tags[] = '<link rel="preload" href="' . self::e($font) . '" as="font" type="font/woff2" crossorigin>';
            }
            $tags[] = '<style nonce="' . self::e((string) $nonce) . '">' . $inline . '</style>';
        }
        foreach ($this->importedFiles($entry) as $file) {
            $tags[] = '<link rel="modulepreload" href="' . self::e(self::BASE . $file) . '">';
        }
        $tags[] = '<script type="module" src="' . self::e(self::BASE . $chunk['file']) . '"></script>';

        return implode("\n", $tags);
    }

    /**
     * URL of a CSS-only entry (e.g. "src/styles/base.css"), or null if not built.
     */
    public function cssUrl(string $entry): ?string
    {
        if ($this->devServer !== null) {
            return $this->devServer . self::BASE . $entry;
        }
        $manifest = $this->manifest();

        return isset($manifest[$entry]['file']) ? self::BASE . $manifest[$entry]['file'] : null;
    }

    /**
     * @return list<string>
     */
    private function cssFiles(string $entry): array
    {
        $files = [];
        $visit = function (string $key) use (&$visit, &$files): void {
            $chunk = $this->chunk($key);
            foreach ($chunk['imports'] ?? [] as $import) {
                $visit($import);
            }
            foreach ($chunk['css'] ?? [] as $css) {
                $files[$css] = true;
            }
        };
        $visit($entry);

        return array_keys($files);
    }

    /**
     * @return list<string>
     */
    private function importedFiles(string $entry): array
    {
        $files = [];
        $visit = function (string $key) use (&$visit, &$files): void {
            foreach ($this->chunk($key)['imports'] ?? [] as $import) {
                $files[$this->chunk($import)['file']] = true;
                $visit($import);
            }
        };
        $visit($entry);

        return array_keys($files);
    }

    /**
     * @return array<string, mixed>
     */
    private function chunk(string $key): array
    {
        $manifest = $this->manifest();
        if (!isset($manifest[$key]['file'])) {
            throw new \RuntimeException('Front-end entry not built: ' . $key . ' (run npm run build).');
        }

        return $manifest[$key];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function manifest(): array
    {
        if ($this->manifest === null) {
            $json = is_file($this->manifestFile()) ? file_get_contents($this->manifestFile()) : false;
            $this->manifest = $json === false ? [] : (array) json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        }

        return $this->manifest;
    }

    private function manifestFile(): string
    {
        return $this->assetsDir . '/.vite/manifest.json';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
