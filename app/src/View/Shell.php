<?php

declare(strict_types=1);

namespace Belprise\View;

use Belprise\Security\SecurityHeaders;

/**
 * HTML shell of every Svelte page. Client colours are injected here, server
 * side, so the page never flashes the default colours (the theme <style>
 * comes after the stylesheets so it overrides their defaults).
 */
final class Shell
{
    public const BOOT_ID = 'belprise-boot';

    public function __construct(private readonly Vite $vite)
    {
    }

    /**
     * @param array<string, mixed> $boot data handed to the front end (JSON)
     */
    public function render(string $entry, string $title, array $boot, string $nonce, ?Theme $theme = null): string
    {
        $css = $theme?->css() ?? '';
        $themeStyle = $css === '' ? '' : '<style nonce="' . self::e($nonce) . '">' . $css . "</style>\n";

        // JSON_HEX_* keeps "</script>" and quotes inert inside the data block.
        $json = json_encode(
            $boot,
            JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE,
        );

        return '<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="' . SecurityHeaders::ROBOTS . '">
<meta name="referrer" content="no-referrer">
<meta name="color-scheme" content="' . ($theme?->colorScheme() ?? 'dark') . '">
<title>' . self::e($title) . '</title>
<link rel="icon" href="' . Vite::BASE . 'favicon.svg" type="image/svg+xml">
' . $this->vite->tags($entry, $nonce) . "\n" . $themeStyle . '<script type="application/json" id="' . self::BOOT_ID . '">' . $json . '</script>
</head>
<body>
<div id="app"></div>
<noscript><p class="noscript">Belprise a besoin de JavaScript pour fonctionner.</p></noscript>
</body>
</html>
';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
