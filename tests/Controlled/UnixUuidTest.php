<?php

declare(strict_types=1);

use Componenta\Identity\Tests\Fixture\ControlledSystem as System;
use Componenta\Identity\Tests\Fixture\UuidFields;
use Componenta\Identity\Uuid;
use Componenta\Identity\UuidFactory;

covers(UuidFactory::class);

beforeEach(fn () => System::reset());
afterEach(fn () => System::reset());

it('initializes random state at the first Unix millisecond', function (): void {
    System::freeze(0);
    System::$integer = 23;
    System::$bytes = hex2bin('1122334455667788');

    expect((new UuidFactory())->v7()->toString())->toBe('00000000-0000-7017-9122-334455667788');
});

it('refreshes the v7 random tail for every value in the same millisecond', function (): void {
    System::freeze(1645557742);
    System::$integer = 0;
    System::$bytes = hex2bin('18c4dc0c0c07398f');
    $factory = new UuidFactory();
    $first = $factory->v7();
    System::$bytes = hex2bin('0123456789abcdef');
    $second = $factory->v7();

    expect($first->toString())->toBe('017f22e2-79b0-7000-98c4-dc0c0c07398f')
        ->and($second->toString())->toBe('017f22e2-79b0-7001-8123-456789abcdef');
});

it('rejects unrepresentable Unix time', function (int $seconds, int $micros): void {
    System::freeze($seconds, $micros);

    expect(fn () => (new UuidFactory())->v7())->toThrow(OverflowException::class);
})->with([
    'before Unix epoch' => [-1, 999999],
    'after maximum millisecond' => [281474976710, 656000],
    'arithmetic overflow' => [PHP_INT_MAX, 0],
]);

it('does not repeat or reverse v7 identifiers through repeated ticks, rollback and rollover', function (): void {
    System::freeze(1645557742);
    $factory = new UuidFactory();
    $last = '';
    for ($i = 0; $i < 9000; ++$i) {
        if ($i === 100) {
            System::freeze(1645557741);
        }
        $id = $factory->v7();
        expect(strcmp($id->toString(), $last))->toBeGreaterThan(0);
        $last = $id->toString();
    }

    expect(UuidFields::unixMilliseconds($id))->toBeGreaterThan(1645557742000);
});

it('fails rather than wrapping the largest 48-bit timestamp', function (): void {
    System::freeze(281474976710, 655999);
    System::$integer = 0;
    $factory = new UuidFactory();

    for ($i = 0; $i < 4096; ++$i) {
        $id = $factory->v7();
        expect(UuidFields::unixMilliseconds($id))->toBe(0xffffffffffff)
            ->and(UuidFields::counter($id))->toBe($i);
    }

    expect(fn () => $factory->v7())->toThrow(OverflowException::class);
});

it('recovers after entropy acquisition fails during initial generation', function (): void {
    System::freeze(1645557742);
    $factory = new UuidFactory();
    System::$integer = 0;
    System::$bytes = new Random\RandomException('entropy failure');

    expect(fn () => $factory->v7())->toThrow(Random\RandomException::class);
    System::$bytes = hex2bin('0102030405060708');
    expect($factory->v7()->toString())->toBe('017f22e2-79b0-7000-8102-030405060708');
});

it('propagates the original entropy failure within a millisecond and resumes ordering', function (): void {
    System::freeze(1645557742);
    $factory = new UuidFactory();
    $first = $factory->v7();
    $failure = new Random\RandomException('entropy failure');
    System::$bytes = $failure;

    try {
        $factory->v7();
        test()->fail('Expected entropy failure.');
    } catch (Random\RandomException $caught) {
        expect($caught)->toBe($failure);
    }

    System::$bytes = null;
    expect(strcmp($factory->v7()->toString(), $first->toString()))->toBeGreaterThan(0);
});

it('recovers the same next v7 prefix after both rollover entropy failures', function (): void {
    System::freeze(1645557742);
    System::$integer = 255;
    System::$bytes = str_repeat("\x11", 8);
    $factory = new UuidFactory();

    for ($i = 255; $i <= 4095; ++$i) {
        $id = $factory->v7();
        expect(UuidFields::unixMilliseconds($id))->toBe(1645557742000)
            ->and(UuidFields::counter($id))->toBe($i);
    }

    $failure = new Random\RandomException('rollover entropy failure');
    System::$integer = $failure;
    expect(fn () => $factory->v7())->toThrow(Random\RandomException::class);
    System::$integer = 17;
    System::$bytes = $failure;
    expect(fn () => $factory->v7())->toThrow(Random\RandomException::class);

    System::$bytes = str_repeat("\x22", 8);
    $id = $factory->v7();
    expect(UuidFields::unixMilliseconds($id))->toBe(1645557742001)
        ->and(UuidFields::counter($id))->toBe(17);
    System::freeze(1645557740);
    $id = $factory->v7();
    expect(UuidFields::unixMilliseconds($id))->toBe(1645557742001)
        ->and(UuidFields::counter($id))->toBe(18);
    System::freeze(1645557743);
    $id = $factory->v7();
    expect(UuidFields::unixMilliseconds($id))->toBe(1645557743000)
        ->and(UuidFields::counter($id))->toBe(17);
});

it('encodes microsecond readings using the containing Unix millisecond', function (int $micros, int $expected): void {
    System::freeze(1645557742, $micros);
    expect(UuidFields::unixMilliseconds((new UuidFactory())->v7()))->toBe($expected);
})->with([
    [999, 1645557742000],
    [1000, 1645557742001],
    [999999, 1645557742999],
]);

it('encodes the lower endpoint of a random v7 seed at initialization and rollover', function (): void {
    System::freeze(1645557742);
    System::$integer = static fn (int $min, int $max): int => $min;
    System::$bytes = str_repeat("\x11", 8);
    $factory = new UuidFactory();

    $first = Uuid::fromString($factory->v7()->toString());
    expect($first->version)->toBe(7)
        ->and(UuidFields::counter($first))->toBe(0);

    for ($i = 1; $i <= 4095; ++$i) {
        $factory->v7();
    }

    $next = Uuid::fromString($factory->v7()->toString());
    expect($next->version)->toBe(7)
        ->and(UuidFields::unixMilliseconds($next))->toBe(1645557742001)
        ->and(UuidFields::counter($next))->toBe(0);
});
