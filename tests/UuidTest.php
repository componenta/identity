<?php

declare(strict_types=1);

namespace Componenta\Identity\Tests;

use Componenta\Identity\RfcUuidInterface;
use Componenta\Identity\Uuid;
use Componenta\Identity\UuidVariant;
use PHPUnit\Framework\TestCase;

final class UuidTest extends TestCase
{
    public function testParsesCanonicalUuidAndExposesRfcFields(): void
    {
        $uuid = Uuid::fromString('018f6d5d-3f7a-7a9b-8c2f-123456789abc');

        self::assertInstanceOf(RfcUuidInterface::class, $uuid);
        self::assertSame('018f6d5d-3f7a-7a9b-8c2f-123456789abc', $uuid->toString());
        self::assertSame(16, strlen($uuid->bytes));
        self::assertSame(7, $uuid->version);
        self::assertSame(UuidVariant::Rfc, $uuid->variant);
    }

    public function testNormalizesUppercaseInput(): void
    {
        $uuid = Uuid::fromString('018F6D5D-3F7A-7A9B-8C2F-123456789ABC');

        self::assertSame('018f6d5d-3f7a-7a9b-8c2f-123456789abc', (string) $uuid);
        self::assertSame('"018f6d5d-3f7a-7a9b-8c2f-123456789abc"', json_encode($uuid));
    }

    public function testEqualsComparesUuidValues(): void
    {
        $uuid = Uuid::fromString('018f6d5d-3f7a-7a9b-8c2f-123456789abc');
        $same = Uuid::fromBytes($uuid->bytes);
        $other = Uuid::fromString('018f6d5d-3f7a-7a9b-8c2f-123456789abd');

        self::assertTrue($uuid->equals($same));
        self::assertFalse($uuid->equals($other));
    }

    public function testRejectsInvalidString(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Uuid::fromString('not-a-uuid');
    }

    public function testRejectsInvalidBytesLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Uuid::fromBytes('short');
    }
}
