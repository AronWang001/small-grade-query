<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Parse command-line arguments
$mode = null;
$releasePath = null;
$argv = $argv ?? [];
$argc = count($argv);

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ($arg === '--runtime') {
        if ($mode !== null) {
            fwrite(STDERR, "ERROR: Cannot specify both --runtime and --release.
");
            exit(1);
        }
        $mode = 'runtime';
    } elseif (str_starts_with($arg, '--release=')) {
        if ($mode !== null) {
            fwrite(STDERR, "ERROR: Cannot specify both --runtime and --release.
");
            exit(1);
        }
        $mode = 'release';
        $releasePath = substr($arg, strlen('--release='));
    } elseif ($arg === '--release') {
        if ($mode !== null) {
            fwrite(STDERR, "ERROR: Cannot specify both --runtime and --release.
");
            exit(1);
        }
        $mode = 'release';
        $i++;
        if ($i >= $argc) {
            fwrite(STDERR, "ERROR: --release requires a path argument.
");
            exit(1);
        }
        $releasePath = $argv[$i];
    } else {
        fwrite(STDERR, "ERROR: Unknown argument: $arg
");
        fwrite(STDERR, "Usage: php tools/pre_open_source_check.php [--runtime | --release=/path | --release /path]
");
        exit(1);
    }
}

if ($mode === null) {
    fwrite(STDERR, "ERROR: No mode specified.
");
    fwrite(STDERR, "Usage: php tools/pre_open_source_check.php [--runtime | --release=/path | --release /path]
");
    exit(1);
}

if ($mode === 'release') {
    if (!$releasePath || $releasePath === '') {
        fwrite(STDERR, "ERROR: --release requires a valid directory path.
");
        exit(1);
    }
    $realPath = realpath($releasePath);
    if (!$realPath || !is_dir($realPath)) {
        fwrite(STDERR, "ERROR: --release path does not exist or is not a directory.
");
        exit(1);
    }
    $releasePath = $realPath;
}

$projectRoot = $mode === 'release' ? realpath($releasePath) : realpath(__DIR__ . '/..');
if (!$projectRoot) {
    fwrite(STDERR, "ERROR: Cannot resolve project root." . PHP_EOL);
    exit(1);
}

echo "Pre-Open-Source Security Check" . PHP_EOL;
echo str_repeat("=", 50) . PHP_EOL;
echo "Mode: " . $mode . PHP_EOL;
echo "Path: " . $projectRoot . PHP_EOL;
echo PHP_EOL;

$errors = 0;
$warnings = 0;

function check(string $label, bool $pass, string $detail = '', bool $isError = true): void {
    global $errors, $warnings;
    echo ($pass ? "[PASS]" : ($isError ? "[FAIL]" : "[WARN]")) . " " . $label;
    if ($detail) echo " - " . $detail;
    echo PHP_EOL;
    if (!$pass) {
        if ($isError) $errors++; else $warnings++;
    }
}

/**
 * @return Generator<int,SplFileInfo>
 */
function projectFiles(string $root): Generator
{
    $directory = new RecursiveDirectoryIterator(
        $root,
        RecursiveDirectoryIterator::SKIP_DOTS
    );
    $filter = new RecursiveCallbackFilterIterator(
        $directory,
        static function (SplFileInfo $item): bool {
            if (
                $item->isDir()
                && in_array($item->getFilename(), ['vendor', '.git', 'node_modules'], true)
            ) {
                return false;
            }
            return true;
        }
    );
    foreach (new RecursiveIteratorIterator($filter) as $file) {
        if ($file instanceof SplFileInfo && $file->isFile()) {
            yield $file;
        }
    }
}

function relativeProjectPath(string $root, string $path): string
{
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $path = str_replace('\\', '/', $path);
    return ltrim(substr($path, strlen($root)), '/');
}

// 1. Check .env
$envFile = $projectRoot . '/.env';
if ($mode === 'release') {
    check('.env absent', !file_exists($envFile), 'Release must not contain .env');
} else {
    check('.env present', file_exists($envFile), 'Runtime needs .env', false);
}

// 2. Check for running data
$storageDirs = ['imports', 'logs', 'legacy', 'query-drafts', 'admin-secrets'];
foreach ($storageDirs as $dir) {
    $d = $projectRoot . '/storage/' . $dir;
    if (!is_dir($d)) continue;
    $files = array_diff(scandir($d) ?: [], ['.', '..', '.gitkeep']);
    $dataFiles = array_filter($files, fn($f) => !str_starts_with($f, '.'));
    if ($mode === 'release') {
        check("storage/$dir clean", count($dataFiles) === 0, count($dataFiles) . ' data files found');
    }
}

// 3. Recursively reject secrets, runtime data, backups and archives.
if ($mode === 'release') {
    $forbiddenFiles = [];
    foreach (projectFiles($projectRoot) as $file) {
        $relative = relativeProjectPath($projectRoot, $file->getPathname());
        $relativeLower = strtolower($relative);
        $nameLower = strtolower($file->getFilename());

        if ($nameLower === '.env.example') {
            continue;
        }
        $allowedSql = $relativeLower === 'database/schema.sql'
            || (
                str_starts_with($relativeLower, 'database/migrations/')
                && str_ends_with($relativeLower, '.sql')
            );

        $forbidden = $nameLower === '.env'
            || str_starts_with($nameLower, '.env.')
            || preg_match('/\.log$/i', $nameLower) === 1
            || preg_match('/\.sql$/i', $nameLower) === 1 && !$allowedSql
            || preg_match('/\.(sqlite|sqlite3|xlsx|xls|csv|old|orig|tar|tgz|zip|rar|7z|dump|pem|key)$/i', $nameLower) === 1
            || preg_match('/\.bak(?:\.|$)/i', $nameLower) === 1
            || preg_match('/\.tar\.gz$/i', $nameLower) === 1;

        if ($forbidden) {
            $forbiddenFiles[] = $relative;
        }
    }
    sort($forbiddenFiles, SORT_STRING);
    check(
        'No forbidden files in nested release directories',
        $forbiddenFiles === [],
        count($forbiddenFiles) . ' file(s): '
            . implode(', ', array_slice($forbiddenFiles, 0, 20))
    );
}

// 4. Check .gitignore
$gitignore = $projectRoot . '/.gitignore';
check('.gitignore present', file_exists($gitignore));
if (file_exists($gitignore)) {
    $content = file_get_contents($gitignore);
    check('.gitignore covers .env', str_contains($content, '.env'));
    check('.gitignore covers storage/*', str_contains($content, 'storage'));
}

// 5. Check APP_KEY (runtime mode only)
if ($mode === 'runtime') {
    try {
        require_once $projectRoot . '/app/bootstrap.php';
        $key = app_key();
    $keyHex = bin2hex($key);
    $isWeak = false;
    // Check for all zeros
    if (trim($keyHex, '0') === '') $isWeak = true;
    // Check for all same byte
    $firstByte = $key[0];
    $allSame = true;
    for ($i = 1; $i < strlen($key); $i++) {
        if ($key[$i] !== $firstByte) { $allSame = false; break; }
    }
    if ($allSame) $isWeak = true;
    // Check length
    if (strlen($key) < 32) $isWeak = true;
    
        check('APP_KEY is strong', !$isWeak, $isWeak ? 'Weak key detected! Use tools/generate_app_key.php' : '');
    } catch (Throwable $e) {
        check('APP_KEY validation', false, $e->getMessage());
    }
} else {
    // Release mode: scan code for hardcoded keys
    $keyPatterns = [
        '/APP_KEY\s*=\s*[^\s]{16,}/' => 'Hardcoded APP_KEY in config files',
        '/DB_PASSWORD\s*=\s*[^\s]{3,}/' => 'Hardcoded DB password',
        '/TURNSTILE_SECRET_KEY\s*=\s*[^\s]{3,}/' => 'Hardcoded Turnstile secret',
        '/MAIL_PASSWORD\s*=\s*[^\s]{3,}/' => 'Hardcoded SMTP password',
    ];
    foreach ($keyPatterns as $pattern => $label) {
        $found = [];
        foreach (projectFiles($projectRoot) as $file) {
            if (preg_match('/\.(php|env|md|yml|yaml|example)$/i', $file->getFilename()) !== 1) {
                continue;
            }
            $content = file_get_contents($file->getPathname());
            if (preg_match($pattern, $content)) {
                // Don not flag .env.example
                if (!str_ends_with($file->getFilename(), '.env.example')) {
                    $found[] = $file->getFilename();
                }
            }
        }
        check("No $label", count($found) === 0, count($found) . ' files: ' . implode(', ', array_slice($found, 0, 5)));
    }
}

// 6. Check SECURITY.md
check('SECURITY.md present', file_exists($projectRoot . '/SECURITY.md'));

// 7. Check composer.lock
check('composer.lock present', file_exists($projectRoot . '/composer.lock'));

// 8. Check for fixed default passwords
$installFile = $projectRoot . '/tools/install.php';
if (file_exists($installFile)) {
    $content = file_get_contents($installFile);
    $hasFixedPassword = str_contains($content, '123456') || str_contains($content, "password_hash('admin");
    check('No fixed default password', !$hasFixedPassword, $hasFixedPassword ? 'Found hardcoded password pattern' : '');
}

// 9. Check PhpSpreadsheet version
$composerLock = $projectRoot . '/composer.lock';
if (file_exists($composerLock)) {
    $lock = json_decode(file_get_contents($composerLock), true);
    $packages = $lock['packages'] ?? [];
    foreach ($packages as $pkg) {
        if (($pkg['name'] ?? '') === 'phpoffice/phpspreadsheet') {
            $ver = $pkg['version'] ?? 'unknown';
            check('PhpSpreadsheet >= 5.8.1', version_compare($ver, '5.8.1', '>='), "Version: $ver");
        }
    }
}

// 9b. Scan for ID card patterns (release only)
if ($mode === 'release') {
    $idPattern = '/[1-9]\d{5}(19|20)\d{2}(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])\d{3}[\dXx]/';
    $idFound = [];
    foreach (projectFiles($projectRoot) as $file) {
        $content = file_get_contents($file->getPathname());
        if ($content !== false && preg_match($idPattern, $content)) {
            $idFound[] = relativeProjectPath($projectRoot, $file->getPathname());
        }
    }
    check('No ID card numbers', count($idFound) === 0, count($idFound) . ' files with possible ID cards');
}

// 10. Check GitHub files
check('.github/workflows exists', is_dir($projectRoot . '/.github/workflows'));

// 11. Check UTF-8 text files for replacement question runs, BOMs and controls.
$encodingIssues = [];
$textExtensions = ['php', 'js', 'css', 'md', 'sql', 'json', 'yml', 'yaml', 'example'];
foreach (projectFiles($projectRoot) as $file) {
    $path = str_replace('\\', '/', $file->getPathname());
    if (
        str_contains($path, '/storage/')
    ) {
        continue;
    }
    $extension = strtolower($file->getExtension());
    if (!in_array($extension, $textExtensions, true)) {
        continue;
    }
    $content = file_get_contents($file->getPathname());
    if (!is_string($content)) {
        $encodingIssues[] = $file->getFilename() . ': unreadable';
        continue;
    }
    $hasBom = str_starts_with($content, "\xEF\xBB\xBF");
    $hasQuestionRun = preg_match('/\?{3,}/', $content) === 1;
    $hasControl = preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $content) === 1;
    if ($hasBom || $hasQuestionRun || $hasControl) {
        $types = [];
        if ($hasBom) {
            $types[] = 'BOM';
        }
        if ($hasQuestionRun) {
            $types[] = 'question-run';
        }
        if ($hasControl) {
            $types[] = 'control-character';
        }
        $encodingIssues[] = $file->getFilename() . ': ' . implode(',', $types);
    }
}
check(
    'No damaged text or UTF-8 BOM',
    $encodingIssues === [],
    count($encodingIssues) . ' files: ' . implode(', ', array_slice($encodingIssues, 0, 8))
);

echo PHP_EOL;
echo str_repeat("=", 50) . PHP_EOL;
echo "Results: $errors error(s), $warnings warning(s)" . PHP_EOL;

exit($errors > 0 ? 1 : 0);
