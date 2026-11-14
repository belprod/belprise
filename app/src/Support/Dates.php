<?php

declare(strict_types=1);

namespace Belprise\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Dates are stored in UTC ("YYYY-MM-DD HH:MM:SS"). Link expiry dates are
 * chosen as a calendar day in Paris time: the link works until the end of
 * that day.
 */
final class Dates
{
    public const LOCAL_TIMEZONE = 'Europe/Paris';

    public static function toIso(string $utc): string
    {
        return str_replace(' ', 'T', $utc) . 'Z';
    }

    /** "2026-12-31" → "2026-12-31 22:59:59" (end of that day in Paris, in UTC). */
    public static function localDateToExpiry(string $date): string
    {
        return (new DateTimeImmutable($date . ' 23:59:59', new DateTimeZone(self::LOCAL_TIMEZONE)))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    /** Start of a local (Paris) day, in UTC. */
    public static function localDayStart(string $date): string
    {
        return (new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone(self::LOCAL_TIMEZONE)))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    /** First instant of the current month in Paris, in UTC. */
    public static function monthStart(?DateTimeImmutable $now = null): string
    {
        $local = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone(self::LOCAL_TIMEZONE));

        return self::localDayStart($local->format('Y-m-01'));
    }

    private const MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    /** "2026-12-06 09:00:00" (UTC) → "6 décembre 2026" (Paris), for e-mails and messages. */
    public static function frenchDay(string $utc): string
    {
        $local = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(self::LOCAL_TIMEZONE));

        return $local->format('j') . ($local->format('j') === '1' ? 'er' : '') . ' ' . self::MONTHS[(int) $local->format('n') - 1] . ' ' . $local->format('Y');
    }

    public static function expiryToLocalDate(string $utc): string
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone(self::LOCAL_TIMEZONE))
            ->format('Y-m-d');
    }
}
