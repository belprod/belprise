<?php

declare(strict_types=1);

namespace Belprise\Media;

use Belprise\Http\HttpException;
use Belprise\Security\MediaSigner;
use Belprise\Storage;
use PDO;

/**
 * Playback versions of a version (CAHIER_DES_CHARGES §5.7 bis), in HLS:
 * a master playlist, one media playlist and one file per quality (segments
 * read through byte ranges). Everything is written in {version dir}/hls/;
 * playlists are served with each address signed on the fly.
 */
final class HlsStore
{
    public const DIR = 'hls';
    public const FILE_PATTERN = '/^[a-z0-9-]{1,40}\.(mp4|m4s)$/D';
    public const PLAYLIST_PATTERN = '/^[a-z0-9-]{1,40}\.m3u8$/D';
    private const MAX_PLAYLIST = 2 * 1024 * 1024;

    public function __construct(
        private readonly PDO $db,
        private readonly Storage $storage,
    ) {
    }

    /** "hls/segments-1.mp4" → its path for this version, or the error. */
    public static function target(string $target): string
    {
        if (!str_starts_with($target, self::DIR . '/') || preg_match(self::FILE_PATTERN, substr($target, strlen(self::DIR) + 1)) !== 1) {
            throw new HttpException(422, 'Fichier de version de lecture invalide.', ['field' => 'target']);
        }

        return $target;
    }

    /** @param array<string, mixed> $version row of Versions::find() */
    public static function dir(array $version): string
    {
        return Storage::versionDir((int) $version['client_id'], (int) $version['video_number'], (int) $version['number']) . '/' . self::DIR;
    }

    /** A finished upload becomes one of the files of the playback version. */
    public function storeFile(array $version, string $part, string $target): void
    {
        $relative = Storage::versionDir((int) $version['client_id'], (int) $version['video_number'], (int) $version['number']) . '/' . self::target($target);
        $absolute = $this->storage->absolute($relative);
        if (!is_dir(dirname($absolute)) && !mkdir(dirname($absolute), 0770, true) && !is_dir(dirname($absolute))) {
            throw new \RuntimeException('Cannot create ' . dirname($absolute));
        }
        if (!rename($part, $absolute)) {
            throw new \RuntimeException('Cannot move the playback file.');
        }
    }

    /**
     * Writes the playlists once every file is there, and makes the version use them.
     *
     * @param array<string, mixed> $version
     * @param array<mixed> $playlists name => content
     */
    public function publish(array $version, array $playlists): void
    {
        if (!isset($playlists['master.m3u8'])) {
            throw new HttpException(422, 'Liste de lecture principale manquante.', ['field' => 'playlists']);
        }
        $dir = self::dir($version);
        foreach ($playlists as $name => $content) {
            if (!is_string($name) || preg_match(self::PLAYLIST_PATTERN, $name) !== 1 || !is_string($content) || strlen($content) > self::MAX_PLAYLIST) {
                throw new HttpException(422, 'Liste de lecture invalide.', ['field' => 'playlists']);
            }
            foreach (self::references($content) as $reference) {
                $known = isset($playlists[$reference]) || (preg_match(self::FILE_PATTERN, $reference) === 1 && is_file($this->storage->absolute($dir . '/' . $reference)));
                if (!$known) {
                    throw new HttpException(422, 'La liste de lecture « ' . $name . ' » cite un fichier absent : ' . $reference . '.', ['field' => 'playlists']);
                }
            }
        }
        foreach ($playlists as $name => $content) {
            file_put_contents($this->storage->absolute($dir . '/' . $name), $content);
        }
        $this->db->prepare('UPDATE versions SET hls_path = ? WHERE id = ?')->execute([$dir . '/master.m3u8', (int) $version['id']]);
    }

    /** Removes the playback version (before a new encoding). */
    public function clear(array $version): void
    {
        $this->db->prepare('UPDATE versions SET hls_path = NULL WHERE id = ?')->execute([(int) $version['id']]);
        $this->storage->deleteTree(self::dir($version));
    }

    /**
     * The playlist with each address replaced by a signed one (same folder only).
     */
    public static function sign(string $content, string $dir, MediaSigner $signer): string
    {
        $lines = preg_split('/\r?\n/', $content) ?: [];
        foreach ($lines as $i => $line) {
            if ($line === '') {
                continue;
            }
            if ($line[0] === '#') {
                $lines[$i] = (string) preg_replace_callback('/URI="([^"]+)"/', static fn (array $m) => 'URI="' . self::signed($m[1], $dir, $signer) . '"', $line);
            } else {
                $lines[$i] = self::signed(trim($line), $dir, $signer);
            }
        }

        return implode("\n", $lines);
    }

    private static function signed(string $name, string $dir, MediaSigner $signer): string
    {
        if (preg_match(self::FILE_PATTERN, $name) !== 1 && preg_match(self::PLAYLIST_PATTERN, $name) !== 1) {
            return '#'; // never hand out an address outside this folder
        }

        return $signer->url($dir . '/' . $name);
    }

    /**
     * File names a playlist points to (URI lines and URI="…" attributes).
     *
     * @return list<string>
     */
    private static function references(string $content): array
    {
        $found = [];
        foreach (preg_split('/\r?\n/', $content) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if ($line[0] === '#') {
                if (preg_match_all('/URI="([^"]+)"/', $line, $m) > 0) {
                    array_push($found, ...$m[1]);
                }
                continue;
            }
            $found[] = $line;
        }

        return $found;
    }
}
