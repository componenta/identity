<?php

declare(strict_types=1);

use Componenta\Identity\Tests\Fixture\ControlledSystem as System;
use Componenta\Identity\Tests\Fixture\UuidFields;
use Componenta\Identity\Uuid;
use Componenta\Identity\UuidFactory;

covers(UuidFactory::class);

beforeEach(fn () => System::reset());
afterEach(fn () => System::reset());

it('changes the v1 sequence only on a physical clock rollback and handles sequence wrap', function (): void {
    System::freeze(1645557742);
    System::$integer = 0x3fff;
    System::$bytes = hex2bin('9e6bdeced846');
    $factory = new UuidFactory();

    $first = $factory->v1();
    $sameTick = $factory->v1();
    System::freeze(1645557741);
    $rolledBack = $factory->v1();
    $repeatedRollbackTick = $factory->v1();

    expect(UuidFields::clockSequence($first))->toBe(0x3fff)
        ->and(UuidFields::clockSequence($sameTick))->toBe(0x3fff)
        ->and(UuidFields::clockSequence($rolledBack))->toBe(0)
        ->and(UuidFields::clockSequence($repeatedRollbackTick))->toBe(0)
        ->and(array_unique(array_map('strval', [$first, $sameTick, $rolledBack, $repeatedRollbackTick])))->toHaveCount(4);
});

it('uses fresh v6 sequence and multicast node bits even at one physical tick', function (): void {
    System::freeze(1645557742);
    $factory = new UuidFactory();
    System::$bytes = hex2bin('1234001122334455');
    $first = Uuid::fromString($factory->v6()->toString());
    System::$bytes = hex2bin('567866778899aabb');
    $second = Uuid::fromString($factory->v6()->toString());

    expect(substr($first->bytes, 8))->toBe(hex2bin('9234011122334455'))
        ->and(substr($second->bytes, 8))->toBe(hex2bin('967867778899aabb'))
        ->and(strcmp($first->bytes, $second->bytes))->toBeLessThan(0);
});

it('supports Gregorian epoch zero without losing the first representable tick', function (string $method): void {
    System::freeze(-12219292800);

    expect(UuidFields::gregorianTicks((new UuidFactory())->$method()))->toBe(0);
})->with(['v1', 'v6']);

it('rejects unrepresentable Gregorian wall times rather than truncating the timestamp', function (string $method, int $seconds): void {
    System::freeze($seconds);

    expect(fn () => (new UuidFactory())->$method())->toThrow(OverflowException::class);
})->with([
    'v1 before epoch' => ['v1', -12219292801],
    'v6 before epoch' => ['v6', -12219292801],
    'v1 after max' => ['v1', 103072857661],
    'v6 after max' => ['v6', 103072857661],
    'v1 arithmetic overflow' => ['v1', PHP_INT_MAX],
    'v6 arithmetic underflow' => ['v6', PHP_INT_MIN],
]);

it('generates the final Gregorian ticks without wrap and rejects exhaustion', function (string $method, bool $failFirst): void {
    // RFC field width and epoch, not private generator constants.
    $maximum = 0x0fffffffffffffff;
    $unixTicks = $maximum - 122192928000000000;
    System::freeze(intdiv($unixTicks, 10000000), intdiv($unixTicks % 10000000, 10));
    $factory = new UuidFactory();

    if ($failFirst) {
        $failure = new Random\RandomException('Gregorian entropy failure');
        System::$bytes = $failure;
        try {
            $factory->$method();
            test()->fail('Expected entropy failure.');
        } catch (Random\RandomException $caught) {
            expect($caught)->toBe($failure);
        }
        System::$bytes = null;
    }

    $remaining = $unixTicks % 10;
    for ($i = 0; $i <= $remaining; ++$i) {
        expect(UuidFields::gregorianTicks($factory->$method()))->toBe($maximum - $remaining + $i);
    }

    expect(fn () => $factory->$method())->toThrow(OverflowException::class);
})->with([
    'v1 exhaustion' => ['v1', false],
    'v6 exhaustion' => ['v6', false],
    'v1 recover after entropy failure' => ['v1', true],
    'v6 recover after entropy failure' => ['v6', true],
]);

it('rejects physical time immediately beyond the last representable Gregorian tick', function (string $method): void {
    System::freeze(103072857660, 684698);
    expect(fn () => (new UuidFactory())->$method())->toThrow(OverflowException::class);
})->with(['v1', 'v6']);

it('refreshes v1 node and clock sequence after a process identity change', function (): void {
    System::freeze(1645557742);
    System::$pid = 100;
    System::$integer = 0;
    System::$bytes = hex2bin('001122334455');
    $factory = new UuidFactory();
    $first = Uuid::fromString($factory->v1()->toString());

    System::$pid = 200;
    System::$integer = 123;
    System::$bytes = hex2bin('66778899aabb');
    $second = Uuid::fromString($factory->v1()->toString());

    expect(UuidFields::clockSequence($first))->toBe(0)
        ->and(substr($first->bytes, 10))->toBe(hex2bin('011122334455'))
        ->and(UuidFields::clockSequence($second))->toBe(123)
        ->and(substr($second->bytes, 10))->toBe(hex2bin('67778899aabb'))
        ->and(UuidFields::gregorianTicks($second))->toBeGreaterThan(UuidFields::gregorianTicks($first));
});

it('fails closed when the OS cannot identify the process owning v1 state', function (): void {
    System::$pid = false;
    expect(fn () => (new UuidFactory())->v1())->toThrow(RuntimeException::class, 'Unable to determine UUID generator process.');
});

it('encodes both possible endpoints of the initial v1 random clock sequence', function (bool $upper, int $expected): void {
    System::freeze(1645557742);
    System::$integer = static fn (int $min, int $max): int => $upper ? $max : $min;
    System::$bytes = hex2bin('001122334455');

    expect(UuidFields::clockSequence((new UuidFactory())->v1()))->toBe($expected);
})->with([
    'lower endpoint' => [false, 0],
    'upper endpoint' => [true, 16383],
]);

it('increments the v1 clock sequence on an ordinary physical clock rollback', function (): void {
    System::freeze(1645557742);
    System::$integer = 2;
    System::$bytes = hex2bin('001122334455');
    $factory = new UuidFactory();
    $first = $factory->v1();
    System::freeze(1645557741);
    $second = $factory->v1();

    expect(UuidFields::clockSequence($first))->toBe(2)
        ->and(UuidFields::clockSequence($second))->toBe(3)
        ->and(UuidFields::gregorianTicks($second))->toBeGreaterThan(UuidFields::gregorianTicks($first));
});
