<?php

declare(strict_types=1);

namespace Belprise\Database;

use PDO;

final class Database
{
    /**
     * Opens the SQLite database (WAL mode) and applies pending migrations.
     */
    public static function open(string $file, string $migrationsDir): PDO
    {
        $pdo = new PDO('sqlite:' . $file, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA foreign_keys = ON');

        (new Migrator($pdo, $migrationsDir))->migrate();

        return $pdo;
    }

    /**
     * Runs $work in a write transaction (BEGIN IMMEDIATE) and returns its result.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function transaction(PDO $db, callable $work): mixed
    {
        $db->exec('BEGIN IMMEDIATE');
        try {
            $result = $work();
            $db->exec('COMMIT');

            return $result;
        } catch (\Throwable $e) {
            $db->exec('ROLLBACK');
            throw $e;
        }
    }
}
