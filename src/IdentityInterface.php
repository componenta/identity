<?php

declare(strict_types=1);

namespace Componenta\Identity;

/**
 * Represents an entity with a stable UUID-based identity.
 *
 * UUID is the sole public identifier of the entity,
 * guaranteed to be present regardless of persistence state.
 */
interface IdentityInterface
{
    /**
     * The unique identifier of this entity.
     */
    public UuidInterface $uuid { get; }
}