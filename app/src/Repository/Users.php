<?php

declare(strict_types=1);

namespace Belprise\Repository;

use PDO;

/** Users of the organizations, with their role (CAHIER_DES_CHARGES §2.2). */
final class Users
{
    public const ROLES = ['admin', 'editor', 'viewer'];

    private const EDITABLE = ['email', 'name', 'password_hash', 'role', 'totp_secret', 'totp_last_step', 'session_epoch', 'last_login_at'];

    public function __construct(private readonly PDO $db)
    {
    }

    public function exists(): bool
    {
        return $this->db->query('SELECT 1 FROM users LIMIT 1')->fetchColumn() !== false;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM users WHERE id = ?', [$id]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        return $this->fetchOne('SELECT * FROM users WHERE email = ? COLLATE NOCASE', [trim($email)]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forOrganization(int $organizationId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE organization_id = ? ORDER BY name COLLATE NOCASE, id');
        $stmt->execute([$organizationId]);

        return $stmt->fetchAll();
    }

    public function count(int $organizationId, ?string $role = null): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM users WHERE organization_id = ? AND (? IS NULL OR role = ?)');
        $stmt->execute([$organizationId, $role, $role]);

        return (int) $stmt->fetchColumn();
    }

    public function create(int $organizationId, string $email, string $name, string $passwordHash, string $role): int
    {
        $this->db->prepare(
            'INSERT INTO users (organization_id, email, name, password_hash, role, session_epoch) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$organizationId, trim($email), $name, $passwordHash, $role, bin2hex(random_bytes(8))]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function update(int $id, array $fields): void
    {
        $fields = array_intersect_key($fields, array_flip(self::EDITABLE));
        if ($fields === []) {
            return;
        }
        $set = implode(', ', array_map(static fn ($c) => $c . ' = :' . $c, array_keys($fields)));
        $this->db->prepare('UPDATE users SET ' . $set . ", updated_at = datetime('now') WHERE id = :id")
            ->execute($fields + ['id' => $id]);
    }

    /**
     * Records a TOTP step only if it is newer than the stored one: two
     * requests with the same code cannot both succeed.
     */
    public function claimTotpStep(int $id, int $step): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE users SET totp_last_step = ? WHERE id = ? AND (totp_last_step IS NULL OR totp_last_step < ?)'
        );
        $stmt->execute([$step, $id, $step]);

        return $stmt->rowCount() === 1;
    }

    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    }

    /**
     * @param list<mixed> $params
     * @return array<string, mixed>|null
     */
    private function fetchOne(string $sql, array $params): ?array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }
}
