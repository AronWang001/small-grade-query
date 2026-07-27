<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once __DIR__ . '/../app/bootstrap.php';
require_once APP_ROOT . '/app/Services/QueryCreationDraftService.php';
require_once APP_ROOT . '/app/Services/ExcelUploadRateLimiter.php';

$failures = [];
$assert = static function (bool $condition, string $label) use (&$failures): void {
    echo sprintf("%s %s\n", $condition ? '[PASS]' : '[FAIL]', $label);
    if (!$condition) {
        $failures[] = $label;
    }
};

$originalServer = $_SERVER;
$originalTrusted = $_ENV['TRUSTED_PROXIES'] ?? null;
$originalCloudflare = $_ENV['TRUST_CLOUDFLARE_IP'] ?? null;

$proxyCase = static function (
    string $remote,
    array $headers,
    string $trusted = '',
    bool $cloudflare = false
): array {
    foreach ([
        'HTTPS',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_FORWARDED_PROTO',
        'HTTP_CF_CONNECTING_IP',
        'HTTP_CF_VISITOR',
    ] as $key) {
        unset($_SERVER[$key]);
    }
    $_SERVER['REMOTE_ADDR'] = $remote;
    foreach ($headers as $key => $value) {
        $_SERVER[$key] = $value;
    }
    $_ENV['TRUSTED_PROXIES'] = $trusted;
    $_ENV['TRUST_CLOUDFLARE_IP'] = $cloudflare ? '1' : '0';
    return [client_ip(), is_https_request()];
};

