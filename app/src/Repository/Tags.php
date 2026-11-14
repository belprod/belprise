<?php

declare(strict_types=1);

namespace Belprise\Repository;

use PDO;

final class Tags
{
    /** @param int|null $org organization of the signed-in user: every query is limited to it (null: no limit, public pages) */
    public function __construct(private readonly PDO $db, private readonly ?int $org = null)
    {
    }

    /**
     * All tag names, most used first (autocomplete).
     *
     * @return list<string>
     */
    public function names(): array
    {
        // In an organization: only the tags its videos use.
        $join = $this->org === null ? 'LEFT JOIN video_tags vt ON vt.tag_id = t.id' : 'JOIN video_tags vt ON vt.tag_id = t.id
             JOIN videos v ON v.id = vt.video_id JOIN projects p ON p.id = v.project_id
             JOIN clients c ON c.id = p.client_id AND c.organization_id = ' . $this->org;

        return $this->db->query(
            'SELECT t.name FROM tags t ' . $join . '
             GROUP BY t.id ORDER BY COUNT(vt.video_id) DESC, t.name COLLATE NOCASE'
        )->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Replaces the tags of a video. Tags are matched case-insensitively;
     * unused tags are removed. Call inside a write transaction.
     *
     * @param list<string> $names
     */
    public function sync(int $videoId, array $names): void
    {
        $this->db->prepare('DELETE FROM video_tags WHERE video_id = ?')->execute([$videoId]);

        $insertTag = $this->db->prepare('INSERT INTO tags (name) VALUES (?) ON CONFLICT (name) DO NOTHING');
        $findTag = $this->db->prepare('SELECT id FROM tags WHERE name = ?');
        $link = $this->db->prepare('INSERT OR IGNORE INTO video_tags (video_id, tag_id) VALUES (?, ?)');
        foreach ($names as $name) {
            $insertTag->execute([$name]);
            $findTag->execute([$name]);
            $link->execute([$videoId, (int) $findTag->fetchColumn()]);
        }

        $this->pruneUnused();
    }

    /** Removes tags no video uses any more. */
    public function pruneUnused(): void
    {
        $this->db->exec('DELETE FROM tags WHERE id NOT IN (SELECT tag_id FROM video_tags)');
    }
}
