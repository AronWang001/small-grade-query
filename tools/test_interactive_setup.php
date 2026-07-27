<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once __DIR__ . '/../app/Support/InteractiveSetup.php';

$failures = [];
$checks = 0;
$assert = static function (bool $condition, string $label) use (&$failures, &$checks): void {
    $checks++;
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$condition) {
        $failures[] = $label;
    }
};
$rejects = static function (callable $operation, string $label) use ($assert): void {
    try {
        $operation();
    } catch (InvalidArgumentException|RuntimeException) {
        $assert(true, $label);
        return;
    }
    $assert(false, $label);
};

$webUser = '';
if (PHP_OS_FAMILY !== 'Windows') {
    $webUser = InteractiveSetup::detectWebUser() ?? '';
    if ($webUser === '' && function_exists('posix_getpwuid')) {
        $currentAccount = posix_getpwuid(posix_geteuid());
        $webUser = is_array($currentAccount)
            ? (string) ($currentAccount['name'] ?? '')
            : '';
    }
}

$valid = InteractiveSetup::validate([
    'host' => '127.0.0.1',
    'port' => '3306',
    'name' => 'grade_query',
    'user' => 'grade_user',
    'password' => "db\"pass\\word=#",
    'url' => 'https://grades.example.test/',
    'web_user' => $webUser,
    'admin_username' => 'admin',
    'admin_password' => 'StrongAdmin2026',
]);
$assert($valid['name'] === 'grade_query', 'Valid database settings are accepted');
$assert($valid['url'] === 'https://grades.example.test', 'Application URL is normalized');
$assert($valid['admin_username'] === 'admin', 'Reserved admin name is allowed for installer');

$environment = InteractiveSetup::renderEnvironment(
    $valid,
    'base64:' . base64_encode(random_bytes(32))
);
$assert(str_contains($environment, 'APP_ENV="production"'), 'Production environment is generated');
$assert(str_contains($environment, 'TURNSTILE_ENABLED="0"'), 'Turnstile defaults to disabled');
$passwordKey = 'DB_' . 'PASSWORD=';
$passwordLine = null;
foreach (preg_split('/\R/', $environment) ?: [] as $line) {
    if (str_starts_with($line, $passwordKey)) {
        $passwordLine = $line;
        break;
    }
}
$assert(
    $passwordLine === $passwordKey . InteractiveSetup::quoteEnvironmentValue($valid['password']),
    'Database password is safely quoted'
);
$assert(!str_contains($environment, 'StrongAdmin2026'), 'Administrator password is not written to .env');

$rejects(
    static fn () => InteractiveSetup::validate(array_merge($valid, ['name' => 'bad-name'])),
    'Unsafe database name is rejected'
);
$rejects(
    static fn () => InteractiveSetup::validate(array_merge($valid, ['admin_password' => '123456'])),
    'Weak administrator password is rejected'
);
$rejects(
    static fn () => InteractiveSetup::validate(array_merge($valid, ['password' => "bad\nvalue"])),
    'Multiline database password is rejected'
);
if (PHP_OS_FAMILY !== 'Windows') {
    $rejects(
        static fn () => InteractiveSetup::validate(array_merge($valid, ['web_user' => 'root'])),
        'Root cannot be selected as the PHP-FPM worker user'
    );
}

$temporaryRoot = sys_get_temp_dir() . '/grade-query-setup-' . bin2hex(random_bytes(8));
mkdir($temporaryRoot, 0700, true);
$target = $temporaryRoot . '/.env';
try {
    InteractiveSetup::prepareApplicationRoot(
        $temporaryRoot,
        PHP_OS_FAMILY === 'Windows' ? null : $valid['web_user']
    );
    InteractiveSetup::writeEnvironmentAtomically(
        $target,
        $environment,
        PHP_OS_FAMILY === 'Windows' ? null : $valid['web_user']
    );
    $assert(is_file($target), 'Environment file is written atomically');
    $assert(file_get_contents($target) === $environment, 'Environment file contents are complete');
    $rejects(
        static fn () => InteractiveSetup::writeEnvironmentAtomically(
            $target,
            $environment,
            $valid['web_user']
        ),
        'Existing .env is never overwritten'
    );
    if (PHP_OS_FAMILY !== 'Windows') {
        $assert(
            ((fileperms($temporaryRoot) & 0777) & 0050) === 0050,
            'Application root is traversable and readable by the PHP-FPM group'
        );
        $assert(
            (fileperms($target) & 0777) === 0640,
            'Environment file grants read access only to owner and PHP-FPM group'
        );
    }

    $storage = $temporaryRoot . '/storage';
    mkdir($storage . '/logs', 0700, true);
    file_put_contents($storage . '/.gitkeep', '');
    InteractiveSetup::prepareWritableStorage($storage, $valid['web_user']);
    if (PHP_OS_FAMILY !== 'Windows') {
        $assert(
            (fileperms($storage) & 0777) === 0770
                && (fileperms($storage . '/.gitkeep') & 0777) === 0660,
            'Storage directories and files are group-writable for PHP-FPM'
        );
    }
} finally {
    if (is_dir($temporaryRoot . '/storage/logs')) {
        rmdir($temporaryRoot . '/storage/logs');
    }
    if (is_file($temporaryRoot . '/storage/.gitkeep')) {
        unlink($temporaryRoot . '/storage/.gitkeep');
    }
    if (is_dir($temporaryRoot . '/storage')) {
        rmdir($temporaryRoot . '/storage');
    }
    if (is_file($target)) {
        unlink($target);
    }
    rmdir($temporaryRoot);
}

$setupSource = file_get_contents(__DIR__ . '/../setup.php');
$installerSource = file_get_contents(__DIR__ . '/../app/Support/Installer.php');
$assert(
    is_string($setupSource)
        && str_contains($setupSource, '确认继续？输入 YES')
        && str_contains($setupSource, '目标数据库不是空库')
        && str_contains($setupSource, 'setupSecret')
        && str_contains($setupSource, 'prepareApplicationRoot')
        && str_contains($setupSource, 'prepareWritableStorage'),
    'Interactive setup includes confirmation, empty-database protection, hidden input and web permissions'
);
$assert(
    is_string($installerSource)
        && str_contains($installerSource, '$superAdministrator')
        && str_contains($installerSource, '$mustChangePassword = 0'),
    'Installer accepts a chosen super administrator without forcing password reset'
);

echo sprintf(
    "Interactive setup tests: %d check(s), %d failure(s).\n",
    $checks,
    count($failures)
);
exit($failures === [] ? 0 : 1);
