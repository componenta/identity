<?php

declare(strict_types=1);

// Isolated process: real entropy, controlled wall clock only for the fork run.
namespace Componenta\Identity {
    function gettimeofday(): array
    {
        return $GLOBALS['identityStressTime'] ?? \gettimeofday();
    }
}

namespace {
    use Componenta\Identity\RfcUuidInterface;
    use Componenta\Identity\UuidFactory;

    require dirname(__DIR__) . '/vendor/autoload.php';

    function check(bool $valid, string $message): void
    {
        if (!$valid) {
            throw new RuntimeException($message);
        }
    }

    foreach (['v1', 'v4', 'v6', 'v7'] as $method) {
        $factories = array_map(static fn () => new UuidFactory(), range(1, 8));
        $seen = [];
        $last = [];
        for ($i = 0; $i < 250000; ++$i) {
            $worker = $i % count($factories);
            $uuid = $factories[$worker]->$method();
            check($uuid instanceof RfcUuidInterface, 'Expected RFC UUID');
            $bytes = $uuid->bytes;
            check(strlen($bytes) === 16 && $uuid->version === (int) substr($method, 1) && (ord($bytes[8]) & 0xc0) === 0x80, 'Incorrect UUID layout');
            check(!isset($seen[$bytes]), 'Duplicate ' . $method);
            $seen[$bytes] = true;
            if ($method === 'v6' || $method === 'v7') {
                check(!isset($last[$worker]) || strcmp($bytes, $last[$worker]) > 0, 'Non-monotonic ' . $method);
                $last[$worker] = $bytes;
            }
        }
        echo $method . ': ' . count($seen) . " values, 8 factories, no duplicates\n";
        unset($seen, $last, $factories);
    }

    if (!function_exists('pcntl_fork')) {
        echo "SKIP multi-process stress: pcntl unavailable\n";
        exit(0);
    }

    $GLOBALS['identityStressTime'] = ['sec' => 1645557742, 'usec' => 0];
    foreach (['v1', 'v6', 'v7'] as $method) {
        $factory = new UuidFactory();
        $factory->$method();
        $pids = [];
        $paths = [];
        try {
            for ($worker = 0; $worker < 8; ++$worker) {
                $path = tempnam(sys_get_temp_dir(), 'identity-fork-');
                check($path !== false, 'Cannot create temporary file');
                $paths[] = $path;
                $pid = pcntl_fork();
                check($pid !== -1, 'Cannot fork');
                if ($pid === 0) {
                    try {
                        $file = fopen($path, 'wb');
                        check($file !== false, 'Cannot open temporary file');
                        for ($i = 0; $i < 20000; ++$i) {
                            $uuid = $factory->$method();
                            check($uuid instanceof RfcUuidInterface && fwrite($file, $uuid->bytes) === 16, 'Cannot write UUID');
                        }
                        fclose($file);
                        exit(0);
                    } catch (Throwable $failure) {
                        fwrite(STDERR, $failure->getMessage() . PHP_EOL);
                        exit(1);
                    }
                }
                $pids[] = $pid;
            }
            foreach ($pids as $pid) {
                check(pcntl_waitpid($pid, $status) === $pid && pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'Child generation failed');
            }
            $pids = [];
            $seen = [];
            foreach ($paths as $path) {
                $bytes = file_get_contents($path);
                check(is_string($bytes) && strlen($bytes) === 320000, 'Incomplete worker output');
                foreach (str_split($bytes, 16) as $id) {
                    check(!isset($seen[$id]), 'Duplicate across forked workers: ' . $method);
                    $seen[$id] = true;
                }
            }
            echo $method . ': ' . count($seen) . " values, 8 forked workers, frozen clock, no duplicates\n";
        } finally {
            foreach ($pids as $pid) {
                pcntl_waitpid($pid, $status);
            }
            foreach ($paths as $path) {
                unlink($path);
            }
        }
    }
}
