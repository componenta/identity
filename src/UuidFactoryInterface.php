<?php

declare(strict_types=1);

namespace Componenta\Identity;

/**
 * Creates and generates UUID instances.
 *
 * Consumers that do not require a specific UUID version should depend on this
 * interface rather than {@see UuidFactory}.
 */
interface UuidFactoryInterface
{
    /**
     * Generates a new UUID.
     *
     * The UUID version is determined by the implementation.
     */
    public function generate(): UuidInterface;

    /**
     * Creates a UUID from raw 16-octet binary representation.
     *
     * The given string MUST be exactly 16 bytes long.
     */
    public function fromBytes(string $bytes): RfcUuidInterface;
}
