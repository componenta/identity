<?php

declare(strict_types=1);

namespace Componenta\Identity\Tests\Fixture;

use Throwable;

/** Mutable test-only controls for the OS clock and entropy boundary. */
final class ControlledSystem
{
    /** @var array{sec: int, usec: int}|null */
    public static ?array $time = null;
    public static int|Throwable|null $integer = null;
    public static string|Throwable|null $bytes = null;

    public static function freeze(int $seconds, int $microseconds = 0): void
    {
        self::$time = ['sec' => $seconds, 'usec' => $microseconds];
    }

    public static function reset(): void
    {
        self::$time = null;
        self::$integer = null;
        self::$bytes = null;
    }
}
