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


    public function testNativeSerializationPreservesValidIdentity(): void
    {
        $uuid = Uuid::fromString('018f6d5d-3f7a-7a9b-8c2f-123456789abc');
        $restored = unserialize(serialize($uuid));

        self::assertInstanceOf(Uuid::class, $restored);
        self::assertTrue($uuid->equals($restored));
        self::assertSame($uuid->bytes, $restored->bytes);
        self::assertSame($uuid->version, $restored->version);
        self::assertSame($uuid->variant, $restored->variant);
    }

    public function testRejectsInconsistentSerializedVersion(): void
    {
        $uuid = Uuid::fromString('018f6d5d-3f7a-7a9b-8c2f-123456789abc');
        $payload = str_replace('s:7:"version";i:7;', 's:7:"version";i:4;', serialize($uuid));

        self::assertNotSame(serialize($uuid), $payload);
        $this->expectException(\InvalidArgumentException::class);
        unserialize($payload);
    }

    public function testRejectsInvalidSerializedBytesLength(): void
    {
        $uuid = Uuid::fromString('018f6d5d-3f7a-7a9b-8c2f-123456789abc');
        $payload = str_replace(serialize($uuid->bytes), serialize('short'), serialize($uuid));

        self::assertNotSame(serialize($uuid), $payload);
        $this->expectException(\InvalidArgumentException::class);
        unserialize($payload);
    }

    public function testRejectsInconsistentSerializedVariant(): void
    {
        $uuid = Uuid::fromString('018f6d5d-3f7a-7a9b-8c2f-123456789abc');
        $payload = str_replace(serialize(UuidVariant::Rfc), serialize(UuidVariant::Ncs), serialize($uuid));

        self::assertNotSame(serialize($uuid), $payload);
        $this->expectException(\InvalidArgumentException::class);
        unserialize($payload);
    }

    public function testRejectsIncompleteSerializedUuid(): void
    {
        $class = Uuid::class;
        $payload = 'O:' . strlen($class) . ':"' . $class . '":0:{}';

        $this->expectException(\InvalidArgumentException::class);
        unserialize($payload);
    }

    public function testAcceptsNilMaxAndReservedVariantBitPatterns(): void
    {
        // A UUID value object parses all 128-bit patterns, not just generated versions.
        $vectors = [
            ['00000000-0000-0000-0000-000000000000', UuidVariant::Ncs, 0],
            ['ffffffff-ffff-ffff-ffff-ffffffffffff', UuidVariant::Future, 15],
            ['00000000-0000-7000-c000-000000000000', UuidVariant::Microsoft, 7],
            ['00000000-0000-f000-b000-000000000000', UuidVariant::Rfc, 15],
        ];

        foreach ($vectors as [$text, $variant, $version]) {
            $uuid = Uuid::fromString($text);
            self::assertSame($text, $uuid->toString());
            self::assertSame($variant, $uuid->variant);
            self::assertSame($version, $uuid->version);
            self::assertSame($text, Uuid::fromBytes($uuid->bytes)->toString());
        }
    }

    public function testRejectsNonCanonicalUuidLayoutsAndWrongBinaryLengths(): void
    {
        foreach (['018f6d5d3f7a7a9b8c2f123456789abc', '{018f6d5d-3f7a-7a9b-8c2f-123456789abc}', 'urn:uuid:018f6d5d-3f7a-7a9b-8c2f-123456789abc', '018f6d5d-3f7a-7a9b-8c2f-123456789abg'] as $text) {
            try {
                Uuid::fromString($text);
                self::fail('Invalid UUID layout accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        foreach ([0, 15, 17, 32] as $length) {
            try {
                Uuid::fromBytes(str_repeat("\0", $length));
                self::fail('Invalid byte length accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }


    public function testMalformedInputIsNotReflectedInTheExceptionMessage(): void
    {
        $payload = "confidential-value\r\n" . str_repeat('x', 100000);

        try {
            Uuid::fromString($payload);
            self::fail('Invalid UUID accepted.');
        } catch (\InvalidArgumentException $exception) {
            self::assertLessThan(128, strlen($exception->getMessage()));
            self::assertStringNotContainsString('confidential-value', $exception->getMessage());
            self::assertStringNotContainsString("\r", $exception->getMessage());
            self::assertStringNotContainsString("\n", $exception->getMessage());
        }
    }

    public function testCanonicalizesMixedCaseAndPreservesExistingTrimBehavior(): void
    {
        $uuid = Uuid::fromString(" \t018F6d5D-3f7A-7a9B-8c2F-123456789aBc\r\n");

        self::assertSame('018f6d5d-3f7a-7a9b-8c2f-123456789abc', $uuid->toString());
    }


    public function testNativeSerializationRetainsTheLegacyWireFormat(): void
    {
        $uuid = Uuid::fromString('018f6d5d-3f7a-7a9b-8c2f-123456789abc');
        $legacy = 'O:' . strlen(Uuid::class) . ':"' . Uuid::class . '":3:{'
            . serialize('bytes') . serialize($uuid->bytes)
            . serialize('version') . serialize(7)
            . serialize('variant') . serialize(UuidVariant::Rfc) . '}';

        self::assertSame($legacy, serialize($uuid));
        $restored = unserialize($legacy);
        self::assertInstanceOf(Uuid::class, $restored);
        self::assertTrue($uuid->equals($restored));
    }

    public function testRestoredUuidCannotBeMutatedThroughReferencesInTheSerializedGraph(): void
    {
        $uuid = Uuid::fromString('018f6d5d-3f7a-7a9b-8c2f-123456789abc');
        $payload = 'a:4:{i:0;' . serialize($uuid) . 'i:1;R:3;i:2;R:4;i:3;R:5;}';
        $graph = unserialize($payload);

        self::assertInstanceOf(Uuid::class, $graph[0]);
        self::assertSame($uuid->bytes, $graph[1]);
        self::assertSame($uuid->version, $graph[2]);
        self::assertSame($uuid->variant, $graph[3]);

        // PHP native hydration can otherwise retain these three aliases even
        // when the properties themselves are readonly and __wakeup validated.
        $graph[1] = 'short';
        $graph[2] = 4;
        $graph[3] = UuidVariant::Ncs;

        self::assertSame($uuid->bytes, $graph[0]->bytes);
        self::assertSame(7, $graph[0]->version);
        self::assertSame(UuidVariant::Rfc, $graph[0]->variant);
        self::assertTrue($uuid->equals($graph[0]));
    }

    public function testEveryVariantOctetAndVersionNibbleIsPreserved(): void
    {
        // Exhaust all 256 variant octets and all 16 raw version nibbles.
        // The variant is a prefix, not a numeric range of whole UUIDs.
        for ($octet = 0; $octet < 256; ++$octet) {
            $expected = match (true) {
                $octet < 128 => UuidVariant::Ncs,
                $octet < 192 => UuidVariant::Rfc,
                $octet < 224 => UuidVariant::Microsoft,
                default => UuidVariant::Future,
            };
            for ($nibble = 0; $nibble < 16; ++$nibble) {
                $bytes = "\x11\x22\x33\x44\x55\x66" . chr(($nibble << 4) | 0x0a)
                    . "\xbb" . chr($octet) . "\x99\x88\x77\x66\x55\x44\x33";
                $uuid = Uuid::fromBytes($bytes);

                self::assertSame($nibble, $uuid->version);
                self::assertSame($expected, $uuid->variant);
                self::assertSame($bytes, Uuid::fromString($uuid->toString())->bytes);
            }
        }
    }

    public function testMinimalUuidContractCanParticipateInValueEquality(): void
    {
        $uuid = Uuid::fromString('018f6d5d-3f7a-7a9b-8c2f-123456789abc');
        $other = new readonly class ($uuid->toString()) implements \Componenta\Identity\UuidInterface {
            public function __construct(private string $text) {}
            public function __toString(): string { return $this->text; }
            public function toString(): string { return $this->text; }
            public function equals(\Componenta\Identity\UuidInterface $other): bool
            {
                return $this->text === $other->toString();
            }
        };

        self::assertTrue($uuid->equals($other));
        self::assertTrue($other->equals($uuid));
        self::assertFalse(Uuid::fromString('018f6d5d-3f7a-7a9b-8c2f-123456789abd')->equals($other));
    }
}
