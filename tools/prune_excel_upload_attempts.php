<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/bootstrap.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$execute = in_array('--execute', $argv ?? [], true);
if ($dryRun === $execute) {
    fwrite(STDERR, 'Usage: php tools/prune_excel_upload_attempts.php --dry-run|--execute' . PHP_EOL);
    exit(2);
}

try {
    $count = (int) db()->query(
        'SELECT COUNT(*) FROM excel_upload_attempts
         WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)'
    )->fetchColumn();
    if ($execute && $count > 0) {
        $stmt = db()->prepare(
            'DELETE FROM excel_upload_attempts
             WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)'
        );
        $stmt->execute();
        $count = $stmt->rowCount();
    }
    fwrite(
        STDOUT,
        sprintf(
            "%s: %d expired upload-attempt record(s).%s",
            $dryRun ? 'DRY-RUN' : 'DELETED',
            $count,
            PHP_EOL
        )
    );
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Upload-attempt cleanup failed.' . PHP_EOL);
    error_log('prune_excel_upload_attempts: ' . get_class($exception));
    exit(1);
}
