<?php

declare(strict_types=1);

// Checks that this server can run belprise (to run once after installing,
// and after changing the PHP version):
//     php app/bin/check-env.php
// Read only: it changes nothing, except a test file it writes and removes
// in data/. Exit code 1 when something blocks.

use Belprise\Config;
use Belprise\ConfigException;
use Belprise\Install\Environment;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/bootstrap.php';

$failures = 0;
$line = static function (bool $ok, string $label, string $detail = '', bool $blocking = true) use (&$failures): void {
    if (!$ok && $blocking) {
        $failures++;
    }
    printf("%s %s%s\n", $ok ? '[ok]    ' : ($blocking ? '[ÉCHEC] ' : '[à voir]'), $label, $detail === '' ? '' : ' — ' . $detail);
};

echo "belprise : vérification de l'environnement\n\n";

// Server: PHP, extensions, SQLite (data folder checked below, once its place is known).
$configFile = Config::file(dirname(__DIR__, 2));
$dataGuess = dirname(__DIR__, 2) . '/data';
try {
    $dataGuess = Config::load($configFile)->dataDir;
} catch (ConfigException) {
}
foreach (Environment::server($dataGuess, dirname(__DIR__, 2) . '/public') as $check) {
    $line($check['ok'], $check['label'], $check['detail'], $check['blocking']);
}
$line((string) ini_get('date.timezone') !== '' || date_default_timezone_get() === 'UTC', 'fuseau horaire', date_default_timezone_get(), false);

// Configuration.
$config = null;
try {
    $config = Config::load($configFile);
    $line(true, 'configuration ' . $configFile);
} catch (ConfigException $e) {
    $line(false, 'configuration ' . $configFile, $e->getMessage() . ' (ouvrez /installation dans le navigateur, ou lancez php app/bin/init-config.php)');
}
if (is_file($configFile)) {
    $mode = fileperms($configFile) & 0o777;
    $line(($mode & 0o007) === 0, 'droits de la configuration : ' . sprintf('%o', $mode), ($mode & 0o007) === 0 ? '' : 'lisible par tous : chmod 600 ' . $configFile, false);
}
if ($config !== null) {
    $line($config->appUrl !== null && str_starts_with($config->appUrl, 'https://'), 'app_url', $config->appUrl ?? 'vide : liens des e-mails impossibles hors local', false);
    $line($config->hsts, 'hsts', $config->hsts ? 'activé' : 'à activer une fois le HTTPS vérifié', false);
    $line(!$config->xsendfile, 'xsendfile', $config->xsendfile ? 'activé : à n\'utiliser qu\'avec mod_xsendfile (pas sur LiteSpeed / o2switch)' : 'désactivé (PHP envoie les fichiers)', false);
}

// Web limits: the CLI may read another php.ini than the web server; shown for information.
echo "\nLimites PHP vues en ligne de commande (le serveur web lit public/.user.ini) :\n";
foreach (['memory_limit', 'upload_max_filesize', 'post_max_size', 'max_execution_time'] as $setting) {
    printf("         %s = %s\n", $setting, (string) ini_get($setting));
}

echo "\n" . ($failures === 0 ? 'Tout ce qui bloque est en ordre.' : $failures . ' point(s) bloquant(s) à corriger.') . "\n";
exit($failures === 0 ? 0 : 1);
