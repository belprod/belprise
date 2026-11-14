<?php

declare(strict_types=1);

namespace Belprise\Repository;

use PDO;

/**
 * Invitations to join an organization (CAHIER_DES_CHARGES §2.2): the token
 * is only ever stored as a SHA-256 hash, it is single use and expires.
 */
final class Invitations
{
    public const LIFETIME = 7 * 86400;

    public function __construct(private readonly PDO $db)
    {
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @return array{id: int, token: string}
     */
    public function create(int $organizationId, string $email, string $role, ?int $invitedBy, int $now): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->db->prepare(
            'INSERT INTO invitations (organization_id, email, role, token_hash, invited_by, expires_at) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$organizationId, trim($email), $role, self::hash($token), $invitedBy, gmdate('Y-m-d H:i:s', $now + self::LIFETIME)]);

        return ['id' => (int) $this->db->lastInsertId(), 'token' => $token];
    }

    /**
     * Pending (not accepted, not expired) invitation for this token.
     *
     * @return array<string, mixed>|null
     */
    public function findUsable(string $token, int $now): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT i.*, o.name AS organization_name FROM invitations i JOIN organizations o ON o.id = i.organization_id
             WHERE i.token_hash = ? AND i.accepted_at IS NULL AND i.expires_at > ?'
        );
        $stmt->execute([self::hash($token), gmdate('Y-m-d H:i:s', $now)]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id, int $organizationId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM invitations WHERE id = ? AND organization_id = ? AND accepted_at IS NULL');
        $stmt->execute([$id, $organizationId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Pending invitations of an organization, expired ones included (they can be sent again).
     *
     * @return list<array<string, mixed>>
     */
    public function pending(int $organizationId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM invitations WHERE organization_id = ? AND accepted_at IS NULL ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute([$organizationId]);

        return $stmt->fetchAll();
    }

    public function countPending(int $organizationId, int $now): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM invitations WHERE organization_id = ? AND accepted_at IS NULL AND expires_at > ?');
        $stmt->execute([$organizationId, gmdate('Y-m-d H:i:s', $now)]);

        return (int) $stmt->fetchColumn();
    }

    public function pendingFor(string $email): ?int
    {
        $stmt = $this->db->prepare('SELECT organization_id FROM invitations WHERE email = ? COLLATE NOCASE AND accepted_at IS NULL LIMIT 1');
        $stmt->execute([trim($email)]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    /** Marks it used; false if another request used it first. */
    public function accept(int $id, int $now): bool
    {
        $stmt = $this->db->prepare('UPDATE invitations SET accepted_at = ? WHERE id = ? AND accepted_at IS NULL');
        $stmt->execute([gmdate('Y-m-d H:i:s', $now), $id]);

        return $stmt->rowCount() === 1;
    }

    /** New token and expiry for a resend: the previous link stops working. */
    public function renew(int $id, int $now): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->db->prepare('UPDATE invitations SET token_hash = ?, expires_at = ? WHERE id = ?')
            ->execute([self::hash($token), gmdate('Y-m-d H:i:s', $now + self::LIFETIME), $id]);

        return $token;
    }

    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM invitations WHERE id = ?')->execute([$id]);
    }
}
