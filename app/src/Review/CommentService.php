<?php

declare(strict_types=1);

namespace Belprise\Review;

use Belprise\Database\Database;
use Belprise\Http\HttpException;
use Belprise\Repository\Activity;
use Belprise\Repository\Comments;
use Belprise\Repository\Versions;
use Belprise\Support\Input;
use PDO;

/**
 * Rules shared by the client pages and the admin (§5.1, §5.4, §5.5).
 */
final class CommentService
{
    /** At most this many comments per address and window (abuse limit, §6). */
    public const ABUSE_LIMIT = 40;
    public const ABUSE_WINDOW = 600;
    public const NAME_MAX = 60;

    public function __construct(
        private readonly PDO $db,
        private readonly Comments $comments,
        private readonly Versions $versions,
    ) {
    }

    /**
     * Body: text, and either parentId (reply) or frameIn [+ frameOut] (positions from the start).
     *
     * @param array<string, mixed> $version row from Versions
     */
    public function create(array $version, Input $input, string $author, ?string $tokenHash, bool $admin, ?string $ipHash): int
    {
        $text = $input->string('text', 'Révision', Comments::MAX_LENGTH);
        $parentId = $input->optionalInt('parentId');

        if ($parentId !== null) {
            $parent = $this->comments->find($parentId);
            if ($parent === null || (int) $parent['version_id'] !== (int) $version['id']) {
                throw HttpException::notFound();
            }
            if ($parent['parent_id'] !== null) {
                throw new HttpException(422, 'On ne peut répondre qu’à une révision, pas à une réponse.', ['field' => 'parentId']);
            }
            $frameIn = (int) $parent['frame_in'];
            $frameOut = null;
        } else {
            $last = max(0, (int) $version['duration_frames'] - 1);
            $frameIn = $input->intRange('frameIn', 'Point d’entrée', 0, $last);
            $frameOut = $input->optionalInt('frameOut');
            if ($frameOut !== null && ($frameOut <= $frameIn || $frameOut > $last + 1)) {
                throw new HttpException(422, 'Le point de sortie doit être après le point d’entrée.', ['field' => 'frameOut']);
            }
        }

        if ($ipHash !== null) {
            $since = gmdate('Y-m-d H:i:s', time() - self::ABUSE_WINDOW);
            if ($this->comments->countRecentByIp($ipHash, $since) >= self::ABUSE_LIMIT) {
                throw new HttpException(429, 'Trop de révisions en peu de temps. Réessayez dans quelques minutes.');
            }
        }

        return Database::transaction($this->db, function () use ($version, $parentId, $author, $tokenHash, $admin, $frameIn, $frameOut, $text, $ipHash): int {
            $id = $this->comments->create([
                'version_id' => (int) $version['id'],
                'parent_id' => $parentId,
                'author_name' => $author,
                'author_token_hash' => $tokenHash,
                'is_admin' => $admin ? 1 : 0,
                'frame_in' => $frameIn,
                'frame_out' => $frameOut,
                'body' => $text,
                'is_read_by_admin' => $admin ? 1 : 0,
                'ip_hash' => $ipHash,
            ]);
            (new Activity($this->db))->record(
                $parentId === null ? 'revision' : 'reply',
                (int) $version['video_id'],
                (int) $version['id'],
                (int) $version['number'],
                $author,
                $admin,
            );
            // "À valider" becomes "Modifications demandées" at the first client comment (§5.4).
            $current = $this->versions->find((int) $version['id']);
            if (!$admin && $parentId === null && $current !== null && $current['status'] === 'to_review') {
                $this->versions->update((int) $version['id'], ['status' => 'changes_requested']);
            }

            return $id;
        });
    }

    /**
     * "Valider cette version": any status becomes "Validé", with the name and date (§5.1, §5.4).
     *
     * @param array<string, mixed> $version
     */
    public function approve(array $version, string $name): void
    {
        $this->versions->update((int) $version['id'], [
            'status' => 'approved',
            'approved_by' => $name,
            'approved_at' => gmdate('Y-m-d H:i:s'),
        ]);
        (new Activity($this->db))->record('approved', (int) $version['video_id'], (int) $version['id'], (int) $version['number'], $name);
    }

    public static function authorName(Input $input, string $key = 'author'): string
    {
        return $input->string($key, 'Nom', self::NAME_MAX);
    }
}
