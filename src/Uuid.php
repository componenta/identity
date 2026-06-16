<?php

declare(strict_types=1);

namespace Componenta\Identity;

/**
 * Immutable UUID value object.
 *
 * Stores UUID internally as raw 16-byte binary data and exposes canonical
 * lowercase RFC 9562 string representation.
 *
 * Requires PHP 8.4+ because it implements interface properties.
 *
 * @see https://www.rfc-editor.org/rfc/rfc9562
 */
final class Uuid implements RfcUuidInterface, \JsonSerializable
{
    /**
     * Canonical UUID textual form:
     * xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
     */
    private const string PATTERN = '/\A[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\z/';

    /**
     * Raw 16-byte UUID representation.
     */
    public readonly string $bytes;

    /**
     * UUID version number.
     */
    public readonly int $version;

    /**
     * UUID variant category.
     */
    public readonly UuidVariant $variant;

    private function __construct(string $bytes)
    {
        if (strlen($bytes) !== 16) {
            throw new \InvalidArgumentException('UUID bytes must be exactly 16 bytes.');
        }

        $this->bytes = $bytes;
        $this->version = (ord($bytes[6]) >> 4) & 0x0F;
        $this->variant = self::detectVariant($bytes);
    }

    /**
     * Creates a UUID from canonical textual representation.
     *
     * Accepted format:
     * xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
     *
     * Hexadecimal digits may be uppercase or lowercase.
     */
    public static function fromString(string $uuid): self
    {
        $uuid = trim($uuid);

        if (!preg_match(self::PATTERN, $uuid)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid UUID string: "%s".',
                $uuid,
            ));
        }

        $bytes = hex2bin(str_replace('-', '', $uuid));

        if ($bytes === false) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid UUID hexadecimal payload: "%s".',
                $uuid,
            ));
        }

        return new self($bytes);
    }

    /**
     * Creates a UUID from raw 16-byte binary representation.
     */
    public static function fromBytes(string $bytes): self
    {
        return new self($bytes);
    }

    /**
     * Returns the canonical lowercase string representation of the UUID.
     */
    public function toString(): string
    {
        $hex = bin2hex($this->bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    public function jsonSerialize(): string
    {
        return $this->toString();
    }

    public function equals(UuidInterface $other): bool
    {
        if ($other instanceof RfcUuidInterface) {
            return hash_equals($this->bytes, $other->bytes);
        }

        return hash_equals($this->toString(), strtolower($other->toString()));
    }

    private static function detectVariant(string $bytes): UuidVariant
    {
        $octet = ord($bytes[8]);

        if (($octet & 0x80) === 0x00) {
            return UuidVariant::Ncs;
        }

        if (($octet & 0xC0) === 0x80) {
            return UuidVariant::Rfc;
        }

        if (($octet & 0xE0) === 0xC0) {
            return UuidVariant::Microsoft;
        }

        return UuidVariant::Future;
    }
}
