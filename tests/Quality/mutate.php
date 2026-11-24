<?php

declare(strict_types=1);

use Pest\Kernel;
use Pest\Mutate\Event\Events\TestSuite\FinishMutationSuite;
use Pest\Mutate\Event\Events\TestSuite\FinishMutationSuiteSubscriber;
use Pest\Mutate\Event\Events\TestSuite\StartMutationGeneration;
use Pest\Mutate\Event\Events\TestSuite\StartMutationGenerationSubscriber;
use Pest\Mutate\Contracts\MutationTestRunner;
use Pest\Support\Container;
use Symfony\Component\Process\Process;
use Pest\Mutate\Event\Facade;
use Pest\Mutate\Plugins\Mutate;
use Pest\Mutate\Support\MutationTestResult;
use Pest\Mutate\Support\StreamWrapper;
use Pest\TestSuite;
use PHPUnit\TestRunner\TestResult\Facade as TestResult;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

$root = dirname(__DIR__, 2);
if (filter_var(ini_get('opcache.enable_cli'), FILTER_VALIDATE_BOOL)) {
    throw new RuntimeException('Disable CLI OPcache for mutation testing.');
}
require $root . '/vendor/autoload.php';
$_SERVER['COLLISION_PRINTER'] = 'DefaultPrinter';
$GLOBALS['_composer_bin_dir'] = $root . '/vendor/bin';
$arguments = $_SERVER['argv'];
$source = getenv(Mutate::ENV_MUTATION_TESTING);
$replacement = getenv(Mutate::ENV_MUTATION_FILE);
$isWorker = $source !== false;
$directory = getenv('IDENTITY_MUTATION_WORKERS');
if (!$isWorker && in_array('--mutate', $arguments, true)) {
    $directory = sys_get_temp_dir() . '/identity-mutation-' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700)) {
        throw new RuntimeException('Cannot create worker report directory.');
    }
    putenv('IDENTITY_MUTATION_WORKERS=' . $directory);
    $_SERVER['IDENTITY_MUTATION_WORKERS'] = $_ENV['IDENTITY_MUTATION_WORKERS'] = $directory;
    register_shutdown_function(static function () use ($directory): void {
        foreach (glob($directory . '/*.json') as $file) {
            unlink($file);
        }
        rmdir($directory);
    });
}

if ($isWorker) {
    // Mutate 4.0.1 passes literal quoted selectors. Use the entire small
    // deterministic suite instead: no empty filtered runs or lost datasets.
    // Keep --bail to stop after the first observed test failure.
    $arguments = array_values(array_filter(
        $arguments,
        static fn (string $argument): bool => !str_starts_with($argument, '--filter='),
    ));
    $_SERVER['argv'] = $arguments;
    register_shutdown_function(static function (): void {
        if (class_exists(StreamWrapper::class, false)) {
            StreamWrapper::disable();
        }
    });
}

