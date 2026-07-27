<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../app/bootstrap.php';
require_once APP_ROOT . '/app/Services/RegistrationService.php';

if (app_env() !== 'testing' || (env_value('DB_NAME', '') ?? '') !== 'securitytest_fas') {
    exit(2);
}

$payload = json_decode((string) stream_get_contents(STDIN), true);
if (
    !is_array($payload)
    || !is_array($payload['input'] ?? null)
    || !is_string($payload['session_id'] ?? null)
    || !is_string($payload['ip'] ?? null)
) {
    exit(3);
}

try {
    session_id($payload['session_id']);
    start_secure_session();
    session_write_close();
    EmailVerificationService::setFeatureStatusOverrideForTesting('enabled');
    RegistrationService::register($payload['input'], $payload['ip']);
    echo json_encode(['result' => 'unexpected_success']);
    exit(4);
} catch (PublicSafeException $exception) {
    echo json_encode(['result' => 'rejected']);
    exit(0);
} catch (Throwable $exception) {
    echo json_encode(['result' => 'internal_failure']);
    exit(5);
} finally {
    EmailVerificationService::setFeatureStatusOverrideForTesting(null);
}
