<?php

declare(strict_types=1);

namespace ParaTest\Tests\Unit\WrapperRunner;

use ParaTest\Tests\TestBase;
use ParaTest\WrapperRunner\CoverageCacheWarmer;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

use function basename;
use function count;
use function file_get_contents;
use function glob;
use function sort;

use const DIRECTORY_SEPARATOR;
use const PHP_BINARY;

#[CoversClass(CoverageCacheWarmer::class)]
final class CoverageCacheWarmerTest extends TestBase
{
    public function testWarmsTheCacheInParallel(): void
    {
        $files          = $this->sourceFiles();
        $cacheDirectory = $this->tmpDir . DIRECTORY_SEPARATOR . 'parallel';
        $warmer         = new CoverageCacheWarmer([PHP_BINARY]);

        self::assertSame(
            ['cacheHits' => 0, 'cacheMisses' => count($files)],
            $warmer->warm($cacheDirectory, true, false, $files, 3),
        );
        self::assertSame(
            ['cacheHits' => count($files), 'cacheMisses' => 0],
            $warmer->warm($cacheDirectory, true, false, $files, 3),
        );
    }

    public function testParallelWarmingWritesTheSameCacheAsASingleProcess(): void
    {
        $files             = $this->sourceFiles();
        $parallelDirectory = $this->tmpDir . DIRECTORY_SEPARATOR . 'parallel';
        $singleDirectory   = $this->tmpDir . DIRECTORY_SEPARATOR . 'single';

        (new CoverageCacheWarmer([PHP_BINARY]))->warm($parallelDirectory, true, true, $files, 3);
        CoverageCacheWarmer::warmFiles($singleDirectory, true, true, $files);

        self::assertSame($this->cacheContents($singleDirectory), $this->cacheContents($parallelDirectory));
    }

    public function testThrowsWhenAWarmingProcessFails(): void
    {
        $warmer = new CoverageCacheWarmer([PHP_BINARY, '-r', 'exit(1);']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Warming the static analysis cache failed/');

        $warmer->warm($this->tmpDir, true, false, $this->sourceFiles(), 2);
    }

    /** @return list<string> */
    private function sourceFiles(): array
    {
        $files = glob($this->fixture('common_results') . DIRECTORY_SEPARATOR . '*.php');
        self::assertNotFalse($files);
        self::assertGreaterThan(3, count($files));

        return $files;
    }

    /** @return array<string, string|false> */
    private function cacheContents(string $directory): array
    {
        $cacheFiles = glob($directory . DIRECTORY_SEPARATOR . '*');
        self::assertNotFalse($cacheFiles);
        sort($cacheFiles);

        $contents = [];
        foreach ($cacheFiles as $cacheFile) {
            $contents[basename($cacheFile)] = file_get_contents($cacheFile);
        }

        return $contents;
    }
}
