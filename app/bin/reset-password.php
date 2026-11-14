<?php

declare(strict_types=1);

// Emergency: sets a new password for a user of an organization, or for the
// operator of the installation (who has no "forgotten password" by e-mail,
// CAHIER_DES_CHARGES §5.10).
// Needs shell access to the server: php app/bin/reset-password.php email@example.com
// The password is typed twice (hidden, never in the shell history). Open
// sessions end; two-factor authentication stays as it was.

use Belprise\Config;
use Belprise\Database\Database;
use Belprise\Repository\AuditLog;
use Belprise\Repository\Superadmins;
use Belprise\Repository\Users;
use Belprise\Security\AdminAuth;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/bootstrap.php';

$email = $argv[1] ?? '';
if ($email === '') {
    fwrite(STDERR, "Usage : php app/bin/reset-password.php <e-mail du compte>\n");
    exit(1);
}

$ask = static function (string $prompt): string {
    fwrite(STDOUT, $prompt);
    $tty = stream_isatty(STDIN);
    if ($tty) {
        shell_exec('stty -echo');
    }
    $value = rtrim((string) fgets(STDIN), "\r\n");
    if ($tty) {
        shell_exec('stty echo');
        fwrite(STDOUT, "\n");
    }

    return $value;
};

$config = Config::load(Config::file(dirname(__DIR__, 2)));
$db = Database::open($config->dataDir . '/belprise.sqlite', dirname(__DIR__) . '/migrations');
$users = new Users($db);
$user = $users->findByEmail($email);
$operator = $user === null ? (new Superadmins($db))->findByEmail($email) : null;
if ($user === null && $operator === null) {
    fwrite(STDERR, "Aucun compte avec cet identifiant.\n");
    exit(1);
}

try {
    $password = $ask('Nouveau mot de passe : ');
    AdminAuth::validatePassword($password, $ask('Confirmer le mot de passe : '));
} catch (\Belprise\Http\HttpException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

if ($operator !== null) {
    $db->prepare('UPDATE superadmins SET password_hash = ?, session_epoch = ? WHERE id = ?')
        ->execute([password_hash($password, PASSWORD_ARGON2ID), bin2hex(random_bytes(8)), (int) $operator['id']]);
    $db->exec('DELETE FROM login_attempts');
    echo "Mot de passe changé pour le compte d'exploitation " . $operator['email'] . ". Connexion : /superadmin (le code de double authentification reste demandé).\n";
    exit(0);
}

// New session epoch: sessions opened with the old password end too.
$users->update((int) $user['id'], ['password_hash' => password_hash($password, PASSWORD_ARGON2ID), 'session_epoch' => bin2hex(random_bytes(8))]);
(new AuditLog($db))->add((int) $user['organization_id'], 'Serveur', 'Mot de passe réinitialisé', (string) $user['name']);
// Attempts already counted against this login no longer block it.
$db->exec('DELETE FROM login_attempts');

echo "Mot de passe changé pour " . $user['email'] . ". Connexion : /admin\n";
echo $user['totp_secret'] === null ? '' : "La double authentification reste active : le code sera demandé.\n";
