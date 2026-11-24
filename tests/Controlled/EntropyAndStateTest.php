<?php

declare(strict_types=1);

use Componenta\Identity\Tests\Fixture\ControlledSystem as System;
use Componenta\Identity\Tests\Fixture\UuidFields;
use Componenta\Identity\Uuid;
use Componenta\Identity\UuidFactory;

covers(UuidFactory::class);

beforeEach(fn () => System::reset());
afterEach(fn () => System::reset());

it('does not substitute a weaker random source after an entropy exception', function (string $method, string $source): void {
    System::freeze(1645557742);
    $failure = new Random\RandomException('entropy unavailable');
    if ($source === 'integer') {
        System::$integer = $failure;
    } else {
        System::$bytes = $failure;
    }

    try {
        (new UuidFactory())->$method();
        test()->fail('Expected the entropy exception.');
    } catch (Random\RandomException $caught) {
        expect($caught)->toBe($failure);
    }
})->with([
    'v1 initial sequence' => ['v1', 'integer'],
    'v1 initial node' => ['v1', 'bytes'],
    'v4 random bits' => ['v4', 'bytes'],
    'v6 random tail' => ['v6', 'bytes'],
    'v7 initial seed' => ['v7', 'integer'],
    'v7 random tail' => ['v7', 'bytes'],
]);

it('does not reproduce a warmed generator sequence after clone or serialization', function (string $method): void {
    System::freeze(1645557742);
    $factory = new UuidFactory();
    $warmup = $factory->$method();
    $clone = clone $factory;
    $restored = unserialize(serialize($factory));

    expect($restored)->toBeInstanceOf(UuidFactory::class);
    $ids = array_map('strval', [$warmup, $factory->$method(), $clone->$method(), $restored->$method()]);

    expect(array_unique($ids))->toHaveCount(4);
})->with(['v1', 'v6', 'v7']);

it('preserves timestamp semantics across mixed-version generation, clock changes and failures', function (): void {
    $factory = new UuidFactory();
    $lastGregorian = null;
    $lastV7 = null;
    $seen = [];
    $seconds = 1645557742;
    $micros = 0;
    $failure = new Random\RandomException('mixed trace entropy failure');
    // A deterministic schedule only for the test clock, never UUID entropy.
    $schedule = new Random\Randomizer(new Random\Engine\Mt19937(12345));

    for ($i = 0; $i < 20000; ++$i) {
        $micros += $schedule->getInt(-2, 2);
        if ($micros < 0) {
            --$seconds;
            $micros += 1000000;
        } elseif ($micros >= 1000000) {
            ++$seconds;
            $micros -= 1000000;
        }
        System::freeze($seconds, $micros);
        $method = ['v1', 'v6', 'v7'][$i % 3];
        if ($i % 19 === 2 && $method !== 'v1') {
            System::$bytes = $failure;
            try {
                $factory->$method();
                test()->fail('Expected entropy failure.');
            } catch (Random\RandomException $caught) {
                expect($caught)->toBe($failure);
            }
            System::$bytes = null;
        }

        $uuid = Uuid::fromString($factory->$method()->toString());
        expect(isset($seen[$uuid->bytes]))->toBeFalse();
        $seen[$uuid->bytes] = true;
        if ($method === 'v7') {
            expect($lastV7 === null || strcmp($uuid->bytes, $lastV7) > 0)->toBeTrue();
            $lastV7 = $uuid->bytes;
        } else {
            $physical = $seconds * 10000000 + $micros * 10 + 122192928000000000;
            $expected = $lastGregorian === null ? $physical : max($physical, $lastGregorian + 1);
            expect(UuidFields::gregorianTicks($uuid))->toBe($expected);
            $lastGregorian = $expected;
        }
    }

    expect($seen)->toHaveCount(20000);
});
