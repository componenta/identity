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
}
