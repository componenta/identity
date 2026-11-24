<?php

declare(strict_types=1);

namespace Componenta\Identity;

/**
 * UUID factory implementing UUID versions v1, v3, v4, v5, v6, v7 and v8.
 *
 * UUIDv2 is intentionally not implemented because RFC 9562 treats it as
 * DCE Security UUIDs and leaves its definition outside the scope of the RFC.
 *
 * Implements {@see UuidFactoryInterface}. The default generation strategy is
 * configurable via {@see UuidGenerationVersion}; UUIDv7 is used by default.
 *
 * Thread safety: UUIDv7, UUIDv1 and UUIDv6 maintain in-process monotonic
 * state. The factory instance should not be shared across truly concurrent
 * execution contexts without external synchronization.
 *
 * Cross-process uniqueness is provided with high probability by random bits
 * and by the generator-specific random node / random payload fields.
 *
 * Requires 64-bit PHP.
 *
 * @see https://www.rfc-editor.org/rfc/rfc9562
 */
final class UuidFactory implements UuidFactoryInterface
{
    /**
     * Gregorian epoch offset to Unix epoch in 100-nanosecond intervals.
     * From 15 October 1582 to 1 January 1970.
     */
    private const int GREGORIAN_TO_UNIX_OFFSET = 0x01B21DD213814000;

    /**
     * Maximum value of the UUIDv7 12-bit seq/rand_a field.
     */
    private const int V7_SEQUENCE_MAX = 0x0FFF;

    /**
     * Maximum random seed used for the lower 8 bits of UUIDv7 seq/rand_a.
     *
     * This gives cross-process spread while leaving at least 3840 counter
     * values available in the same millisecond.
     */
    private const int V7_SEQUENCE_SEED_MAX = 0x00FF;

    /**
     * Maximum values of the RFC timestamp fields.
     */
    private const int V7_TIMESTAMP_MAX = 0xFFFFFFFFFFFF;
    private const int GREGORIAN_TIMESTAMP_MAX = 0x0FFFFFFFFFFFFFFF;

    /**
     * Last Gregorian timestamp used by v1/v6 generators.
     *
     * Stored in 100-nanosecond intervals since 15 October 1582 UTC.
     */
    private ?int $lastGregorianTicks = null;

    /**
     * Stable random node ID for v1/v6.
     *
     * Generated lazily with the multicast bit set to avoid exposing MAC
     * addresses.
     */
    private ?string $node = null;

    /**
     * Stable random 14-bit clock sequence for v1/v6.
     */
    private ?int $clockSeq = null;

    /**
     * Last timestamp seen by v7 generator, in milliseconds.
     */
    private ?int $v7LastMs = null;

    /**
     * Monotonic counter for v7 sequencing within the same millisecond.
     *
     * Stored in the 12-bit rand_a field.
     */
    private int $v7Seq = 0;

    /** Process that owns the cached v1/v6 node and clock sequence. */
    private ?int $processId = null;

    /**
     * @throws \RuntimeException If PHP is not running on a 64-bit platform.
     */
    public function __construct(
        private readonly UuidGenerationVersion $defaultVersion = UuidGenerationVersion::V7,
    ) {
        if (PHP_INT_SIZE < 8) {
            throw new \RuntimeException('UuidFactory requires 64-bit PHP.');
        }
    }

    /** A copied generator must not reuse the original node/clock sequence. */
    public function __clone(): void
    {
        $this->processId = null;
        $this->node = null;
        $this->clockSeq = null;
    }

    /** @return array{defaultVersion: UuidGenerationVersion} */
    public function __serialize(): array
    {
        // A restored factory is a new generator, not a replay of live state.
        return ['defaultVersion' => $this->defaultVersion];
    }

    /** @param array<array-key, mixed> $data */
    public function __unserialize(array $data): void
    {
        $version = $data['defaultVersion']
            ?? $data["\0" . self::class . "\0defaultVersion"]
            ?? null;

        if (!$version instanceof UuidGenerationVersion) {
            throw new \InvalidArgumentException('Invalid serialized UUID factory version.');
        }

        $this->__construct($version);
    }

