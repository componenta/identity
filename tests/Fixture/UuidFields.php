<?php

declare(strict_types=1);

namespace Componenta\Identity\Tests\Fixture;

use Componenta\Identity\UuidInterface;

/** Decode public RFC fields independently of the production generator. */
final class UuidFields
{
    public static function unixMilliseconds(UuidInterface $uuid): int
    {
        return (int) hexdec(substr(str_replace('-', '', $uuid->toString()), 0, 12));
    }

    public static function gregorianTicks(UuidInterface $uuid): int
    {
        $hex = str_replace('-', '', $uuid->toString());
        $timestamp = match ($hex[12]) {
            '1' => substr($hex, 13, 3) . substr($hex, 8, 4) . substr($hex, 0, 8),
            '6' => substr($hex, 0, 12) . substr($hex, 13, 3),
            default => throw new \InvalidArgumentException('Expected a Gregorian UUID.'),
        };

        return (int) hexdec($timestamp);
    }

    public static function counter(UuidInterface $uuid): int
    {
        return (int) hexdec(substr(str_replace('-', '', $uuid->toString()), 13, 3));
    }

    public static function clockSequence(UuidInterface $uuid): int
    {
        return (int) hexdec(substr(str_replace('-', '', $uuid->toString()), 16, 4)) & 0x3fff;
    }
}
