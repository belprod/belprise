<?php

declare(strict_types=1);

namespace Belprise\Controller\Admin;

use Belprise\Controller\ClientReviewController;
use Belprise\Http\FileResponse;
use Belprise\Http\HttpException;
use Belprise\Http\Request;
use Belprise\Http\Response;
use Belprise\Media\HlsStore;
use Belprise\Media\MasterFormat;
use Belprise\Media\MediaMeta;
use Belprise\Repository\Versions;
use Belprise\Services;
use Belprise\Storage;
use Belprise\Support\Input;
use Belprise\Timecode;

/**
 * Versions of a video (§5.4): publication, status, manual metadata
 * correction, poster and thumbnail sheet, deletion.
 */
final class VersionsController
{
    public const POSTER_MAX_BYTES = 8 * 1024 * 1024;
    public const SPRITE_MAX_BYTES = 16 * 1024 * 1024;
    public const THUMB_MAX_BYTES = 1024 * 1024;
    public const SPRITE_TILE = [256, 144];
    public const SPRITE_MAX_COUNT = 200;

    public function __construct(private readonly Services $services)
    {
    }

    public function list(int $videoId): Response
    {
        $this->services->videos()->find($videoId) ?? throw HttpException::notFound();

        return Response::json(array_map(
            $this->services->presenter()->version(...),
            $this->services->versions()->forVideo($videoId),
        ));
    }

    /**
     * Body, all optional: published (bool), status, and the metadata
     * fpsNum, fpsDen, dropFrame, startTimecode ("HH:MM:SS:FF"), width, height.
     */
    public function update(int $id, Request $request): Response
    {
        $version = $this->find($id);
        $input = new Input($request->json());
        $fields = [];
        $now = gmdate('Y-m-d H:i:s');

        // "En cours" means unpublished; any other status means published.
        if ($input->has('published')) {
            $published = $input->bool('published', 'Publiée');
            $fields['is_published'] = $published ? 1 : 0;
            if ($published) {
                $fields['published_at'] = $now;
                if ($version['status'] === 'in_progress') {
                    $fields['status'] = 'to_review';
                }
            } else {
                $fields['status'] = 'in_progress';
            }
        }
        if ($input->has('status')) {
            $status = $input->string('status', 'Statut', 20);
            if (!in_array($status, Versions::STATUSES, true)) {
                throw new HttpException(422, 'Statut inconnu.', ['field' => 'status']);
            }
            $fields['status'] = $status;
            $fields['is_published'] = $status === 'in_progress' ? 0 : 1;
            if ($status !== 'in_progress' && (int) $version['is_published'] === 0) {
                $fields['published_at'] = $now;
            }
        }
        if (isset($fields['status'])) {
            $approved = $fields['status'] === 'approved';
            $fields['approved_by'] = $approved ? ($version['approved_by'] ?? $this->organizationName()) : null;
            $fields['approved_at'] = $approved ? ($version['approved_at'] ?? $now) : null;
        }

        if ($input->has('fpsNum') || $input->has('startTimecode') || $input->has('width')) {
            $fields += $this->metadata($version, $input);
        }

        $this->services->versions()->update($id, $fields);
        if (isset($fields['status']) && $fields['status'] !== $version['status']) {
            $this->services->activity()->record(
                'status',
                (int) $version['video_id'],
                $id,
                (int) $version['number'],
                (string) ($this->services->user()['name'] ?? ''),
                true,
                (string) $fields['status'],
            );
        }

        return Response::json($this->services->presenter()->version($this->find($id)));
    }

    public function delete(int $id): Response
    {
        $version = $this->find($id);
        $this->services->versions()->delete($id);
        $this->services->storage()->deleteTree($this->dir($version));

        return Response::json(['deleted' => true]);
    }

