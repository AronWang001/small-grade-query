<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once __DIR__ . '/../app/bootstrap.php';
require_once APP_ROOT . '/app/Services/QueryCreationDraftService.php';

$failures = [];
$assert = static function (bool $condition, string $label) use (&$failures): void {
    echo sprintf("%s %s\n", $condition ? '[PASS]' : '[FAIL]', $label);
    if (!$condition) {
        $failures[] = $label;
    }
};

$pdo = db();
$suffix = bin2hex(random_bytes(8));
$username = 'publish_test_' . $suffix;
$token = bin2hex(random_bytes(32));
$projectName = 'Concurrent publish test ' . $suffix;
$outputNames = [
    'test_concurrent_' . $suffix . '_1.json',
    'test_concurrent_' . $suffix . '_2.json',
];
$outputPaths = array_map(
    static fn(string $name): string => APP_ROOT . '/storage/' . $name,
    $outputNames
);
$adminId = 0;
$draftId = 0;
$projectId = 0;

try {
    $insertAdmin = $pdo->prepare(
        "INSERT INTO admin_users
            (username, username_normalized, password_hash, role, status, must_change_password)
         VALUES (?, ?, ?, 'admin', 'active', 0)"
    );
    $insertAdmin->execute([
        $username,
        $username,
        password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
    ]);
    $adminId = (int) $pdo->lastInsertId();

    $stepData = [
        'columns' => [
            [
                'column_index' => 1,
                'header' => 'Name',
                'label' => 'Name',
                'key' => 'name',
                'type' => 'text',
                'sensitive' => 0,
            ],
            [
                'column_index' => 2,
                'header' => 'Student Number',
                'label' => 'Student Number',
                'key' => 'student_number',
                'type' => 'text',
                'sensitive' => 0,
            ],
            [
                'column_index' => 3,
                'header' => 'Score',
                'label' => 'Score',
                'key' => 'score',
                'type' => 'number',
                'sensitive' => 0,
                'decimal_places' => 2,
            ],
        ],
        'query_settings' => [
            'conditions' => [
                ['column_index' => 1, 'required' => true],
                ['column_index' => 2, 'required' => true],
            ],
            'results' => [1, 2, 3],
        ],
        'page_settings' => [
            'name' => $projectName,
            'login_notice' => '',
            'result_notice' => '',
            'description' => 'Synthetic concurrency test project',
            'status' => 'disabled',
        ],
    ];
    $insertDraft = $pdo->prepare(
        'INSERT INTO query_creation_drafts
            (token, admin_id, current_step, original_filename, step_data_json, expires_at)
         VALUES (?, ?, 4, ?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))'
    );
    $insertDraft->execute([
        $token,
        $adminId,
        'synthetic.xlsx',
        json_encode($stepData, JSON_UNESCAPED_SLASHES),
    ]);
    $draftId = (int) $pdo->lastInsertId();
    QueryCreationDraftService::savePreviewFile($draftId, $token, [
        'raw_rows' => [
            ['1' => 'Test A', '2' => 'T001', '3' => '91.25'],
            ['1' => 'Test B', '2' => 'T002', '3' => '88.50'],
        ],
    ]);

    $processes = [];
    foreach ($outputNames as $outputName) {
        $command = implode(' ', [
            escapeshellarg(PHP_BINARY),
            escapeshellarg(APP_ROOT . '/tools/test_concurrent_publish_worker.php'),
            (string) $draftId,
            (string) $adminId,
            escapeshellarg($outputName),
        ]);
        $pipes = [];
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            APP_ROOT
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start a concurrent publish worker.');
        }
        fclose($pipes[0]);
        $processes[] = [$process, $pipes];
    }

    $exitCodes = [];
    foreach ($processes as [$process, $pipes]) {
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCodes[] = proc_close($process);
    }

    $results = [];
    foreach ($outputPaths as $path) {
        $decoded = is_file($path)
            ? json_decode((string) file_get_contents($path), true)
            : null;
        $results[] = is_array($decoded) ? $decoded : [];
    }
    $assert(
        count(array_filter($results, static fn(array $row): bool => !empty($row['success']))) === 2,
        'Both concurrent publish requests return successfully'
    );
    $assert(
        count(array_filter($results, static fn(array $row): bool => !empty($row['already_published']))) === 1,
        'Exactly one concurrent request returns already_published'
    );
    $projectIds = array_values(array_unique(array_map(
        static fn(array $row): int => (int) ($row['project_id'] ?? 0),
        $results
    )));
    $assert(count($projectIds) === 1 && $projectIds[0] > 0, 'Both requests return one project ID');
    $projectId = $projectIds[0] ?? 0;

    $count = static function (PDO $pdo, string $sql, array $parameters): int {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($parameters);
        return (int) $stmt->fetchColumn();
    };
    $assert(
        $count($pdo, 'SELECT COUNT(*) FROM query_projects WHERE name = ?', [$projectName]) === 1,
        'Concurrent publish creates one project'
    );
    $assert(
        $count($pdo, 'SELECT COUNT(*) FROM query_fields WHERE project_id = ?', [$projectId]) === 3,
        'Concurrent publish creates one field set'
    );
    $assert(
        $count($pdo, 'SELECT COUNT(*) FROM query_records WHERE project_id = ?', [$projectId]) === 2,
        'Concurrent publish creates one record set'
    );
    $assert(
        $count($pdo, 'SELECT COUNT(*) FROM import_batches WHERE draft_token = ?', [$token]) === 1,
        'Concurrent publish creates one import batch'
    );
    $published = $pdo->prepare(
        'SELECT published_project_id FROM query_creation_drafts WHERE id = ?'
    );
    $published->execute([$draftId]);
    $assert((int) $published->fetchColumn() === $projectId, 'Draft references the single project');
    $assert(!in_array(255, $exitCodes, true), 'Concurrent publish does not produce a TypeError');
} catch (Throwable $exception) {
    $failures[] = 'Unexpected test failure: ' . get_class($exception);
    fwrite(STDERR, "[FAIL] Concurrent publish test aborted.\n");
} finally {
    foreach ($outputPaths as $path) {
        if (is_file($path) && !is_link($path)) {
            @unlink($path);
        }
    }
    try {
        if ($projectId > 0) {
            $pdo->prepare('DELETE FROM import_batches WHERE project_id = ?')->execute([$projectId]);
            $pdo->prepare(
                'DELETE FROM query_record_values
                 WHERE record_id IN (SELECT id FROM query_records WHERE project_id = ?)'
            )->execute([$projectId]);
            $pdo->prepare('DELETE FROM query_records WHERE project_id = ?')->execute([$projectId]);
            $pdo->prepare('DELETE FROM query_fields WHERE project_id = ?')->execute([$projectId]);
        }
        if ($draftId > 0) {
            $pdo->prepare('DELETE FROM query_creation_drafts WHERE id = ?')->execute([$draftId]);
        }
        if ($projectId > 0) {
            $pdo->prepare('DELETE FROM query_projects WHERE id = ?')->execute([$projectId]);
            $pdo->prepare(
                "DELETE FROM admin_audit_logs
                 WHERE entity_type = 'query_project' AND entity_id = ?"
            )->execute([$projectId]);
        }
        if ($adminId > 0) {
            $pdo->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$adminId]);
        }
        QueryCreationDraftService::deleteDraftFiles($token);
    } catch (Throwable) {
        $failures[] = 'Temporary concurrent publish cleanup failed';
    }
}

echo sprintf("Concurrent publish tests: %d failure(s).\n", count($failures));
exit($failures === [] ? 0 : 1);
