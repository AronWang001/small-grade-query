<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/bootstrap.php';

try {
    $pdo = db();
    $pdo->beginTransaction();
    $expiredBlocks = $pdo->prepare('DELETE FROM auth_ip_blocks WHERE blocked_until IS NOT NULL AND blocked_until < DATE_SUB(NOW(), INTERVAL 30 DAY)');
    $expiredBlocks->execute();
    $oldAttempts = $pdo->prepare('DELETE FROM admin_login_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 180 DAY)');
    $oldAttempts->execute();
    $oldRegistrations = $pdo->prepare('DELETE FROM admin_registration_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)');
    $oldRegistrations->execute();
    $pdo->commit();
    echo 'expired_ip_blocks=' . $expiredBlocks->rowCount() . PHP_EOL;
    echo 'old_login_attempts=' . $oldAttempts->rowCount() . PHP_EOL;
    echo 'old_registration_attempts=' . $oldRegistrations->rowCount() . PHP_EOL;
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '清理失败：' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
