<?php

declare(strict_types=1);

use Componenta\Identity\Uuid;
use Componenta\Identity\UuidNamespace;

covers(UuidNamespace::class);

it('exposes the predefined RFC namespace identifiers and network-order bytes', function (UuidNamespace $namespace, string $expected): void {
    expect($namespace->value)->toBe($expected)
        ->and($namespace->toUuid()->toString())->toBe($expected)
        ->and(Uuid::fromBytes($namespace->getBytes())->toString())->toBe($expected);
})->with([
    'DNS' => [UuidNamespace::Dns, '6ba7b810-9dad-11d1-80b4-00c04fd430c8'],
    'URL' => [UuidNamespace::Url, '6ba7b811-9dad-11d1-80b4-00c04fd430c8'],
    'OID' => [UuidNamespace::Oid, '6ba7b812-9dad-11d1-80b4-00c04fd430c8'],
    'X.500' => [UuidNamespace::X500, '6ba7b814-9dad-11d1-80b4-00c04fd430c8'],
]);
