<?php

declare(strict_types=1);

namespace ParaTest\WrapperRunner;

use Closure;
use Generator;
use ParaTest\Options;
use PHPUnit\Event\Facade as EventFacade;
use PHPUnit\Framework\DataProviderTestSuite;
use PHPUnit\Framework\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\TestSuite;
use PHPUnit\Runner\ExecutionOrder\ReorderPipeline;
use PHPUnit\Runner\Extension\ExtensionBootstrapper;
use PHPUnit\Runner\Extension\ExtensionFacade;
use PHPUnit\Runner\Extension\PharLoader;
use PHPUnit\Runner\Phpt\TestCase as PhptTestCase;
use PHPUnit\Runner\TestRunHistory\DefaultTestRunHistory;
use PHPUnit\Runner\TestRunHistory\NullTestRunHistory;
use PHPUnit\Runner\TestSuiteSorter;
use PHPUnit\TestRunner\TestResult\Facade as TestResultFacade;
use PHPUnit\TextUI\Command\Result;
use PHPUnit\TextUI\Configuration\CodeCoverageFilterRegistry;
use PHPUnit\TextUI\Configuration\PhpHandler;
use PHPUnit\TextUI\Configuration\TestSuiteBuilder;
use PHPUnit\TextUI\TestSuiteFilterProcessor;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionProperty;
use SebastianBergmann\Timer\Timer;
use Symfony\Component\Console\Output\OutputInterface;

use function array_filter;
use function array_keys;
use function array_merge;
use function array_slice;
use function array_values;
use function assert;
use function ceil;
use function count;
use function is_int;
use function is_string;
use function mt_srand;
use function preg_quote;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;

use const ARRAY_FILTER_USE_KEY;
use const PHP_EOL;

