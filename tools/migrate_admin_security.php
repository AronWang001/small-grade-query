<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once __DIR__ . '/../app/bootstrap.php';
require_once APP_ROOT . '/app/Support/MigrationRunner.php';

fwrite(STDOUT, "This compatibility command now runs the shared migration pipeline.\n");
try {
    $result = (new MigrationRunner(
        db(),
        APP_ROOT . '/database/migrations'
    ))->runPendingMigrations();
    fwrite(STDOUT, sprintf(
        "Migration complete: %d executed, %d skipped.\n",
        count($result['executed']),
        count($result['skipped'])
    ));
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Migration failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
