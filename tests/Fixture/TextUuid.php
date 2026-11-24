<?php

declare(strict_types=1);

namespace Componenta\Identity\Tests\Fixture;

use Componenta\Identity\UuidInterface;

/** A consumer implementation that only supports the original minimal contract. */
final readonly class TextUuid implements UuidInterface
{
    public function __construct(private string $value) {}

    public function __toString(): string
    {
        return $this->value;
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(UuidInterface $other): bool
    {
        return strcasecmp($this->value, $other->toString()) === 0;
    }
}
