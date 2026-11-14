<?php

declare(strict_types=1);

namespace Belprise\View;

/** Colours written into the HTML shell, server side, so the page never flashes the defaults. */
interface Theme
{
    /** One ":root{…}" rule of CSS variables. */
    public function css(): string;

    /** "dark" or "light", for <meta name="color-scheme">. */
    public function colorScheme(): string;
}
