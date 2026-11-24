<?php

declare(strict_types=1);

use Componenta\Identity\Tests\Fixture\ControlledSystem as System;
use Componenta\Identity\UuidFactory;
use Componenta\Identity\UuidNamespace;

covers(UuidFactory::class);

beforeEach(fn () => System::reset());
afterEach(fn () => System::reset());

it('matches the RFC 9562 Gregorian timestamp and node vectors', function (string $method, ?int $seed, string $entropy, string $expected): void {
    System::freeze(1645557742);
    System::$integer = $seed;
    System::$bytes = hex2bin($entropy);

    expect((new UuidFactory())->$method()->toString())->toBe($expected);
})->with([
    'A.1 v1' => ['v1', 0x33c8, '9e6bdeced846', 'c232ab00-9414-11ec-b3c8-9f6bdeced846'],
    'A.5 v6' => ['v6', null, '33c89e6bdeced846', '1ec9414c-232a-6b00-b3c8-9f6bdeced846'],
]);

it('matches the RFC A.3 random UUID vector', function (): void {
    System::$bytes = hex2bin('919108f752d133205bacf847db4148a8');

    expect((new UuidFactory())->v4()->toString())->toBe('919108f7-52d1-4320-9bac-f847db4148a8');
});

it('matches the RFC name-based vectors', function (string $method, string $expected): void {
    expect((new UuidFactory())->$method(UuidNamespace::Dns, 'www.example.com')->toString())->toBe($expected);
})->with([
    'A.2 v3' => ['v3', '5df41881-3aed-3515-88a7-2f4a814cf09e'],
    'A.4 v5' => ['v5', '2ed6657d-e927-568b-95e1-2665a8aea6a2'],
]);

it('matches RFC A.6 field encoding with a dedicated v7 counter', function (): void {
    System::freeze(1645557742);
    System::$integer = 0xc3;
    System::$bytes = hex2bin('18c4dc0c0c07398f');
    $factory = new UuidFactory();

    // A.6 illustrates rand_a = 0xcc3. Reach it using the public API
    // rather than modifying the private sequence state.
    for ($i = 0; $i <= 0xc00; ++$i) {
        $uuid = $factory->v7();
    }

    expect($uuid->toString())->toBe('017f22e2-79b0-7cc3-98c4-dc0c0c07398f');
});

it('matches the illustrative RFC v8 custom-field vectors', function (string $a, int $b, string $c, string $expected): void {
    expect((new UuidFactory())->v8(hex2bin($a), $b, hex2bin($c))->toString())->toBe($expected);
})->with([
    'B.1' => ['2489e9ad2ee2', 0xe00, '0ec932d5f69181c0', '2489e9ad-2ee2-8e00-8ec9-32d5f69181c0'],
    'B.2 corrected' => ['5c146b143c52', 0xafd, '938a375d0df1fbf6', '5c146b14-3c52-8afd-938a-375d0df1fbf6'],
]);
