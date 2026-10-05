<?php

declare(strict_types=1);

use ApiClient\Application\RelayDeckApplication;

require __DIR__ . '/vendor/autoload.php';

$application = new RelayDeckApplication(__DIR__);
$application->run();