    /**
     * Generates a new UUID using the configured default version.
     */
    public function generate(): UuidInterface
    {
        return match ($this->defaultVersion) {
            UuidGenerationVersion::V1 => $this->v1(),
            UuidGenerationVersion::V4 => $this->v4(),
            UuidGenerationVersion::V6 => $this->v6(),
            UuidGenerationVersion::V7 => $this->v7(),
        };
    }

    /**
     * Creates a UUID from raw 16-octet binary representation.
     */
    public function fromBytes(string $bytes): RfcUuidInterface
    {
        return Uuid::fromBytes($bytes);
    }

    /**
     * Generates a UUIDv1, Gregorian time-based.
     *
     * Uses a random node ID with the multicast bit set to avoid exposing
     * hardware MAC addresses.
     *
     * Layout:
     * time_low (32) | time_mid (16) | version (4) | time_high (12)
     * | variant (2) | clock_seq (14) | node (48)
     *
     * @see https://www.rfc-editor.org/rfc/rfc9562#section-5.1
     */
    public function v1(): UuidInterface
    {
        $this->ensureProcessState();
        $ticks = $this->monotonicGregorianTicks();

        $timeLow  = $ticks & 0xFFFFFFFF;
        $timeMid  = ($ticks >> 32) & 0xFFFF;
        $timeHigh = ($ticks >> 48) & 0x0FFF;

        $clockSeq = $this->clockSeq();
        $node     = $this->node();

        $bytes = pack(
            'NnnCa*',
            $timeLow,
            $timeMid,
            0x1000 | $timeHigh,
            0x80 | (($clockSeq >> 8) & 0x3F),
            chr($clockSeq & 0xFF) . $node,
        );

        return Uuid::fromBytes($bytes);
    }

    /**
     * Generates a UUIDv3, name-based MD5.
     *
     * Deterministic: the same namespace and name produce the same UUID.
     * Prefer {@see self::v5()} unless MD5 compatibility is required.
     *
     * The name is used as-is. Callers are responsible for providing the
     * canonical byte representation required by their namespace.
     *
     * @param UuidNamespace|UuidInterface $namespace Namespace UUID.
     * @param string                      $name      Name to hash.
     *
     * @see https://www.rfc-editor.org/rfc/rfc9562#section-5.3
     */
    public function v3(UuidNamespace|UuidInterface $namespace, string $name): UuidInterface
    {
        $hash = md5($this->namespaceBytes($namespace) . $name, binary: true);

        return Uuid::fromBytes($this->applyVersionAndVariant($hash, 0x30));
    }

    /**
     * Generates a UUIDv4, random.
     *
     * Uses 122 bits of cryptographically secure random data.
     *
     * @see https://www.rfc-editor.org/rfc/rfc9562#section-5.4
     */
    public function v4(): UuidInterface
    {
        return Uuid::fromBytes(
            $this->applyVersionAndVariant(random_bytes(16), 0x40),
        );
    }

    /**
     * Generates a UUIDv5, name-based SHA-1.
     *
     * Deterministic: the same namespace and name produce the same UUID.
     *
     * The name is used as-is. Callers are responsible for providing the
     * canonical byte representation required by their namespace.
     *
     * @param UuidNamespace|UuidInterface $namespace Namespace UUID.
     * @param string                      $name      Name to hash.
     *
     * @see https://www.rfc-editor.org/rfc/rfc9562#section-5.5
     */
    public function v5(UuidNamespace|UuidInterface $namespace, string $name): UuidInterface
    {
        $hash = substr(
            sha1($this->namespaceBytes($namespace) . $name, binary: true),
            0,
            16,
        );

        return Uuid::fromBytes($this->applyVersionAndVariant($hash, 0x50));
    }

    /**
     * Generates a UUIDv6, reordered Gregorian time-based.
     *
     * Field-compatible with UUIDv1, but reordered for improved database locality.
     * Use {@see self::v7()} for new systems not requiring UUIDv1 compatibility.
     *
     * Layout:
     * time_high (48) | version (4) | time_low (12)
     * | variant (2) | clock_seq (14) | node (48)
     *
     * @see https://www.rfc-editor.org/rfc/rfc9562#section-5.6
     */
    public function v6(): UuidInterface
    {
        $this->ensureProcessState();
        $ticks = $this->monotonicGregorianTicks();

        $timeHigh = ($ticks >> 12) & 0xFFFFFFFFFFFF;
        $timeLow  = $ticks & 0x0FFF;

        $clockSeq = $this->clockSeq();
        $node     = $this->node();

        $bytes = $this->packUint48($timeHigh)
            . pack('n', 0x6000 | $timeLow)
            . chr(0x80 | (($clockSeq >> 8) & 0x3F))
            . chr($clockSeq & 0xFF)
            . $node;

        return Uuid::fromBytes($bytes);
    }

