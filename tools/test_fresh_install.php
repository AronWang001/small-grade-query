<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once __DIR__ . '/../app/bootstrap.php';
require_once APP_ROOT . '/app/Support/Installer.php';

/**
 * @return array{database:string,allow_existing_empty:bool,drop_after_test:bool}
 */
function parseFreshInstallArguments(array $arguments): array
{
    $result = [
        'database' => 'securitytest_fas_install_test',
        'allow_existing_empty' => false,
        'drop_after_test' => false,
    ];

    for ($index = 0, $count = count($arguments); $index < $count; $index++) {
        $argument = (string) $arguments[$index];
        if (str_starts_with($argument, '--database=')) {
            $result['database'] = substr($argument, strlen('--database='));
            continue;
        }
        if ($argument === '--database') {
            $index++;
            if ($index >= $count) {
                throw new InvalidArgumentException('--database requires a value.');
            }
            $result['database'] = (string) $arguments[$index];
            continue;
        }
        if ($argument === '--allow-existing-empty') {
            $result['allow_existing_empty'] = true;
            continue;
        }
        if ($argument === '--drop-after-test') {
            $result['drop_after_test'] = true;
            continue;
        }
        throw new InvalidArgumentException('Unknown argument: ' . $argument);
    }

    return $result;
}

function assertSafeInstallTestDatabase(string $database, string $currentDatabase): void
{
    $reserved = [
        '',
        $currentDatabase,
        'securitytest_fas',
        'mysql',
        'information_schema',
        'performance_schema',
        'sys',
    ];
    if (in_array(strtolower($database), array_map('strtolower', $reserved), true)) {
        throw new RuntimeException('Refusing to use a protected or active database.');
    }
    if (
        preg_match('/^[a-z0-9_]{8,64}$/', $database) !== 1
        || !str_starts_with($database, 'securitytest_')
        || !str_ends_with($database, '_install_test')
    ) {
        throw new RuntimeException(
            'Install-test database must start with securitytest_ and end with _install_test.'
        );
    }
}

