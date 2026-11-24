<?php

declare(strict_types=1);

// Run in a standalone PHP process; never include these clock fixtures in an
// application or in the normal PHPUnit process.
namespace Componenta\Identity {
    function gettimeofday(): array
    {
        return $GLOBALS['identityTestTime'] ?? \gettimeofday();
    }

    function random_int(int $min, int $max): int
    {
        $fixture = $GLOBALS['identityTestInt'] ?? null;
        if ($fixture instanceof \Throwable) {
            throw $fixture;
        }
        if ($fixture !== null) {
            if ($fixture < $min || $fixture > $max) {
                throw new \RuntimeException('Entropy fixture outside requested range.');
            }
            return $fixture;
        }
        return \random_int($min, $max);
    }

    function random_bytes(int $length): string
    {
        $fixture = $GLOBALS['identityTestBytes'] ?? null;
        if ($fixture instanceof \Throwable) {
            throw $fixture;
        }
        if ($fixture !== null) {
            if (strlen($fixture) !== $length) {
                throw new \RuntimeException('Unexpected entropy fixture length.');
            }
            return $fixture;
        }
        return \random_bytes($length);
    }
}

namespace {
    use Componenta\Identity\Uuid;
    use Componenta\Identity\UuidFactory;
    use Componenta\Identity\UuidGenerationVersion;
    use Componenta\Identity\UuidNamespace;

    require dirname(__DIR__) . '/vendor/autoload.php';

