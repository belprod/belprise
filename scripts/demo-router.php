<?php

// Local preview of the demo with PHP's built-in server (never on a real server):
//     BELPRISE_CONFIG=$PWD/dist/demo/config.php php -S 127.0.0.1:8092 -t public scripts/demo-router.php
// Files of public/ are served as they are, everything else goes to the application.
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && !str_contains($path, '..') && is_file(dirname(__DIR__) . '/public' . $path)) {
    return false;
}
require dirname(__DIR__) . '/public/index.php';
