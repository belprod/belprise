<?php

declare(strict_types=1);

namespace Belprise\Controller;

use Belprise\Http\FileResponse;
use Belprise\Http\HttpException;
use Belprise\Http\Request;
use Belprise\Http\Response;
use Belprise\LogoStore;
use Belprise\Media\HlsStore;
use Belprise\Media\MasterFormat;
use Belprise\Services;

/**
 * Files from data/, only through a valid signed URL (§6).
 * Logos, posters, thumbnail sheets and masters (with Range support).
 * Nothing in the response reveals where the file lives.
 */
final class MediaController
{
    public const LOGO_PATTERN = '#^/media/(?<path>logos/o?[0-9]+-[0-9a-f]{12}\.(?:png|webp|svg))$#';
    public const IMAGE_PATTERN = '#^/media/(?<path>media/[0-9]+/[0-9]+/v[0-9]+/(?:poster|thumb|sprite)\.jpg)$#';
    public const VIDEO_PATTERN = '#^/media/(?<path>media/[0-9]+/[0-9]+/v[0-9]+/master\.(?:mp4|mov|mxf))$#';

    public const HLS_PATTERN = '#^/media/(?<path>media/[0-9]+/[0-9]+/v[0-9]+/hls/[a-z0-9-]{1,40}\.(?:m3u8|mp4|m4s))$#';

    public const PLAYBACK_CHUNK = 8 * 1024 * 1024;

    private const CONTENT_TYPES = LogoStore::CONTENT_TYPES + ['jpg' => 'image/jpeg'] + MasterFormat::TYPES
        + ['m3u8' => 'application/vnd.apple.mpegurl', 'm4s' => 'video/iso.segment'];

    public function __construct(private readonly Services $services)
    {
    }

    public function serve(Request $request, string $path): Response
    {
        $expires = $request->queryString('e');
        if (!$this->services->signer()->isValid($path, $expires, $request->queryString('s'))) {
            throw HttpException::notFound();
        }
        $file = $this->services->storage()->absolute($path);
        $type = self::CONTENT_TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;
        if ($type === null || !is_file($file) || is_link($file)) {
            throw HttpException::notFound();
        }

        // Same URL for the whole signature period: the browser may cache it until then.
        $cache = 'private, max-age=' . max(0, (int) $expires - time());
        if ($type === 'application/vnd.apple.mpegurl') {
            // HLS playlist: every address inside is signed for this request.
            $response = new Response(200, HlsStore::sign((string) file_get_contents($file), dirname($path), $this->services->signer()), $type);
            $response->setHeader('Cache-Control', 'private, no-store');
            $response->setHeader('Content-Security-Policy', "default-src 'none'; sandbox");

            return $response;
        }
        if (in_array($type, MasterFormat::TYPES, true) || $type === 'video/iso.segment') {
            // Videos are played in parts (8 MB at most per answer); images are small.
            $response = new FileResponse($file, $type, $request->header('Range'), $this->services->config->xsendfile, self::PLAYBACK_CHUNK);
            $response->setHeader('Cache-Control', $cache);
            $response->setHeader('Content-Security-Policy', "default-src 'none'; sandbox");

            return $response;
        }

        $response = new Response(200, (string) file_get_contents($file), $type);
        $response->setHeader('Cache-Control', $cache);
        // An SVG opened on its own must not run anything.
        $response->setHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");

        return $response;
    }
}
