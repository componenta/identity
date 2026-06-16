<?php

declare(strict_types=1);

namespace Componenta\Identity;

/**
 * Full RFC 9562 UUID contract.
 *
 * Extends {@see UuidInterface} with low-level fields defined by the spec:
 * raw bytes, version, and variant. Intended for validation, parsing,
 * and infrastructure code that needs to inspect UUID structure.
 *
 * Domain code should depend on {@see UuidInterface} instead.
 *
 * Requires PHP 8.4+ because interface properties are used.
 *
 * @see https://www.rfc-editor.org/rfc/rfc9562
 */
interface RfcUuidInterface extends UuidInterface
{
    /**
     * Raw 16-octet binary representation of the UUID.
     *
     * MUST be exactly 16 bytes long.
     */
    public string $bytes { get; }

    /**
     * UUID version number.
     *
     * The version is the 4-bit value stored in the UUID version field.
     *
     * For RFC 9562 UUIDs, known versions are:
     * 1 - time-based,
     * 2 - DCE security,
     * 3 - name-based MD5,
     * 4 - random,
     * 5 - name-based SHA-1,
     * 6 - reordered time,
     * 7 - Unix timestamp,
     * 8 - custom.
     */
    public int $version { get; }

    /**
     * UUID variant category.
     */
    public UuidVariant $variant { get; }
}
