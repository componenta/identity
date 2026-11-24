<?php

declare(strict_types=1);

$loader = require dirname(__DIR__) . '/vendor/autoload.php';

// Only test tooling may select another source tree (negative controls).
$source = getenv('IDENTITY_TEST_SOURCE');
if ($source !== false && $source !== '') {
    if (!is_file($source . '/Uuid.php') || !is_file($source . '/UuidFactory.php')) {
        throw new RuntimeException('Invalid Identity test source directory.');
    }
    $loader->setPsr4('Componenta\\Identity\\', $source);
}
