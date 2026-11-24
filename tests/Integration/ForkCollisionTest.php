<?php

declare(strict_types=1);

use Componenta\Identity\Uuid;
use Componenta\Identity\UuidFactory;
use Componenta\Identity\UuidVariant;

covers(UuidFactory::class);

/** @return list<string> Binary UUID samples generated in a separate process. */
function forkedUuidSamples(string $method, int $workers, int $perWorker): array
{
    $process = proc_open(
        [PHP_BINARY, dirname(__DIR__) . '/Fixture/fork-sample.php', $method, (string) $workers, (string) $perWorker],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start fork sample fixture.');
    }
    fclose($pipes[0]);
    try {
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
    } finally {
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
    }

    expect($status)->toBe(0, (string) $errors)
        ->and($output)->toBeString()
        ->and(strlen($output))->toBe($workers * $perWorker * 16);

    return str_split($output, 16);
}

it('does not repeat the next UUID across two forked copies of a warmed factory', function (string $method): void {
    $samples = forkedUuidSamples($method, 2, 1);

    expect(array_unique($samples))->toHaveCount(2);
    foreach ($samples as $sample) {
        $uuid = Uuid::fromBytes($sample);
        expect($uuid->version)->toBe((int) substr($method, 1))
            ->and($uuid->variant)->toBe(UuidVariant::Rfc);
    }
})->with(['v1', 'v6', 'v7'])->skip(!function_exists('pcntl_fork'), 'pcntl is required for actual fork tests');

it('has no duplicates across eight warmed forked factories at fixed wall time', function (string $method): void {
    $samples = forkedUuidSamples($method, 8, 20000);

    expect($samples)->toHaveCount(160000)
        ->and(array_unique($samples))->toHaveCount(160000);
})->with(['v1', 'v6', 'v7'])->group('stress')->skip(!function_exists('pcntl_fork'), 'pcntl is required for actual fork tests');
