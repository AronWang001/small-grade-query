<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Support/Installer.php';

try {
    $runner = new MigrationRunner(
        db(),
        APP_ROOT . '/database/migrations'
    );
    $result = $runner->runPendingMigrations();
    [$tablesVerified, $columnsVerified] = Installer::verifyRequiredStructure($runner);
    fwrite(
        STDOUT,
        sprintf(
            "Migration complete: %d executed, %d skipped, batch #%d; %d tables and %d required columns verified.%s",
            count($result['executed']),
            count($result['skipped']),
            $result['batch'],
            $tablesVerified,
            $columnsVerified,
            PHP_EOL
        )
    );
    exit(0);
} catch (MigrationExecutionException $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Migration failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
