<?php

declare(strict_types=1);

namespace Componenta\Identity;

/**
 * Minimal contract for a UUID value object.
 *
 * Provides string representation and equality comparison -
 * the two operations sufficient for identity-related domain logic.
 *
 * For full RFC 9562 capabilities (version, variant, raw bytes)
 * see {@see RfcUuidInterface}.
 *
 * @see https://www.rfc-editor.org/rfc/rfc9562
 */
interface UuidInterface extends \Stringable
{
    /**
     * Returns the canonical lowercase string representation of the UUID.
     *
     * Format: xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
     * 36 characters: 32 hexadecimal digits and 4 hyphens, grouped as 8-4-4-4-12.
     */
    public function toString(): string;

    /**
     * Returns true if this UUID is equal to the given UUID.
     */
    public function equals(UuidInterface $other): bool;
}