<?php

declare(strict_types=1);

namespace Belprise;

use Belprise\Http\HttpException;
use Belprise\Security\SvgSanitizer;

/**
 * Logos (clients, collections, organizations): SVG, PNG or WebP, at most 2 MB, stored in data/logos/.
 * The type is detected from the content, never from the file name.
 * SVG files are rebuilt by SvgSanitizer: what is stored is the cleaned copy.
 */
final class LogoStore
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    public const CONTENT_TYPES = [
        'png' => 'image/png',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
    ];

    public function __construct(private readonly Storage $storage)
    {
    }

    /**
     * @param array{name: string, tmp_name: string, size: int, error: int} $upload
     * @param int|string $owner prefix of the file name (client id, "o12" for an organization…)
     * @return string path relative to data/
     */
    public function store(int|string $owner, array $upload): string
    {
        if ($upload['error'] === UPLOAD_ERR_INI_SIZE || $upload['error'] === UPLOAD_ERR_FORM_SIZE
            || $upload['size'] > self::MAX_BYTES) {
            throw self::invalid('Le logo ne doit pas dépasser 2 Mo.');
        }
        if ($upload['error'] !== UPLOAD_ERR_OK || !is_file($upload['tmp_name'])) {
            throw self::invalid('Le fichier n’a pas pu être reçu. Réessayez.');
        }
        $content = (string) file_get_contents($upload['tmp_name']);
        if (strlen($content) > self::MAX_BYTES) {
            throw self::invalid('Le logo ne doit pas dépasser 2 Mo.');
        }

        [$extension, $content] = self::detect($content);
        $relative = 'logos/' . preg_replace('/[^a-z0-9]/', '', strtolower((string) $owner)) . '-' . bin2hex(random_bytes(6)) . '.' . $extension;
        $target = $this->storage->absolute($relative);
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0770, true);
        }
        if (file_put_contents($target, $content) === false) {
            throw new \RuntimeException('Cannot write logo file.');
        }

        return $relative;
    }

    public static function contentType(string $path): ?string
    {
        return self::CONTENT_TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;
    }

    /**
     * @return array{0: string, 1: string} extension and the content to store
     */
    private static function detect(string $content): array
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content);

        if ($mime === 'image/png' || $mime === 'image/webp') {
            $info = @getimagesizefromstring($content);
            if ($info === false || $info[0] < 1 || $info[1] < 1) {
                throw self::invalid('Cette image est illisible.');
            }

            return [$mime === 'image/png' ? 'png' : 'webp', $content];
        }

        if (preg_match('/<svg[\s>\/]/i', $content) === 1) {
            $clean = SvgSanitizer::clean($content);
            if ($clean === null) {
                throw self::invalid('Ce SVG est illisible ou contient des déclarations non autorisées.');
            }

            return ['svg', $clean];
        }

        throw self::invalid('Format non pris en charge : utilisez un fichier SVG, PNG ou WebP.');
    }

    private static function invalid(string $message): HttpException
    {
        return new HttpException(422, $message, ['field' => 'logo']);
    }
}
