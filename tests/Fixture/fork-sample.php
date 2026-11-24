<?php

declare(strict_types=1);

// A fixture executable, not a test runner. Fork before emitting binary output
// so no Pest/PHPUnit runner state or assertion machinery is inherited.
use Componenta\Identity\RfcUuidInterface;
use Componenta\Identity\Tests\Fixture\ControlledSystem;
use Componenta\Identity\UuidFactory;

require __DIR__ . '/controlled-bootstrap.php';

$method = $argv[1] ?? '';
$workers = (int) ($argv[2] ?? 0);
$perWorker = (int) ($argv[3] ?? 0);
if (!in_array($method, ['v1', 'v6', 'v7'], true) || $workers < 2 || $workers > 8 || $perWorker < 1 || $perWorker > 20000) {
    throw new InvalidArgumentException('Invalid fork sample request.');
}

ControlledSystem::freeze(1645557742);
$factory = new UuidFactory();
$factory->$method();
$pids = [];
$paths = [];

try {
    for ($worker = 0; $worker < $workers; ++$worker) {
        $path = tempnam(sys_get_temp_dir(), 'identity-fork-');
        if ($path === false) {
            throw new RuntimeException('Cannot create temporary file.');
        }
        $paths[] = $path;
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Cannot fork.');
        }

        if ($pid === 0) {
            try {
                $file = fopen($path, 'wb');
                if ($file === false) {
                    throw new RuntimeException('Cannot open worker output.');
                }
                for ($i = 0; $i < $perWorker; ++$i) {
                    $uuid = $factory->$method();
                    if (!$uuid instanceof RfcUuidInterface || fwrite($file, $uuid->bytes) !== 16) {
                        throw new RuntimeException('Cannot write complete UUID.');
                    }
                }
                fclose($file);
                exit(0);
            } catch (Throwable $failure) {
                fwrite(STDERR, $failure->getMessage() . PHP_EOL);
                exit(1);
            }
        }
        $pids[$pid] = true;
    }

    foreach (array_keys($pids) as $pid) {
        $waited = pcntl_waitpid($pid, $status);
        if ($waited === $pid) {
            unset($pids[$pid]);
        }
        if ($waited !== $pid || !pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
            throw new RuntimeException('Child generation failed.');
        }
    }

    // Keep the fixture responsible for transport, not collision assertions.
    foreach ($paths as $path) {
        $bytes = file_get_contents($path);
        if (!is_string($bytes) || strlen($bytes) !== $perWorker * 16 || fwrite(STDOUT, $bytes) !== strlen($bytes)) {
            throw new RuntimeException('Incomplete worker output.');
        }
    }
} finally {
    foreach (array_keys($pids) as $pid) {
        pcntl_waitpid($pid, $status);
    }
    foreach ($paths as $path) {
        unlink($path);
    }
}
