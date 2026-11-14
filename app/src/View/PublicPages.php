<?php

declare(strict_types=1);

namespace Belprise\View;

use Belprise\Security\SecurityHeaders;

/**
 * Server-rendered public pages (no JavaScript).
 */
final class PublicPages
{
    /**
     * Neutral password form: no client name, logo or colour, so it reveals
     * nothing about what the link leads to.
     */
    public static function password(?string $cssUrl, ?string $error): string
    {
        $css = $cssUrl === null
            ? ''
            : '<link rel="stylesheet" href="' . htmlspecialchars($cssUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">' . "\n";
        $errorHtml = $error === null
            ? ''
            : '<p class="form-error" role="alert" id="password-error">' . htmlspecialchars($error, ENT_QUOTES | ENT_HTML5, 'UTF-8') . "</p>\n";
        $describedBy = $error === null ? '' : ' aria-describedby="password-error" aria-invalid="true"';

        return '<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="' . SecurityHeaders::ROBOTS . '">
<meta name="referrer" content="no-referrer">
<meta name="color-scheme" content="dark">
<title>Accès protégé</title>
<link rel="icon" href="' . Vite::BASE . 'favicon.svg" type="image/svg+xml">
' . $css . '</head>
<body class="error-page">
<main class="error-page__main">
<p class="eyebrow">// Accès protégé</p>
<h1 class="error-page__title">Mot de passe</h1>
<p class="error-page__text">Ce lien est protégé. Saisissez le mot de passe qui vous a été communiqué.</p>
<form class="password-form" method="post">
<div class="field">
<label class="field__label" for="password">Mot de passe</label>
<input class="input" type="password" id="password" name="password" autocomplete="current-password" required autofocus' . $describedBy . '>
</div>
' . $errorHtml . '<button class="btn btn--primary" type="submit">Accéder</button>
</form>
</main>
<footer class="signature"><span class="wordmark">belprise<span class="wordmark__dot">.</span></span> · propulsé par <a href="https://belprod.fr" rel="noopener noreferrer">belprod</a></footer>
</body>
</html>
';
    }
}
