<?php

declare(strict_types=1);

namespace Componenta\Identity;

/**
 * UUID variant category.
 *
 * The variant describes how the UUID bit layout should be interpreted.
 *
 * @see https://www.rfc-editor.org/rfc/rfc9562
 */
enum UuidVariant: int
{
    /**
     * Reserved, NCS backward compatibility.
     *
     * Bit pattern: 0xxx
     */
    case Ncs = 0;

    /**
     * RFC 4122 / RFC 9562 variant.
     *
     * Bit pattern: 10xx
     */
    case Rfc = 1;

    /**
     * Reserved, Microsoft backward compatibility.
     *
     * Bit pattern: 110x
     */
    case Microsoft = 2;

    /**
     * Reserved for future definition.
     *
     * Bit pattern: 111x
     */
    case Future = 3;
}
