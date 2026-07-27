<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once __DIR__ . '/../app/bootstrap.php';
require_once APP_ROOT . '/app/Services/QueryCreationService.php';

$draftId = isset($argv[1]) ? (int) $argv[1] : 0;
$adminId = isset($argv[2]) ? (int) $argv[2] : 0;
$outputName = (string) ($argv[3] ?? '');
if (
    $draftId <= 0
    || $adminId <= 0
    || preg_match('/^test_concurrent_[a-f0-9]{16}_[12]\.json$/D', $outputName) !== 1
) {
    fwrite(STDERR, "Invalid worker arguments.\n");
    exit(2);
}

$outputPath = APP_ROOT . '/storage/' . basename($outputName);
try {
    $result = QueryCreationService::step5Publish($draftId, $adminId);
    $payload = [
        'success' => true,
        'already_published' => (bool) ($result['already_published'] ?? false),
        'project_id' => (int) ($result['project_id'] ?? 0),
    ];
    $status = 0;
} catch (Throwable $exception) {
    $payload = [
        'success' => false,
        'error_class' => get_class($exception),
    ];
    $status = 1;
}

$encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
if (!is_string($encoded) || file_put_contents($outputPath, $encoded, LOCK_EX) === false) {
    fwrite(STDERR, "Unable to write worker result.\n");
    exit(3);
}
exit($status);
