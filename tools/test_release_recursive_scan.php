<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$releasePath = null;
foreach (array_slice($argv ?? [], 1) as $index => $argument) {
    if (str_starts_with((string) $argument, '--release=')) {
        $releasePath = substr((string) $argument, strlen('--release='));
    } elseif ($argument === '--release') {
        $releasePath = (string) (($argv ?? [])[$index + 2] ?? '');
    }
}
if (!is_string($releasePath) || $releasePath === '') {
    fwrite(STDERR, "Usage: php tools/test_release_recursive_scan.php --release=/absolute/path\n");
    exit(2);
}
$releaseRoot = realpath($releasePath);
$applicationRoot = realpath(dirname(__DIR__));
if (
    !is_string($releaseRoot)
    || !is_dir($releaseRoot)
    || !is_string($applicationRoot)
    || hash_equals($applicationRoot, $releaseRoot)
) {
    fwrite(STDERR, "[FAIL] Release scan fixture requires a separate existing release directory.\n");
    exit(2);
}

/**
 * @return array{exit:int,output:string}
 */
function runReleaseScan(string $applicationRoot, string $releaseRoot): array
{
    $command = [
        PHP_BINARY,
        $applicationRoot . '/tools/pre_open_source_check.php',
        '--release=' . $releaseRoot,
    ];
    $pipes = [];
    $process = proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $applicationRoot
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start release scanner.');
    }
    fclose($pipes[0]);
    $output = (string) stream_get_contents($pipes[1])
        . (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'output' => $output];
}

$relativeFiles = [
    'app/test.bak.20260727',
    'tools/archive.tar.gz',
    'database/backup.zip',
    'assets/debug.log',
];
$created = [];
$failures = [];
$assert = static function (bool $condition, string $label) use (&$failures): void {
    echo sprintf("%s %s\n", $condition ? '[PASS]' : '[FAIL]', $label);
    if (!$condition) {
        $failures[] = $label;
    }
};

try {
    foreach ($relativeFiles as $relative) {
        $path = $releaseRoot . '/' . $relative;
        if (file_exists($path)) {
            throw new RuntimeException('Release scan fixture would overwrite an existing file.');
        }
        $parent = dirname($path);
        $parentReal = realpath($parent);
        if (
            !is_string($parentReal)
            || !str_starts_with(
                str_replace('\\', '/', $parentReal) . '/',
                rtrim(str_replace('\\', '/', $releaseRoot), '/') . '/'
            )
        ) {
            throw new RuntimeException('Release scan fixture path is outside the release root.');
        }
        if (file_put_contents($path, "synthetic release scanner fixture\n") === false) {
            throw new RuntimeException('Unable to write release scan fixture.');
        }
        $created[] = $path;
    }

    $negative = runReleaseScan($applicationRoot, $releaseRoot);
    $allReported = $negative['exit'] !== 0;
    foreach ($relativeFiles as $relative) {
        $allReported = $allReported && str_contains($negative['output'], $relative);
    }
    $assert($allReported, 'Nested backup and log fixtures make the release scan fail');
} catch (Throwable $exception) {
    $failures[] = 'Unexpected negative scan failure: ' . get_class($exception);
} finally {
    foreach ($created as $path) {
        if (is_file($path) && !is_link($path)) {
            unlink($path);
        }
    }
}

try {
    $positive = runReleaseScan($applicationRoot, $releaseRoot);
    $assert(
        $positive['exit'] === 0,
        'Release scan passes again after exact fixture cleanup'
    );
} catch (Throwable $exception) {
    $failures[] = 'Unexpected positive scan failure: ' . get_class($exception);
}

echo sprintf("Recursive release scan tests: %d failure(s).\n", count($failures));
exit($failures === [] ? 0 : 1);
