<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once __DIR__ . '/../app/bootstrap.php';

$failures = [];
$assert = static function (bool $condition, string $label) use (&$failures): void {
    echo sprintf("%s %s\n", $condition ? '[PASS]' : '[FAIL]', $label);
    if (!$condition) {
        $failures[] = $label;
    }
};

$forbidden = '/SQLSTATE|SELECT|INSERT|UPDATE|admin_users|vendor[\\\\\/]|PDOException|stack trace|\/www\/wwwroot/i';
$forbiddenLog = '/SQLSTATE|SELECT|INSERT|UPDATE|admin_users|vendor[\\\\\/]|stack trace|\/www\/wwwroot/i';
$pdoException = new PDOException(
    'SQLSTATE[42S02]: Base table not found: admin_users at /www/wwwroot/example/vendor/file.php'
);
$runtimeException = new RuntimeException(
    'Internal encryption failure at /www/wwwroot/example/app/Secret.php'
);
$safeException = new PublicSafeException('页面已过期，请刷新后重试。');

$pdoMessage = PublicErrorHandler::publicMessage($pdoException);
$runtimeMessage = PublicErrorHandler::publicMessage($runtimeException);
$safeMessage = PublicErrorHandler::publicMessage($safeException);
$json = json_encode(['success' => false, 'message' => $pdoMessage], JSON_UNESCAPED_UNICODE);
$html = '<p>' . e($runtimeMessage) . '</p>';

$assert(preg_match($forbidden, $pdoMessage) !== 1, 'PDOException details are hidden');
$assert(preg_match($forbidden, $runtimeMessage) !== 1, 'RuntimeException details are hidden');
$assert(is_string($json) && preg_match($forbidden, $json) !== 1, 'JSON error response is sanitized');
$assert(preg_match($forbidden, $html) !== 1, 'HTML error response is sanitized');
$assert($safeMessage === '页面已过期，请刷新后重试。', 'PublicSafeException message remains user-visible');

$logDirectory = APP_ROOT . '/storage/logs';
if (!is_dir($logDirectory) && !mkdir($logDirectory, 0770, true) && !is_dir($logDirectory)) {
    throw new RuntimeException('Unable to prepare isolated error-log test directory.');
}
$logPath = $logDirectory . '/public-error-test-' . bin2hex(random_bytes(8)) . '.log';
$oldLog = ini_get('error_log');

try {
    ini_set('error_log', $logPath);
    PublicErrorHandler::logException($pdoException, [
        'component' => 'test',
        'operation' => 'pdo_failure',
        'password' => 'must-not-be-logged',
    ]);
    $logContent = is_file($logPath) ? (string) file_get_contents($logPath) : '';
    $assert($logContent !== '', 'Sanitized internal error record is written');
    $assert(preg_match($forbiddenLog, $logContent) !== 1, 'Internal error record excludes SQL, paths and exception message');
    $assert(!str_contains($logContent, 'must-not-be-logged'), 'Non-allow-listed context is excluded');
} finally {
    ini_set('error_log', is_string($oldLog) ? $oldLog : '');
    if (is_file($logPath)) {
        unlink($logPath);
    }
}

echo sprintf("Public error handler tests: %d failure(s).\n", count($failures));
exit($failures === [] ? 0 : 1);
