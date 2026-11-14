<?php

declare(strict_types=1);

// Never print errors (paths, broken headers), whatever the host's php.ini says.
ini_set('display_errors', '0');

require dirname(__DIR__) . '/app/bootstrap.php';

Belprise\App::run(dirname(__DIR__));
