<?php

declare(strict_types=1);

namespace Belprise\Repository;

use PDO;

/** Reset links (password or two-factor) sent by e-mail: hashed token, single use, 1 hour. */
final class AccountResets
{
    public const LIFETIME = 3600;
    public const KINDS = ['password', 'totp'];

    public function __construct(private readonly PDO $db)
    {
    }

    /** New link; the previous unused ones of the same kind stop working. */
    public function create(int $userId, string $kind, string $requestedBy, int $now): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->db->prepare('DELETE FROM account_resets WHERE user_id = ? AND kind = ? AND used_at IS NULL')->execute([$userId, $kind]);
        $this->db->prepare('INSERT INTO account_resets (user_id, kind, token_hash, requested_by, expires_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$userId, $kind, Invitations::hash($token), $requestedBy, gmdate('Y-m-d H:i:s', $now + self::LIFETIME)]);

        return $token;
    }

    /**
     * @return array<string, mixed>|null the reset with the user it concerns
     */
    public function findUsable(string $token, int $now): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT r.*, u.email, u.name, u.organization_id, u.totp_secret, u.password_hash
             FROM account_resets r JOIN users u ON u.id = r.user_id
             WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > ?'
        );
        $stmt->execute([Invitations::hash($token), gmdate('Y-m-d H:i:s', $now)]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** Marks it used; false if another request used it first. */
    public function consume(int $id, int $now): bool
    {
        $stmt = $this->db->prepare('UPDATE account_resets SET used_at = ? WHERE id = ? AND used_at IS NULL');
        $stmt->execute([gmdate('Y-m-d H:i:s', $now), $id]);

        return $stmt->rowCount() === 1;
    }
}
