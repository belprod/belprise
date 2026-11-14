<?php

declare(strict_types=1);

namespace Belprise\Repository;

use PDO;

/** Platform operators (CAHIER_DES_CHARGES §5.11): no organization, TOTP always on. */
final class Superadmins
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->one('SELECT * FROM superadmins WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return $this->one('SELECT * FROM superadmins WHERE email = ? COLLATE NOCASE', [trim($email)]);
    }

    public function create(string $email, string $name, string $passwordHash, string $sealedTotpSecret, int $step): int
    {
        $this->db->prepare(
            'INSERT INTO superadmins (email, name, password_hash, totp_secret, totp_last_step, session_epoch) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([trim($email), $name, $passwordHash, $sealedTotpSecret, $step, bin2hex(random_bytes(8))]);

        return (int) $this->db->lastInsertId();
    }

    public function claimTotpStep(int $id, int $step): bool
    {
        $stmt = $this->db->prepare('UPDATE superadmins SET totp_last_step = ? WHERE id = ? AND (totp_last_step IS NULL OR totp_last_step < ?)');
        $stmt->execute([$step, $id, $step]);

        return $stmt->rowCount() === 1;
    }

    public function touch(int $id, string $at): void
    {
        $this->db->prepare('UPDATE superadmins SET last_login_at = ? WHERE id = ?')->execute([$at, $id]);
    }

    /**
     * @param list<mixed> $params
     * @return array<string, mixed>|null
     */
    private function one(string $sql, array $params): ?array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }
}
