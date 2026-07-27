<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/bootstrap.php';

$days = max(1, (int) (env_value('QUERY_LOG_RETENTION_DAYS', '180') ?? '180'));
try {
    $stmt = db()->prepare('DELETE FROM query_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)');
    $stmt->execute([$days]);
    echo '已清理 ' . $stmt->rowCount() . ' 条超过 ' . $days . " 天的查询日志。\n";
} catch (Throwable $exception) {
    fwrite(STDERR, '日志清理失败：' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

