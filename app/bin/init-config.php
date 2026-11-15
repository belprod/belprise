<?php

declare(strict_types=1);

// Creates app/config.php from config.example.php with a random HMAC secret.

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$appDir = dirname(__DIR__);
$target = $appDir . '/config.php';

if (file_exists($target)) {
    fwrite(STDERR, "app/config.php existe déjà : rien n'a été modifié.\n");
    exit(1);
}

$example = file_get_contents($appDir . '/config.example.php');
$placeholder = "'secret' => '',";
if ($example === false || substr_count($example, $placeholder) !== 1) {
    fwrite(STDERR, "config.example.php est illisible ou ne contient pas la clé 'secret' attendue.\n");
    exit(1);
}

$secret = bin2hex(random_bytes(32));
$config = str_replace($placeholder, "'secret' => '" . $secret . "',", $example);

if (file_put_contents($target, $config) === false) {
    fwrite(STDERR, "Impossible d'écrire app/config.php.\n");
    exit(1);
}
// The secret protects the sessions, the media links and the two-factor keys: not readable by everyone.
// Run as root in the development container: the web server's group may read it.
chmod($target, 0640);
if (function_exists('posix_getuid') && posix_getuid() === 0) {
    @chgrp($target, 'www-data');
}

echo "app/config.php créé avec une clé secrète aléatoire.\n";
