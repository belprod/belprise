<?php

declare(strict_types=1);

namespace Belprise\View;

use Belprise\Security\SecurityHeaders;

/**
 * Server-rendered error pages (no JavaScript).
 * The 404 is deliberately identical for every cause: unknown, disabled,
 * expired or unsigned — nothing tells them apart.
 */
final class ErrorPages
{
    public static function notFound(?string $cssUrl): string
    {
        return self::page(
            $cssUrl,
            'Page introuvable',
            '404',
            'Page introuvable',
            'Ce lien n’existe pas ou n’est plus disponible.',
        );
    }

    public static function unavailable(?string $cssUrl): string
    {
        return self::page(
            $cssUrl,
            'Service indisponible',
            'Erreur',
            'Service indisponible',
            'Une erreur est survenue. Réessayez dans quelques instants.',
        );
    }

    private static function page(?string $cssUrl, string $title, string $code, string $heading, string $message): string
    {
        $css = $cssUrl === null
            ? ''
            : '<link rel="stylesheet" href="' . htmlspecialchars($cssUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">' . "\n";

        return '<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="' . SecurityHeaders::ROBOTS . '">
<meta name="referrer" content="no-referrer">
<meta name="color-scheme" content="dark">
<title>' . $title . '</title>
<link rel="icon" href="' . Vite::BASE . 'favicon.svg" type="image/svg+xml">
' . $css . '</head>
<body class="error-page">
<main class="error-page__main">
<p class="eyebrow">// ' . $code . '</p>
<h1 class="error-page__title">' . $heading . '</h1>
<p class="error-page__text">' . $message . '</p>
</main>
<footer class="signature"><span class="wordmark">belprise<span class="wordmark__dot">.</span></span> · propulsé par <a href="https://belprod.fr" rel="noopener noreferrer">belprod</a></footer>
</body>
</html>
';
    }
}
