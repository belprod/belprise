<?php

declare(strict_types=1);

// Update in one command (CAHIER_DES_CHARGES §7.2), from the installation folder:
//     php app/bin/update.php ~/belprise-2.1.0.zip      (or the extracted folder)
//     php app/bin/update.php ~/belprise-2.1.0.zip --check   checks the archive only
// 1. checks the archive; 2. consistent copy of the database; 3. swaps app/,
// public/ and site/ (the configuration and data/ stay); 4. applies the
// migrations and checks the environment with the new code; on any failure,
// puts everything back as it was. The previous code stays in .avant-maj-{version}/.
// From an older version without this script, run the new one from the extracted
// archive: php belprise-2.1.0/app/bin/update.php belprise-2.1.0.zip --installation=$HOME/belprise

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 2);
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--installation=')) {
        $root = rtrim(substr($arg, strlen('--installation=')), '/');
    }
}
if (!is_file($root . '/app/config.php')) {
    fwrite(STDERR, 'ÉCHEC : aucune installation configurée dans ' . $root . " (app/config.php absent).\n");
    exit(1);
}
$args = array_values(array_filter(array_slice($argv, 1), static fn ($a) => !str_starts_with($a, '--')));
$removeTree = static function (string $dir) use (&$removeTree): void {
    if (!is_dir($dir) || is_link($dir)) {
        @unlink($dir);

        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            $removeTree($dir . '/' . $entry);
        }
    }
    @rmdir($dir);
};
$checkOnly = in_array('--check', $argv, true);
$package = $args[0] ?? '';
$say = static fn (string $line) => print($line . "\n");
$fail = static function (string $message): never {
    fwrite(STDERR, 'ÉCHEC : ' . $message . "\n");
    exit(1);
};

if ($package === '' || !file_exists($package)) {
    $fail('Usage : php app/bin/update.php <archive belprise-x.y.z.zip ou dossier extrait> [--check]');
}
$current = is_file($root . '/VERSION') ? trim((string) file_get_contents($root . '/VERSION')) : 'inconnue';
$say('belprise : mise à jour depuis la version ' . $current);

// 1. The new code, extracted next to the installation (same disk: moves are instant).
$work = $root . '/.maj-' . bin2hex(random_bytes(4));
$source = $package;
if (is_file($package)) {
    if (!str_ends_with(strtolower($package), '.zip')) {
        $fail('L’archive doit être un fichier .zip produit par scripts/build-dist.sh.');
    }
    mkdir($work, 0700);
    if (class_exists(ZipArchive::class)) {
        $zip = new ZipArchive();
        if ($zip->open($package) !== true || !$zip->extractTo($work)) {
            $fail('Archive illisible.');
        }
        $zip->close();
    } elseif (class_exists(PharData::class)) {
        (new PharData($package))->extractTo($work, null, true);
    } else {
        $fail('Ni l’extension zip ni phar : extrayez l’archive à la main, puis donnez le dossier extrait.');
    }
    $folders = glob($work . '/belprise-*', GLOB_ONLYDIR) ?: [];
    $source = $folders[0] ?? $work;
}
$source = rtrim($source, '/');
foreach (['VERSION', 'app/bootstrap.php', 'app/migrations', 'app/bin/update.php', 'public/index.php', 'site/index.html'] as $required) {
    if (!file_exists($source . '/' . $required)) {
        $fail('Archive incomplète : ' . $required . ' manquant. Est-ce bien une archive belprise ?');
    }
}
$next = trim((string) file_get_contents($source . '/VERSION'));
$say('Nouvelle version : ' . $next);
if ($checkOnly) {
    if (is_dir($work)) {
        $removeTree($work);
    }
    $say('Archive valable : rien n’a été modifié (--check).');
    exit(0);
}

// 2. Consistent copy of the database.
require __DIR__ . '/../bootstrap.php';
$config = Belprise\Config::load($root . '/app/config.php');
$database = $config->dataDir . '/belprise.sqlite';
$backup = $config->dataDir . '/avant-maj-' . $current . '.sqlite';
if (is_file($database)) {
    @unlink($backup);
    (new PDO('sqlite:' . $database))->exec('VACUUM INTO ' . (new PDO('sqlite::memory:'))->quote($backup));
    $say('Base sauvegardée : ' . $backup);
}
$version = static fn (): int => is_file($database) ? (int) (new PDO('sqlite:' . $database))->query('PRAGMA user_version')->fetchColumn() : 0;
$schemaBefore = $version();

// 3. Swap the code; the configuration goes with it.
$keep = $root . '/.avant-maj-' . $current;
if (is_dir($keep)) {
    $removeTree($keep);
}
mkdir($keep, 0700);
$parts = ['app', 'public', 'site'];
$moved = [];
$rollback = static function () use (&$moved, $root, $keep, $database, $backup, $schemaBefore, $version, $say, $removeTree, $work): void {
    foreach (array_reverse($moved) as $part) {
        if (is_dir($root . '/' . $part)) {
            $removeTree($root . '/' . $part); // the new code that failed
        }
        rename($keep . '/' . $part, $root . '/' . $part);
    }
    $removeTree($keep);
    $removeTree($work);
    if ($version() !== $schemaBefore && is_file($backup)) {
        copy($backup, $database);
        @unlink($database . '-wal');
        @unlink($database . '-shm');
        $say('Base remise dans son état d’avant.');
    }
    $say('Ancienne version remise en place.');
};
try {
    foreach ($parts as $part) {
        if (!is_dir($source . '/' . $part)) {
            continue;
        }
        if (is_dir($root . '/' . $part)) {
            rename($root . '/' . $part, $keep . '/' . $part);
            $moved[] = $part;
        }
        rename($source . '/' . $part, $root . '/' . $part);
    }
    copy($keep . '/app/config.php', $root . '/app/config.php');
    chmod($root . '/app/config.php', 0600);

    // 4. The new code checks itself: migrations, then the environment.
    $php = escapeshellarg(PHP_BINARY);
    $migrate = sprintf(
        '%s -r %s',
        $php,
        escapeshellarg('require ' . var_export($root . '/app/bootstrap.php', true) . '; $c = Belprise\Config::load(' . var_export($root . '/app/config.php', true) . '); Belprise\Database\Database::open($c->dataDir . "/belprise.sqlite", ' . var_export($root . '/app/migrations', true) . '); echo "ok";'),
    );
    exec($migrate . ' 2>&1', $out, $code);
    if ($code !== 0 || end($out) !== 'ok') {
        throw new RuntimeException('Migrations : ' . implode(' ', $out));
    }
    $say('Base à jour (schéma ' . $schemaBefore . ' → ' . $version() . ').');
    exec($php . ' ' . escapeshellarg($root . '/app/bin/check-env.php') . ' 2>&1', $check, $code);
    if ($code !== 0) {
        throw new RuntimeException("Environnement :\n" . implode("\n", $check));
    }
} catch (Throwable $e) {
    $rollback();
    $fail($e->getMessage());
}
// Only now: the version number and the guides of the new code.
foreach (['VERSION', 'DEPLOIEMENT.md'] as $file) {
    if (is_file($source . '/' . $file)) {
        copy($source . '/' . $file, $root . '/' . $file);
    }
}
if (is_dir($work)) {
    $removeTree($work);
}

$say('Version ' . $next . ' installée. L’ancien code reste dans ' . $keep . ' (à supprimer quand tout va bien).');
$say('Rechargez l’administration (Cmd + Maj + R) pour charger la nouvelle interface.');
