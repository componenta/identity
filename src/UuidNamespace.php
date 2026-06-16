<?php

declare(strict_types=1);

namespace Componenta\Identity;

/**
 * Standard namespace UUIDs for name-based UUIDv3 and UUIDv5.
 *
 * @see https://www.rfc-editor.org/rfc/rfc9562
 */
enum UuidNamespace: string
{
    case Dns  = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';
    case Url  = '6ba7b811-9dad-11d1-80b4-00c04fd430c8';
    case Oid  = '6ba7b812-9dad-11d1-80b4-00c04fd430c8';
    case X500 = '6ba7b814-9dad-11d1-80b4-00c04fd430c8';

    public function toUuid(): Uuid
    {
        return Uuid::fromString($this->value);
    }

    public function getBytes(): string
    {
        return $this->toUuid()->bytes;
    }
}
