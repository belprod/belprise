<?php

declare(strict_types=1);

// Speed of the encoding on the server (CAHIER_DES_CHARGES §5.7 bis), with the
// threads chosen in /superadmin; the result is shown there too:
//     php app/bin/encode-test.php
// Encodes a 20-second test clip like a real version; changes nothing else.

use Belprise\Config;
use Belprise\Database\Database;
use Belprise\DataDir;
use Belprise\Install\Environment;
use Belprise\Media\ServerEncoder;
use Belprise\Repository\Settings;
use Belprise\Storage;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/bootstrap.php';

$config = Config::load(Config::file(dirname(__DIR__, 2)));
DataDir::prepare($config->dataDir);
$db = Database::open($config->dataDir . '/belprise.sqlite', dirname(__DIR__) . '/migrations');
$ffmpeg = Environment::ffmpeg($config->ffmpeg);
if ($ffmpeg === null) {
    fwrite(STDERR, "FFmpeg est introuvable : indiquez son chemin ('ffmpeg' dans la configuration) ou installez-le.\n");
    exit(1);
}
$threads = ServerEncoder::threads(new Settings($db));
echo $ffmpeg['version'] . ' (' . $ffmpeg['path'] . "), {$threads} fil(s) : encodage d'une vidéo d'essai de 20 s…\n";
try {
    $ratio = (new ServerEncoder($db, new Storage($config->dataDir), $ffmpeg, $threads))->benchmark();
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
printf("Vitesse : %s × le temps réel, soit environ %d min pour une heure de vidéo.\n", number_format($ratio, 2, ',', ' '), (int) ceil(60 / max(0.01, $ratio)));
