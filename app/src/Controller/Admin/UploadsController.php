<?php

declare(strict_types=1);

namespace Belprise\Controller\Admin;

use Belprise\Http\HttpException;
use Belprise\Http\Request;
use Belprise\Http\Response;
use Belprise\Media\HlsStore;
use Belprise\Media\MasterFormat;
use Belprise\Media\MediaMeta;
use Belprise\Media\VersionImporter;
use Belprise\Services;
use Belprise\Storage;
use Belprise\Support\Dates;
use Belprise\Support\Input;

/**
 * Chunked, resumable upload of masters (§5.7).
 *
 * 1. POST   /api/admin/uploads                 {videoId, name, size, fingerprint} → resumes a known file
 * 2. PUT    /api/admin/uploads/{id}?offset=N   raw chunk + X-Chunk-Sha256, written at its position
 * 3. POST   /api/admin/uploads/{id}/complete   {meta} → new version (the file is moved, not copied)
 *
 * The fingerprint (name, size, SHA-256 of the first and last 4 MiB) lets a
 * re-selected file resume after the tab was closed.
 */
final class UploadsController
{
    public const CHUNK_SIZE = 32 * 1024 * 1024;
    private const DISK_MARGIN = 512 * 1024 * 1024;
    public const FINGERPRINT_SAMPLE = 4 * 1024 * 1024;
    private const ORPHAN_DELAY = 3600;

    public function __construct(private readonly Services $services)
    {
    }

    public function list(Request $request): Response
    {
        $this->removeOrphanParts();
        $video = $request->queryString('video');
        $rows = $this->services->uploads()->all($video !== null && ctype_digit($video) ? (int) $video : null);

        return Response::json(array_map(self::present(...), $rows));
    }

    public function show(int $id): Response
    {
        return Response::json(self::present($this->find($id)));
    }

    public function start(Request $request): Response
    {
        $input = new Input($request->json());
        $videoId = $input->int('videoId', 'Vidéo');
        // A file of a playback version (§5.7 bis), for an existing version of this video.
        $target = $input->has('target') ? HlsStore::target($input->string('target', 'Fichier', 60)) : null;
        $versionId = null;
        if ($target !== null) {
            $version = $this->services->versions()->find($input->int('versionId', 'Version'));
            if ($version === null || (int) $version['video_id'] !== $videoId) {
                throw new HttpException(422, 'Cette version n’existe pas.', ['field' => 'versionId']);
            }
            $versionId = (int) $version['id'];
        }
        $name = $target === null ? self::fileName($input->string('name', 'Nom du fichier', 255)) : basename($target);
        $size = $input->intRange('size', 'Taille', 1, PHP_INT_MAX);
        $fingerprint = $input->string('fingerprint', 'Empreinte', 64);
        if (preg_match('/^[0-9a-f]{64}$/D', $fingerprint) !== 1) {
            throw new HttpException(422, 'Empreinte invalide.', ['field' => 'fingerprint']);
        }
        if ($this->services->videos()->find($videoId) === null) {
            throw new HttpException(422, 'Cette vidéo n’existe pas.', ['field' => 'videoId']);
        }

        $uploads = $this->services->uploads();
        $existing = $uploads->findByFingerprint($fingerprint);
        if ($existing !== null && ($existing['target'] !== $target || (int) $existing['version_id'] !== (int) $versionId)) {
            $existing = null; // same bytes for another purpose: a separate upload
        }
        if ($existing !== null) {
            if ((int) $existing['video_id'] !== $videoId) {
                $uploads->setVideo((int) $existing['id'], $videoId);
            }
            if (!is_file($this->part((int) $existing['id']))) {
                $uploads->setReceived((int) $existing['id'], 0);
            }

            return Response::json(self::present($this->find((int) $existing['id'])));
        }

        // Plan first (the organization's share), then the disk (the server's).
        $this->services->quota()->ensureRoom((int) $this->services->organizationId(), $size);
        $free = $this->services->storage()->freeSpace();
        if ($free !== null && $free < $size) {
            throw new HttpException(507, sprintf(
                'Espace disque insuffisant : %s disponibles, %s nécessaires.',
                self::gigabytes($free),
                self::gigabytes($size),
            ));
        }

        $id = $uploads->create($name, $size, $fingerprint, $videoId, $versionId, $target);
        touch($this->part($id));

        return Response::json(self::present($this->find($id)), 201);
    }

    /**
     * Writes one chunk at its offset. Chunks are sent in order; a chunk
     * sent again (retry after a cut) is accepted and changes nothing.
     */
    public function chunk(int $id, Request $request): Response
    {
        $upload = $this->find($id);
        $offset = $request->queryString('offset');
        if ($offset === null || !ctype_digit($offset)) {
            throw new HttpException(400, 'Position du morceau manquante.');
        }
        $offset = (int) $offset;
        $received = (int) $upload['bytes_received'];
        $size = (int) $upload['size_bytes'];
        $body = $request->body(self::CHUNK_SIZE);
        $length = strlen($body);

        if ($offset > $received) {
            throw new HttpException(409, 'Morceau hors séquence.', ['bytesReceived' => $received]);
        }
        if ($length === 0 || $length > self::CHUNK_SIZE || $offset + $length > $size) {
            throw new HttpException(400, 'Taille de morceau invalide.', ['bytesReceived' => $received]);
        }
        $expected = strtolower((string) $request->header('X-Chunk-Sha256'));
        if (!hash_equals(hash('sha256', $body), $expected)) {
            throw new HttpException(422, 'Morceau corrompu pendant l’envoi : il sera renvoyé.', ['bytesReceived' => $received]);
        }

        // Several uploads share the disk: check again for each chunk,
        // keeping a margin so the database never runs out of room.
        $free = $this->services->storage()->freeSpace();
        if ($free !== null && $free < $length + self::DISK_MARGIN) {
            throw new HttpException(507, 'Espace disque insuffisant pour continuer l’envoi.', ['bytesReceived' => $received]);
        }

        $handle = fopen($this->part($id), 'c+b');
        if ($handle === false || fseek($handle, $offset) !== 0 || fwrite($handle, $body) !== $length) {
            throw new \RuntimeException('Cannot write upload part ' . $id);
        }
        fflush($handle);
        fclose($handle);

        $received = max($received, $offset + $length);
        $this->services->uploads()->setReceived($id, $received);

        return Response::json(['bytesReceived' => $received]);
    }

