<?php

declare(strict_types=1);

namespace Belprise\Media;

use Belprise\Http\HttpException;
use Belprise\Support\Input;
use Belprise\Timecode;

/**
 * Metadata of a master, measured in the admin's browser (mp4box.js) and
 * checked again here. V1 accepts H.264 MP4 only, 4K at most (§5.7).
 */
final class MediaMeta
{
    public const MAX_LONG_SIDE = 4096;
    public const MAX_SHORT_SIDE = 2160;

    /**
     * @return array{codec: string, width: int, height: int, fps_num: int, fps_den: int, drop_frame: int,
     *               start_frame: int, duration_frames: int, bit_rate: int|null, timecode_source: string}
     */
    public static function fromInput(Input $input): array
    {
        $codec = $input->text('codec', 'Codec', 40);
        if (preg_match(MasterFormat::CODEC_PATTERN, $codec) !== 1) {
            throw new HttpException(422, 'Codec non pris en charge : H.264, HEVC ou ProRes (ou MXF).', ['field' => 'codec']);
        }
        $width = $input->intRange('width', 'Largeur', 16, 8192);
        $height = $input->intRange('height', 'Hauteur', 16, 8192);
        self::checkResolution($width, $height);

        [$num, $den, $dropFrame] = self::rate($input);
        $start = $input->intRange('startFrame', 'Timecode de départ', 0, Timecode::framesPerDay($num, $den, $dropFrame) - 1);
        $source = $input->has('timecodeSource') ? $input->text('timecodeSource', 'Source du timecode', 10) : 'default';
        if (!in_array($source, ['tmcd', 'xmp', 'default'], true)) {
            throw new HttpException(422, 'Source du timecode invalide.', ['field' => 'timecodeSource']);
        }

        return [
            'codec' => $codec,
            'width' => $width,
            'height' => $height,
            'fps_num' => $num,
            'fps_den' => $den,
            'drop_frame' => $dropFrame ? 1 : 0,
            'start_frame' => $start,
            'duration_frames' => $input->intRange('durationFrames', 'Durée', 1, Timecode::framesPerDay($num, $den, $dropFrame)),
            'bit_rate' => $input->optionalInt('bitRate'),
            'timecode_source' => $source,
        ];
    }

    /**
     * @return array{int, int, bool}
     */
    public static function rate(Input $input): array
    {
        $num = $input->intRange('fpsNum', 'Cadence', 1, 10_000_000);
        $den = $input->intRange('fpsDen', 'Cadence', 1, 1_000_000);
        if ($num / $den < 1 || $num / $den > 120) {
            throw new HttpException(422, 'Cadence invalide.', ['field' => 'fpsNum']);
        }
        $dropFrame = $input->has('dropFrame') && $input->bool('dropFrame', 'Drop-frame');

        return [$num, $den, $dropFrame && Timecode::supportsDropFrame($num, $den)];
    }

    public static function checkResolution(int $width, int $height): void
    {
        if (max($width, $height) > self::MAX_LONG_SIDE || min($width, $height) > self::MAX_SHORT_SIDE) {
            throw new HttpException(422, 'Résolution trop élevée (' . $width . '×' . $height . ') : 4K au maximum.', ['field' => 'width']);
        }
    }
}
