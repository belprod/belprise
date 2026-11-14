<?php

declare(strict_types=1);

namespace Belprise\Install;

use PDO;

/**
 * What the server needs to run belprise (CAHIER_DES_CHARGES §7.2): shared by
 * app/bin/check-env.php and the installer. Read only, except a test file
 * written and removed in the data folder.
 */
final class Environment
{
    private const HINT_PHP = 'Hébergement avec cPanel : « Sélectionner une version de PHP ». Docker : utilisez l’image belprise.';
    private const HINT_EXTENSION = 'Hébergement avec cPanel : « Sélectionner une version de PHP » → onglet Extensions, cochez-la.';
    private const HINT_WRITABLE = 'Créez le dossier data/ à côté de app/ et public/, accessible en écriture par le serveur web (droits 755, ou 775 selon l’hébergeur).';

    /**
     * @return list<array{label: string, ok: bool, detail: string, blocking: bool}>
     */
    public static function server(string $dataDir, string $publicDir): array
    {
        $checks = [];
        $add = static function (bool $ok, string $label, string $detail = '', bool $blocking = true) use (&$checks): void {
            $checks[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail, 'blocking' => $blocking];
        };

        $add(PHP_VERSION_ID >= 80200, 'PHP ' . PHP_VERSION, PHP_VERSION_ID >= 80200 ? '' : 'il faut PHP 8.2 ou plus. ' . self::HINT_PHP);
        foreach (['pdo_sqlite', 'mbstring', 'fileinfo', 'openssl', 'iconv', 'ctype', 'json'] as $extension) {
            $add(extension_loaded($extension), 'extension ' . $extension, extension_loaded($extension) ? '' : 'absente. ' . self::HINT_EXTENSION);
        }
        $add(defined('PASSWORD_ARGON2ID'), 'mots de passe Argon2id', defined('PASSWORD_ARGON2ID') ? '' : 'PHP compilé sans Argon2 : choisissez une autre version');
        if (extension_loaded('pdo_sqlite')) {
            $version = (string) (new PDO('sqlite::memory:'))->query('SELECT sqlite_version()')->fetchColumn();
            $add(version_compare($version, '3.35.0', '>='), 'SQLite ' . $version, version_compare($version, '3.35.0', '>=') ? '' : 'il faut SQLite 3.35 ou plus');
        }
        $add(function_exists('mail'), 'fonction mail()', function_exists('mail') ? '' : 'désactivée : aucun e-mail ne partira');
        $add(extension_loaded('zip') || extension_loaded('phar'), 'lecture des archives .zip (mises à jour)', extension_loaded('zip') || extension_loaded('phar') ? '' : 'extensions zip et phar absentes : extrayez les archives à la main', false);

        $add(is_dir($dataDir) && is_writable($dataDir), 'dossier de données ' . $dataDir, is_dir($dataDir) ? (is_writable($dataDir) ? '' : 'non accessible en écriture. ' . self::HINT_WRITABLE) : 'absent. ' . self::HINT_WRITABLE);
        $realData = realpath($dataDir);
        $realPublic = realpath($publicDir);
        $add($realData !== false && ($realPublic === false || !str_starts_with($realData . '/', $realPublic . '/')), 'données hors de la racine web');
        if (is_dir($dataDir) && is_writable($dataDir)) {
            $probe = $dataDir . '/.check-env-' . bin2hex(random_bytes(4));
            $written = @file_put_contents($probe, 'ok') === 2;
            @unlink($probe);
            $add($written, 'écriture dans les données');
            $free = @disk_free_space($dataDir);
            $add($free !== false, 'espace libre', $free === false ? 'inconnu' : number_format($free / 1e9, 1, ',', ' ') . ' Go', false);
        }

        $ffmpeg = self::ffmpeg();
        $add($ffmpeg !== null, 'FFmpeg (facultatif)', $ffmpeg === null
            ? 'absent : les versions de lecture seront encodées dans le navigateur (encodage sur le serveur indisponible)'
            : $ffmpeg['version'] . ' — ' . $ffmpeg['path'], false);

        return $checks;
    }

    /**
     * FFmpeg, for the encoding on the server (CAHIER_DES_CHARGES §5.7 bis):
     * the BELPRISE_FFMPEG path, else the usual places. Never required.
     *
     * FFmpeg and, next to it, ffprobe (to know whether a master has sound).
     *
     * @return array{path: string, version: string, probe: string|null}|null
     */
    public static function ffmpeg(?string $configured = null): ?array
    {
        if (!function_exists('proc_open')) {
            return null; // disabled by the host: nothing can be run
        }
        $env = getenv('BELPRISE_FFMPEG');
        $candidates = array_filter([$configured, is_string($env) ? $env : null, '/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/homebrew/bin/ffmpeg']);
        foreach ($candidates as $path) {
            if (!@is_file($path) || !@is_executable($path)) {
                continue;
            }
            $process = @proc_open([$path, '-hide_banner', '-version'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) {
                continue;
            }
            $out = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            if (proc_close($process) === 0 && preg_match('/^ffmpeg version (\S+)/', $out, $m) === 1) {
                $probe = dirname($path) . '/ffprobe';

                return ['path' => $path, 'version' => 'FFmpeg ' . $m[1], 'probe' => @is_executable($probe) ? $probe : null];
            }
        }

        return null;
    }

    /** @param list<array{ok: bool, blocking: bool}> $checks */
    public static function blocking(array $checks): int
    {
        return count(array_filter($checks, static fn (array $c) => !$c['ok'] && $c['blocking']));
    }
}
