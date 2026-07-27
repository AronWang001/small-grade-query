<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Support/Installer.php';

$arguments = array_slice($argv ?? [], 1);
$allowProduction = in_array('--allow-production', $arguments, true);
$expectedDatabase = null;
$expectedRoot = null;
foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--expected-database=')) {
        $expectedDatabase = substr($argument, strlen('--expected-database='));
    } elseif (str_starts_with($argument, '--expected-root=')) {
        $expectedRoot = substr($argument, strlen('--expected-root='));
    } elseif ($argument !== '--allow-production') {
        fwrite(STDERR, 'Unknown argument: ' . $argument . PHP_EOL);
        exit(2);
    }
}

try {
    $root = realpath(APP_ROOT);
    if (!is_string($root) || $root === '') {
        throw new RuntimeException('Unable to resolve the application root.');
    }
    if ($expectedRoot !== null) {
        $expectedRootReal = realpath($expectedRoot);
        if (!is_string($expectedRootReal) || !hash_equals($expectedRootReal, $root)) {
            throw new RuntimeException('Application root does not match --expected-root.');
        }
    }

    $environment = app_env();
    if ($environment === 'production' && !$allowProduction) {
        throw new RuntimeException(
            'APP_ENV is production. Re-run with --allow-production only after verifying the target database and backup.'
        );
    }

    $configuredDatabase = env_value('DB_NAME', '') ?? '';
    if ($configuredDatabase === '') {
        throw new RuntimeException('DB_NAME is not configured.');
    }
    if ($expectedDatabase !== null && !hash_equals($expectedDatabase, $configuredDatabase)) {
        throw new RuntimeException('Configured database does not match --expected-database.');
    }

    app_key();
    $pdo = db();
    $connectedDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    if (!hash_equals($configuredDatabase, $connectedDatabase)) {
        throw new RuntimeException('Connected database does not match DB_NAME.');
    }

    fwrite(STDOUT, 'Application root: ' . $root . PHP_EOL);
    fwrite(STDOUT, 'APP_ENV: ' . $environment . PHP_EOL);
    fwrite(STDOUT, 'Database: ' . $connectedDatabase . PHP_EOL);

    $result = Installer::install(
        $pdo,
        APP_ROOT . '/database/schema.sql',
        APP_ROOT . '/database/migrations'
    );

    fwrite(
        STDOUT,
        sprintf(
            "Installation complete: %d tables and %d required columns verified.%s",
            $result['tables_verified'],
            $result['columns_verified'],
            PHP_EOL
        )
    );
    if ($result['admin_created']) {
        fwrite(STDOUT, 'Super administrator username: ' . $result['admin_username'] . PHP_EOL);
        fwrite(STDOUT, 'Temporary password (shown once): ' . $result['temporary_password'] . PHP_EOL);
        fwrite(STDOUT, 'The administrator must change this password at first login.' . PHP_EOL);
    } else {
        fwrite(STDOUT, 'Existing administrator accounts were preserved; no password or role was changed.' . PHP_EOL);
    }
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Installation failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
