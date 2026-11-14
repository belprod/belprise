<?php

declare(strict_types=1);

namespace Belprise\Database;

use PDO;

/**
 * Applies app/migrations/NNNN_name.sql in order. The schema version is kept
 * in PRAGMA user_version, so the up-to-date check costs one query.
 *
 * A migration that rebuilds a table referenced by others starts with the
 * line "-- belprise:foreign-keys-off": foreign keys are then disabled for the
 * batch (dropping the old table must not cascade), and every reference is
 * checked before COMMIT.
 */
final class Migrator
{
    public const FOREIGN_KEYS_OFF = '-- belprise:foreign-keys-off';

    public function __construct(
        private readonly PDO $db,
        private readonly string $dir,
    ) {
    }

    public function currentVersion(): int
    {
        return (int) $this->db->query('PRAGMA user_version')->fetchColumn();
    }

    /**
     * @return array<int, string> version => file path, sorted
     */
    public function available(): array
    {
        $files = [];
        foreach (glob($this->dir . '/*.sql') ?: [] as $file) {
            if (preg_match('/^(\d+)_[a-z0-9_]+\.sql$/', basename($file), $m) !== 1) {
                throw new \RuntimeException('Invalid migration file name: ' . basename($file));
            }
            $version = (int) $m[1];
            if (isset($files[$version])) {
                throw new \RuntimeException('Duplicate migration version: ' . $version);
            }
            $files[$version] = $file;
        }
        ksort($files);

        return $files;
    }

    /**
     * @return list<int> versions applied by this call
     */
    public function migrate(): array
    {
        $available = $this->available();
        if ($available === [] || $this->currentVersion() >= array_key_last($available)) {
            return [];
        }

        $applied = [];
        $pending = array_filter($available, fn (int $v) => $v > $this->currentVersion(), ARRAY_FILTER_USE_KEY);
        $keysOff = false;
        foreach ($pending as $file) {
            $keysOff = $keysOff || str_starts_with((string) file_get_contents($file), self::FOREIGN_KEYS_OFF);
        }
        if ($keysOff) {
            // Only possible outside a transaction.
            $this->db->exec('PRAGMA foreign_keys = OFF');
        }
        // IMMEDIATE takes the write lock: concurrent requests wait, then see the new version.
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $current = $this->currentVersion();
            foreach ($available as $version => $file) {
                if ($version <= $current) {
                    continue;
                }
                $sql = file_get_contents($file);
                if ($sql === false) {
                    throw new \RuntimeException('Unreadable migration: ' . basename($file));
                }
                $this->db->exec($sql);
                $this->db->exec('PRAGMA user_version = ' . $version);
                $applied[] = $version;
            }
            if ($keysOff && $this->db->query('PRAGMA foreign_key_check')->fetch() !== false) {
                throw new \RuntimeException('Migration left broken foreign keys.');
            }
            $this->db->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        } finally {
            if ($keysOff) {
                $this->db->exec('PRAGMA foreign_keys = ON');
            }
        }

        return $applied;
    }
}
