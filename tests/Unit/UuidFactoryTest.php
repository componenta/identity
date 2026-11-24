<?php

declare(strict_types=1);

use Componenta\Identity\RfcUuidInterface;
use Componenta\Identity\Uuid;
use Componenta\Identity\UuidFactory;
use Componenta\Identity\UuidGenerationVersion;
use Componenta\Identity\UuidNamespace;
use Componenta\Identity\UuidVariant;

covers(UuidFactory::class);

it('generates the version configured at construction', function (UuidGenerationVersion $version): void {
    $uuid = (new UuidFactory($version))->generate();

    expect($uuid)->toBeInstanceOf(RfcUuidInterface::class)
        ->and($uuid->version)->toBe($version->value)
        ->and($uuid->variant)->toBe(UuidVariant::Rfc);
})->with(UuidGenerationVersion::cases());

it('uses UUIDv7 by default', function (): void {
    expect(Uuid::fromString((new UuidFactory())->generate()->toString())->version)->toBe(7);
});

it('generates correctly marked random or time-based versions', function (string $method, int $version): void {
    $uuid = (new UuidFactory())->$method();

    expect($uuid)->toBeInstanceOf(RfcUuidInterface::class)
        ->and(strlen($uuid->bytes))->toBe(16)
        ->and($uuid->version)->toBe($version)
        ->and($uuid->variant)->toBe(UuidVariant::Rfc);
})->with([
    'v1' => ['v1', 1],
    'v4' => ['v4', 4],
    'v6' => ['v6', 6],
    'v7' => ['v7', 7],
]);

it('generates the same name-based value for the same namespace and octets', function (string $method, int $version): void {
    $factory = new UuidFactory();
    $first = $factory->$method(UuidNamespace::Dns, 'example.com');
    $second = $factory->$method(UuidNamespace::Dns, 'example.com');

    expect($first->equals($second))->toBeTrue()
        ->and(Uuid::fromString($first->toString())->version)->toBe($version);
})->with([['v3', 3], ['v5', 5]]);

it('restores the UUID from network-order bytes', function (): void {
    $uuid = (new UuidFactory())->fromBytes(hex2bin('6ba7b8109dad11d180b400c04fd430c8'));

    expect($uuid)->toBeInstanceOf(RfcUuidInterface::class)
        ->and($uuid->toString())->toBe('6ba7b810-9dad-11d1-80b4-00c04fd430c8');
});

it('rejects malformed binary values through the factory interface', function (int $length): void {
    expect(fn () => (new UuidFactory())->fromBytes(str_repeat("\0", $length)))->toThrow(InvalidArgumentException::class);
})->with([0, 15, 17]);

it('sets the v8 version and variant without changing the custom payload bits', function (): void {
    $uuid = (new UuidFactory())->v8(
        "\x01\x02\x03\x04\x05\x06",
        0x0ABC,
        "\xFF\xEE\xDD\xCC\xBB\xAA\x99\x88",
    );

    expect($uuid->toString())->toBe('01020304-0506-8abc-bfee-ddccbbaa9988');
});

it('rejects invalid custom v8 fields', function (string $a, int $b, string $c): void {
    expect(fn () => (new UuidFactory())->v8($a, $b, $c))->toThrow(InvalidArgumentException::class);
})->with([
    'short a' => ['short', 0, str_repeat("\0", 8)],
    'long a' => [str_repeat("\0", 7), 0, str_repeat("\0", 8)],
    'negative b' => [str_repeat("\0", 6), -1, str_repeat("\0", 8)],
    'overflow b' => [str_repeat("\0", 6), 4096, str_repeat("\0", 8)],
    'short c' => [str_repeat("\0", 6), 0, 'short'],
    'long c' => [str_repeat("\0", 6), 0, str_repeat("\0", 9)],
]);

it('matches independent name-based vectors including arbitrary binary input', function (string $name, string $v3, string $v5): void {
    // Reference vectors obtained from Python hashlib/uuid; do not derive
    // expected UUIDs using the implementation under test.
    $namespace = Uuid::fromString('c63c06d8-9c3e-4d1a-b002-2cb47a9e1375');
    $factory = new UuidFactory();

    expect($factory->v3($namespace, $name)->toString())->toBe($v3)
        ->and($factory->v5($namespace, $name)->toString())->toBe($v5);
})->with([
    'empty' => ['', '1b2f9130-1500-3c17-9869-75192794a2bf', '251d6522-009e-5649-97cb-6fcb5306cb87'],
    'lowercase' => ['example', '19f766d7-755e-3748-bb94-ecd1a6dbec58', '3b068c89-8402-5b04-91c3-9a0e9eb77f7d'],
    'mixed case' => ['Example', 'c3368d1e-2631-34f3-b129-7154528143cd', '4640dcb7-f505-5ea4-b661-e9ba84945ced'],
    'binary' => ["a\0b\xff", 'a5b568d7-fa57-3a84-97c5-0ef50773071e', 'f81b714c-8a7e-5ea0-8e26-fc37292e557d'],
    'precomposed UTF-8' => ["\xc3\xa9", 'c7ddd87a-a8ac-3735-9af9-c0737f4dbc43', '67c81f80-4482-54af-8e36-4deb5ef2d91d'],
    'decomposed UTF-8' => ["e\xcc\x81", '9eefe10b-4819-3948-b5e5-aefe56d1c492', '2e5de8ce-163a-580c-9676-4685cbf72607'],
]);

it('preserves the configured version through factory serialization', function (UuidGenerationVersion $version): void {
    $restored = unserialize(serialize(new UuidFactory($version)));

    expect($restored)->toBeInstanceOf(UuidFactory::class)
        ->and(Uuid::fromString($restored->generate()->toString())->version)->toBe($version->value);
})->with(UuidGenerationVersion::cases());

it('restores the version of a legacy native factory snapshot', function (): void {
    $class = UuidFactory::class;
    $key = "\0" . $class . "\0defaultVersion";
    $payload = 'O:' . strlen($class) . ':"' . $class . '":1:{' . serialize($key) . serialize(UuidGenerationVersion::V4) . '}';

    $restored = unserialize($payload);

    expect($restored)->toBeInstanceOf(UuidFactory::class)
        ->and(Uuid::fromString($restored->generate()->toString())->version)->toBe(4);
});

it('cannot change the configured version of an initialized factory by restoring state', function (): void {
    $factory = new UuidFactory(UuidGenerationVersion::V4);

    expect(fn () => $factory->__unserialize(['defaultVersion' => UuidGenerationVersion::V7]))->toThrow(Error::class);
    expect(Uuid::fromString($factory->generate()->toString())->version)->toBe(4);
});
