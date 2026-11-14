<?php

declare(strict_types=1);

// Emergency: turns off a user's two-factor authentication (lost phone).
// Needs shell access to the server: php app/bin/disable-totp.php email@example.com

use Belprise\Config;
use Belprise\Database\Database;
use Belprise\Repository\RecoveryCodes;
use Belprise\Repository\Users;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/bootstrap.php';

$email = $argv[1] ?? '';
if ($email === '') {
    fwrite(STDERR, "Usage : php app/bin/disable-totp.php <e-mail du compte>\n");
    exit(1);
}
$config = Config::load(Config::file(dirname(__DIR__, 2)));
$db = Database::open($config->dataDir . '/belprise.sqlite', dirname(__DIR__) . '/migrations');
$users = new Users($db);
$user = $users->findByEmail($email);
if ($user === null) {
    fwrite(STDERR, "Aucun compte avec cet identifiant.\n");
    exit(1);
}
if ($user['totp_secret'] === null) {
    echo "La double authentification n'est pas activée pour ce compte.\n";
    exit(0);
}
// New session epoch: sessions opened with the lost phone end too.
$users->update((int) $user['id'], ['totp_secret' => null, 'totp_last_step' => null, 'session_epoch' => bin2hex(random_bytes(8))]);
(new RecoveryCodes($db))->delete((int) $user['id']);
echo "Double authentification désactivée. Connectez-vous avec le mot de passe, puis réactivez-la dans Réglages.\n";
