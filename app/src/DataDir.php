<?php

declare(strict_types=1);

namespace Belprise;

/**
 * Creates the data/ layout on first run.
 */
final class DataDir
{
    public const SUBDIRS = ['sessions', 'media', 'logos', 'uploads'];

    public static function prepare(string $dir): void
    {
        if (is_dir($dir . '/sessions')) {
            return;
        }
        foreach (['', ...array_map(static fn ($s) => '/' . $s, self::SUBDIRS)] as $sub) {
            $path = $dir . $sub;
            if (!is_dir($path) && !mkdir($path, 0770, true) && !is_dir($path)) {
                throw new \RuntimeException('Cannot create data directory: ' . $path);
            }
        }
        // Defence in depth if data/ ever ends up under a web root.
        if (!is_file($dir . '/.htaccess')) {
            file_put_contents($dir . '/.htaccess', "Require all denied\n");
        }
    }
}