    /**
     * Poster and/or thumbnail sheet, JPEG images made in the browser.
     * Fields: poster (file), posterFrame (int), thumb (file, 640 px), sprite (file), spriteMeta (JSON).
     */
    public function images(int $id, Request $request): Response
    {
        $version = $this->find($id);
        $form = $request->form();
        $fields = [];

        if (isset($request->files['poster'])) {
            $this->storeJpeg($request->files['poster'], $this->dir($version) . '/poster.jpg', self::POSTER_MAX_BYTES, 3840);
            $fields['poster_path'] = $this->dir($version) . '/poster.jpg';
            $frame = $form['posterFrame'] ?? null;
            $fields['poster_frame'] = is_string($frame) && ctype_digit($frame) && strlen($frame) <= 12
                && (int) $frame < (int) $version['duration_frames'] ? (int) $frame : null;
        }
        if (isset($request->files['thumb'])) {
            $this->storeJpeg($request->files['thumb'], $this->dir($version) . '/thumb.jpg', self::THUMB_MAX_BYTES, 640);
            $fields['thumb_path'] = $this->dir($version) . '/thumb.jpg';
        }
        if (isset($request->files['sprite'])) {
            $metaJson = $form['spriteMeta'] ?? '';
            $meta = self::spriteMeta(is_string($metaJson) ? $metaJson : '');
            $this->storeJpeg(
                $request->files['sprite'],
                $this->dir($version) . '/sprite.jpg',
                self::SPRITE_MAX_BYTES,
                $meta['columns'] * self::SPRITE_TILE[0],
                (int) ceil($meta['count'] / $meta['columns']) * self::SPRITE_TILE[1],
            );
            $fields['sprite_path'] = $this->dir($version) . '/sprite.jpg';
            $fields['sprite_meta'] = json_encode($meta, JSON_THROW_ON_ERROR);
        }
        if ($fields === []) {
            throw new HttpException(422, 'Aucune image reçue.');
        }
        $this->services->versions()->update($id, $fields);

        return Response::json($this->services->presenter()->version($this->find($id)));
    }

    /** The master, with Range support (admin preview and analysis). */
    public function file(int $id, Request $request): Response
    {
        $version = $this->find($id);
        $path = $this->services->storage()->absolute((string) $version['file_path']);
        if (!is_file($path)) {
            throw HttpException::notFound();
        }
        $response = new FileResponse($path, MasterFormat::contentType($path) ?? 'application/octet-stream', $request->header('Range'), $this->services->config->xsendfile);
        $response->setHeader('Cache-Control', 'private, no-store');

        return $response;
    }

    /** Removes the playback version before a new encoding (§5.7 bis). */
    public function clearHls(int $id): Response
    {
        (new HlsStore($this->services->db(), $this->services->storage()))->clear($this->find($id));

        return Response::json($this->services->presenter()->version($this->find($id)));
    }

    /**
     * "Générer la version de lecture" when the encoding is done on the server
     * (§5.7 bis): the version joins the queue of the scheduled task.
     */
    public function encode(int $id): Response
    {
        $version = $this->find($id);
        if ($this->services->encodingMode() !== 'server') {
            throw new HttpException(409, 'Les versions de lecture sont préparées dans le navigateur sur cette installation.');
        }
        if ($version['file_path'] === null) {
            throw new HttpException(409, 'Cette version n’a pas de master.');
        }
        $this->services->encodeJobs()->enqueue($id);

        return Response::json($this->services->presenter()->version($this->find($id)));
    }

    /** Playlists of the playback version, sent once its files are uploaded. */
    public function publishHls(int $id, Request $request): Response
    {
        $playlists = $request->json()['playlists'] ?? null;
        if (!is_array($playlists)) {
            throw new HttpException(422, 'Listes de lecture manquantes.', ['field' => 'playlists']);
        }
        (new HlsStore($this->services->db(), $this->services->storage()))->publish($this->find($id), $playlists);

        return Response::json($this->services->presenter()->version($this->find($id)));
    }

    /**
     * "Télécharger le master" in the admin (every role): the original file as
     * an attachment, e.g. to keep it before a purge (§5.12).
     */
    public function download(int $id, Request $request): Response
    {
        $version = $this->find($id);
        if ($version['file_path'] === null) {
            throw HttpException::notFound();
        }
        $path = $this->services->storage()->absolute((string) $version['file_path']);
        if (!is_file($path) || is_link($path)) {
            throw HttpException::notFound();
        }
        $name = ClientReviewController::downloadName((int) $version['video_number'], (string) $version['video_title'], (int) $version['number'], pathinfo($path, PATHINFO_EXTENSION));
        $response = new FileResponse($path, MasterFormat::contentType($path) ?? 'application/octet-stream', $request->header('Range'), $this->services->config->xsendfile);
        $response->setHeader('Content-Disposition', sprintf(
            'attachment; filename="%s"; filename*=UTF-8\'\'%s',
            ClientReviewController::asciiName($name),
            rawurlencode($name),
        ));
        $response->setHeader('Cache-Control', 'private, no-store');
        $response->setHeader('Content-Security-Policy', "default-src 'none'; sandbox");

        return $response;
    }

