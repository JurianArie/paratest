<?php

declare(strict_types=1);

namespace ParaTest\WrapperRunner;

use ParaTest\Options;
use SplFileInfo;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Throwable;

use function array_map;
use function array_slice;
use function assert;
use function clearstatcache;
use function count;
use function file_get_contents;
use function filesize;
use function implode;
use function is_array;
use function is_string;
use function max;
use function serialize;
use function sprintf;
use function str_split;
use function strlen;
use function touch;
use function uniqid;

use const DIRECTORY_SEPARATOR;

/** @internal */
final class WrapperWorker
{
    public const string COMMAND_EXIT = "EXIT\n";

    public readonly SplFileInfo $statusFile;
    public readonly SplFileInfo $progressFile;
    public readonly SplFileInfo $unexpectedOutputFile;
    public readonly SplFileInfo $testResultFile;
    public readonly SplFileInfo $resultCacheFile;
    public readonly SplFileInfo $junitFile;
    public readonly SplFileInfo $coverageFile;
    public readonly SplFileInfo $teamcityFile;
    public readonly SplFileInfo $testdoxFile;

    /** @var list<string> Assigned tests the worker hasn't finished yet: the first one is running */
    private array $queue = [];
    private Process $process;
    private int $inExecution = 0;
    private int $finished    = 0;
    private InputStream $input;
    private int $exitCode = -1;

    /**
     * @param non-empty-string[] $parameters
     * @param non-empty-string   $tmpDir     Private directory, owned by the current run, holding worker files
     */
    public function __construct(
        private readonly OutputInterface $output,
        private readonly Options $options,
        array $parameters,
        private readonly int $token,
        string $tmpDir,
    ) {
        $commonTmpFilePath = sprintf(
            '%s%sworker_%02s_stdout_%s_',
            $tmpDir,
            DIRECTORY_SEPARATOR,
            $token,
            uniqid(),
        );
        $this->statusFile  = new SplFileInfo($commonTmpFilePath . 'status');
        touch($this->statusFile->getPathname());
        $this->progressFile = new SplFileInfo($commonTmpFilePath . 'progress');
        touch($this->progressFile->getPathname());
        $this->unexpectedOutputFile = new SplFileInfo($commonTmpFilePath . 'unexpected_output');
        touch($this->unexpectedOutputFile->getPathname());
        $this->testResultFile = new SplFileInfo($commonTmpFilePath . 'test_result');

        if ($this->options->configuration->recordTestRunHistory()) {
            $this->resultCacheFile = new SplFileInfo($commonTmpFilePath . 'result_cache');
        }

        if ($options->configuration->hasLogfileJunit()) {
            $this->junitFile = new SplFileInfo($commonTmpFilePath . 'junit');
        }

        if ($options->configuration->hasCoverageReport()) {
            $this->coverageFile = new SplFileInfo($commonTmpFilePath . 'coverage');
        }

        if ($options->needsTeamcity) {
            $this->teamcityFile = new SplFileInfo($commonTmpFilePath . 'teamcity');
        }

        if ($options->needsTestdox) {
            $this->testdoxFile = new SplFileInfo($commonTmpFilePath . 'testdox');
        }

        $parameters[] = '--status-file';
        $parameters[] = $this->statusFile->getPathname();
        $parameters[] = '--progress-file';
        $parameters[] = $this->progressFile->getPathname();
        $parameters[] = '--unexpected-output-file';
        $parameters[] = $this->unexpectedOutputFile->getPathname();
        $parameters[] = '--test-result-file';
        $parameters[] = $this->testResultFile->getPathname();

        if (isset($this->resultCacheFile)) {
            $parameters[] = '--result-cache-file';
            $parameters[] = $this->resultCacheFile->getPathname();
        }

        if (isset($this->teamcityFile)) {
            $parameters[] = '--teamcity-file';
            $parameters[] = $this->teamcityFile->getPathname();
        }

        if (isset($this->testdoxFile)) {
            $parameters[] = '--testdox-file';
            $parameters[] = $this->testdoxFile->getPathname();
        }

        $phpunitArguments = [$options->phpunit];
        foreach ($options->phpunitOptions as $key => $value) {
            if ($options->functional && $key === 'filter') {
                continue;
            }

            if ($value === true) {
                $phpunitArguments[] = "--{$key}";
                continue;
            }

            if (! is_array($value)) {
                $value = [$value];
            }

            foreach ($value as $innerValue) {
                $phpunitArguments[] = "--{$key}";
                $phpunitArguments[] = (string) $innerValue;
            }
        }

        $phpunitArguments[] = '--do-not-record-test-run-history';
        $phpunitArguments[] = '--no-logging';
        $phpunitArguments[] = '--no-coverage';
        $phpunitArguments[] = '--no-output';
        if (isset($this->junitFile)) {
            $phpunitArguments[] = '--log-junit';
            $phpunitArguments[] = $this->junitFile->getPathname();
        }

        if (isset($this->coverageFile)) {
            $phpunitArguments[] = '--coverage-php';
            $phpunitArguments[] = $this->coverageFile->getPathname();
        }

        $parameters[] = '--phpunit-argv';
        $parameters[] = serialize($phpunitArguments);

        if ($options->verbose) {
            $output->write(sprintf(
                "Starting process {$this->token}: %s\n",
                implode(' ', array_map('\\escapeshellarg', $parameters)),
            ));
        }

        $this->input   = new InputStream();
        $this->process = new Process(
            $parameters,
            $options->cwd,
            $options->fillEnvWithTokens($token),
            $this->input,
            null,
        );
    }

