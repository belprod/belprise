<?php

declare(strict_types=1);

namespace Belprise\Media;

use Belprise\Database\Database;
use Belprise\Http\HttpException;
use Belprise\Repository\Activity;
use Belprise\Repository\Versions;
use Belprise\Storage;
use PDO;

/**
 * Turns a finished upload into the next version of a video. The file is moved, never copied.
 */
final class VersionImporter
{
    public function __construct(
        private readonly PDO $db,
        private readonly Versions $versions,
        private readonly Storage $storage,
    ) {
    }

    /**
     * @param array<string, mixed> $video row from Videos::find()
     * @param array<string, mixed> $meta  from MediaMeta::fromInput()
     * @return int new version id
     */
    public function import(array $video, string $source, string $originalName, array $meta): int
    {
        $extension = MasterFormat::extension($originalName);
        MasterFormat::assertContainer($source, $extension);
        $size = (int) filesize($source);

        [$id, $relative] = Database::transaction($this->db, function () use ($video, $originalName, $size, $meta, $extension): array {
            $number = $this->versions->nextNumber((int) $video['id']);
            $relative = Storage::versionDir((int) $video['client_id'], (int) $video['number'], $number) . '/master.' . $extension;
            $id = $this->versions->create((int) $video['id'], $number, $relative, $originalName, $size, $meta);
            (new Activity($this->db))->record('version', (int) $video['id'], $id, $number);

            return [$id, $relative];
        });

        $target = $this->storage->absolute($relative);
        $dir = dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            $this->versions->delete($id);
            throw new \RuntimeException('Cannot create ' . $dir);
        }
        if (is_file($target) || !@rename($source, $target)) {
            $this->versions->delete($id);
            throw new HttpException(
                500,
                'Le fichier n’a pas pu être déplacé. Le dossier d’import et le dossier des médias doivent être sur le même volume.',
            );
        }
        // The source was checked before, but could have been swapped for a link meanwhile.
        if (is_link($target)) {
            unlink($target);
            $this->versions->delete($id);
            throw new HttpException(422, 'Les liens symboliques ne sont pas acceptés.');
        }

        return $id;
    }

}