    /**
     * @param array<string, mixed> $version
     * @return array<string, mixed>
     */
    private function metadata(array $version, Input $input): array
    {
        [$num, $den, $dropFrame] = MediaMeta::rate($input);
        $width = $input->intRange('width', 'Largeur', 16, 8192);
        $height = $input->intRange('height', 'Hauteur', 16, 8192);
        MediaMeta::checkResolution($width, $height);

        $start = Timecode::toFrames($input->string('startTimecode', 'Timecode de départ', 11), $num, $den, $dropFrame);
        if ($start === null) {
            throw new HttpException(422, 'Timecode de départ invalide pour cette cadence.', ['field' => 'startTimecode']);
        }

        $fields = [
            'fps_num' => $num,
            'fps_den' => $den,
            'drop_frame' => $dropFrame ? 1 : 0,
            'start_frame' => $start,
            'width' => $width,
            'height' => $height,
        ];
        // The duration is a frame count: keep the same length in seconds.
        if ($version['duration_frames'] !== null && $version['fps_num'] !== null
            && ((int) $version['fps_num'] !== $num || (int) $version['fps_den'] !== $den)) {
            $seconds = (int) $version['duration_frames'] * (int) $version['fps_den'] / (int) $version['fps_num'];
            $fields['duration_frames'] = (int) round($seconds * $num / $den);
        }
        if ($start !== (int) $version['start_frame'] || $dropFrame !== ((int) $version['drop_frame'] === 1)) {
            $fields['timecode_source'] = 'manual';
        }

        return $fields;
    }

    /**
     * @param array{name: string, tmp_name: string, size: int, error: int} $upload
     */
    private function storeJpeg(array $upload, string $relative, int $maxBytes, int $maxWidth, ?int $maxHeight = null): void
    {
        if ($upload['error'] !== UPLOAD_ERR_OK || !is_file($upload['tmp_name']) || $upload['size'] > $maxBytes) {
            throw new HttpException(422, 'Image non reçue ou trop lourde.');
        }
        $content = (string) file_get_contents($upload['tmp_name']);
        $info = @getimagesizefromstring($content);
        if ($info === false || $info[2] !== IMAGETYPE_JPEG || $info[0] > $maxWidth || $info[0] < 16
            || $info[1] < 16 || $info[1] > ($maxHeight ?? $maxWidth)) {
            throw new HttpException(422, 'Image JPEG invalide.');
        }
        $target = $this->services->storage()->absolute($relative);
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0770, true);
        }
        file_put_contents($target, $content);
    }

    /**
     * @return array{columns: int, count: int, width: int, height: int, interval: float}
     */
    private static function spriteMeta(string $json): array
    {
        $meta = json_decode($json, true);
        $valid = is_array($meta)
            && is_int($meta['columns'] ?? null) && $meta['columns'] >= 1 && $meta['columns'] <= 20
            && is_int($meta['count'] ?? null) && $meta['count'] >= 1 && $meta['count'] <= self::SPRITE_MAX_COUNT
            && ($meta['width'] ?? null) === self::SPRITE_TILE[0] && ($meta['height'] ?? null) === self::SPRITE_TILE[1]
            && is_numeric($meta['interval'] ?? null) && $meta['interval'] > 0 && $meta['interval'] <= 3600;
        if (!$valid) {
            throw new HttpException(422, 'Description de la planche de miniatures invalide.');
        }

        return [
            'columns' => $meta['columns'],
            'count' => $meta['count'],
            'width' => $meta['width'],
            'height' => $meta['height'],
            'interval' => (float) $meta['interval'],
        ];
    }

    /**
     * @param array<string, mixed> $version
     */
    private function dir(array $version): string
    {
        return Storage::versionDir((int) $version['client_id'], (int) $version['video_number'], (int) $version['number']);
    }

    /**
     * @return array<string, mixed>
     */
    private function find(int $id): array
    {
        return $this->services->versions()->find($id) ?? throw HttpException::notFound();
    }

    private function organizationName(): string
    {
        $organization = $this->services->organizations()->find((int) $this->services->organizationId());

        return (string) ($organization['name'] ?? 'belprod.');
    }
}
