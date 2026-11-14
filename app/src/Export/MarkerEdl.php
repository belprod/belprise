<?php

declare(strict_types=1);

namespace Belprise\Export;

use Belprise\Timecode;

/**
 * Comments as CMX 3600 markers, in the form DaVinci Resolve reads with
 * Timelines > Import > Timeline Markers from EDL (§5.6):
 *
 *   001  001      V     C        01:00:04:10 01:00:04:11 01:00:04:10 01:00:04:11
 *    |C:ResolveColorYellow |M:Claire Martin : Logo trop tôt |D:1
 *
 * Timecodes are the source timecode (start timecode + position); D is the
 * duration in frames (1 for an instant). Yellow = "À faire", green = "Fait".
 */
final class MarkerEdl
{
    public const COLORS = ['todo' => 'ResolveColorYellow', 'done' => 'ResolveColorGreen', 'refused' => 'ResolveColorRed'];
    private const MAX_TEXT = 500;

    /**
     * @param list<array{frameIn: int, frameOut: int|null, author: string, text: string, status: string}> $markers
     */
    public static function build(string $title, int $fpsNum, int $fpsDen, bool $dropFrame, int $startFrame, array $markers): string
    {
        $dropFrame = $dropFrame && Timecode::supportsDropFrame($fpsNum, $fpsDen);
        $tc = static fn (int $frame): string => Timecode::fromFrames($frame, $fpsNum, $fpsDen, $dropFrame);

        $lines = [
            'TITLE: ' . self::clean($title, 70),
            'FCM: ' . ($dropFrame ? 'DROP FRAME' : 'NON-DROP FRAME'),
            '',
        ];
        foreach (array_values($markers) as $i => $marker) {
            $in = $startFrame + $marker['frameIn'];
            $duration = $marker['frameOut'] === null ? 1 : max(1, $marker['frameOut'] - $marker['frameIn']);
            $start = $tc($in);
            $end = $tc($in + $duration);
            $lines[] = sprintf('%03d  001      V     C        %s %s %s %s  ', $i + 1, $start, $end, $start, $end);
            $lines[] = sprintf(
                ' |C:%s |M:%s |D:%d',
                self::COLORS[$marker['status']] ?? self::COLORS['todo'],
                self::clean($marker['author'] . ' : ' . $marker['text'], self::MAX_TEXT),
                $duration,
            );
            $lines[] = '';
        }

        return implode("\n", $lines) . "\n";
    }

    /** "{n°}_{titre}_V{x}_marqueurs.edl" (§5.6). */
    public static function fileName(int $videoNumber, string $title, int $version): string
    {
        $title = trim(preg_replace('#[\\\\/:*?"<>|\x00-\x1F]+#u', ' ', $title) ?? '');

        return $videoNumber . '_' . ($title === '' ? 'video' : $title) . '_V' . $version . '_marqueurs.edl';
    }

    /** One line, no "|" (field separator), limited length. */
    private static function clean(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', str_replace('|', '/', $text)) ?? '');

        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . '…' : $text;
    }
}
