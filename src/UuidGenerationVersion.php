<?php

declare(strict_types=1);

namespace Componenta\Identity;

/**
 * UUID versions that can be generated without additional input.
 *
 * Name-based UUIDs v3/v5 require namespace + name.
 * UUIDv8 requires custom fields.
 */
enum UuidGenerationVersion: int
{
    case V1 = 1;
    case V4 = 4;
    case V6 = 6;
    case V7 = 7;
}
