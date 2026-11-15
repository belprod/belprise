<?php

declare(strict_types=1);

namespace Belprise\Update;

use Belprise\Repository\Settings;
use Closure;

/**
 * "Nouvelle version disponible" (CAHIER_DES_CHARGES §6, decision D8): the
 * server, through the scheduled task and once a day at most, reads
 * https://belprise.fr/versions.json — the only request belprise makes to
 * another domain. Nothing is sent but the request itself (the user agent is
 * "belprise", without the version). Off with 'update_check' => false.
 */
final class UpdateCheck
{
    public const URL = 'https://belprise.fr/versions.json';
    public const LATEST_KEY = 'update.latest';
    public const CHECKED_KEY = 'update.checked_at';
    private const EVERY = 86400;
    private const VERSION_PATTERN = '/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/D';

    /** @var Closure(string): ?string */
    private readonly Closure $fetch;

    /** @var Closure(): int */
    private readonly Closure $now;

    /**
     * @param (Closure(string): ?string)|null $fetch body of a URL, null on failure (tests)
     * @param (Closure(): int)|null $now
     */
    public function __construct(
        private readonly Settings $settings,
        ?Closure $fetch = null,
        ?Closure $now = null,
    ) {
        $this->fetch = $fetch ?? self::download(...);
        $this->now = $now ?? static fn (): int => time();
    }

    /**
     * Reads versions.json when the last check is a day old.
     *
     * @return string|null the latest version read now, null when nothing was read
     */
    public function run(): ?string
    {
        $checked = (int) ($this->settings->get(self::CHECKED_KEY) ?? 0);
        if (($this->now)() - $checked < self::EVERY) {
            return null;
        }
        $this->settings->set(self::CHECKED_KEY, (string) ($this->now)());
        $body = ($this->fetch)(self::URL);
        $data = $body === null ? null : json_decode($body, true);
        if (!is_array($data) || !is_string($data['version'] ?? null) || preg_match(self::VERSION_PATTERN, $data['version']) !== 1) {
            return null;
        }
        $url = is_string($data['url'] ?? null) && str_starts_with($data['url'], 'https://') ? $data['url'] : null;
        $this->settings->set(self::LATEST_KEY, (string) json_encode(['version' => $data['version'], 'url' => $url]));

        return $data['version'];
    }

    /**
     * What /superadmin shows.
     *
     * @return array{installed: string, latest: string|null, newer: bool, url: string|null, checkedAt: int|null}
     */
    public function state(string $installed): array
    {
        $latest = json_decode((string) $this->settings->get(self::LATEST_KEY), true);
        $version = is_array($latest) && is_string($latest['version'] ?? null) ? $latest['version'] : null;
        $checked = $this->settings->get(self::CHECKED_KEY);

        return [
            'installed' => $installed,
            'latest' => $version,
            'newer' => $version !== null && preg_match(self::VERSION_PATTERN, $installed) === 1 && version_compare($version, $installed, '>'),
            'url' => is_array($latest) && is_string($latest['url'] ?? null) ? $latest['url'] : null,
            'checkedAt' => $checked === null ? null : (int) $checked,
        ];
    }

    /** The installed version: the VERSION file of the release (absent in development). */
    public static function installed(string $rootDir): string
    {
        $version = @file_get_contents($rootDir . '/VERSION');

        return is_string($version) && trim($version) !== '' ? trim($version) : 'développement';
    }

    private static function download(string $url): ?string
    {
        $context = stream_context_create([
            'http' => ['timeout' => 5, 'user_agent' => 'belprise', 'ignore_errors' => false, 'follow_location' => 0],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents($url, false, $context, 0, 64 * 1024);

        return is_string($body) ? $body : null;
    }
}
