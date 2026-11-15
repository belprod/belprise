<?php

declare(strict_types=1);

// Scheduled tasks (CAHIER_DES_CHARGES §7.1), run every hour by the host's cron
// (automatic archiving, downgrades and purges, deletions of organizations, and
// the playback versions encoded on the server when the operator chose it):
//     php app/bin/cron.php            does the work
//     php app/bin/cron.php --dry-run  only lists what it would do
// A lock keeps two runs from overlapping.

use Belprise\Config;
use Belprise\Database\Database;
use Belprise\DataDir;
use Belprise\Demo\Demo;
use Belprise\Demo\DemoSnapshot;
use Belprise\Install\Environment;
use Belprise\Lifecycle\Downgrade;
use Belprise\Lifecycle\Notices;
use Belprise\Lifecycle\OrganizationDeletion;
use Belprise\Mail\NativeMailer;
use Belprise\Media\AutoArchiver;
use Belprise\Media\ServerEncoder;
use Belprise\Repository\EncodeJobs;
use Belprise\Repository\Settings;
use Belprise\Storage;
use Belprise\Update\UpdateCheck;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/bootstrap.php';

$dryRun = in_array('--dry-run', $argv, true);
$config = Config::load(Config::file(dirname(__DIR__, 2)));
DataDir::prepare($config->dataDir);

$lock = fopen($config->dataDir . '/cron.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Une autre exécution est en cours : rien à faire.\n";
    exit(0);
}

$db = Database::open($config->dataDir . '/belprise.sqlite', dirname(__DIR__) . '/migrations');
$storage = new Storage($config->dataDir);
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$prefix = $dryRun ? '[essai] ' : '';

// Public demo (§5.16): only the reset to the reference state, every 6 hours.
if ($config->demo) {
    $snapshot = new DemoSnapshot($config->dataDir, dirname(__DIR__) . '/migrations');
    if (!$snapshot->exists()) {
        echo "Démo : aucun instantané. Lancez « php app/bin/demo.php snapshot ».\n";
    } elseif (!$snapshot->due($now->getTimestamp())) {
        echo 'Démo : prochaine remise à zéro le ' . (new DateTimeImmutable('@' . Demo::nextReset($now->getTimestamp())))->setTimezone(new DateTimeZone(Demo::TIMEZONE))->format('d/m/Y à H:i') . ".\n";
    } elseif ($dryRun) {
        echo "{$prefix}Démo : remise à zéro due.\n";
    } else {
        $done = $snapshot->restore($db, $now->getTimestamp());
        printf("Démo remise à zéro : %d ligne(s), %d fichier(s) recopié(s), %d supprimé(s).\n", $done['rows'], $done['copied'], $done['deleted']);
    }
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(0);
}

// 1. Automatic archiving (6 months without a new version or revision).
$archived = (new AutoArchiver($db, $storage))->run($now, $dryRun);
foreach ($archived as ['video' => $video, 'plan' => $plan]) {
    printf(
        "%sArchivage : n° %d %s (organisation du client %d) — V%d gardée, %d version(s) et %d révision(s) supprimées, %.1f Mo libérés\n",
        $prefix,
        $video['number'],
        $video['title'],
        $video['client_id'],
        $plan['keep']['number'] ?? 0,
        count($plan['delete']),
        $plan['revisions'],
        $plan['freedBytes'] / 1e6,
    );
}
echo $prefix . count($archived) . " vidéo(s) archivée(s).\n";

// 2. Downgrades (reminders J-7 / J-1, purge at J+30) and deletions of organizations (same rhythm).
$notices = new Notices($db, new NativeMailer($config->mailFrom), $config->appUrl);
$events = array_merge(
    (new Downgrade($db, $storage, $notices))->run($now, $dryRun),
    (new OrganizationDeletion($db, $storage, $notices))->run($now, $dryRun),
);
foreach ($events as $event) {
    printf("%s%s — %s : %s\n", $prefix, $event['organization'], $event['event'], $event['details']);
}

// 3. New version of belprise: https://belprise.fr/versions.json, once a day at most (D8).
if ($config->updateCheck && !$dryRun) {
    $latest = (new UpdateCheck(new Settings($db)))->run();
    if ($latest !== null && version_compare($latest, UpdateCheck::installed(dirname(__DIR__, 2)), '>')) {
        echo "Nouvelle version disponible : {$latest}.\n";
    }
}

// 4. Playback versions encoded on the server (§5.7 bis): one at a time, at low priority.
// New versions are not started after 50 minutes, so an hourly run never meets the next one.
$settings = new Settings($db);
if (ServerEncoder::mode($settings) === 'server') {
    $counts = (new EncodeJobs($db))->counts();
    $ffmpeg = Environment::ffmpeg($config->ffmpeg);
    if ($ffmpeg === null) {
        echo "Encodage sur le serveur choisi, mais FFmpeg est introuvable : {$counts['queued']} version(s) en attente.\n";
    } elseif ($dryRun) {
        echo "{$prefix}Encodage sur le serveur : {$counts['queued']} version(s) en attente, {$counts['running']} en cours.\n";
    } else {
        $encoder = new ServerEncoder($db, $storage, $ffmpeg, ServerEncoder::threads($settings));
        $deadline = time() + 50 * 60;
        while (time() < $deadline && ($done = $encoder->runOne()) !== null) {
            printf("Encodage : version %d — %s (%s)\n", $done['version'], $done['status'], $done['details']);
        }
    }
}

flock($lock, LOCK_UN);
fclose($lock);
