<?php

declare(strict_types=1);

namespace Belprise\Tests;

use Belprise\Media\MediaProbe;
use PHPUnit\Framework\TestCase;

/** Metadata of the masters read by ffprobe on the server (CAHIER_DES_CHARGES §5.7 bis). */
final class MediaProbeTest extends TestCase
{
    /** @param array<string, mixed> $video @param array<string, mixed> $format */
    private static function json(array $video, array $format = [], array $more = []): string
    {
        return (string) json_encode(['streams' => [['codec_type' => 'video'] + $video, ...$more], 'format' => $format]);
    }

    public function testAnMxfIn25FramesWithItsTimecodeOnTheContainer(): void
    {
        $meta = MediaProbe::parse(self::json(
            ['width' => 1920, 'height' => 1080, 'r_frame_rate' => '25/1', 'duration' => '10.000000'],
            ['duration' => '10.000000', 'bit_rate' => '120000000', 'tags' => ['timecode' => '10:00:00:00']],
        ));

        self::assertSame([
            'width' => 1920, 'height' => 1080, 'fps_num' => 25, 'fps_den' => 1, 'drop_frame' => 0, 'start_frame' => 900000,
            'duration_frames' => 250, 'timecode_source' => 'tmcd', 'bit_rate' => 120000000,
        ], $meta);
    }

    public function testFramesCountedByTheFileAndDropFrameTimecodeOnAStream(): void
    {
        $meta = MediaProbe::parse(self::json(
            ['width' => 1920, 'height' => 1080, 'r_frame_rate' => '30000/1001', 'nb_frames' => '1798'],
            ['duration' => '60.0'],
            [['codec_type' => 'data', 'tags' => ['timecode' => '01:00:00;00']]],
        ));

        self::assertSame([30000, 1001, 1, 107892, 1798, 'tmcd'], [$meta['fps_num'], $meta['fps_den'], $meta['drop_frame'], $meta['start_frame'], $meta['duration_frames'], $meta['timecode_source']]);
    }

    public function testWithoutTimecodeTheStartIsZeroAndAWeirdNominalRateFallsBackOnTheAverage(): void
    {
        $meta = MediaProbe::parse(self::json(['width' => 1280, 'height' => 720, 'r_frame_rate' => '90000/1', 'avg_frame_rate' => '24000/1001', 'duration' => '2.002']));

        self::assertSame([24000, 1001, 0, 'default', 48], [$meta['fps_num'], $meta['fps_den'], $meta['start_frame'], $meta['timecode_source'], $meta['duration_frames']]);
    }

    public function testUnreadableMastersAreRefusedWithAReason(): void
    {
        foreach ([
            '{"streams":[{"codec_type":"audio"}]}' => 'Aucune piste vidéo',
            self::json(['width' => 7680, 'height' => 4320, 'r_frame_rate' => '25/1', 'duration' => '1']) => '4K au maximum',
            self::json(['width' => 1920, 'height' => 1080, 'r_frame_rate' => '0/0', 'duration' => '1']) => 'Cadence illisible',
            self::json(['width' => 1920, 'height' => 1080, 'r_frame_rate' => '25/1']) => 'Durée illisible',
            'pas du json' => 'Aucune piste vidéo',
        ] as $json => $reason) {
            try {
                MediaProbe::parse($json);
                self::fail('refused: ' . $reason);
            } catch (\RuntimeException $e) {
                self::assertStringContainsString($reason, $e->getMessage());
            }
        }
    }
}
