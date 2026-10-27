<?php

declare(strict_types=1);

namespace ParaTest\WrapperRunner;

use RuntimeException;
use SebastianBergmann\CodeCoverage\StaticAnalysis\CachingSourceAnalyser;
use SebastianBergmann\CodeCoverage\StaticAnalysis\ParsingSourceAnalyser;
use Symfony\Component\Process\Process;

use function array_fill;
use function assert;
use function count;
use function dirname;
use function file_get_contents;
use function implode;
use function is_array;
use function is_int;
use function json_decode;
use function min;
use function realpath;
use function sprintf;

use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;

/**
 * Warms the static analysis cache like PHPUnit's CacheWarmer, but spreads the files over several processes
 *
 * @internal
 */
final readonly class CoverageCacheWarmer
{
    /** @param non-empty-list<non-empty-string> $phpCommand PHP binary and arguments to start a warming process with */
    public function __construct(
        private array $phpCommand,
    ) {
    }

    /**
     * @param non-empty-string $cacheDirectory
     * @param list<string>     $files
     *
     * @return array{cacheHits: non-negative-int, cacheMisses: non-negative-int}
     */
    public function warm(
        string $cacheDirectory,
        bool $useAnnotationsForIgnoringCode,
        bool $ignoreDeprecatedCode,
        array $files,
        int $processes,
    ): array {
        $processes = min($processes, count($files));
        if ($processes <= 1) {
            return self::warmFiles($cacheDirectory, $useAnnotationsForIgnoringCode, $ignoreDeprecatedCode, $files);
        }

        // Round-robin, so the large files of a directory don't all end up in the same process
        $chunks = array_fill(0, $processes, []);
        foreach ($files as $index => $file) {
            $chunks[$index % $processes][] = $file;
        }

        $script = realpath(
            dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'warm-coverage-cache.php',
        );
        assert($script !== false);

        $command = [...$this->phpCommand, $script, '--cache-directory', $cacheDirectory];
        if ($useAnnotationsForIgnoringCode) {
            $command[] = '--use-annotations-for-ignoring-code';
        }

        if ($ignoreDeprecatedCode) {
            $command[] = '--ignore-deprecated-code';
        }

        $warmingProcesses = [];
        foreach ($chunks as $chunk) {
            $process = new Process($command, null, null, implode("\n", $chunk), null);
            $process->start();
            $warmingProcesses[] = $process;
        }

        $cacheHits   = 0;
        $cacheMisses = 0;
        foreach ($warmingProcesses as $process) {
            $process->wait();
            if (! $process->isSuccessful()) {
                throw new RuntimeException(sprintf(
                    "Warming the static analysis cache failed:\n%s%s",
                    $process->getOutput(),
                    $process->getErrorOutput(),
                ));
            }

            $statistics = json_decode($process->getOutput(), true, 2, JSON_THROW_ON_ERROR);
            assert(is_array($statistics));
            assert(is_int($statistics['cacheHits']) && $statistics['cacheHits'] >= 0);
            assert(is_int($statistics['cacheMisses']) && $statistics['cacheMisses'] >= 0);

            $cacheHits   += $statistics['cacheHits'];
            $cacheMisses += $statistics['cacheMisses'];
        }

        return ['cacheHits' => $cacheHits, 'cacheMisses' => $cacheMisses];
    }

    /**
     * @param non-empty-string      $cacheDirectory
     * @param iterable<int, string> $files
     *
     * @return array{cacheHits: non-negative-int, cacheMisses: non-negative-int}
     */
    public static function warmFiles(
        string $cacheDirectory,
        bool $useAnnotationsForIgnoringCode,
        bool $ignoreDeprecatedCode,
        iterable $files,
    ): array {
        $analyser = new CachingSourceAnalyser($cacheDirectory, new ParsingSourceAnalyser());

        foreach ($files as $file) {
            if ($file === '') {
                continue;
            }

            $sourceCode = file_get_contents($file);
            if ($sourceCode === false) {
                continue;
            }

            $analyser->analyse($file, $sourceCode, $useAnnotationsForIgnoringCode, $ignoreDeprecatedCode);
        }

        return ['cacheHits' => $analyser->cacheHits(), 'cacheMisses' => $analyser->cacheMisses()];
    }
}
