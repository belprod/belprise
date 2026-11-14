<?php

declare(strict_types=1);

namespace Belprise\Repository;

use PDO;

/**
 * Comments on a version (§5.1): top-level comments carry a frame range and
 * a status; replies (one level) carry neither. Deletion is soft.
 */
final class Comments
{
    public const MAX_LENGTH = 5000;

    private const SELECT = 'SELECT c.*, ve.video_id, ve.number AS version_number, ve.status AS version_status,
            ve.is_published, ve.fps_num, ve.fps_den, ve.drop_frame, ve.start_frame
        FROM comments c JOIN versions ve ON ve.id = c.version_id';

    /** @param int|null $org organization of the signed-in user: every query is limited to it (null: no limit, public pages) */
    public function __construct(private readonly PDO $db, private readonly ?int $org = null)
    {
    }

    /** " AND …" restricting the version alias "ve" to the organization. */
    private function scope(): string
    {
        return $this->org === null ? '' : ' AND ve.video_id IN (SELECT v.id FROM videos v JOIN projects p ON p.id = v.project_id JOIN clients c ON c.id = p.client_id WHERE c.organization_id = ' . $this->org . ')';
    }

    /**
     * Comments of a version, in display order. A deleted top-level comment
     * is kept (without its text) when it still has replies.
     *
     * @return list<array<string, mixed>>
     */
    public function forVersion(int $versionId): array
    {
        $stmt = $this->db->prepare(self::SELECT . '
            WHERE c.version_id = :version' . $this->scope() . '
              AND (c.deleted_at IS NULL OR (c.parent_id IS NULL AND EXISTS (
                    SELECT 1 FROM comments r WHERE r.parent_id = c.id AND r.deleted_at IS NULL)))
            ORDER BY COALESCE((SELECT p.frame_in FROM comments p WHERE p.id = c.parent_id), c.frame_in),
                     COALESCE(c.parent_id, c.id), c.parent_id IS NOT NULL, c.created_at, c.id');
        $stmt->execute(['version' => $versionId]);

        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare(self::SELECT . ' WHERE c.id = ? AND c.deleted_at IS NULL' . $this->scope());
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array{version_id: int, parent_id: int|null, author_name: string, author_token_hash: string|null,
     *              is_admin: int, frame_in: int, frame_out: int|null, body: string, is_read_by_admin: int,
     *              ip_hash: string|null} $fields
     */
    public function create(array $fields): int
    {
        $this->db->prepare(
            'INSERT INTO comments (version_id, parent_id, author_name, author_token_hash, is_admin, frame_in, frame_out,
                body, is_read_by_admin, ip_hash)
             VALUES (:version_id, :parent_id, :author_name, :author_token_hash, :is_admin, :frame_in, :frame_out,
                :body, :is_read_by_admin, :ip_hash)'
        )->execute($fields);

        return (int) $this->db->lastInsertId();
    }

    public function updateBody(int $id, string $body): void
    {
        $this->db->prepare("UPDATE comments SET body = ?, updated_at = datetime('now') WHERE id = ?")->execute([$body, $id]);
    }

    public function setStatus(int $id, string $status): void
    {
        $this->db->prepare("UPDATE comments SET status = ?, updated_at = datetime('now') WHERE id = ?")->execute([$status, $id]);
    }

    public function softDelete(int $id): void
    {
        $this->db->prepare("UPDATE comments SET deleted_at = datetime('now'), updated_at = datetime('now') WHERE id = ?")
            ->execute([$id]);
    }

    /** Opening a version in the admin review page marks its comments as read (§5.7). */
    public function markVersionRead(int $versionId): int
    {
        $stmt = $this->db->prepare('UPDATE comments SET is_read_by_admin = 1 WHERE version_id = ? AND is_read_by_admin = 0');
        $stmt->execute([$versionId]);

        return $stmt->rowCount();
    }

    /** Comments posted from one (hashed) address since a date: abuse limit. */
    public function countRecentByIp(string $ipHash, string $since): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM comments WHERE ip_hash = ? AND created_at >= ?');
        $stmt->execute([$ipHash, $since]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Client comments and replies for "Révisions" and the search
     * (§5.7). Text search is done by the caller.
     *
     * @param array{status?: string, client?: int|null, project?: int|null, tag?: string|null,
     *              from?: string|null, to?: string|null} $filters dates in UTC
     * @return list<array<string, mixed>>
     */
    public function search(array $filters, int $limit = 500): array
    {
        $where = ['c.is_admin = 0', 'c.deleted_at IS NULL'];
        if ($this->org !== null) {
            $where[] = 'cl.organization_id = ' . $this->org;
        }
        $params = [];
        switch ($filters['status'] ?? 'unread') {
            case 'unread':
                $where[] = 'c.is_read_by_admin = 0';
                break;
            case 'todo':
            case 'done':
            case 'refused':
                $where[] = 'c.parent_id IS NULL AND c.status = :status';
                $params['status'] = $filters['status'];
                break;
        }
        foreach (['client' => 'cl.id', 'project' => 'pr.id'] as $key => $column) {
            if (($filters[$key] ?? null) !== null) {
                $where[] = $column . ' = :' . $key;
                $params[$key] = $filters[$key];
            }
        }
        if (($filters['tag'] ?? null) !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM video_tags vt JOIN tags t ON t.id = vt.tag_id WHERE vt.video_id = v.id AND t.name = :tag)';
            $params['tag'] = $filters['tag'];
        }
        if (($filters['from'] ?? null) !== null) {
            $where[] = 'c.created_at >= :from';
            $params['from'] = $filters['from'];
        }
        if (($filters['to'] ?? null) !== null) {
            $where[] = 'c.created_at <= :to';
            $params['to'] = $filters['to'];
        }

        $stmt = $this->db->prepare(
            'SELECT c.id, c.parent_id, c.author_name, c.frame_in, c.frame_out, c.body, c.status, c.is_read_by_admin, c.created_at,
                    COALESCE(p.frame_in, c.frame_in) AS position,
                    ve.id AS version_id, ve.number AS version_number, ve.fps_num, ve.fps_den, ve.drop_frame, ve.start_frame,
                    v.id AS video_id, v.number AS video_number, v.title AS video_title,
                    pr.id AS project_id, pr.name AS project_name, cl.id AS client_id, cl.name AS client_name,
                    (SELECT group_concat(t.name, \' \') FROM video_tags vt JOIN tags t ON t.id = vt.tag_id WHERE vt.video_id = v.id) AS tag_names
             FROM comments c
             JOIN versions ve ON ve.id = c.version_id
             JOIN videos v ON v.id = ve.video_id
             JOIN projects pr ON pr.id = v.project_id
             JOIN clients cl ON cl.id = pr.client_id
             LEFT JOIN comments p ON p.id = c.parent_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY c.created_at DESC, c.id DESC
             LIMIT ' . $limit
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * @return array{todo: int, unread: int}
     */
    public function counts(): array
    {
        $row = $this->db->query(
            "SELECT
                SUM(CASE WHEN c.parent_id IS NULL AND c.status = 'todo' THEN 1 ELSE 0 END) AS todo,
                SUM(CASE WHEN c.is_admin = 0 AND c.is_read_by_admin = 0 THEN 1 ELSE 0 END) AS unread
             FROM comments c JOIN versions ve ON ve.id = c.version_id WHERE c.deleted_at IS NULL" . $this->scope()
        )->fetch();

        return ['todo' => (int) ($row['todo'] ?? 0), 'unread' => (int) ($row['unread'] ?? 0)];
    }

    /**
     * Unread client comments and replies, newest first ("Révisions").
     *
     * @return list<array<string, mixed>>
     */
    public function unread(int $limit = 200): array
    {
        $stmt = $this->db->prepare(
            'SELECT c.id, c.parent_id, c.author_name, c.frame_in, c.frame_out, c.body, c.created_at,
                    COALESCE(p.frame_in, c.frame_in) AS position,
                    ve.id AS version_id, ve.number AS version_number, ve.fps_num, ve.fps_den, ve.drop_frame, ve.start_frame,
                    v.id AS video_id, v.number AS video_number, v.title AS video_title, cl.name AS client_name
             FROM comments c
             JOIN versions ve ON ve.id = c.version_id
             JOIN videos v ON v.id = ve.video_id
             JOIN projects pr ON pr.id = v.project_id
             JOIN clients cl ON cl.id = pr.client_id
             LEFT JOIN comments p ON p.id = c.parent_id
             WHERE c.is_read_by_admin = 0 AND c.is_admin = 0 AND c.deleted_at IS NULL' . ($this->org === null ? '' : ' AND cl.organization_id = ' . $this->org) . '
             ORDER BY c.created_at DESC, c.id DESC
             LIMIT ' . $limit
        );
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
