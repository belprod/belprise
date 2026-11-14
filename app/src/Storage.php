<?php

declare(strict_types=1);

namespace Belprise;

/**
 * Files under data/. Paths handed around are relative to data/
 * (e.g. "logos/12-ab12cd34.png", "media/3/1042").
 */
final class Storage
{
    public function __construct(public readonly string $dataDir)
    {
    }

    public function absolute(string $relative): string
    {
        if ($relative === '' || str_contains($relative, '..') || str_starts_with($relative, '/') || str_contains($relative, "\0")) {
            throw new \InvalidArgumentException('Invalid data path.');
        }

        return $this->dataDir . '/' . $relative;
    }

    public static function clientMediaDir(int $clientId): string
    {
        return 'media/' . $clientId;
    }

    public static function videoMediaDir(int $clientId, int $videoNumber): string
    {
        return 'media/' . $clientId . '/' . $videoNumber;
    }

    public static function versionDir(int $clientId, int $videoNumber, int $versionNumber): string
    {
        return self::videoMediaDir($clientId, $videoNumber) . '/v' . $versionNumber;
    }

    public static function uploadPart(int $uploadId): string
    {
        return 'uploads/' . $uploadId . '.part';
    }

    public function freeSpace(): ?int
    {
        $free = @disk_free_space($this->dataDir);

        return $free === false ? null : (int) $free;
    }

    /**
     * Space used by Belprise (data/) and left on the volume.
     *
     * @return array{used: int, free: int|null, total: int|null}
     */
    /**
     * Free and total space of the volume holding data/.
     *
     * @return array{free: int|null, total: int|null}
     */
    public function disk(): array
    {
        $total = @disk_total_space($this->dataDir);

        return ['free' => $this->freeSpace(), 'total' => $total === false ? null : (int) $total];
    }

    public function usage(): array
    {
        $used = 0;
        if (is_dir($this->dataDir)) {
            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dataDir, \FilesystemIterator::SKIP_DOTS));
            foreach ($items as $item) {
                /** @var \SplFileInfo $item */
                if ($item->isFile() && !$item->isLink()) {
                    $used += $item->getSize();
                }
            }
        }
        $total = @disk_total_space($this->dataDir);

        return ['used' => $used, 'free' => $this->freeSpace(), 'total' => $total === false ? null : (int) $total];
    }

    public function deleteFile(?string $relative): void
    {
        if ($relative === null || $relative === '') {
            return;
        }
        $path = $this->absolute($relative);
        if (is_file($path)) {
            unlink($path);
        }
    }

    /** Removes a directory under data/ and everything in it. */
    /** Bytes of a file or of a whole folder under data/ (links are not followed). */
    public function treeSize(string $relative): int
    {
        $path = $this->absolute($relative);
        if (is_link($path)) {
            return 0;
        }
        if (is_file($path)) {
            return (int) filesize($path);
        }
        if (!is_dir($path)) {
            return 0;
        }
        $size = 0;
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isFile() && !$item->isLink()) {
                $size += $item->getSize();
            }
        }

        return $size;
    }

    public function deleteTree(string $relative): void
    {
        $root = $this->absolute($relative);
        if (!is_dir($root) || is_link($root)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($root);
    }
}
