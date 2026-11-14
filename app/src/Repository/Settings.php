<?php

declare(strict_types=1);

namespace Belprise\Repository;

use PDO;

final class Settings
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function get(string $key): ?string
    {
        $stmt = $this->db->prepare('SELECT value FROM settings WHERE key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    public function set(string $key, string $value): void
    {
        $this->db->prepare(
            'INSERT INTO settings (key, value) VALUES (?, ?)
             ON CONFLICT (key) DO UPDATE SET value = excluded.value'
        )->execute([$key, $value]);
    }

    /**
     * Inserts only if the key does not exist yet.
     *
     * @return bool true if the value was inserted
     */
    public function add(string $key, string $value): bool
    {
        $stmt = $this->db->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT (key) DO NOTHING');
        $stmt->execute([$key, $value]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Compare-and-set: writes $value only if the stored value is still
     * $expected (null = key absent).
     *
     * @return bool true if the value was written
     */
    public function replace(string $key, ?string $expected, string $value): bool
    {
        if ($expected === null) {
            return $this->add($key, $value);
        }
        $stmt = $this->db->prepare('UPDATE settings SET value = ? WHERE key = ? AND value = ?');
        $stmt->execute([$value, $key, $expected]);

        return $stmt->rowCount() === 1;
    }

    public function delete(string $key): void
    {
        $this->db->prepare('DELETE FROM settings WHERE key = ?')->execute([$key]);
    }
}