/** @internal */
final readonly class SuiteLoader
{
    public int $testCount;
    /** @var list<non-empty-string> */
    public array $tests;

    public function __construct(
        private Options $options,
        OutputInterface $output,
        CodeCoverageFilterRegistry $codeCoverageFilterRegistry,
        CoverageCacheWarmer $coverageCacheWarmer,
    ) {
        $emitter = EventFacade::emitter();
        (new PhpHandler($emitter))->handle($this->options->configuration->php());

        // Warming the static analysis cache doesn't need the test suite: let it run while the suite is loaded
        $finishWarmingCoverageCache = null;
        if ($this->options->configuration->hasCoverageReport()) {
            $finishWarmingCoverageCache = $this->startWarmingCoverageCache(
                $output,
                $codeCoverageFilterRegistry,
                $coverageCacheWarmer,
            );
        }

        if ($this->options->configuration->hasBootstrap()) {
            $bootstrapFilename = $this->options->configuration->bootstrap();
            include_once $bootstrapFilename;
            $emitter->testRunnerBootstrapFinished($bootstrapFilename);
        }

        if (! $this->options->configuration->noExtensions()) {
            if ($this->options->configuration->hasPharExtensionDirectory()) {
                (new PharLoader($emitter))->loadPharExtensionsInDirectory(
                    $this->options->configuration->pharExtensionDirectory(),
                );
            }

            $extensionFacade       = new ExtensionFacade();
            $extensionBootstrapper = new ExtensionBootstrapper(
                $this->options->configuration,
                $extensionFacade,
                $emitter,
            );

            foreach ($this->options->configuration->extensionBootstrappers() as $bootstrapper) {
                $extensionBootstrapper->bootstrap(
                    $bootstrapper['className'],
                    $bootstrapper['parameters'],
                );
            }
        }

        TestResultFacade::init();
        EventFacade::instance()->seal();

        $testSuite = (new TestSuiteBuilder($emitter))->build($this->options->configuration);

        if ($this->options->hasShard()) {
            $this->shardTests($testSuite);
        }

        if ($this->options->configuration->executionOrder() === TestSuiteSorter::ORDER_RANDOMIZED) {
            mt_srand($this->options->configuration->randomOrderSeed());
        }

        if (
            $this->options->configuration->executionOrder() !== TestSuiteSorter::ORDER_DEFAULT ||
            $this->options->configuration->executionOrderDefects() !== TestSuiteSorter::ORDER_DEFAULT ||
            $this->options->configuration->resolveDependencies()
        ) {
            $resultCache = new NullTestRunHistory();
            if ($this->options->configuration->recordTestRunHistory()) {
                $resultCache = new DefaultTestRunHistory($this->options->configuration->testRunHistoryFile());
                $resultCache->load();
            }

            $pipeline = ReorderPipeline::fromConfiguration(
                $this->options->configuration->executionOrder(),
                $this->options->configuration->executionOrderDefects(),
                $this->options->configuration->resolveDependencies(),
            );
            if (! $pipeline->isEmpty()) {
                (new TestSuiteSorter($resultCache))->apply($testSuite, $pipeline);
            }
        }

        (new TestSuiteFilterProcessor($emitter))->process($this->options->configuration, $testSuite);

        $this->testCount = count($testSuite);

        $files = [];
        $tests = [];
        foreach ($this->loadFiles($testSuite) as $file => $test) {
            $files[$file] = null;

            if ($test instanceof PhptTestCase) {
                $tests[] = $file;
            } else {
                $name = $test->name();
                if ($test->providedData() !== []) {
                    $dataName = $test->dataName();
                    if ($this->options->functional) {
                        $name = sprintf('/::%s%s$/', preg_quote($name, '/'), preg_quote($test->dataSetAsString(), '/'));
                    } else {
                        if (is_int($dataName)) {
                            $name .= '#' . $dataName;
                        } else {
                            $name .= '@' . $dataName;
                        }
                    }
                } else {
                    $name = sprintf('/::%s$/', $name);
                }

                $tests[] = "$file\0$name";
            }
        }

        $this->tests = $this->options->functional
            ? $tests
            : array_keys($files);

        if ($finishWarmingCoverageCache === null) {
            return;
        }

        $finishWarmingCoverageCache();
    }

    /**
     * Same as PHPUnit's WarmCodeCoverageCacheCommand, but the files are analysed in parallel
     *
     * @return Closure(): void Waits for the cache to be warmed and reports on it
     */
    private function startWarmingCoverageCache(
        OutputInterface $output,
        CodeCoverageFilterRegistry $codeCoverageFilterRegistry,
        CoverageCacheWarmer $coverageCacheWarmer,
    ): Closure {
        if (! $this->options->configuration->hasCoverageCacheDirectory()) {
            $output->write('Cache for static analysis has not been configured' . PHP_EOL);

            exit(Result::FAILURE);
        }

        $codeCoverageFilterRegistry->init($this->options->configuration, true);
        if (! $codeCoverageFilterRegistry->configured()) {
            $output->write('Filter for code coverage has not been configured' . PHP_EOL);

            exit(Result::FAILURE);
        }

        $timer = new Timer();
        $timer->start();

        $waitForWarming = $coverageCacheWarmer->start(
            $this->options->configuration->coverageCacheDirectory(),
            ! $this->options->configuration->disableCodeCoverageIgnore(),
            $this->options->configuration->ignoreDeprecatedCodeUnitsFromCodeCoverage(),
            $codeCoverageFilterRegistry->get()->files(),
            $this->options->processes,
        );

        return static function () use ($output, $timer, $waitForWarming): void {
            $output->write('Warming cache for static analysis ... ');

            $statistics = $waitForWarming();

            $output->write(sprintf(
                '[%s]%s%s%d file%s processed, %d cache hit%s, %d cache miss%s%s',
                $timer->stop()->asString(),
                PHP_EOL,
                PHP_EOL,
                $statistics['cacheHits'] + $statistics['cacheMisses'],
                $statistics['cacheHits'] + $statistics['cacheMisses'] !== 1 ? 's' : '',
                $statistics['cacheHits'],
                $statistics['cacheHits'] !== 1 ? 's' : '',
                $statistics['cacheMisses'],
                $statistics['cacheMisses'] !== 1 ? 'es' : '',
                PHP_EOL,
            ));
        };
    }

    /** @return Generator<non-empty-string, (PhptTestCase|TestCase)> */
    private function loadFiles(TestSuite $testSuite): Generator
    {
        foreach ($testSuite as $test) {
            if ($test instanceof TestSuite) {
                yield from $this->loadFiles($test);

                continue;
            }

            if ($test instanceof PhptTestCase) {
                $refProperty = new ReflectionProperty(PhptTestCase::class, 'filename');
                $filename    = $refProperty->getValue($test);
                assert(is_string($filename) && $filename !== '');
                $filename = $this->stripCwd($filename);

                yield $filename => $test;

                continue;
            }

            if ($test instanceof TestCase) {
                $refClass = new ReflectionClass($test);
                $filename = $refClass->getFileName();
                assert(is_string($filename));
                $filename = $this->stripCwd($filename);

                yield $filename => $test;

                continue;
            }
        }
    }

    /**
     * @param non-empty-string $filename
     *
     * @return non-empty-string
     */
    private function stripCwd(string $filename): string
    {
        if (! str_starts_with($filename, $this->options->cwd)) {
            return $filename;
        }

        $substr = substr($filename, 1 + strlen($this->options->cwd));
        assert($substr !== '');

        return $substr;
    }

    private function shardTests(TestSuite $suite): void
    {
        $shards  = $this->options->totalShards;
        $current = $this->options->currentShard - 1; // 0 indexed. Shard 1 is in reality shard 0

        // With --functional, shard over individual test methods (values).
        // Without --functional, shard over class-level suites (keys) to keep whole files together.
        $items = $this->options->functional
            ? $this->extractTestsInSuite($suite)
            : $this->extractClassSuites($suite);

        $shardedItems = match ($this->options->shardDistribution) {
            ShardDistribution::Sequential => array_slice($items, (int) ceil(count($items) / $shards) * $current, (int) ceil(count($items) / $shards)),
            ShardDistribution::RoundRobin => array_values(array_filter($items, static fn (int $i): bool => $i % $shards === $current, ARRAY_FILTER_USE_KEY)),
            ShardDistribution::Random => $this->randomShardTests($items, $shards, $current),
        };

        $suite->setTests($shardedItems);
    }

    /**
     * @param list<Test> $tests
     *
     * @return list<Test>
     */
    private function randomShardTests(array $tests, int $shards, int $current): array
    {
        $randomizer = new Randomizer(new Mt19937($this->options->shardDistributionSeed));
        /** @var list<Test> $tests */
        $tests = $randomizer->shuffleArray($tests);

        return array_values(array_filter($tests, static fn (int $i): bool => $i % $shards === $current, ARRAY_FILTER_USE_KEY));
    }

    /** @return list<TestSuite> */
    private function extractClassSuites(TestSuite $suite): array
    {
        $classSuites = [];

        foreach ($suite->tests() as $item) {
            if (! ($item instanceof TestSuite)) {
                continue;
            }

            $children = $item->tests();
            if (
                $children !== []
                && $children[0] instanceof TestSuite
                && ! ($children[0] instanceof DataProviderTestSuite)
            ) {
                $classSuites = array_merge($classSuites, $this->extractClassSuites($item));
            } else {
                $classSuites[] = $item;
            }
        }

        return $classSuites;
    }

    /** @return list<Test> */
    private function extractTestsInSuite(TestSuite $suite): array
    {
        $extractedTests = [];
        $suiteItems     = $suite->tests();

        foreach ($suiteItems as $item) {
            if ($item instanceof TestSuite) {
                $extractedTests = array_merge($extractedTests, $this->extractTestsInSuite($item));
            } else {
                $extractedTests[] = $item;
            }
        }

        return $extractedTests;
    }
}
