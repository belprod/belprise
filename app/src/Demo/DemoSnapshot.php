<?php

declare(strict_types=1);

namespace Belprise\Demo;

use Belprise\Database\Database;
use Belprise\Storage;
use PDO;

/**
 * The reference state of the demo (§5.16): a copy of the database and of the
 * files, taken once, put back every 6 hours by the scheduled task. Dates are
 * moved forward on each reset, so the demo always looks recent.
 */
final class DemoSnapshot
{
    public const DIR = 'demo-snapshot';
    private const STATE = 'demo-state.json';
    /** Folders of data/ that belong to the demo set; uploads/ and tmp/ are emptied. */
    private const TREES = ['media', 'logos'];

    private readonly Storage $storage;

    public function __construct(private readonly string $dataDir, private readonly string $migrationsDir)
    {
        $this->storage = new Storage($dataDir);
    }

    public function exists(): bool
    {
        return is_file($this->dataDir . '/' . self::DIR . '/belprise.sqlite');
    }

    /** Last reset (or snapshot), as a Unix time; null: never. */
    public function lastReset(): ?int
    {
        $state = @file_get_contents($this->dataDir . '/' . self::STATE);
        $data = is_string($state) ? json_decode($state, true) : null;

        return is_array($data) && is_int($data['lastReset'] ?? null) ? $data['lastReset'] : null;
    }

    /** A reset is due once per 6-hour slot. */
    public function due(int $now): bool
    {
        return $this->exists() && ($this->lastReset() ?? 0) < Demo::slotStart($now);
    }

    /** Takes the current state as the reference. */
    public function take(PDO $db, int $now): void
    {
        $tmp = self::DIR . '.tmp';
        $this->storage->deleteTree($tmp);
        $dir = $this->storage->absolute($tmp);
        if (!mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create ' . $dir);
        }
        $db->exec('VACUUM INTO ' . $db->quote($dir . '/belprise.sqlite'));
        foreach (self::TREES as $tree) {
            $this->sync($this->dataDir . '/' . $tree, $dir . '/' . $tree);
        }
        file_put_contents($dir . '/snapshot.json', json_encode(['takenAt' => $now]) . "\n");
        $this->storage->deleteTree(self::DIR);
        rename($dir, $this->dataDir . '/' . self::DIR);
        $this->remember($now);
    }

    /**
     * Puts the reference state back: every table, then the files.
     *
     * @return array{rows: int, copied: int, deleted: int}
     */
    public function restore(PDO $db, int $now): array
    {
        if (!$this->exists()) {
            throw new \RuntimeException('Aucun instantané de la démo : lancez d’abord « php app/bin/demo.php snapshot ».');
        }
        $dir = $this->dataDir . '/' . self::DIR;
        // After an update of belprise, the reference gets the new migrations too.
        Database::open($dir . '/belprise.sqlite', $this->migrationsDir);
        $meta = json_decode((string) @file_get_contents($dir . '/snapshot.json'), true);
        $shift = max(0, $now - (int) (is_array($meta) ? ($meta['takenAt'] ?? $now) : $now));

        $db->exec('PRAGMA foreign_keys = OFF');
        $db->exec('ATTACH DATABASE ' . $db->quote($dir . '/belprise.sqlite') . ' AS snap');
        try {
            $rows = Database::transaction($db, function () use ($db, $shift): int {
                $rows = 0;
                $tables = $db->query("SELECT name FROM main.sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($tables as $table) {
                    $columns = array_column($db->query('PRAGMA main.table_info(' . self::id($table) . ')')->fetchAll(PDO::FETCH_ASSOC), 'name');
                    $list = implode(', ', array_map(self::id(...), $columns));
                    $db->exec('DELETE FROM main.' . self::id($table));
                    $rows += (int) $db->exec("INSERT INTO main." . self::id($table) . " ({$list}) SELECT {$list} FROM snap." . self::id($table));
                    foreach ($columns as $column) {
                        if (str_ends_with($column, '_at') && $shift > 0) {
                            $c = self::id($column);
                            $db->exec("UPDATE main." . self::id($table) . " SET {$c} = datetime({$c}, '+{$shift} seconds') WHERE typeof({$c}) = 'text' AND {$c} GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9] *'");
                        }
                    }
                }
                if ($db->query("SELECT 1 FROM snap.sqlite_master WHERE name = 'sqlite_sequence'")->fetchColumn() !== false) {
                    $db->exec('DELETE FROM main.sqlite_sequence');
                    $db->exec('INSERT INTO main.sqlite_sequence (name, seq) SELECT name, seq FROM snap.sqlite_sequence');
                }

                return $rows;
            });
        } finally {
            $db->exec('DETACH DATABASE snap');
            $db->exec('PRAGMA foreign_keys = ON');
        }

        $copied = $deleted = 0;
        foreach (self::TREES as $tree) {
            [$c, $d] = $this->sync($dir . '/' . $tree, $this->dataDir . '/' . $tree);
            $copied += $c;
            $deleted += $d;
        }
        foreach (['uploads', 'tmp'] as $scratch) {
            $this->storage->deleteTree($scratch);
        }
        @mkdir($this->dataDir . '/uploads', 0770);
        $this->remember($now);

        return ['rows' => $rows, 'copied' => $copied, 'deleted' => $deleted];
    }

    /**
     * Makes $to a copy of $from: files missing or changed are copied, extra ones deleted.
     *
     * @return array{0: int, 1: int} files copied, files deleted
     */
    private function sync(string $from, string $to): array
    {
        $copied = $deleted = 0;
        if (!is_dir($to) && !mkdir($to, 0770, true) && !is_dir($to)) {
            throw new \RuntimeException('Cannot create ' . $to);
        }
        $wanted = [];
        if (is_dir($from)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS)) as $file) {
                /** @var \SplFileInfo $file */
                if (!$file->isFile() || $file->isLink()) {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen($from) + 1);
                $wanted[$relative] = true;
                $target = $to . '/' . $relative;
                if (is_file($target) && !is_link($target) && filesize($target) === $file->getSize() && filemtime($target) === $file->getMTime()) {
                    continue;
                }
                if (!is_dir(dirname($target))) {
                    mkdir(dirname($target), 0770, true);
                }
                if (is_link($target)) {
                    unlink($target);
                }
                copy($file->getPathname(), $target . '.part');
                rename($target . '.part', $target);
                touch($target, $file->getMTime());
                $copied++;
            }
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($to, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $relative = substr($item->getPathname(), strlen($to) + 1);
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname()); // only when empty
            } elseif (!isset($wanted[$relative])) {
                unlink($item->getPathname());
                $deleted++;
            }
        }

        return [$copied, $deleted];
    }

    private function remember(int $now): void
    {
        file_put_contents($this->dataDir . '/' . self::STATE, json_encode(['lastReset' => $now]) . "\n", LOCK_EX);
    }

    private static function id(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }
}
