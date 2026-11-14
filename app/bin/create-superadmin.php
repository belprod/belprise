<?php

declare(strict_types=1);

// Creates a platform operator account (CAHIER_DES_CHARGES §5.11), on the server only.
// Usage: php app/bin/create-superadmin.php email@example.com "Prénom Nom"
// The password is typed twice (hidden), then the authenticator app is set up:
// the account exists only once a first code has been checked.

use Belprise\Config;
use Belprise\Database\Database;
use Belprise\Repository\Superadmins;
use Belprise\Security\AdminAuth;
use Belprise\Security\SecretBox;
use Belprise\Security\Totp;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/bootstrap.php';

[$email, $name] = [$argv[1] ?? '', $argv[2] ?? ''];
if ($email === '' || $name === '') {
    fwrite(STDERR, "Usage : php app/bin/create-superadmin.php <e-mail> \"<Prénom Nom>\"\n");
    exit(1);
}

$ask = static function (string $prompt, bool $hidden = false): string {
    fwrite(STDOUT, $prompt);
    $tty = $hidden && stream_isatty(STDIN);
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

try {
    $email = AdminAuth::validateEmail($email);
    $name = AdminAuth::validateName($name);
    $password = $ask('Mot de passe : ', true);
    AdminAuth::validatePassword($password, $ask('Confirmer le mot de passe : ', true));
} catch (\Belprise\Http\HttpException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$config = Config::load(Config::file(dirname(__DIR__, 2)));
$repository = new Superadmins(Database::open($config->dataDir . '/belprise.sqlite', dirname(__DIR__) . '/migrations'));
if ($repository->findByEmail($email) !== null) {
    fwrite(STDERR, "Ce compte existe déjà.\n");
    exit(1);
}

$secret = Totp::generateSecret();
echo "\nDouble authentification (obligatoire). Dans l'application d'authentification,\n";
echo "ajoutez un compte avec cette clé :\n\n    " . implode(' ', str_split($secret, 4)) . "\n\n";
echo "ou cette adresse (otpauth) :\n\n    " . Totp::uri($secret, $email) . "\n\n";
$step = null;
for ($try = 0; $try < 3 && $step === null; $try++) {
    $step = Totp::verify($secret, $ask('Code affiché par l’application : '), time());
    if ($step === null) {
        echo "Code incorrect.\n";
    }
}
if ($step === null) {
    fwrite(STDERR, "Compte non créé.\n");
    exit(1);
}

$repository->create($email, $name, password_hash($password, PASSWORD_ARGON2ID), (new SecretBox($config->secret, 'totp'))->seal($secret), $step);
echo "Compte d’exploitation créé. Connexion : /superadmin\n";