    public function start(): void
    {
        $this->process->start();
    }

    public function getWorkerCrashedException(?Throwable $previousException = null): WorkerCrashedException
    {
        $this->readStatus();

        return WorkerCrashedException::fromProcess(
            $this->process,
            $this->queue[0] ?? 'N.A.',
            $previousException,
        );
    }

    public function assign(string $test): void
    {
        if ($this->options->verbose) {
            $this->output->write("Process {$this->token} executing: {$test}\n");
        }

        $this->write($test . "\n");
        $this->queue[] = $test;
        ++$this->inExecution;
    }

    /** Number of assigned tests the worker hasn't finished yet */
    public function queued(): int
    {
        $this->readStatus();

        return count($this->queue);
    }

    public function stop(): void
    {
        $this->write(self::COMMAND_EXIT);
    }

    private function write(string $command): void
    {
        $this->input->write($command);

        // The process only passes input on to the worker when it looks at its pipes, one chunk at a time, and the
        // first chunk is the empty one the input stream yields while idle: look twice right away, instead of letting
        // the worker wait for the next two cycles of the runner's loop
        $this->process->isRunning();
        $this->process->isRunning();
    }

    public function isFree(): bool
    {
        return $this->queued() === 0;
    }

    /**
     * The worker writes one exit code to the status file per finished test: take the tests it finished since the
     * last look off the queue, and keep the worst exit code among them
     */
    private function readStatus(): void
    {
        $statusFilepath = $this->statusFile->getPathname();
        clearstatcache(true, $statusFilepath);

        if (filesize($statusFilepath) <= $this->finished) {
            return;
        }

        $exitCodes = file_get_contents($statusFilepath, false, null, $this->finished);
        assert(is_string($exitCodes) && $exitCodes !== '');
        foreach (str_split($exitCodes) as $exitCode) {
            $this->exitCode = max($this->exitCode, (int) $exitCode);
        }

        $this->queue     = array_slice($this->queue, strlen($exitCodes));
        $this->finished += strlen($exitCodes);
    }

    public function getExitCode(): int
    {
        return $this->exitCode;
    }

    public function isRunning(): bool
    {
        return $this->process->isRunning();
    }

    public function hasExecutedTests(): bool
    {
        return $this->inExecution > 0;
    }
}