    function verify(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    function rejects(callable $call, string $class): void
    {
        try {
            $call();
        } catch (Throwable $failure) {
            verify($failure instanceof $class, 'Unexpected exception: ' . $failure::class);
            return;
        }
        throw new RuntimeException('Expected ' . $class);
    }

    function frozen(int $seconds, int $microseconds = 0): void
    {
        $GLOBALS['identityTestTime'] = ['sec' => $seconds, 'usec' => $microseconds];
    }

    function unixMs(string $uuid): int
    {
        return (int) hexdec(substr(str_replace('-', '', $uuid), 0, 12));
    }

    frozen(1645557742);
    $GLOBALS['identityTestInt'] = 0x33C8;
    $GLOBALS['identityTestBytes'] = hex2bin('9e6bdeced846');
    verify((string) (new UuidFactory())->v1() === 'c232ab00-9414-11ec-b3c8-9f6bdeced846', 'RFC A.1');
    unset($GLOBALS['identityTestInt']);
    $GLOBALS['identityTestBytes'] = hex2bin('33c89e6bdeced846');
    verify((string) (new UuidFactory())->v6() === '1ec9414c-232a-6b00-b3c8-9f6bdeced846', 'RFC A.5');
    $GLOBALS['identityTestBytes'] = hex2bin('919108f752d133205bacf847db4148a8');
    verify((string) (new UuidFactory())->v4() === '919108f7-52d1-4320-9bac-f847db4148a8', 'RFC A.3');
    $factory = new UuidFactory();
    verify((string) $factory->v3(UuidNamespace::Dns, 'www.example.com') === '5df41881-3aed-3515-88a7-2f4a814cf09e', 'RFC A.2');
    verify((string) $factory->v5(UuidNamespace::Dns, 'www.example.com') === '2ed6657d-e927-568b-95e1-2665a8aea6a2', 'RFC A.4');
    verify((string) $factory->v8(hex2bin('2489e9ad2ee2'), 0xe00, hex2bin('0ec932d5f69181c0')) === '2489e9ad-2ee2-8e00-8ec9-32d5f69181c0', 'RFC B.1');

    // Physical rollback is different from synthetic increments at one tick.
    // A known v1 sequence advances on rollback, including 14-bit wrap.
    frozen(1645557742);
    $GLOBALS['identityTestInt'] = 0x3fff;
    $GLOBALS['identityTestBytes'] = hex2bin('9e6bdeced846');
    $factory = new UuidFactory();
    $first = $factory->v1();
    $sameTick = $factory->v1();
    frozen(1645557741);
    $rolledBack = $factory->v1();
    $repeatedRollbackTick = $factory->v1();
    $sequence = static fn ($uuid): int => unpack('n', substr(Uuid::fromString((string) $uuid)->bytes, 8, 2))[1] & 0x3fff;
    verify($sequence($first) === 0x3fff && $sequence($sameTick) === 0x3fff, 'V1 repeated physical tick must not rotate clock sequence');
    verify($sequence($rolledBack) === 0 && $sequence($repeatedRollbackTick) === 0, 'V1 physical rollback advances clock sequence, including wrap');
    verify(count(array_unique(array_map('strval', [$first, $sameTick, $rolledBack, $repeatedRollbackTick]))) === 4, 'V1 rollback and sequence wrap uniqueness');
    unset($GLOBALS['identityTestInt'], $GLOBALS['identityTestBytes']);

    // V6 follows the RFC 5.6 recommendation to refresh both fields.
    frozen(1645557742);
    $factory = new UuidFactory();
    $GLOBALS['identityTestBytes'] = hex2bin('1234001122334455');
    $first = Uuid::fromString((string) $factory->v6());
    $GLOBALS['identityTestBytes'] = hex2bin('567866778899aabb');
    $second = Uuid::fromString((string) $factory->v6());
    verify(substr($first->bytes, 8) === hex2bin('9234011122334455'), 'V6 first fresh tail');
    verify(substr($second->bytes, 8) === hex2bin('967867778899aabb'), 'V6 second fresh tail');
    verify(strcmp($first->bytes, $second->bytes) < 0, 'V6 ordering survives fresh entropy');
    unset($GLOBALS['identityTestBytes']);

    // Reach the RFC A.6 counter via the public API (method 1).
    $GLOBALS['identityTestInt'] = 0xc3;
    $GLOBALS['identityTestBytes'] = hex2bin('18c4dc0c0c07398f');
    $factory = new UuidFactory();
    for ($i = 0; $i <= 0xc00; ++$i) {
        $uuid = $factory->v7();
    }
    verify((string) $uuid === '017f22e2-79b0-7cc3-98c4-dc0c0c07398f', 'RFC A.6');
    $GLOBALS['identityTestBytes'] = hex2bin('0123456789abcdef');
    verify(substr(Uuid::fromString((string) $factory->v7())->bytes, 8) === hex2bin('8123456789abcdef'), 'Fresh rand_b');
    unset($GLOBALS['identityTestInt'], $GLOBALS['identityTestBytes']);

    foreach (['v1', 'v6', 'v7'] as $method) {
        $factory = new UuidFactory();
        $factory->$method();
        $clone = clone $factory;
        $restored = unserialize(serialize($factory));
        verify($restored instanceof UuidFactory, 'Factory restoration');
        $ids = [(string) $factory->$method(), (string) $clone->$method(), (string) $restored->$method()];
        verify(count(array_unique($ids)) === count($ids), $method . ' copied state collision');

        if (function_exists('pcntl_fork')) {
            $factory = new UuidFactory();
            $factory->$method();
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            verify($pair !== false, 'Socket pair');
            $pid = pcntl_fork();
            verify($pid !== -1, 'Fork failed');
            if ($pid === 0) {
                fclose($pair[0]);
                fwrite($pair[1], (string) $factory->$method());
                fclose($pair[1]);
                exit(0);
            }
            fclose($pair[1]);
            stream_set_timeout($pair[0], 10);
            $parent = (string) $factory->$method();
            $child = stream_get_contents($pair[0]);
            fclose($pair[0]);
            pcntl_waitpid($pid, $status);
            verify(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0 && is_string($child) && strlen($child) === 36, 'Child result');
            verify($parent !== $child, $method . ' fork collision');
        }
    }
    if (!function_exists('pcntl_fork')) {
        echo "SKIP actual fork checks: pcntl unavailable\n";
    }

    foreach (UuidGenerationVersion::cases() as $version) {
        $factory = unserialize(serialize(new UuidFactory($version)));
        verify($factory instanceof UuidFactory && Uuid::fromString((string) $factory->generate())->version === $version->value, 'Restored default version');
    }
    $class = UuidFactory::class;
    $key = "\0" . $class . "\0defaultVersion";
    $legacy = 'O:' . strlen($class) . ':"' . $class . '":1:{' . serialize($key) . serialize(UuidGenerationVersion::V4) . '}';
    $factory = unserialize($legacy);
    verify($factory instanceof UuidFactory && Uuid::fromString((string) $factory->generate())->version === 4, 'Legacy serialized factory');

    frozen(0);
    $GLOBALS['identityTestInt'] = 23;
    $GLOBALS['identityTestBytes'] = hex2bin('1122334455667788');
    verify((string) (new UuidFactory())->v7() === '00000000-0000-7017-9122-334455667788', 'Epoch zero');
    unset($GLOBALS['identityTestInt'], $GLOBALS['identityTestBytes']);
    frozen(-1, 999999);
    rejects(fn () => (new UuidFactory())->v7(), OverflowException::class);
    foreach (['v1', 'v6'] as $method) {
        frozen(-12219292800);
        $uuid = Uuid::fromString((string) (new UuidFactory())->$method());
        verify(substr($uuid->toString(), 0, 13) === '00000000-0000', 'Gregorian epoch zero');
        frozen(-12219292801);
        rejects(fn () => (new UuidFactory())->$method(), OverflowException::class);
        frozen(103072857661);
        rejects(fn () => (new UuidFactory())->$method(), OverflowException::class);
    }

    frozen(1645557742);
    $factory = new UuidFactory();
    $last = '';
    for ($i = 0; $i < 9000; ++$i) {
        if ($i === 100) {
            frozen(1645557741);
        }
        $id = (string) $factory->v7();
        verify(strcmp($id, $last) > 0, 'v7 monotonicity through rollback/rollover');
        $last = $id;
    }
    verify(unixMs($last) > 1645557742000, 'Synthetic millisecond rollover');

    frozen(281474976710, 655999);
    $GLOBALS['identityTestInt'] = 0;
    $factory = new UuidFactory();
    for ($i = 0; $i < 4096; ++$i) {
        verify(unixMs((string) $factory->v7()) === 0xffffffffffff, 'Maximum UUIDv7 timestamp');
    }
    rejects(fn () => $factory->v7(), OverflowException::class);
    frozen(281474976710, 656000);
    rejects(fn () => (new UuidFactory())->v7(), OverflowException::class);
    unset($GLOBALS['identityTestInt']);

    // Verify actual 60-bit field exhaustion, not just out-of-range input.
    $maximumTicks = 0x0fffffffffffffff;
    $unixTicks = $maximumTicks - 0x01b21dd213814000;
    $maximumSeconds = intdiv($unixTicks, 10000000);
    $maximumMicros = intdiv($unixTicks % 10000000, 10);
    foreach (['v1', 'v6'] as $method) {
        frozen($maximumSeconds, $maximumMicros);
        $factory = new UuidFactory();
        $remaining = $unixTicks % 10;
        for ($i = 0; $i <= $remaining; ++$i) {
            $hex = str_replace('-', '', (string) $factory->$method());
            $timestampHex = $method === 'v1'
                ? substr($hex, 13, 3) . substr($hex, 8, 4) . substr($hex, 0, 8)
                : substr($hex, 0, 12) . substr($hex, 13, 3);
            verify((int) hexdec($timestampHex) === $maximumTicks - $remaining + $i, 'Gregorian boundary encoding');
        }
        rejects(fn () => $factory->$method(), OverflowException::class);
    }

    // Failed entropy acquisition must not consume any representable ticks.
    foreach (['v1', 'v6'] as $method) {
        frozen($maximumSeconds, $maximumMicros);
        $factory = new UuidFactory();
        $failure = new Random\RandomException('Gregorian entropy failure');
        $GLOBALS['identityTestBytes'] = $failure;
        try {
            $factory->$method();
            throw new RuntimeException('Expected entropy failure');
        } catch (Random\RandomException $caught) {
            verify($caught === $failure, 'Original Gregorian entropy failure');
        }
        unset($GLOBALS['identityTestBytes']);
        for ($i = 0; $i <= $unixTicks % 10; ++$i) {
            $hex = str_replace('-', '', (string) $factory->$method());
            $timestampHex = $method === 'v1'
                ? substr($hex, 13, 3) . substr($hex, 8, 4) . substr($hex, 0, 8)
                : substr($hex, 0, 12) . substr($hex, 13, 3);
            verify((int) hexdec($timestampHex) === $maximumTicks - ($unixTicks % 10) + $i, 'Entropy failure must not consume a Gregorian tick');
        }
        rejects(fn () => $factory->$method(), OverflowException::class);
    }

    verify((string) (new UuidFactory())->v8(hex2bin('5c146b143c52'), 0xafd, hex2bin('938a375d0df1fbf6')) === '5c146b14-3c52-8afd-938a-375d0df1fbf6', 'RFC B.2 field encoding');
    foreach ([['short', 0, str_repeat("\0", 8)], [str_repeat("\0", 6), -1, str_repeat("\0", 8)], [str_repeat("\0", 6), 4096, str_repeat("\0", 8)], [str_repeat("\0", 6), 0, 'short']] as [$a, $b, $c]) {
        rejects(fn () => (new UuidFactory())->v8($a, $b, $c), InvalidArgumentException::class);
    }

    frozen(1645557742);
    $factory = new UuidFactory();
    $first = (string) $factory->v7();
    $failure = new Random\RandomException('entropy failure');
    $GLOBALS['identityTestBytes'] = $failure;
    try {
        $factory->v7();
        throw new RuntimeException('Expected entropy failure');
    } catch (Random\RandomException $caught) {
        verify($caught === $failure, 'Entropy failure identity');
    }
    unset($GLOBALS['identityTestBytes']);
    verify(strcmp((string) $factory->v7(), $first) > 0, 'Recovery preserves ordering');
    $GLOBALS['identityTestInt'] = $failure;
    rejects(fn () => (new UuidFactory())->v7(), Random\RandomException::class);
    unset($GLOBALS['identityTestInt']);
    // No weak fallback is permitted for the remaining random paths.
    $GLOBALS['identityTestBytes'] = $failure;
    rejects(fn () => (new UuidFactory())->v4(), Random\RandomException::class);
    $factory = new UuidFactory();
    $GLOBALS['identityTestInt'] = 0;
    rejects(fn () => $factory->v7(), Random\RandomException::class);
    unset($GLOBALS['identityTestBytes']);
    $GLOBALS['identityTestBytes'] = hex2bin('0102030405060708');
    verify((string) $factory->v7() === '017f22e2-79b0-7000-8102-030405060708', 'V7 failed initial tail does not consume counter');
    unset($GLOBALS['identityTestBytes'], $GLOBALS['identityTestInt']);

    $GLOBALS['identityTestInt'] = $failure;
    rejects(fn () => (new UuidFactory())->v1(), Random\RandomException::class);
    unset($GLOBALS['identityTestInt']);

    echo "RFC vectors, copied state, fork, time limits, rollback, rollover and entropy checks passed.\n";
}
