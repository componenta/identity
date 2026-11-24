<?php

declare(strict_types=1);

use Componenta\Identity\RfcUuidInterface;
use Componenta\Identity\UuidFactory;
use Componenta\Identity\UuidVariant;

covers(UuidFactory::class);

it('generates 250000 distinct RFC UUIDs with real entropy across eight independent factories', function (string $method, int $version): void {
    $factories = array_map(static fn () => new UuidFactory(), range(1, 8));
    $seen = [];
    $last = [];
    $invalid = 0;
    $outOfOrder = 0;

    for ($i = 0; $i < 250000; ++$i) {
        $worker = $i % count($factories);
        $uuid = $factories[$worker]->$method();
        if (!$uuid instanceof RfcUuidInterface) {
            test()->fail('Expected an RFC UUID at sample ' . $i);
        }
        $bytes = $uuid->bytes;
        if (strlen($bytes) !== 16 || $uuid->version !== $version || $uuid->variant !== UuidVariant::Rfc || (ord($bytes[8]) & 0xc0) !== 0x80) {
            ++$invalid;
        }
        $seen[$bytes] = true;
        if ($version === 6 || $version === 7) {
            if (isset($last[$worker]) && strcmp($bytes, $last[$worker]) <= 0) {
                ++$outOfOrder;
            }
            $last[$worker] = $bytes;
        }
    }

    expect($invalid)->toBe(0)
        ->and($seen)->toHaveCount(250000)
        ->and($outOfOrder)->toBe(0);
})->with([
    'v1' => ['v1', 1],
    'v4' => ['v4', 4],
    'v6' => ['v6', 6],
    'v7' => ['v7', 7],
])->group('stress');
