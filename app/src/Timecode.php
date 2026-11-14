<?php

declare(strict_types=1);

namespace Belprise;

/**
 * SMPTE timecode ↔ frame number. Timecodes are always stored as frame
 * numbers; this converts for display, input and export only.
 * Same algorithm as frontend/src/lib/timecode.ts (shared test fixture).
 */
final class Timecode
{
    public static function nominalFps(int $num, int $den): int
    {
        return (int) round($num / $den);
    }

    /** Drop-frame exists for 29.97 and 59.94 only. */
    public static function supportsDropFrame(int $num, int $den): bool
    {
        $fps = self::nominalFps($num, $den);

        return $den === 1001 && ($fps === 30 || $fps === 60);
    }

    public static function framesPerDay(int $num, int $den, bool $dropFrame): int
    {
        return self::nominalFps($num, $den) * 86400 - self::dropPerMinute($num, $den, $dropFrame) * (24 * 60 - 24 * 6);
    }

    public static function fromFrames(int $frames, int $num, int $den, bool $dropFrame): string
    {
        $fps = self::nominalFps($num, $den);
        $drop = self::dropPerMinute($num, $den, $dropFrame);
        $day = self::framesPerDay($num, $den, $dropFrame);
        $n = (($frames % $day) + $day) % $day;

        if ($drop > 0) {
            $perTenMinutes = $fps * 600 - $drop * 9;
            $perMinute = $fps * 60 - $drop;
            $tens = intdiv($n, $perTenMinutes);
            $rest = $n % $perTenMinutes;
            $n += $drop * 9 * $tens + ($rest > $drop ? $drop * intdiv($rest - $drop, $perMinute) : 0);
        }

        $ff = $n % $fps;
        $totalSeconds = intdiv($n, $fps);

        return sprintf(
            '%02d:%02d:%02d%s%02d',
            intdiv($totalSeconds, 3600),
            intdiv($totalSeconds, 60) % 60,
            $totalSeconds % 60,
            $drop > 0 ? ';' : ':',
            $ff,
        );
    }

    /**
     * "HH:MM:SS:FF" (or ";" / "." before the frames) → frame number; null if invalid.
     */
    public static function toFrames(string $timecode, int $num, int $den, bool $dropFrame): ?int
    {
        if (preg_match('/^(\d{1,2}):(\d{2}):(\d{2})[:;.](\d{2})$/D', trim($timecode), $m) !== 1) {
            return null;
        }
        [$hh, $mm, $ss, $ff] = array_map('intval', array_slice($m, 1));
        $fps = self::nominalFps($num, $den);
        $drop = self::dropPerMinute($num, $den, $dropFrame);
        if ($hh > 23 || $mm > 59 || $ss > 59 || $ff >= $fps) {
            return null;
        }
        // Labels skipped by drop-frame counting do not exist.
        if ($drop > 0 && $ss === 0 && $ff < $drop && $mm % 10 !== 0) {
            return null;
        }
        $totalMinutes = $hh * 60 + $mm;

        return (($hh * 3600 + $mm * 60 + $ss) * $fps + $ff) - $drop * ($totalMinutes - intdiv($totalMinutes, 10));
    }

    private static function dropPerMinute(int $num, int $den, bool $dropFrame): int
    {
        return $dropFrame && self::supportsDropFrame($num, $den) ? intdiv(self::nominalFps($num, $den), 15) : 0;
    }
}