/** @return array<string,array{count:int,max_id:int,max_updated:string}> */
function currentDatabaseFingerprint(PDO $pdo): array
{
    $fingerprint = [];
    foreach (['admin_users', 'query_projects', 'query_records', 'schema_migrations'] as $table) {
        $columns = $pdo->prepare(
            'SELECT COLUMN_NAME
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $columns->execute([$table]);
        $available = array_map('strval', $columns->fetchAll(PDO::FETCH_COLUMN));
        if ($available === []) {
            $fingerprint[$table] = ['count' => -1, 'max_id' => -1, 'max_updated' => ''];
            continue;
        }
        $maxId = in_array('id', $available, true) ? 'COALESCE(MAX(id), 0)' : '0';
        $maxUpdated = in_array('updated_at', $available, true)
            ? "COALESCE(DATE_FORMAT(MAX(updated_at), '%Y-%m-%d %H:%i:%s'), '')"
            : "''";
        $row = $pdo->query(
            "SELECT COUNT(*) AS row_count,
                    $maxId AS max_id,
                    $maxUpdated AS max_updated
             FROM `$table`"
        )->fetch(PDO::FETCH_ASSOC);
        $fingerprint[$table] = [
            'count' => (int) ($row['row_count'] ?? -1),
            'max_id' => (int) ($row['max_id'] ?? -1),
            'max_updated' => (string) ($row['max_updated'] ?? ''),
        ];
    }
    return $fingerprint;
}

try {
    $options = parseFreshInstallArguments(array_slice($argv ?? [], 1));
} catch (Throwable $exception) {
    fwrite(STDERR, '[FAIL] ' . $exception->getMessage() . PHP_EOL);
    fwrite(
        STDERR,
        "Usage: php tools/test_fresh_install.php [--database=name|--database name] [--allow-existing-empty] [--drop-after-test]\n"
    );
    exit(2);
}

$currentDatabase = env_value('DB_NAME', '') ?? '';
if (app_env() !== 'testing' || $currentDatabase !== 'securitytest_fas') {
    fwrite(STDERR, "[FAIL] Fresh-install tests require the securitytest_fas testing environment.\n");
    exit(2);
}

$databaseName = $options['database'];
try {
    assertSafeInstallTestDatabase($databaseName, $currentDatabase);
} catch (Throwable $exception) {
    fwrite(STDERR, '[FAIL] ' . $exception->getMessage() . PHP_EOL);
    exit(2);
}

$control = db();
$originalFingerprint = currentDatabaseFingerprint($control);
$exists = $control->prepare(
    'SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?'
);
$exists->execute([$databaseName]);
$databaseExists = (int) $exists->fetchColumn() === 1;
$createdByTest = false;

if ($databaseExists && !$options['allow_existing_empty']) {
    fwrite(
        STDERR,
        "[SKIP] Target database exists. Re-run with --allow-existing-empty only after confirming it is disposable and empty.\n"
    );
    exit(2);
}

if (!$databaseExists) {
    try {
        $control->exec(
            sprintf(
                'CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                $databaseName
            )
        );
        $createdByTest = true;
    } catch (Throwable) {
        $databaseUser = (string) $control->query('SELECT CURRENT_USER()')->fetchColumn();
        $host = str_contains($databaseUser, '@')
            ? substr($databaseUser, strrpos($databaseUser, '@') + 1)
            : 'localhost';
        $user = str_contains($databaseUser, '@')
            ? substr($databaseUser, 0, strrpos($databaseUser, '@'))
            : $databaseUser;
        fwrite(STDERR, "[SKIP] The test account cannot create the independent database.\n");
        fwrite(STDERR, "Run the following as a database administrator, then rerun with --allow-existing-empty:\n");
        fwrite(
            STDERR,
            sprintf(
                "CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n",
                $databaseName
            )
        );
        fwrite(
            STDERR,
            sprintf(
                "GRANT ALL PRIVILEGES ON `%s`.* TO '%s'@'%s';\nFLUSH PRIVILEGES;\n",
                $databaseName,
                str_replace("'", "''", $user),
                str_replace("'", "''", $host)
            )
        );
        exit(2);
    }
}

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    env_value('DB_HOST', '127.0.0.1'),
    (int) (env_value('DB_PORT', '3306') ?? '3306'),
    $databaseName
);
$testPdo = new PDO(
    $dsn,
    env_value('DB_USER', ''),
    env_value('DB_PASSWORD', ''),
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

if ($databaseExists) {
    $tables = $testPdo->prepare(
        'SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = ?'
    );
    $tables->execute([$databaseName]);
    if ((int) $tables->fetchColumn() !== 0) {
        fwrite(STDERR, "[FAIL] Existing install-test database is not empty; no changes were made.\n");
        exit(2);
    }
}

$failures = [];
$assert = static function (bool $condition, string $label) use (&$failures): void {
    echo sprintf("%s %s\n", $condition ? '[PASS]' : '[FAIL]', $label);
    if (!$condition) {
        $failures[] = $label;
    }
};
$temporaryMigrationDirectory = null;

try {
    $first = Installer::install(
        $testPdo,
        APP_ROOT . '/database/schema.sql',
        APP_ROOT . '/database/migrations',
        static function (): void {
        }
    );
    $assert($first['admin_created'] === true, 'Fresh install creates a super administrator');
    $assert(
        is_string($first['temporary_password'])
            && strlen($first['temporary_password']) >= 12
            && !hash_equals('123456', $first['temporary_password']),
        'Fresh install creates a non-default strong temporary password'
    );

    $runner = new MigrationRunner(
        $testPdo,
        APP_ROOT . '/database/migrations',
        static function (): void {
        }
    );
    [$tableCount, $columnCount] = Installer::verifyRequiredStructure($runner);
    $assert($tableCount === 15 && $columnCount === 15, 'All required tables and columns exist');
    $migrationCount = (int) $testPdo->query(
        'SELECT COUNT(*) FROM schema_migrations'
    )->fetchColumn();
    $assert(
        $migrationCount === count($runner->discoverMigrations()),
        'Every discovered migration is recorded exactly once'
    );

    $admin = $testPdo->query(
        "SELECT password_hash, role, status, must_change_password
         FROM admin_users WHERE username_normalized = 'admin'"
    )->fetch(PDO::FETCH_ASSOC);
    $assert(
        is_array($admin)
            && $admin['role'] === 'super_admin'
            && $admin['status'] === 'active',
        'Fresh install creates an active super administrator'
    );
    $assert(
        is_array($admin)
            && (int) $admin['must_change_password'] === 1
            && password_verify(
                (string) $first['temporary_password'],
                (string) $admin['password_hash']
            ),
        'Temporary password verifies and requires first-login password change'
    );
    $originalHash = is_array($admin) ? (string) $admin['password_hash'] : '';

    $second = Installer::install(
        $testPdo,
        APP_ROOT . '/database/schema.sql',
        APP_ROOT . '/database/migrations',
        static function (): void {
        }
    );
    $currentHash = (string) $testPdo->query(
        "SELECT password_hash FROM admin_users WHERE username_normalized = 'admin'"
    )->fetchColumn();
    $assert($second['admin_created'] === false, 'Repeated install preserves existing administrators');
    $assert(
        $originalHash !== '' && hash_equals($originalHash, $currentHash),
        'Repeated install does not reset the administrator password'
    );

    $third = $runner->runPendingMigrations();
    $assert($third['executed'] === [], 'Repeated migrate executes no migration twice');
    $assert(
        count($third['skipped']) === count($runner->discoverMigrations()),
        'Repeated migrate reports every migration as skipped'
    );

    $temporaryMigrationDirectory = APP_ROOT
        . '/storage/query-drafts/migration-failure-test-'
        . bin2hex(random_bytes(8));
    if (
        !mkdir($temporaryMigrationDirectory, 0770, true)
        && !is_dir($temporaryMigrationDirectory)
    ) {
        throw new RuntimeException('Unable to create an isolated migration test directory.');
    }
    $failureName = '99999999_expected_failure.sql';
    $failurePath = $temporaryMigrationDirectory . '/' . $failureName;
    if (file_put_contents(
        $failurePath,
        'SELECT * FROM table_that_must_not_exist_for_install_test;'
    ) === false) {
        throw new RuntimeException('Unable to write an isolated failure migration.');
    }
    $failureRunner = new MigrationRunner(
        $testPdo,
        $temporaryMigrationDirectory,
        static function (): void {
        }
    );
    $failedAsExpected = false;
    try {
        $failureRunner->runPendingMigrations();
    } catch (MigrationExecutionException) {
        $failedAsExpected = true;
    }
    $notRecorded = $testPdo->prepare(
        'SELECT COUNT(*) FROM schema_migrations WHERE migration = ?'
    );
    $notRecorded->execute([$failureName]);
    $assert(
        $failedAsExpected && (int) $notRecorded->fetchColumn() === 0,
        'Failed migration is not recorded'
    );

    $assert(
        currentDatabaseFingerprint($control) === $originalFingerprint,
        'Active securitytest_fas database remains unchanged'
    );
} catch (Throwable $exception) {
    $failures[] = 'Unexpected test failure: ' . get_class($exception);
    fwrite(STDERR, "[FAIL] Fresh-install test aborted.\n");
} finally {
    if (is_string($temporaryMigrationDirectory)) {
        $failurePath = $temporaryMigrationDirectory . '/99999999_expected_failure.sql';
        if (is_file($failurePath)) {
            unlink($failurePath);
        }
        if (is_dir($temporaryMigrationDirectory)) {
            rmdir($temporaryMigrationDirectory);
        }
    }
    $testPdo = null;
    if ($options['drop_after_test']) {
        try {
            assertSafeInstallTestDatabase($databaseName, $currentDatabase);
            $control->exec(sprintf('DROP DATABASE `%s`', $databaseName));
        } catch (Throwable) {
            $failures[] = 'Install-test database cleanup failed';
        }
    }
}

echo sprintf(
    "Fresh-install tests: %d failure(s); database retained=%s; created_by_test=%s.\n",
    count($failures),
    $options['drop_after_test'] ? 'no' : 'yes',
    $createdByTest ? 'yes' : 'no'
);
exit($failures === [] ? 0 : 1);