    /**
     * Generates a UUIDv7, Unix timestamp-based.
     *
     * Recommended for new systems. Provides millisecond-precision timestamps
     * with a dedicated 12-bit counter (RFC 9562 section 6.2, method 1).
     * Each new real or synthetic millisecond starts with an 8-bit random
     * counter seed. Every UUID independently obtains 62 random rand_b bits.
     *
     * Layout:
     * unix_ts_ms (48) | version (4) | seq/rand_a (12)
     * | variant (2) | rand_b (62)
     *
     * @see https://www.rfc-editor.org/rfc/rfc9562#section-5.7
     */
    public function v7(): UuidInterface
    {
        $currentMs = $this->currentMs();
        $lastMs = $this->v7LastMs;

        if ($lastMs === null || $currentMs > $lastMs) {
            $ms = $currentMs;
            $seq = random_int(0, self::V7_SEQUENCE_SEED_MAX);
        } elseif ($this->v7Seq === self::V7_SEQUENCE_MAX) {
            if ($lastMs === self::V7_TIMESTAMP_MAX) {
                throw new \OverflowException('UUIDv7 timestamp exhausted.');
            }
            $ms = $lastMs + 1;
            $seq = random_int(0, self::V7_SEQUENCE_SEED_MAX);
        } else {
            $ms = $lastMs;
            $seq = $this->v7Seq + 1;
        }

        // A copied/forked counter is not a copied random stream. Never
        // generate predictable tails or publish state after failed entropy.
        $randB = random_bytes(8);
        $randB[0] = chr((ord($randB[0]) & 0x3F) | 0x80);
        $uuid = Uuid::fromBytes($this->packUint48($ms)
            . pack('n', 0x7000 | $seq)
            . $randB);

        $this->v7LastMs = $ms;
        $this->v7Seq = $seq;

        return $uuid;
    }

    /**
     * Generates a UUIDv8, custom format.
     *
     * Accepts custom data distributed across the three RFC 9562 custom fields.
     * The version and variant bits are set automatically.
     *
     * @param string $customA 48 bits, 6 bytes, for bits 0-47.
     * @param int    $customB 12 bits for bits 52-63.
     * @param string $customC 62 bits, 8 bytes with top 2 bits ignored, for bits 66-127.
     *
     * @throws \InvalidArgumentException If the field sizes are incorrect.
     *
     * @see https://www.rfc-editor.org/rfc/rfc9562#section-5.8
     */
    public function v8(string $customA, int $customB, string $customC): UuidInterface
    {
        if (strlen($customA) !== 6) {
            throw new \InvalidArgumentException('customA must be exactly 6 bytes.');
        }

        if ($customB < 0 || $customB > 0x0FFF) {
            throw new \InvalidArgumentException('customB must fit in 12 bits.');
        }

        if (strlen($customC) !== 8) {
            throw new \InvalidArgumentException('customC must be exactly 8 bytes.');
        }

        $customC[0] = chr((ord($customC[0]) & 0x3F) | 0x80);

        $bytes = $customA
            . pack('n', 0x8000 | $customB)
            . $customC;

        return Uuid::fromBytes($bytes);
    }

    /**
     * Returns the current Unix time in milliseconds.
     */
    private function currentMs(): int
    {
        $time = gettimeofday();

        $seconds = $time['sec'];
        if ($seconds < 0 || $seconds > intdiv(self::V7_TIMESTAMP_MAX, 1000)) {
            throw new \OverflowException('Unix time does not fit the UUIDv7 timestamp.');
        }

        $ms = ($seconds * 1000) + intdiv($time['usec'], 1000);
        if ($ms > self::V7_TIMESTAMP_MAX) {
            throw new \OverflowException('Unix time does not fit the UUIDv7 timestamp.');
        }

        return $ms;
    }

