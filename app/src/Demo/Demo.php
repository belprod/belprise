<?php

declare(strict_types=1);

namespace Belprise\Demo;

use Belprise\Http\Request;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Public demo (CAHIER_DES_CHARGES §5.16, 'demo' => true): shared accounts
 * shown on the sign-in screen, a reset every 6 hours, and no file can be
 * sent, whatever the call.
 */
final class Demo
{
    public const RESET_HOURS = 6;
    public const TIMEZONE = 'Europe/Paris';
    public const PASSWORD = 'belprise-demo';
    public const CREDIT = 'Films : Big Buck Bunny, Sintel et Tears of Steel, © Blender Foundation (blender.org), licence CC BY 3.0.';

    /** The shared accounts, one per role. Nobody receives e-mail at these addresses: the demo sends none. */
    public const ACCOUNTS = [
        ['role' => 'admin', 'name' => 'Alex Martin', 'email' => 'admin@demo.belprise.fr'],
        ['role' => 'editor', 'name' => 'Sam Leroy', 'email' => 'editeur@demo.belprise.fr'],
        ['role' => 'viewer', 'name' => 'Charlie Durand', 'email' => 'lecteur@demo.belprise.fr'],
    ];

    private const FILES = 'Dans la démo, aucun fichier ne peut être envoyé.';
    private const ACCOUNT = 'Les comptes de la démo ne sont pas modifiables.';
    private const MAIL = 'La démo n’envoie aucun e-mail : les identifiants sont affichés sur l’écran de connexion.';
    private const OFF = 'Impossible dans la démo.';

    /** [method, path, message]: refused whatever the role, the body or the session. */
    private const REFUSED = [
        // Files: videos, playback versions, images, logos (§5.16).
        ['POST', '#^/api/admin/uploads$#', self::FILES],
        ['PUT', '#^/api/admin/uploads/[0-9]+$#', self::FILES],
        ['POST', '#^/api/admin/uploads/[0-9]+/complete$#', self::FILES],
        ['POST', '#^/api/admin/versions/[0-9]+/(images|hls|encode)$#', self::FILES],
        ['DELETE', '#^/api/admin/versions/[0-9]+/hls$#', self::FILES],
        ['POST', '#^/api/admin/(organization|clients/[0-9]+|collections/[0-9]+)/logo$#', self::FILES],
        // The shared accounts: password, e-mail, two-factor authentication, role, removal.
        ['POST', '#^/api/admin/setup$#', self::OFF],
        ['POST', '#^/api/admin/password$#', self::ACCOUNT],
        ['PATCH', '#^/api/admin/account$#', self::ACCOUNT],
        ['POST', '#^/api/admin/totp/#', self::ACCOUNT],
        ['PATCH', '#^/api/admin/users/[0-9]+$#', self::ACCOUNT],
        ['DELETE', '#^/api/admin/users/[0-9]+$#', self::ACCOUNT],
        ['POST', '#^/api/admin/users/[0-9]+/(reset|transfer)$#', self::ACCOUNT],
        ['POST', '#^/api/admin/reset/#', self::ACCOUNT],
        ['POST', '#^/api/admin/invitation/#', self::ACCOUNT],
        // E-mails.
        ['POST', '#^/api/admin/forgot$#', self::MAIL],
        ['POST', '#^/api/admin/invitations(/[0-9]+/resend)?$#', self::MAIL],
        // The organization stays.
        ['POST', '#^/api/admin/organization/deletion$#', self::OFF],
    ];

    /** Why this request is refused in the demo, or null. */
    public static function refusal(Request $request): ?string
    {
        foreach (self::REFUSED as [$method, $pattern, $message]) {
            if ($request->method === $method && preg_match($pattern, $request->path) === 1) {
                return $message;
            }
        }
        // Two-factor authentication required for everybody would lock the shared accounts out.
        if ($request->method === 'PATCH' && $request->path === '/api/admin/organization') {
            try {
                $body = $request->json();
            } catch (\Throwable) {
                $body = [];
            }
            if (array_key_exists('totpRequired', $body)) {
                return self::ACCOUNT;
            }
        }

        return null;
    }

    /** Pages of the operator: the demo has none (§5.16). */
    public static function closed(Request $request): bool
    {
        return $request->path === '/superadmin' || str_starts_with($request->path, '/api/superadmin/');
    }

    /** Start of the current 6-hour slot (0 h, 6 h, 12 h, 18 h in Paris), as a Unix time. */
    public static function slotStart(int $now): int
    {
        $local = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone(self::TIMEZONE));
        $hour = intdiv((int) $local->format('G'), self::RESET_HOURS) * self::RESET_HOURS;

        return $local->setTime($hour, 0)->getTimestamp();
    }

    /** Next reset, as a Unix time. */
    public static function nextReset(int $now): int
    {
        $local = (new DateTimeImmutable('@' . self::slotStart($now)))->setTimezone(new DateTimeZone(self::TIMEZONE));

        return $local->modify('+' . self::RESET_HOURS . ' hours')->getTimestamp();
    }

    /**
     * What the sign-in screen and the banner show.
     *
     * @return array{resetAt: string, accounts: list<array{role: string, name: string, email: string, password: string}>, credit: string}
     */
    public static function state(int $now): array
    {
        return [
            'resetAt' => gmdate('Y-m-d\TH:i:s\Z', self::nextReset($now)),
            'accounts' => array_map(static fn (array $a) => $a + ['password' => self::PASSWORD], self::ACCOUNTS),
            'credit' => self::CREDIT,
        ];
    }
}