$kernel = Kernel::boot(TestSuite::getInstance($root, 'tests'), new ArgvInput($arguments), new ConsoleOutput(ConsoleOutput::VERBOSITY_NORMAL, false));
if ($isWorker) {
    if ($directory === false || $replacement === false || !is_dir($directory)) {
        throw new RuntimeException('Missing mutation worker metadata.');
    }
    // Check the substituted file before executing tests. A worker that
    // never reaches the result recorder must not count as a detected fault.
    $hash = hash_file('sha256', $source);
    if ($hash === false || $hash !== hash_file('sha256', $replacement)) {
        throw new RuntimeException('Requested source replacement was not installed.');
    }
} elseif ($directory !== false && in_array('--mutate', $arguments, true)) {
    Facade::instance()->registerSubscribers(new class($root, $directory) implements StartMutationGenerationSubscriber {
        public function __construct(private readonly string $root, private readonly string $directory) {}

        public function notify(StartMutationGeneration $event): void
        {
            // Pest uses argv[0] directly as an executable. This adapter is a
            // PHP script, so explicitly include the interpreter and settings.
            $command = [PHP_BINARY, '-d', 'opcache.enable_cli=0', __FILE__, '--configuration=' . $this->root . '/phpunit.mutation.xml'];
            Container::getInstance()->get(MutationTestRunner::class)->setOriginalArguments($command);

            // Verify the actual worker path with a behavior-preserving
            // substitution and the same literal quoted filter as Pest.
            $source = $this->root . '/src/UuidFactory.php';
            $replacement = $this->directory . '/control.php';
            $original = file_get_contents($source);
            if (!is_string($original) || file_put_contents($replacement, $original . "\n// Mutation worker control.\n") === false) {
                throw new RuntimeException('Cannot prepare mutation worker control.');
            }
            try {
                $process = new Process([...$command, '--bail', '--filter="matches the RFC A.3 random UUID vector"'], $this->root, [
                    Mutate::ENV_MUTATION_TESTING => $source,
                    Mutate::ENV_MUTATION_FILE => $replacement,
                    'XDEBUG_MODE' => 'off',
                ], timeout: 30);
                $process->mustRun();
                $key = hash('sha256', $source . "\0" . $replacement);
                $record = json_decode(file_get_contents($this->directory . '/' . $key . '.json'), true, flags: JSON_THROW_ON_ERROR);
                if ($record['status'] !== 0 || $record['tests'] < 1 || $record['failures'] || $record['errors'] || $record['skipped'] || $record['issues']) {
                    throw new RuntimeException('Behavior-preserving mutation control did not pass.');
                }
                unlink($this->directory . '/' . $key . '.json');
                echo "\nMutation worker control passed (", $record['tests'], " actual tests).\n";
            } finally {
                unlink($replacement);
            }

            // Coverage has already been collected by the parent.
            putenv('XDEBUG_MODE=off');
            $_SERVER['XDEBUG_MODE'] = $_ENV['XDEBUG_MODE'] = 'off';
        }
    }, new class($directory) implements FinishMutationSuiteSubscriber {
        public function __construct(private readonly string $directory) {}

        public function notify(FinishMutationSuite $event): void
        {
            $counts = ['assertion_failure' => 0, 'test_error' => 0, 'survived' => 0, 'uncovered' => 0, 'unverified' => 0];
            $unverified = [];
            foreach ($event->mutationSuite->repository->all() as $collection) {
                foreach ($collection->tests() as $test) {
                    if ($test->result() === MutationTestResult::Uncovered) {
                        ++$counts['uncovered'];
                        continue;
                    }
                    $mutation = $test->mutation;
                    $key = hash('sha256', $mutation->file->getRealPath() . "\0" . $mutation->modifiedSourcePath);
                    $path = $this->directory . '/' . $key . '.json';
                    $record = is_file($path) ? json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : null;
                    $category = 'unverified';
                    if (is_array($record) && $record['tests'] > 0 && !$record['skipped'] && !$record['issues']) {
                        if ($test->result() === MutationTestResult::Tested && $record['status'] !== 0) {
                            if ($record['failures'] > 0 && $record['errors'] === 0) {
                                $category = 'assertion_failure';
                            } elseif ($record['errors'] > 0) {
                                $category = 'test_error';
                            }
                        } elseif ($test->result() === MutationTestResult::Untested && $record['status'] === 0 && $record['failures'] === 0 && $record['errors'] === 0) {
                            $category = 'survived';
                        }
                    }
                    ++$counts[$category];
                    if ($category === 'unverified') {
                        $unverified[] = $test->getId();
                    }
                }
            }
            echo "\nVerified mutation worker outcomes: ", json_encode($counts, JSON_THROW_ON_ERROR), "\n";
            if ($unverified !== []) {
                echo 'Unverified mutant IDs: ', implode(', ', $unverified), "\n";
                throw new RuntimeException('Mutation report contains unverified outcomes; do not use its score.');
            }
        }
    });
}

$status = $kernel->handle($arguments, $arguments);
if ($isWorker) {
    $result = TestResult::result();
    $record = [
        'status' => $status,
        'tests' => $result->numberOfTestsRun(),
        'failures' => $result->numberOfTestFailedEvents(),
        'errors' => $result->numberOfTestErroredEvents(),
        'skipped' => $result->hasSkippedTests() || $result->hasTestSuiteSkippedEvents(),
        'issues' => $result->hasIssues(),
    ];
    $key = hash('sha256', $source . "\0" . $replacement);
    if (file_put_contents($directory . '/' . $key . '.json', json_encode($record, JSON_THROW_ON_ERROR)) === false) {
        throw new RuntimeException('Cannot record mutation test execution.');
    }
}
exit($status);
