<?php

declare(strict_types=1);

namespace Belprise\Security;

use Belprise\Http\Request;

/**
 * What each role may do in its organization (CAHIER_DES_CHARGES §2.2):
 * Lecteur reads, Éditeur also creates / edits / archives / imports /
 * replies, Administrateur also deletes for good and manages the team.
 */
final class Roles
{
    public const VIEWER = 'viewer';
    public const EDITOR = 'editor';
    public const ADMIN = 'admin';

    private const RANK = [self::VIEWER => 1, self::EDITOR => 2, self::ADMIN => 3];

    public const LABELS = [self::ADMIN => 'Administrateur', self::EDITOR => 'Éditeur', self::VIEWER => 'Lecteur'];

    /** Personal actions, open to every role whatever the method. */
    private const SELF_SERVICE = [
        '#^/api/admin/(logout|password|account)$#',
        '#^/api/admin/totp/(setup|enable|disable|recovery-codes)$#',
        // Opening a review marks its revisions read for everyone.
        '#^/api/admin/versions/[0-9]+/comments/read$#',
    ];

    /** Administrator only, even to read. */
    private const ADMIN_ONLY = [
        '#^/api/admin/(users|invitations)(/|$)#',
        '#^/api/admin/organization(/|$)#',
    ];

    /** Deleting for good: administrator only. */
    private const DELETE_ADMIN = '#^/api/admin/(clients|projects|videos|versions)/[0-9]+$#';

    public static function required(Request $request): string
    {
        foreach (self::ADMIN_ONLY as $pattern) {
            if (preg_match($pattern, $request->path) === 1) {
                return self::ADMIN;
            }
        }
        if ($request->isSafeMethod()) {
            return self::VIEWER;
        }
        foreach (self::SELF_SERVICE as $pattern) {
            if (preg_match($pattern, $request->path) === 1) {
                return self::VIEWER;
            }
        }
        if ($request->method === 'DELETE' && preg_match(self::DELETE_ADMIN, $request->path) === 1) {
            return self::ADMIN;
        }

        return self::EDITOR;
    }

    public static function allows(string $role, string $required): bool
    {
        return (self::RANK[$role] ?? 0) >= self::RANK[$required];
    }
}
