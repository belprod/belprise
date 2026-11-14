<?php

declare(strict_types=1);

namespace Belprise;

final class Config
{
    private const MIN_SECRET_LENGTH = 32;
    public const DEFAULT_MAIL_FROM = 'Belprise <no-reply@belprise.fr>';

    /**
     * @param list<string> $trustedProxies
     */
    private function __construct(
        public readonly string $dataDir,
        public readonly string $secret,
        public readonly bool $xsendfile,
        public readonly bool $hsts,
        public readonly array $trustedProxies,
        public readonly string $mailFrom = self::DEFAULT_MAIL_FROM,
        public readonly ?string $appUrl = null,
        /** FFmpeg for the encoding on the server, when it is not found by itself (e.g. a static build in the home folder). */
        public readonly ?string $ffmpeg = null,
        /** Daily check of https://belprise.fr/versions.json by the scheduled task (D8); false: never. */
        public readonly bool $updateCheck = true,
    ) {
    }

    /**
     * Where the configuration lives: app/config.php, or the file named by the
     * BELPRISE_CONFIG environment variable (Docker keeps it in the data
     * volume, so a new image does not lose it).
     */
    public static function file(string $rootDir): string
    {
        $env = getenv('BELPRISE_CONFIG');

        return is_string($env) && $env !== '' ? $env : $rootDir . '/app/config.php';
    }

    public static function load(string $file): self
    {
        if (!is_file($file)) {
            throw new ConfigException('Missing configuration file: ' . $file);
        }
        $values = require $file;
        if (!is_array($values)) {
            throw new ConfigException('The configuration file must return an array.');
        }

        return self::fromArray($values);
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function fromArray(array $values): self
    {
        $dataDir = $values['data_dir'] ?? null;
        if (!is_string($dataDir) || $dataDir === '') {
            throw new ConfigException('"data_dir" must be a non-empty path.');
        }

        $secret = $values['secret'] ?? null;
        if (!is_string($secret) || strlen($secret) < self::MIN_SECRET_LENGTH) {
            throw new ConfigException('"secret" must be at least ' . self::MIN_SECRET_LENGTH . ' characters long.');
        }

        $proxies = $values['trusted_proxies'] ?? [];
        if (!is_array($proxies) || array_filter($proxies, static fn ($p) => !is_string($p)) !== []) {
            throw new ConfigException('"trusted_proxies" must be a list of IP addresses.');
        }

        return new self(
            rtrim($dataDir, '/'),
            $secret,
            (bool) ($values['xsendfile'] ?? false),
            (bool) ($values['hsts'] ?? false),
            array_values($proxies),
            self::mailFrom($values['mail_from'] ?? self::DEFAULT_MAIL_FROM),
            self::appUrl($values['app_url'] ?? null),
            is_string($values['ffmpeg'] ?? null) && $values['ffmpeg'] !== '' ? $values['ffmpeg'] : null,
            (bool) ($values['update_check'] ?? true),
        );
    }

    private static function mailFrom(mixed $value): string
    {
        if (!is_string($value) || $value === '' || preg_match('/[\r\n\0]/', $value) === 1) {
            throw new ConfigException('"mail_from" must be an address on one line, e.g. "Belprise <no-reply@belprise.fr>".');
        }

        return $value;
    }

    /** Public address of the application (links in e-mails); null: taken from the request. */
    private static function appUrl(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || preg_match('#^https?://[a-z0-9.-]+(:[0-9]+)?$#iD', rtrim($value, '/')) !== 1) {
            throw new ConfigException('"app_url" must look like "https://app.belprise.fr".');
        }

        return rtrim($value, '/');
    }
}
