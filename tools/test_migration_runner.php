<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once __DIR__ . '/../app/bootstrap.php';
require_once APP_ROOT . '/app/Support/MigrationRunner.php';

$suffix = strtolower(bin2hex(random_bytes(4)));
$directory = APP_ROOT . '/storage/test-migrations-' . $suffix;
$table = 'codex_migration_ok_' . $suffix;
$failureTable = 'codex_migration_fail_' . $suffix;
$verifyTable = 'codex_migration_verify_' . $suffix;
$migration = '01_comment_' . $suffix . '.sql';
$failureMigration = '02_failure_' . $suffix . '.sql';
$verifyMigration = '03_verify_' . $suffix . '.sql';
$failures = [];

$assert = static function (bool $condition, string $label) use (&$failures): void {
    echo sprintf("%s %s\n", $condition ? '[PASS]' : '[FAIL]', $label);
    if (!$condition) {
        $failures[] = $label;
    }
};

$write = static function (string $path, string $content): void {
    if (file_put_contents($path, $content, LOCK_EX) === false) {
        throw new RuntimeException('Unable to create a temporary migration fixture.');
    }
};

try {
    if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the migration fixture directory.');
    }
    $write(
        $directory . '/' . $migration,
        "-- A leading comment must not hide CREATE TABLE.\n"
        . "# A second whole-line comment is also supported.\n"
        . "CREATE TABLE `{$table}` (\n"
        . "  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,\n"
        . "  sample_text VARCHAR(100) NOT NULL\n"
        . ") ENGINE=InnoDB;\n"
        . "INSERT INTO `{$table}` (sample_text)\n"
        . "VALUES ('literal; -- not a comment # still literal')"
    );

    $pdo = db();
    $runner = new MigrationRunner($pdo, $directory, static function (): void {
    });
    $first = $runner->runPendingMigrations();
    $assert($first['executed'] === [$migration], 'Leading-comment migration executes');
    $value = $pdo->query("SELECT sample_text FROM `{$table}` LIMIT 1")->fetchColumn();
    $assert(
        $value === 'literal; -- not a comment # still literal',
        'Literals preserve semicolons and comment markers'
    );

    $second = $runner->runPendingMigrations();
    $assert($second['executed'] === [], 'Executed migration is not executed twice');
    $assert($second['skipped'] === [$migration], 'Executed migration is reported as skipped');

    $write(
        $directory . '/' . $failureMigration,
        "CREATE TABLE `{$failureTable}` (id INT PRIMARY KEY) ENGINE=InnoDB;\n"
        . "THIS IS NOT VALID SQL;\n"
    );
    $caughtFailure = null;
    try {
        $runner->runMigrationFile($directory . '/' . $failureMigration, 999);
    } catch (MigrationExecutionException $exception) {
        $caughtFailure = $exception;
    }
    $assert($caughtFailure instanceof MigrationExecutionException, 'SQL failure stops the migration');
    $assert(
        $caughtFailure instanceof MigrationExecutionException
            && $caughtFailure->statementNumber === 2
            && $caughtFailure->ddlMayHaveApplied,
        'Failure reports statement number and possible DDL side effects'
    );
    $notRecorded = $pdo->prepare(
        'SELECT COUNT(*) FROM schema_migrations WHERE migration = ?'
    );
    $notRecorded->execute([$failureMigration]);
    $assert((int) $notRecorded->fetchColumn() === 0, 'Failed migration is not recorded');

    $write(
        $directory . '/' . $verifyMigration,
        "CREATE TABLE `{$verifyTable}` (id INT PRIMARY KEY) ENGINE=InnoDB;\n"
    );
    $caughtVerification = false;
    try {
        $runner->runMigrationFile(
            $directory . '/' . $verifyMigration,
            999,
            static function (): void {
                throw new RuntimeException('Synthetic structure verification failure.');
            }
        );
    } catch (MigrationExecutionException) {
        $caughtVerification = true;
    }
    $assert($caughtVerification, 'Critical structure verification failure stops migration');
    $notRecorded->execute([$verifyMigration]);
    $assert((int) $notRecorded->fetchColumn() === 0, 'Unverified migration is not recorded');
} catch (Throwable $exception) {
    $failures[] = 'Unexpected test failure: ' . get_class($exception);
    fwrite(STDERR, "[FAIL] Migration runner test aborted.\n");
} finally {
    try {
        if (isset($pdo) && $pdo instanceof PDO) {
            foreach ([$table, $failureTable, $verifyTable] as $temporaryTable) {
                $pdo->exec("DROP TABLE IF EXISTS `{$temporaryTable}`");
            }
            $deleteVersions = $pdo->prepare(
                'DELETE FROM schema_migrations WHERE migration IN (?, ?, ?)'
            );
            $deleteVersions->execute([$migration, $failureMigration, $verifyMigration]);
        }
    } catch (Throwable) {
        $failures[] = 'Temporary database cleanup failed';
    }
    if (is_dir($directory)) {
        foreach (new DirectoryIterator($directory) as $entry) {
            if ($entry->isFile() && !$entry->isLink()) {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($directory);
    }
}

echo sprintf(
    "Migration runner tests: %d failure(s).\n",
    count($failures)
);
exit($failures === [] ? 0 : 1);
