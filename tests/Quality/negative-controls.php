<?php

declare(strict_types=1);

// Deliberate, scoped negative controls. Verify that each relevant public
// contract fails on one faulty source change and passes on restored source.
// Never write mutations to the actual package source directory.
$root = dirname(__DIR__, 2);
$workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'identity-negative-' . bin2hex(random_bytes(8));
if (!mkdir($workspace, 0700)) {
    throw new RuntimeException('Cannot create negative-control workspace.');
}
$source = $workspace . '/src';
mkdir($source, 0700);

function removeTree(string $path): void
{
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($entries as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($path);
}

/** @return array{status: int, tests: int, failures: int, errors: int, skipped: int} */
function runPestControl(string $root, string $workspace, string $configuration, string $filter): array
{
    $report = $workspace . '/result.xml';
    if (is_file($report)) {
        unlink($report);
    }

    $env = getenv();
    $env['IDENTITY_TEST_SOURCE'] = $workspace . '/src';
    $process = proc_open(
        [PHP_BINARY, $root . '/vendor/bin/pest', '--configuration=' . $configuration, '--filter=' . $filter, '--log-junit=' . $report, '--exclude-group=stress', '--colors=never'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
        $pipes,
        $root,
        $env,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start Pest.');
    }
    fclose($pipes[0]);
    try {
        $output = stream_get_contents($pipes[1]);
    } finally {
        fclose($pipes[1]);
        $status = proc_close($process);
    }

    if (!is_file($report)) {
        throw new RuntimeException("Pest did not produce a JUnit report:\n" . $output);
    }
    $xml = simplexml_load_file($report);
    if ($xml === false) {
        throw new RuntimeException('Invalid JUnit report.');
    }

    return [
        'status' => $status,
        'tests' => count($xml->xpath('//testcase') ?: []),
        'failures' => count($xml->xpath('//testcase/failure') ?: []),
        'errors' => count($xml->xpath('//testcase/error') ?: []),
        'skipped' => count($xml->xpath('//testcase/skipped') ?: []),
    ];
}

try {
    foreach (glob($root . '/src/*.php') as $file) {
        if (!copy($file, $source . '/' . basename($file))) {
            throw new RuntimeException('Cannot copy source.');
        }
    }

    // Each mutation has a corresponding, specific behavior test. Do not count
    // parse errors, runner crashes, missing tests or skipped tests as kills.
    $unit = 'phpunit.xml';
    $controlled = 'phpunit.controlled.xml';
    $controls = [
        ['variant mask', 'Uuid.php', '($octet & 0xC0) === 0x80', '($octet & 0xC0) === 0xC0', $unit, 'preserves every variant octet'],
        ['false equality', 'Uuid.php', 'return hash_equals($this->bytes, $other->bytes);', 'return true;', $unit, 'compares UUID values'],
        ['untrusted message disclosure', 'Uuid.php', "throw new \\InvalidArgumentException('Invalid UUID string.');", "throw new \\InvalidArgumentException('Invalid UUID string: ' . \$uuid);", $unit, 'does not reflect an arbitrarily long'],
        ['trace disclosure', 'Uuid.php', 'fromString(#[\\SensitiveParameter] string $uuid)', 'fromString(string $uuid)', $unit, 'redacts the input in library exception trace'],
        ['serialized variant tampering', 'Uuid.php', '|| $variant !== self::detectVariant($bytes)', '', $unit, 'rejects inconsistent serialized fields'],
        ['v4 version bit', 'UuidFactory.php', '$this->applyVersionAndVariant(random_bytes(16), 0x40)', '$this->applyVersionAndVariant(random_bytes(16), 0x50)', $controlled, 'matches the RFC A.3 random UUID vector'],
        ['v1 rollback sequence', 'UuidFactory.php', '$clockSeq = ($clockSeq + 1) & 0x3FFF;', '$clockSeq = $clockSeq;', $controlled, 'changes the v1 sequence only'],
        ['v6 multicast bit', 'UuidFactory.php', '$tail[2] = chr(ord($tail[2]) | 0x01);', '$tail[2] = chr(ord($tail[2]) & 0xFE);', $controlled, 'uses fresh v6 sequence'],
        ['v7 reused entropy', 'UuidFactory.php', '$randB = random_bytes(8);', '$randB = str_repeat("\\0", 8);', $controlled, 'refreshes the v7 random tail'],
        ['v7 rollover guard', 'UuidFactory.php', 'elseif ($this->v7Seq === self::V7_SEQUENCE_MAX)', 'elseif ($this->v7Seq > self::V7_SEQUENCE_MAX)', $controlled, 'fails rather than wrapping the largest'],
        ['v7 initial entropy failure consumes prefix', 'UuidFactory.php', '$randB = random_bytes(8);', '$this->v7LastMs = $ms; $this->v7Seq = $seq; $randB = random_bytes(8);', $controlled, 'recovers after entropy acquisition fails during initial generation'],
        ['v1 random range below zero', 'UuidFactory.php', '$clockSeq = $this->clockSeq ?? random_int(0, 0x3FFF);', '$clockSeq = $this->clockSeq ?? random_int(-1, 0x3FFF);', $controlled, 'encodes both possible endpoints'],
        ['v1 random range above 14 bits', 'UuidFactory.php', '$clockSeq = $this->clockSeq ?? random_int(0, 0x3FFF);', '$clockSeq = $this->clockSeq ?? random_int(0, 0x4000);', $controlled, 'encodes both possible endpoints'],
        ['v1 sequence truncation on rollback', 'UuidFactory.php', '$clockSeq = ($clockSeq + 1) & 0x3FFF;', '$clockSeq = ($clockSeq + 1) & 0x3FFE;', $controlled, 'increments the v1 clock sequence on an ordinary'],
        ['forked state reuse', 'UuidFactory.php', 'if ($this->processId !== $pid)', 'if ($this->processId === null)', $unit, 'does not repeat the next UUID across two forked copies'],
    ];
    if (!function_exists('pcntl_fork')) {
        // These controls are Linux CI checks; do not silently count an
        // unavailable process primitive as a successful mutation kill.
        throw new RuntimeException('PCNTL is required for negative controls.');
    }

    foreach ($controls as [$label, $file, $from, $to, $configuration, $filter]) {
        $path = $source . '/' . $file;
        $original = file_get_contents($path);
        if (!is_string($original) || substr_count($original, $from) !== 1) {
            throw new RuntimeException('Negative control needs review: ' . $label);
        }
        $mutated = str_replace($from, $to, $original);
        file_put_contents($path, $mutated);

        try {
            $red = runPestControl($root, $workspace, $configuration, $filter);
            if ($red['status'] !== 1 || $red['tests'] < 1 || $red['failures'] < 1 || $red['errors'] !== 0 || $red['skipped'] !== 0) {
                throw new RuntimeException('Expected assertion failure for ' . $label . ': ' . json_encode($red));
            }
        } finally {
            file_put_contents($path, $original);
        }

        $green = runPestControl($root, $workspace, $configuration, $filter);
        if ($green['status'] !== 0 || $green['tests'] !== $red['tests'] || $green['failures'] !== 0 || $green['errors'] !== 0 || $green['skipped'] !== 0) {
            throw new RuntimeException('Expected restored passing test for ' . $label . ': ' . json_encode($green));
        }

        echo 'RED/GREEN: ', $label, ' (', $red['failures'], ' assertion failure(s); ', $green['tests'], " restored passing test(s))\n";
    }

    echo count($controls), " targeted negative controls passed.\n";
} finally {
    removeTree($workspace);
}
