<?php

declare(strict_types=1);

namespace Componenta\Identity\Tests;

use Componenta\Identity\RfcUuidInterface;
use Componenta\Identity\Uuid;
use Componenta\Identity\UuidFactory;
use Componenta\Identity\UuidGenerationVersion;
use Componenta\Identity\UuidInterface;
use Componenta\Identity\UuidNamespace;
use Componenta\Identity\UuidVariant;
use PHPUnit\Framework\TestCase;

final class UuidFactoryTest extends TestCase
{
    public function testGenerateUsesConfiguredDefaultVersion(): void
    {
        $uuid = (new UuidFactory(UuidGenerationVersion::V4))->generate();

        self::assertInstanceOf(UuidInterface::class, $uuid);
        self::assertSame(4, Uuid::fromString($uuid->toString())->version);
    }

    public function testGeneratedRfcVersionsExposeVersionAndVariant(): void
    {
        $factory = new UuidFactory();

        foreach ([1 => $factory->v1(), 4 => $factory->v4(), 6 => $factory->v6(), 7 => $factory->v7()] as $version => $uuid) {
            $rfcUuid = Uuid::fromString($uuid->toString());

            self::assertSame($version, $rfcUuid->version);
            self::assertSame(UuidVariant::Rfc, $rfcUuid->variant);
        }
    }

    public function testNameBasedUuidsAreDeterministic(): void
    {
        $factory = new UuidFactory();

        $v3 = $factory->v3(UuidNamespace::Dns, 'example.com');
        $sameV3 = $factory->v3(UuidNamespace::Dns, 'example.com');
        $v5 = $factory->v5(UuidNamespace::Dns, 'example.com');
        $sameV5 = $factory->v5(UuidNamespace::Dns, 'example.com');

        self::assertTrue($v3->equals($sameV3));
        self::assertTrue($v5->equals($sameV5));
        self::assertSame(3, Uuid::fromString($v3->toString())->version);
        self::assertSame(5, Uuid::fromString($v5->toString())->version);
    }

    public function testFromBytesReturnsRfcUuid(): void
    {
        $uuid = (new UuidFactory())->fromBytes(UuidNamespace::Dns->getBytes());

        self::assertInstanceOf(RfcUuidInterface::class, $uuid);
        self::assertSame(UuidNamespace::Dns->value, $uuid->toString());
    }

    public function testV8AppliesVersionAndVariantToCustomFields(): void
    {
        $uuid = (new UuidFactory())->v8(
            "\x01\x02\x03\x04\x05\x06",
            0x0ABC,
            "\xFF\xEE\xDD\xCC\xBB\xAA\x99\x88",
        );

        $rfcUuid = Uuid::fromString($uuid->toString());

        self::assertSame(8, $rfcUuid->version);
        self::assertSame(UuidVariant::Rfc, $rfcUuid->variant);
    }

    public function testNameBasedVectorsPreserveExactInputOctets(): void
    {
        // Independent reference vectors generated with Python hashlib and uuid.
        // The custom namespace is deliberately not one of the built-in enums.
        $namespace = Uuid::fromString('c63c06d8-9c3e-4d1a-b002-2cb47a9e1375');
        $vectors = [
            ['', '1b2f9130-1500-3c17-9869-75192794a2bf', '251d6522-009e-5649-97cb-6fcb5306cb87'],
            ['example', '19f766d7-755e-3748-bb94-ecd1a6dbec58', '3b068c89-8402-5b04-91c3-9a0e9eb77f7d'],
            ['Example', 'c3368d1e-2631-34f3-b129-7154528143cd', '4640dcb7-f505-5ea4-b661-e9ba84945ced'],
            ["a\0b\xff", 'a5b568d7-fa57-3a84-97c5-0ef50773071e', 'f81b714c-8a7e-5ea0-8e26-fc37292e557d'],
            ["\xc3\xa9", 'c7ddd87a-a8ac-3735-9af9-c0737f4dbc43', '67c81f80-4482-54af-8e36-4deb5ef2d91d'],
            ["e\xcc\x81", '9eefe10b-4819-3948-b5e5-aefe56d1c492', '2e5de8ce-163a-580c-9676-4685cbf72607'],
        ];
        $factory = new UuidFactory();
        foreach ($vectors as [$name, $v3, $v5]) {
            self::assertSame($v3, $factory->v3($namespace, $name)->toString());
            self::assertSame($v5, $factory->v5($namespace, $name)->toString());
        }
    }

    public function testNamespaceIdentifiersMatchRfc9562Registry(): void
    {
        $vectors = [
            [UuidNamespace::Dns, '6ba7b810-9dad-11d1-80b4-00c04fd430c8'],
            [UuidNamespace::Url, '6ba7b811-9dad-11d1-80b4-00c04fd430c8'],
            [UuidNamespace::Oid, '6ba7b812-9dad-11d1-80b4-00c04fd430c8'],
            [UuidNamespace::X500, '6ba7b814-9dad-11d1-80b4-00c04fd430c8'],
        ];

        foreach ($vectors as [$namespace, $expected]) {
            self::assertSame($expected, $namespace->value);
            self::assertSame($expected, $namespace->toUuid()->toString());
            self::assertSame($expected, Uuid::fromBytes($namespace->getBytes())->toString());
        }
    }
}