    /**
     * Returns a 60-bit Gregorian timestamp:
     * 100-nanosecond intervals since 15 October 1582 UTC.
     */
    private function gregorianTicks(): int
    {
        $time = gettimeofday();

        $seconds = $time['sec'];
        if ($seconds < -intdiv(self::GREGORIAN_TO_UNIX_OFFSET, 10_000_000)
            || $seconds > intdiv(self::GREGORIAN_TIMESTAMP_MAX - self::GREGORIAN_TO_UNIX_OFFSET, 10_000_000)) {
            throw new \OverflowException('Gregorian time does not fit the UUID timestamp.');
        }

        $ticks = ($seconds * 10_000_000)
            + ($time['usec'] * 10)
            + self::GREGORIAN_TO_UNIX_OFFSET;
        if ($ticks > self::GREGORIAN_TIMESTAMP_MAX) {
            throw new \OverflowException('Gregorian time does not fit the UUID timestamp.');
        }

        return $ticks;
    }

    /**
     * Returns a strictly monotonic Gregorian timestamp for v1/v6.
     */
    private function monotonicGregorianTicks(): int
    {
        $ticks = $this->gregorianTicks();

        if ($this->lastGregorianTicks !== null && $ticks <= $this->lastGregorianTicks) {
            if ($this->lastGregorianTicks === self::GREGORIAN_TIMESTAMP_MAX) {
                throw new \OverflowException('Gregorian UUID timestamp exhausted.');
            }
            $ticks = $this->lastGregorianTicks + 1;
        }

        $this->lastGregorianTicks = $ticks;

        return $ticks;
    }

    /**
     * Returns the stable random node ID used by v1/v6.
     */
    private function node(): string
    {
        if ($this->node === null) {
            $node = random_bytes(6);
            $node[0] = chr(ord($node[0]) | 0x01);

            $this->node = $node;
        }

        return $this->node;
    }

    /**
     * Returns the stable random 14-bit clock sequence used by v1/v6.
     */
    private function clockSeq(): int
    {
        return $this->clockSeq ??= random_int(0, 0x3FFF);
    }

    /** Do not share cached node/sequence state across process forks. */
    private function ensureProcessState(): void
    {
        $pid = getmypid();
        if ($pid === false) {
            throw new \RuntimeException('Unable to determine UUID generator process.');
        }

        if ($this->processId !== $pid) {
            $this->node = null;
            $this->clockSeq = null;
            $this->processId = $pid;
        }
    }

    /**
     * Packs a 48-bit unsigned integer as a 6-byte big-endian string.
     */
    private function packUint48(int $value): string
    {
        if ($value < 0 || $value > 0xFFFFFFFFFFFF) {
            throw new \InvalidArgumentException('Value must fit in 48 bits.');
        }

        return pack(
            'Nn',
            ($value >> 16) & 0xFFFFFFFF,
            $value & 0xFFFF,
        );
    }

    /**
     * Applies RFC 9562 version nibble and variant bits to a 16-byte string.
     *
     * @param string $bytes   16 raw bytes.
     * @param int    $version Version nibble shifted left by 4, e.g. 0x40 for v4.
     *
     * @throws \InvalidArgumentException If bytes length is not 16.
     * @throws \InvalidArgumentException If version nibble is invalid.
     */
    private function applyVersionAndVariant(string $bytes, int $version): string
    {
        if (strlen($bytes) !== 16) {
            throw new \InvalidArgumentException('UUID bytes must be exactly 16 bytes.');
        }

        if (($version & 0x0F) !== 0 || $version < 0x10 || $version > 0x80) {
            throw new \InvalidArgumentException(
                'Version must be a UUID version nibble, e.g. 0x40 for v4.',
            );
        }

        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | $version);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return $bytes;
    }

    /**
     * Returns the 16-byte binary representation of a namespace UUID.
     */
    private function namespaceBytes(UuidNamespace|UuidInterface $namespace): string
    {
        if ($namespace instanceof UuidNamespace) {
            return $namespace->getBytes();
        }

        return Uuid::fromString($namespace->toString())->bytes;
    }
}
