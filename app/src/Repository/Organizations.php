<?php

declare(strict_types=1);

namespace Belprise\Repository;

use PDO;

/** Organizations (CAHIER_DES_CHARGES §5.10). */
final class Organizations
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM organizations WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @param int|null $quota bytes; null: no limit */
    public function create(string $name, ?int $quota = null): int
    {
        $this->db->prepare('INSERT INTO organizations (name, quota_bytes) VALUES (?, ?)')->execute([$name, $quota]);

        return (int) $this->db->lastInsertId();
    }

    /** @param int|null $quota bytes; null: no limit */
    public function setQuota(int $id, ?int $quota): void
    {
        $this->db->prepare("UPDATE organizations SET quota_bytes = ?, updated_at = datetime('now') WHERE id = ?")->execute([$quota, $id]);
    }

    /** Back-office identity (§5.7): accent and dark or light background. */
    public function setBrand(int $id, string $accent, string $theme): void
    {
        $this->db->prepare("UPDATE organizations SET accent_color = ?, theme = ?, updated_at = datetime('now') WHERE id = ?")->execute([$accent, $theme, $id]);
    }

    public function setTotpRequired(int $id, bool $required): void
    {
        $this->db->prepare("UPDATE organizations SET totp_required = ?, updated_at = datetime('now') WHERE id = ?")->execute([$required ? 1 : 0, $id]);
    }

    public function setLogo(int $id, ?string $path): void
    {
        $this->db->prepare("UPDATE organizations SET logo = ?, updated_at = datetime('now') WHERE id = ?")->execute([$path, $id]);
    }

    public function rename(int $id, string $name): void
    {
        $this->db->prepare("UPDATE organizations SET name = ?, updated_at = datetime('now') WHERE id = ?")->execute([$name, $id]);
    }

    /**
     * Next video number of the organization (1001, 1002…). Call inside a
     * write transaction: the counter and the insert must not interleave.
     */
    public function nextVideoNumber(int $id): int
    {
        $stmt = $this->db->prepare(
            'SELECT MAX(o.last_video_number,
                        COALESCE((SELECT MAX(v.number) FROM videos v JOIN projects p ON p.id = v.project_id
                                  JOIN clients c ON c.id = p.client_id WHERE c.organization_id = o.id), 1000)) + 1
             FROM organizations o WHERE o.id = ?'
        );
        $stmt->execute([$id]);
        $number = (int) $stmt->fetchColumn();
        $this->db->prepare('UPDATE organizations SET last_video_number = ? WHERE id = ?')->execute([$number, $id]);

        return $number;
    }
}