try {
    [$ip, $https] = $proxyCase(
        '198.51.100.10',
        [
            'HTTP_X_FORWARDED_FOR' => '203.0.113.8',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_CF_CONNECTING_IP' => '203.0.113.9',
            'HTTP_CF_VISITOR' => '{"scheme":"https"}',
        ],
        '',
        true
    );
    $assert($ip === '198.51.100.10', 'Untrusted proxy headers cannot forge client IP');
    $assert($https === false, 'Untrusted proxy headers cannot forge HTTPS');

    [$ip, $https] = $proxyCase(
        '10.0.0.2',
        [
            'HTTP_X_FORWARDED_FOR' => '203.0.113.10',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ],
        '10.0.0.0/8'
    );
    $assert($ip === '203.0.113.10', 'Trusted single proxy resolves client IP');
    $assert($https === true, 'Trusted proxy may report HTTPS');

    [$ip] = $proxyCase(
        '10.0.0.2',
        ['HTTP_X_FORWARDED_FOR' => '203.0.113.11, 10.1.1.1'],
        '10.0.0.0/8'
    );
    $assert($ip === '203.0.113.11', 'Trusted multi-proxy chain is stripped right-to-left');

    [$ip] = $proxyCase(
        '2001:db8:ffff::2',
        ['HTTP_X_FORWARDED_FOR' => '2001:db8:1::10, 2001:db8:ffff::3'],
        '2001:db8:ffff::/48'
    );
    $assert($ip === '2001:db8:1::10', 'Trusted IPv6 proxy chain is supported');

    [$ip] = $proxyCase(
        '10.0.0.2',
        ['HTTP_X_FORWARDED_FOR' => "203.0.113.12\nX-Injected: yes"],
        '10.0.0.0/8'
    );
    $assert($ip === '10.0.0.2', 'Malformed forwarded header falls back to REMOTE_ADDR');

    [$ip, $https] = $proxyCase(
        '173.245.48.20',
        [
            'HTTP_CF_CONNECTING_IP' => '203.0.113.13',
            'HTTP_CF_VISITOR' => '{"scheme":"https"}',
        ],
        '',
        true
    );
    $assert($ip === '203.0.113.13', 'Cloudflare header is accepted only from a Cloudflare range');
    $assert($https === true, 'Cloudflare HTTPS header is accepted only from a Cloudflare range');

    $tokenA = bin2hex(random_bytes(32));
    $tokenB = bin2hex(random_bytes(32));
    $directory = QueryCreationDraftService::storageDirectoryPath();
    $filesA = [
        QueryCreationDraftService::buildDraftPath($tokenA),
        QueryCreationDraftService::buildSourcePath($tokenA, 'xlsx'),
        $directory . DIRECTORY_SEPARATOR . 'source_' . $tokenA . '.xls',
        $directory . DIRECTORY_SEPARATOR . 'temp_' . $tokenA . '.dat',
    ];
    $filesB = [
        QueryCreationDraftService::buildDraftPath($tokenB),
        QueryCreationDraftService::buildSourcePath($tokenB, 'xlsx'),
        $directory . DIRECTORY_SEPARATOR . 'source_' . $tokenB . '.xls',
        $directory . DIRECTORY_SEPARATOR . 'temp_' . $tokenB . '.dat',
    ];
    foreach (array_merge($filesA, $filesB) as $file) {
        if (file_put_contents($file, 'test-fixture', LOCK_EX) === false) {
            throw new RuntimeException('Unable to create an isolated draft fixture.');
        }
    }
    QueryCreationDraftService::deleteDraftFiles($tokenA);
    $assert(
        count(array_filter($filesA, 'file_exists')) === 0,
        'Deleting draft A removes all A files'
    );
    $assert(
        count(array_filter($filesB, 'file_exists')) === count($filesB),
        'Deleting draft A preserves every draft B file'
    );
    QueryCreationDraftService::deleteDraftFiles($tokenB);

    $pdo = db();
    $userSuffix = bin2hex(random_bytes(6));
    $insertAdmin = $pdo->prepare(
        "INSERT INTO admin_users
            (username, username_normalized, password_hash, role, status, must_change_password)
         VALUES (?, ?, ?, 'admin', 'active', 0)"
    );
    $adminIds = [];
    for ($index = 1; $index <= 3; $index++) {
        $username = 'rate_test_' . $userSuffix . '_' . $index;
        $insertAdmin->execute([
            $username,
            $username,
            password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
        ]);
        $adminIds[] = (int) $pdo->lastInsertId();
    }

    $_ENV['TRUSTED_PROXIES'] = '';
    $_ENV['TRUST_CLOUDFLARE_IP'] = '0';
    $_SERVER['REMOTE_ADDR'] = '192.0.2.21';
    for ($index = 0; $index < 5; $index++) {
        ExcelUploadRateLimiter::accept($adminIds[0], 'new_query');
    }
    $minuteRejected = false;
    try {
        ExcelUploadRateLimiter::accept($adminIds[0], 'new_query');
    } catch (RuntimeException) {
        $minuteRejected = true;
    }
    $assert($minuteRejected, 'Sixth upload in one minute is rejected');

    $_SERVER['REMOTE_ADDR'] = '192.0.2.22';
    $otherAdminAttempt = ExcelUploadRateLimiter::accept(
        $adminIds[1],
        'existing_project_import'
    );
    $assert($otherAdminAttempt > 0, 'Another administrator is not counted with the first');

    $ipHash = hash_hmac('sha256', '192.0.2.23', search_key());
    $seed = $pdo->prepare(
        "INSERT INTO excel_upload_attempts
            (admin_id, ip_hash, purpose, status, created_at)
         VALUES (?, ?, 'new_query', 'accepted', DATE_SUB(NOW(), INTERVAL 2 MINUTE))"
    );
    for ($index = 0; $index < 30; $index++) {
        $seed->execute([$adminIds[0], $ipHash]);
    }
    $_SERVER['REMOTE_ADDR'] = '192.0.2.23';
    $hourRejected = false;
    try {
        ExcelUploadRateLimiter::accept($adminIds[0], 'new_query');
    } catch (RuntimeException) {
        $hourRejected = true;
    }
    $assert($hourRejected, 'Thirty-first administrator upload in one hour is rejected');

    $sharedIp = '192.0.2.24';
    $sharedHash = hash_hmac('sha256', $sharedIp, search_key());
    for ($index = 0; $index < 50; $index++) {
        $seed->execute([$adminIds[$index % 2], $sharedHash]);
    }
    $_SERVER['REMOTE_ADDR'] = $sharedIp;
    $ipRejected = false;
    try {
        ExcelUploadRateLimiter::accept($adminIds[2], 'new_query');
    } catch (RuntimeException) {
        $ipRejected = true;
    }
    $assert($ipRejected, 'Fifty-first upload from one IP in one hour is rejected');

    $throttleIp = '192.0.2.25';
    $throttleHash = hash_hmac('sha256', $throttleIp, search_key());
    $_SERVER['REMOTE_ADDR'] = $throttleIp;
    for ($index = 0; $index < 5; $index++) {
        ExcelUploadRateLimiter::accept($adminIds[2], 'new_query');
    }
    $beforeRejected = $pdo->prepare(
        "SELECT COUNT(*) FROM excel_upload_attempts
         WHERE admin_id = ? AND ip_hash = ? AND purpose = 'new_query'
           AND status = 'rejected'"
    );
    $beforeRejected->execute([$adminIds[2], $throttleHash]);
    $rejectedRowsBefore = (int) $beforeRejected->fetchColumn();
    $beforeAudit = $pdo->prepare(
        "SELECT COUNT(*) FROM admin_audit_logs
         WHERE action = 'excel_upload_rate_limited'
           AND entity_type = 'admin_user'
           AND entity_id = ?"
    );
    $beforeAudit->execute([$adminIds[2]]);
    $auditRowsBefore = (int) $beforeAudit->fetchColumn();

    $allRejected = true;
    for ($index = 0; $index < 100; $index++) {
        try {
            ExcelUploadRateLimiter::accept($adminIds[2], 'new_query');
            $allRejected = false;
        } catch (PublicSafeException) {
        }
    }
    $beforeRejected->execute([$adminIds[2], $throttleHash]);
    $rejectedRowsAfter = (int) $beforeRejected->fetchColumn();
    $beforeAudit->execute([$adminIds[2]]);
    $auditRowsAfter = (int) $beforeAudit->fetchColumn();
    $assert($allRejected, 'All 100 over-limit upload requests remain rejected');
    $assert(
        $rejectedRowsAfter - $rejectedRowsBefore === 1,
        'One hundred rejected requests create only one throttled rejection row'
    );
    $assert(
        $auditRowsAfter - $auditRowsBefore === 1,
        'One hundred rejected requests create only one throttled audit row'
    );

    $unexpectedUpload = $directory . DIRECTORY_SEPARATOR . 'rate-limit-should-not-exist.tmp';
    $assert(!file_exists($unexpectedUpload), 'Rejected rate-limit checks do not create upload files');
} catch (Throwable $exception) {
    $failures[] = 'Unexpected test failure: ' . get_class($exception);
    fwrite(STDERR, "[FAIL] Release blocker test aborted.\n");
} finally {
    $_SERVER = $originalServer;
    if ($originalTrusted === null) {
        unset($_ENV['TRUSTED_PROXIES']);
    } else {
        $_ENV['TRUSTED_PROXIES'] = $originalTrusted;
    }
    if ($originalCloudflare === null) {
        unset($_ENV['TRUST_CLOUDFLARE_IP']);
    } else {
        $_ENV['TRUST_CLOUDFLARE_IP'] = $originalCloudflare;
    }

    if (isset($filesA)) {
        foreach (array_merge($filesA, $filesB ?? []) as $file) {
            if (is_file($file) && !is_link($file)) {
                @unlink($file);
            }
        }
    }
    if (isset($pdo, $adminIds) && $pdo instanceof PDO && $adminIds !== []) {
        try {
            $placeholders = implode(',', array_fill(0, count($adminIds), '?'));
            $pdo->prepare(
                "DELETE FROM excel_upload_attempts WHERE admin_id IN ({$placeholders})"
            )->execute($adminIds);
            $pdo->prepare(
                "DELETE FROM admin_audit_logs
                 WHERE action = 'excel_upload_rate_limited'
                   AND entity_type = 'admin_user'
                   AND entity_id IN ({$placeholders})"
            )->execute($adminIds);
            $pdo->prepare(
                "DELETE FROM admin_users WHERE id IN ({$placeholders})"
            )->execute($adminIds);
        } catch (Throwable) {
            $failures[] = 'Temporary rate-limit data cleanup failed';
        }
    }
}

echo sprintf("Release blocker tests: %d failure(s).\n", count($failures));
exit($failures === [] ? 0 : 1);
