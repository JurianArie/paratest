<?php

declare(strict_types=1);

use ParaTest\WrapperRunner\CoverageCacheWarmer;

(static function (): void {
    // The statistics are written to stdout: keep warnings from mixing in
    ini_set('display_errors', 'stderr');

    $getopt = getopt('', [
        'cache-directory:',
        'use-annotations-for-ignoring-code',
        'ignore-deprecated-code',
    ]);

    $composerAutoloadFiles = [
        dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'autoload.php',
        dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php',
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php',
    ];

    foreach ($composerAutoloadFiles as $file) {
        if (file_exists($file)) {
            require_once $file;

            break;
        }
    }

    assert(isset($getopt['cache-directory']) && is_string($getopt['cache-directory']) && $getopt['cache-directory'] !== '');

    $files = file('php://stdin', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    assert(is_array($files));

    echo json_encode(
        CoverageCacheWarmer::warmFiles(
            $getopt['cache-directory'],
            isset($getopt['use-annotations-for-ignoring-code']),
            isset($getopt['ignore-deprecated-code']),
            $files,
        ),
        JSON_THROW_ON_ERROR,
    );
})();
