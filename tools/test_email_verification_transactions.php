<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once __DIR__ . '/../app/bootstrap.php';
require_once APP_ROOT . '/app/Services/RegistrationService.php';

if (app_env() !== 'testing' || (env_value('DB_NAME', '') ?? '') !== 'securitytest_fas') {
    fwrite(STDERR, "[FAIL] This test is restricted to the securitytest_fas testing environment.\n");
    exit(2);
}

$pdo = db();
$failures = [];
$codeIds = [];
$adminIds = [];
$assert = static function (bool $condition, string $label) use (&$failures): void {
    echo sprintf("%s %s\n", $condition ? '[PASS]' : '[FAIL]', $label);
    if (!$condition) {
        $failures[] = $label;
    }
};

$insertCode = static function (
    string $email,
    string $code,
    string $sessionHash,
    string $token
) use ($pdo, &$codeIds): int {
    $stmt = $pdo->prepare(
        "INSERT INTO admin_email_verification_codes
            (email_hash, email_encrypted, code_hash, verification_token,
             session_hash, ip_hash, ip_encrypted, purpose, status,
             attempts, max_attempts, sent_at, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'admin_registration', 'pending',
                 0, 5, NOW(), DATE_ADD(NOW(), INTERVAL 10 MINUTE))"
    );
    $ip = '192.0.2.20';
    $stmt->execute([
        AdminValidation::emailHash($email),
        encrypt_value($email),
        hash_hmac('sha256', $code, app_key()),
        $token,
        $sessionHash,
        hash_hmac('sha256', $ip, search_key()),
        encrypt_value($ip),
    ]);
    $id = (int) $pdo->lastInsertId();
    $codeIds[] = $id;
    return $id;
};

$registrationInput = static function (
    string $username,
    string $phone,
    string $email,
    string $password,
    string $code,
    string $token
): array {
    return [
        'username' => $username,
        'phone' => $phone,
        'email' => $email,
        'password' => $password,
        'password_confirmation' => $password,
        'email_code' => $code,
        'email_code_token' => $token,
    ];
};

