<?php

declare(strict_types=1);

// Public demo (CAHIER_DES_CHARGES §5.16), on an installation with 'demo' => true:
//     php app/bin/demo.php build [dossier des extraits]   builds the demo set on an empty
//                                                        base (default dist/demo/extraits;
//                                                        needs FFmpeg), then takes the snapshot
//     php app/bin/demo.php snapshot                      takes the current state as the reference
//     php app/bin/demo.php reset                         puts the reference back now
// The scheduled task (cron.php) puts the reference back every 6 hours.

use Belprise\Config;
use Belprise\Database\Database;
use Belprise\DataDir;
use Belprise\Demo\Demo;
use Belprise\Demo\DemoBuilder;
use Belprise\Demo\DemoSnapshot;
use Belprise\Install\Environment;
use Belprise\Storage;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/bootstrap.php';

$root = dirname(__DIR__, 2);
$config = Config::load(Config::file($root));
if (!$config->demo) {
    fwrite(STDERR, "Cette installation n’est pas en mode démo : ajoutez 'demo' => true à la configuration.\n");
    exit(1);
}
DataDir::prepare($config->dataDir);
$db = Database::open($config->dataDir . '/belprise.sqlite', $root . '/app/migrations');
$snapshot = new DemoSnapshot($config->dataDir, $root . '/app/migrations');

switch ($argv[1] ?? '') {
    case 'build':
        $ffmpeg = Environment::ffmpeg($config->ffmpeg);
        if ($ffmpeg === null) {
            fwrite(STDERR, "FFmpeg est introuvable : il sert à préparer les versions de lecture de la démo.\n");
            exit(1);
        }
        $extracts = rtrim($argv[2] ?? $root . '/dist/demo/extraits', '/');
        try {
            $links = (new DemoBuilder($db, new Storage($config->dataDir), $ffmpeg, $extracts, static function (string $m): void {
                echo $m, "\n";
            }))->build();
        } catch (RuntimeException $e) {
            fwrite(STDERR, $e->getMessage() . "\n");
            exit(1);
        }
        $snapshot->take($db, time());
        echo "\nDémo prête, instantané pris. Comptes (mot de passe « " . Demo::PASSWORD . " ») :\n";
        foreach (Demo::ACCOUNTS as $account) {
            printf("  %-9s %s\n", $account['role'], $account['email']);
        }
        echo "Liens partagés :\n";
        foreach ($links as $name => $path) {
            echo "  {$name} : ", ($config->appUrl ?? '') . $path, "\n";
        }
        break;
    case 'snapshot':
        $snapshot->take($db, time());
        echo "Instantané pris : c’est l’état remis toutes les 6 heures.\n";
        break;
    case 'reset':
        $done = $snapshot->restore($db, time());
        printf("Démo remise à zéro : %d ligne(s), %d fichier(s) recopié(s), %d supprimé(s).\n", $done['rows'], $done['copied'], $done['deleted']);
        break;
    default:
        fwrite(STDERR, "Usage : php app/bin/demo.php build [dossier des extraits] | snapshot | reset\n");
        exit(1);
}
