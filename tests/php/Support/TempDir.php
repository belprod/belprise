<?php

declare(strict_types=1);

namespace Belprise\Tests\Support;

/** Creates a unique temporary directory, removed by the caller with remove(). */
final class TempDir
{
    public static function create(): string
    {
        $dir = sys_get_temp_dir() . '/belprise-test-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);

        return $dir;
    }

    public static function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
