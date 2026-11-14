<?php

declare(strict_types=1);

namespace Belprise\Review;

use Belprise\Support\Dates;

final class CommentPresenter
{
    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function comment(array $row, ?string $viewerTokenHash, bool $forAdmin): array
    {
        $deleted = $row['deleted_at'] !== null;
        $topLevel = $row['parent_id'] === null;
        $out = [
            'id' => (int) $row['id'],
            'parentId' => $topLevel ? null : (int) $row['parent_id'],
            'author' => $deleted ? '' : $row['author_name'],
            'isAdmin' => (int) $row['is_admin'] === 1,
            'frameIn' => (int) $row['frame_in'],
            'frameOut' => $row['frame_out'] === null ? null : (int) $row['frame_out'],
            'text' => $deleted ? '' : $row['body'],
            'status' => $topLevel ? $row['status'] : null,
            'deleted' => $deleted,
            'createdAt' => Dates::toIso((string) $row['created_at']),
            'mine' => !$deleted && $viewerTokenHash !== null && $row['author_token_hash'] !== null
                && hash_equals((string) $row['author_token_hash'], $viewerTokenHash),
        ];
        if ($forAdmin) {
            $out['read'] = (int) $row['is_read_by_admin'] === 1;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $version
     * @return array<string, mixed>
     */
    public static function versionState(array $version): array
    {
        return [
            'id' => (int) $version['id'],
            'status' => $version['status'],
            'approvedBy' => $version['approved_by'],
            'approvedAt' => $version['approved_at'] === null ? null : Dates::toIso((string) $version['approved_at']),
        ];
    }
}
