<?php

declare(strict_types=1);

namespace Belprise\Security;

use Belprise\Http\Response;

/**
 * Headers carried by every response. public/.htaccess sends the same
 * fixed values for files Apache serves on its own.
 */
final class SecurityHeaders
{
    public const ROBOTS = 'noindex, nofollow, noarchive, nosnippet, noimageindex, noai, noimageai';

    public const PERMISSIONS_POLICY = 'accelerometer=(), autoplay=(self), browsing-topics=(), camera=(), '
        . 'display-capture=(), fullscreen=(self), geolocation=(), gyroscope=(), magnetometer=(), '
        . 'microphone=(), midi=(), payment=(), picture-in-picture=(self), publickey-credentials-get=(), '
        . 'screen-wake-lock=(), usb=(), xr-spatial-tracking=()';

    /**
     * @param string|null $devServer Vite dev server origin, development only
     */
    public static function contentSecurityPolicy(string $nonce, ?string $devServer = null): string
    {
        $dev = $devServer === null ? '' : ' ' . $devServer;
        $devSocket = $devServer === null ? '' : ' ' . preg_replace('#^http#', 'ws', $devServer);
        // Vite injects <style> tags in development: nonces cannot be used there.
        $styles = $devServer === null ? "'self' 'nonce-" . $nonce . "'" : "'self' 'unsafe-inline'" . $dev;

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self'" . $dev,
            'style-src ' . $styles,
            "img-src 'self' blob:" . $dev,
            "media-src 'self' blob:",
            "font-src 'self'" . $dev,
            "connect-src 'self'" . $dev . $devSocket,
            "worker-src 'self' blob:",
            "object-src 'none'",
            "base-uri 'none'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }

    public static function apply(Response $response, string $nonce, ?string $devServer = null, bool $hsts = false): void
    {
        $response
            ->setHeader('X-Robots-Tag', self::ROBOTS)
            ->setHeader('Referrer-Policy', 'no-referrer')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('X-Frame-Options', 'DENY')
            ->setHeader('Permissions-Policy', self::PERMISSIONS_POLICY)
            ->setHeader('Cross-Origin-Opener-Policy', 'same-origin');

        // A response may carry a stricter policy of its own (media files).
        if ($response->header('Content-Security-Policy') === null) {
            $response->setHeader('Content-Security-Policy', self::contentSecurityPolicy($nonce, $devServer));
        }
        if ($hsts) {
            $response->setHeader('Strict-Transport-Security', 'max-age=31536000');
        }
    }
}