try {
    EmailVerificationService::setFeatureStatusOverrideForTesting('enabled');

    $suffix = bin2hex(random_bytes(6));
    $email = 'verification-' . $suffix . '@example.invalid';
    $username = 'verify_' . $suffix;
    $phone = '139' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    $password = 'Verify' . bin2hex(random_bytes(8)) . '9';
    $correctCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    do {
        $wrongCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    } while (hash_equals($correctCode, $wrongCode));
    $token = bin2hex(random_bytes(32));
    $sessionName = 'ev-main-' . bin2hex(random_bytes(8));
    session_id($sessionName);
    start_secure_session();
    $sessionHash = hash_hmac('sha256', session_id(), search_key());
    $codeId = $insertCode($email, $correctCode, $sessionHash, $token);
    $input = $registrationInput($username, $phone, $email, $password, $wrongCode, $token);

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $failedSafely = false;
        try {
            RegistrationService::register($input, '192.0.2.20');
        } catch (PublicSafeException $exception) {
            $failedSafely = true;
        }
        $state = $pdo->prepare(
            'SELECT attempts, status FROM admin_email_verification_codes WHERE id = ?'
        );
        $state->execute([$codeId]);
        $row = $state->fetch(PDO::FETCH_ASSOC);
        $expectedStatus = $attempt === 5 ? 'invalidated' : 'pending';
        $assert(
            $failedSafely
                && is_array($row)
                && (int) $row['attempts'] === $attempt
                && $row['status'] === $expectedStatus,
            sprintf(
                'Wrong code attempt %d persists with expected status (stored=%d,status=%s)',
                $attempt,
                is_array($row) ? (int) $row['attempts'] : -1,
                is_array($row) ? (string) $row['status'] : 'missing'
            )
        );
    }

    $sixthInput = $registrationInput(
        $username,
        $phone,
        $email,
        $password,
        $correctCode,
        $token
    );
    $sixthRejected = false;
    try {
        $adminIds[] = RegistrationService::register($sixthInput, '192.0.2.20');
    } catch (PublicSafeException $exception) {
        $sixthRejected = true;
    }
    $created = $pdo->prepare(
        'SELECT COUNT(*) FROM admin_users WHERE username_normalized = ?'
    );
    $created->execute([AdminValidation::normalizeUsername($username)]);
    $assert(
        $sixthRejected && (int) $created->fetchColumn() === 0,
        'Correct code is rejected after the fifth failed attempt'
    );

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $concurrentSuffix = bin2hex(random_bytes(6));
    $concurrentEmail = 'verification-concurrent-' . $concurrentSuffix . '@example.invalid';
    $concurrentCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    do {
        $concurrentWrong = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    } while (hash_equals($concurrentCode, $concurrentWrong));
    $concurrentToken = bin2hex(random_bytes(32));
    $concurrentSession = 'ev-concurrent-' . bin2hex(random_bytes(8));
    session_id($concurrentSession);
    start_secure_session();
    $concurrentSession = session_id();
    $concurrentSessionHash = hash_hmac('sha256', session_id(), search_key());
    session_write_close();
    $concurrentCodeId = $insertCode(
        $concurrentEmail,
        $concurrentCode,
        $concurrentSessionHash,
        $concurrentToken
    );
    $concurrentPassword = 'Verify' . bin2hex(random_bytes(8)) . '7';
    $payload = json_encode([
        'session_id' => $concurrentSession,
        'ip' => '192.0.2.21',
        'input' => $registrationInput(
            'verify_concurrent_' . $concurrentSuffix,
            '138' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            $concurrentEmail,
            $concurrentPassword,
            $concurrentWrong,
            $concurrentToken
        ),
    ], JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) {
        throw new RuntimeException('Unable to create concurrent test payload.');
    }

    $workers = [];
    for ($i = 0; $i < 2; $i++) {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, APP_ROOT . '/tools/test_email_verification_worker.php'],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            APP_ROOT
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start concurrent verification worker.');
        }
        fwrite($pipes[0], $payload);
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }

    $workerSuccess = true;
    foreach ($workers as [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $decoded = json_decode((string) $stdout, true);
        if (
            $exitCode !== 0
            || !is_array($decoded)
            || ($decoded['result'] ?? '') !== 'rejected'
            || trim((string) $stderr) !== ''
        ) {
            $workerSuccess = false;
        }
    }

    $concurrentState = $pdo->prepare(
        'SELECT attempts, status FROM admin_email_verification_codes WHERE id = ?'
    );
    $concurrentState->execute([$concurrentCodeId]);
    $concurrentRow = $concurrentState->fetch(PDO::FETCH_ASSOC);
    $assert(
        $workerSuccess
            && is_array($concurrentRow)
            && (int) $concurrentRow['attempts'] === 2
            && $concurrentRow['status'] === 'pending',
        sprintf(
            'Two concurrent wrong-code requests preserve both increments (stored=%d,status=%s,workers=%s)',
            is_array($concurrentRow) ? (int) $concurrentRow['attempts'] : -1,
            is_array($concurrentRow) ? (string) $concurrentRow['status'] : 'missing',
            $workerSuccess ? 'ok' : 'failed'
        )
    );
} catch (Throwable $exception) {
    $failures[] = 'Unexpected test failure: ' . get_class($exception);
    fwrite(STDERR, "[FAIL] Email verification transaction test aborted.\n");
} finally {
    EmailVerificationService::setFeatureStatusOverrideForTesting(null);

    if ($codeIds !== []) {
        $placeholders = implode(',', array_fill(0, count($codeIds), '?'));
        $deleteAudit = $pdo->prepare(
            "DELETE FROM admin_audit_logs
             WHERE entity_type = 'email_verification'
               AND entity_id IN ($placeholders)"
        );
        $deleteAudit->execute($codeIds);
        $deleteCodes = $pdo->prepare(
            "DELETE FROM admin_email_verification_codes WHERE id IN ($placeholders)"
        );
        $deleteCodes->execute($codeIds);
    }
    if ($adminIds !== []) {
        $validIds = array_values(array_filter($adminIds, static fn (int $id): bool => $id > 0));
        if ($validIds !== []) {
            $placeholders = implode(',', array_fill(0, count($validIds), '?'));
            $pdo->prepare("DELETE FROM admin_users WHERE id IN ($placeholders)")->execute($validIds);
        }
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        session_destroy();
    }
}

echo sprintf("Email verification transaction tests: %d failure(s).\n", count($failures));
exit($failures === [] ? 0 : 1);
