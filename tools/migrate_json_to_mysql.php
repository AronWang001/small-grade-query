<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/Repositories/GradeRepository.php';

$options = getopt('', ['file::', 'force']);
$candidates = [];
if (isset($options['file']) && is_string($options['file'])) {
    $candidates[] = $options['file'];
}
$candidates[] = APP_ROOT . '/data.json';
$candidates[] = APP_ROOT . '/storage/legacy/data.json';
$source = null;
foreach ($candidates as $candidate) {
    if (is_file($candidate)) {
        $source = realpath($candidate) ?: $candidate;
        break;
    }
}
if ($source === null) {
    fwrite(STDERR, "未找到待迁移的 data.json。\n");
    exit(1);
}

$raw = file_get_contents($source);
$rows = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($rows)) {
    fwrite(STDERR, "旧数据文件不是有效的JSON数组。\n");
    exit(1);
}

try {
    $pdo = db();
    $projectStmt = $pdo->prepare('SELECT id FROM query_projects WHERE slug = ? AND deleted_at IS NULL LIMIT 1');
    $projectStmt->execute(['comprehensive-gpa-ranking']);
    $projectId = $projectStmt->fetchColumn();
    if ($projectId === false) {
        throw new RuntimeException('默认项目不存在，请先运行 tools/install.php。');
    }
    $projectId = (int) $projectId;
    $existing = $pdo->prepare('SELECT COUNT(*) FROM query_records WHERE project_id = ? AND deleted_at IS NULL');
    $existing->execute([$projectId]);
    if ((int) $existing->fetchColumn() > 0 && !array_key_exists('force', $options)) {
        throw new RuntimeException('默认项目已经存在数据；如确认需要覆盖，请增加 --force 参数。');
    }

    $mapping = [
        'name' => 'name',
        'id_card' => 'id_card',
        'class_name' => 'class',
        'total_credits' => 'total_credits',
        'total_score' => 'total_score',
        'average_score' => 'average_score',
        'weighted_average' => 'weighted_average',
        'total_credits_gpa' => 'total_credits_gpa',
        'average_credits_gpa' => 'average_credits_gpa',
        'rank' => 'rank',
    ];
    $pdo->beginTransaction();
    if (array_key_exists('force', $options)) {
        $pdo->prepare('UPDATE query_records SET deleted_at = NOW() WHERE project_id = ? AND deleted_at IS NULL')->execute([$projectId]);
    }
    $migrated = 0;
    foreach ($rows as $index => $row) {
        if (!is_array($row)) {
            throw new RuntimeException('第' . ($index + 1) . '条旧数据格式无效。');
        }
        $values = [];
        foreach ($mapping as $newKey => $oldKey) {
            $values[$newKey] = trim((string) ($row[$oldKey] ?? ''));
        }
        GradeRepository::saveRecord($projectId, $values);
        $migrated++;
    }
    $pdo->commit();

    $legacyDirectory = APP_ROOT . '/storage/legacy';
    if (!is_dir($legacyDirectory) && !mkdir($legacyDirectory, 0770, true) && !is_dir($legacyDirectory)) {
        throw new RuntimeException('数据已迁移，但无法创建旧数据归档目录。');
    }
    if (realpath(dirname($source)) === realpath(APP_ROOT)) {
        $archivePath = $legacyDirectory . '/data.' . date('Ymd_His') . '.json';
        if (!rename($source, $archivePath)) {
            throw new RuntimeException('数据已迁移，但无法把公开目录中的 data.json 移入受保护目录。');
        }
        @chmod($archivePath, 0600);
    }
    echo '迁移完成：' . $migrated . " 条记录已写入MySQL。\n";
    echo "运行时不再读取 data.json。\n";
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '迁移失败：' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
