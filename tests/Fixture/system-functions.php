<?php

declare(strict_types=1);

namespace Componenta\Identity;

use Componenta\Identity\Tests\Fixture\ControlledSystem;
use RuntimeException;
use Throwable;

// Loaded ONLY in the controlled-clock test process and fork sample worker.
// Do not load these functions through the normal Composer autoloader.
function gettimeofday(): array
{
    return ControlledSystem::$time ?? \gettimeofday();
}

function random_int(int $min, int $max): int
{
    $value = ControlledSystem::$integer;
    if ($value instanceof Throwable) {
        throw $value;
    }
    if ($value === null) {
        return \random_int($min, $max);
    }
    if ($value < $min || $value > $max) {
        throw new RuntimeException('Entropy fixture outside requested range.');
    }
    return $value;
}

function random_bytes(int $length): string
{
    $value = ControlledSystem::$bytes;
    if ($value instanceof Throwable) {
        throw $value;
    }
    if ($value === null) {
        return \random_bytes($length);
    }
    if (strlen($value) !== $length) {
        throw new RuntimeException('Unexpected entropy fixture length.');
    }
    return $value;
}