    public function complete(int $id, Request $request): Response
    {
        $upload = $this->find($id);
        $size = (int) $upload['size_bytes'];
        if ((int) $upload['bytes_received'] !== $size) {
            throw new HttpException(409, 'L’envoi n’est pas terminé.', ['bytesReceived' => (int) $upload['bytes_received']]);
        }
        $part = $this->part($id);
        clearstatcache(true, $part);
        if (!is_file($part) || filesize($part) !== $size) {
            throw new HttpException(409, 'Le fichier reçu est incomplet. Renvoyez-le.');
        }
        if (!hash_equals((string) $upload['fingerprint'], self::fingerprint($part, (string) $upload['name']))) {
            throw new HttpException(422, 'Le fichier reçu ne correspond pas à l’original. Renvoyez-le.');
        }

        if ($upload['target'] !== null) {
            $version = $this->services->versions()->find((int) $upload['version_id']) ?? throw HttpException::notFound();
            (new HlsStore($this->services->db(), $this->services->storage()))->storeFile($version, $part, (string) $upload['target']);
            $this->services->uploads()->delete($id);

            return Response::json(['stored' => $upload['target']], 201);
        }

        $video = $this->services->videos()->find((int) $upload['video_id']) ?? throw HttpException::notFound();
        $serverEncoding = $this->services->encodingMode() === 'server';
        if (($request->json()['meta'] ?? null) === null && $serverEncoding && MasterFormat::extension((string) $upload['name']) === 'mxf') {
            // An MXF without its H.264 file: the scheduled task reads its metadata with ffprobe (§5.7 bis).
            $meta = ['codec' => 'mxf'];
        } else {
            $meta = MediaMeta::fromInput((new Input($request->json()))->object('meta', 'Métadonnées'));
        }
        $importer = new VersionImporter($this->services->db(), $this->services->versions(), $this->services->storage());
        $versionId = $importer->import($video, $part, (string) $upload['name'], $meta);
        $this->services->uploads()->delete($id);
        if ($serverEncoding) {
            // The playback version is encoded by the scheduled task: the admin can close the page.
            $this->services->encodeJobs()->enqueue($versionId);
        }

        $version = $this->services->versions()->find($versionId) ?? throw HttpException::notFound();

        return Response::json($this->services->presenter()->version($version), 201);
    }

    public function cancel(int $id): Response
    {
        $this->find($id);
        $this->services->uploads()->delete($id);
        $this->services->storage()->deleteFile(Storage::uploadPart($id));

        return Response::json(['deleted' => true]);
    }

    /**
     * Same formula as the front end: SHA-256 of
     * "{name}\n{size}\n{sha256(first 4 MiB)}\n{sha256(last 4 MiB)}".
     */
    public static function fingerprint(string $file, string $name): string
    {
        $size = (int) filesize($file);
        $sample = min($size, self::FINGERPRINT_SAMPLE);
        $head = (string) file_get_contents($file, false, null, 0, $sample);
        $tail = (string) file_get_contents($file, false, null, $size - $sample, $sample);

        return hash('sha256', $name . "\n" . $size . "\n" . hash('sha256', $head) . "\n" . hash('sha256', $tail));
    }

    /** A plain file name with an accepted extension (.mp4, .mov, .mxf). */
    public static function fileName(string $name): string
    {
        if ($name !== basename($name) || str_contains($name, '\\') || str_starts_with($name, '.')) {
            throw new HttpException(422, 'Nom de fichier invalide.', ['field' => 'name']);
        }
        MasterFormat::extension($name);

        return $name;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'size' => (int) $row['size_bytes'],
            'bytesReceived' => (int) $row['bytes_received'],
            'videoId' => $row['video_id'] === null ? null : (int) $row['video_id'],
            'videoNumber' => $row['video_number'] === null ? null : (int) $row['video_number'],
            'videoTitle' => $row['video_title'],
            'updatedAt' => Dates::toIso((string) $row['updated_at']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function find(int $id): array
    {
        return $this->services->uploads()->find($id) ?? throw HttpException::notFound();
    }

    private function part(int $id): string
    {
        return $this->services->storage()->absolute(Storage::uploadPart($id));
    }

    /** Part files left behind by deleted videos or cancelled uploads. */
    private function removeOrphanParts(): void
    {
        $known = array_flip($this->services->uploads()->ids());
        foreach (glob($this->services->storage()->absolute('uploads') . '/*.part') ?: [] as $file) {
            $id = (int) basename($file, '.part');
            if (!isset($known[$id]) && filemtime($file) < time() - self::ORPHAN_DELAY) {
                @unlink($file);
            }
        }
    }

    private static function gigabytes(int $bytes): string
    {
        return number_format($bytes / 1e9, 1, ',', ' ') . ' Go';
    }
}
