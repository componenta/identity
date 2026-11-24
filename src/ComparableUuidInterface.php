<?php

declare(strict_types=1);

namespace Componenta\Identity;

/** A UUID supporting an optional total ordering by its 128-bit value. */
interface ComparableUuidInterface extends UuidInterface
{
    /**
     * Compares this UUID with another UUID in unsigned network-byte order.
     *
     * The result is independent of UUID version and variant. Equal means
     * the same UUID value, not object identity. The operand must satisfy
     * UuidInterface; chronological ordering is not implied.
     */
    public function compareTo(UuidInterface $other): ComparisonResult;
}
