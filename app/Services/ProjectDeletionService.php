<?php

declare(strict_types=1);

require_once __DIR__ . '/AdminAccountService.php';
require_once __DIR__ . '/QueryCreationDraftService.php';
require_once __DIR__ . '/../Repositories/GradeRepository.php';

final class ProjectDeletionService
{
    public static function previewDeletion(int $projectId): array
    {
        $project = GradeRepository::project($projectId, true);
        if ($project === null) {
            throw new InvalidArgumentException('查询项目不存在。');
        }
        $stmt = db()->prepare('SELECT
            (SELECT COUNT(*) FROM query_fields WHERE project_id = ?) AS field_count,
            (SELECT COUNT(*) FROM query_records WHERE project_id = ? AND deleted_at IS NULL) AS active_record_count,
            (SELECT COUNT(*) FROM query_records WHERE project_id = ? AND deleted_at IS NOT NULL) AS deleted_record_count,
            (SELECT COUNT(*) FROM query_record_values rv INNER JOIN query_records r ON r.id = rv.record_id WHERE r.project_id = ?) AS value_count,
            (SELECT COUNT(*) FROM query_logs WHERE project_id = ?) AS query_log_count,
            (SELECT COUNT(*) FROM import_batches WHERE project_id = ?) AS import_batch_count,
            (SELECT COUNT(*) FROM import_batches WHERE project_id = ? AND committed_at IS NULL) AS pending_preview_count,
            (SELECT MAX(created_at) FROM query_logs WHERE project_id = ?) AS last_query_at');
        $stmt->execute(array_fill(0, 8, $projectId));
        $counts = $stmt->fetch() ?: [];
        return array_merge($project, array_map(
            static fn (mixed $value): mixed => is_numeric($value) ? (int) $value : $value,
            $counts
        ));
    }

    public static function deletePermanently(
        array $actor,
        int $projectId,
        string $projectNameConfirmation,
        string $deleteConfirmation,
        bool $acknowledged,
        ?callable $failureInjector = null
    ): array {
        AdminAccountService::requireSuper($actor, 'permanently_delete_query_project');
        if (!$acknowledged) {
            throw new InvalidArgumentException('请先勾选“我已了解此操作无法恢复”。');
        }
        if (!hash_equals('DELETE', trim($deleteConfirmation))) {
            throw new InvalidArgumentException('请输入 DELETE 确认永久删除。');
        }

        $pdo = db();
        $ownsTransaction = !$pdo->inTransaction();
        $savepoint = 'project_delete_' . bin2hex(random_bytes(4));
        $previewFiles = [];
        $draftTokens = [];
        try {
            if ($ownsTransaction) {
                $pdo->beginTransaction();
            } else {
                $pdo->exec('SAVEPOINT ' . $savepoint);
            }

            $lock = $pdo->prepare('SELECT id, name FROM query_projects WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
            $lock->execute([$projectId]);
            $project = $lock->fetch();
            if (!is_array($project)) {
                throw new InvalidArgumentException('查询项目不存在或已被删除。');
            }
            if (!hash_equals((string) $project['name'], trim($projectNameConfirmation))) {
                throw new InvalidArgumentException('输入的项目名称与当前项目不一致。');
            }

            $freshActor = AdminRepository::findById((int) ($actor['id'] ?? 0));
            AdminAccountService::requireSuper($freshActor ?? [], 'permanently_delete_query_project');
            $impact = self::previewDeletion($projectId);

            $files = $pdo->prepare('SELECT preview_path FROM import_batches WHERE project_id = ? AND committed_at IS NULL');
            $files->execute([$projectId]);
            $previewFiles = array_values(array_filter(array_map(
                static fn (mixed $path): string => basename((string) $path),
                $files->fetchAll(PDO::FETCH_COLUMN)
            ), static fn (string $path): bool => $path !== '' && $path !== '.' && $path !== '..'));
            $drafts = $pdo->prepare(
                'SELECT token FROM query_creation_drafts
                 WHERE published_project_id = ?'
            );
            $drafts->execute([$projectId]);
            $draftTokens = array_values(array_filter(array_map(
                'strval',
                $drafts->fetchAll(PDO::FETCH_COLUMN)
            )));

            $pdo->prepare('DELETE FROM query_logs WHERE project_id = ?')->execute([$projectId]);
            $pdo->prepare('DELETE FROM import_batches WHERE project_id = ?')->execute([$projectId]);
            $pdo->prepare(
                'DELETE FROM query_creation_drafts WHERE published_project_id = ?'
            )->execute([$projectId]);
            $pdo->prepare('DELETE rv FROM query_record_values rv INNER JOIN query_records r ON r.id = rv.record_id WHERE r.project_id = ?')->execute([$projectId]);
            $pdo->prepare('DELETE FROM query_records WHERE project_id = ?')->execute([$projectId]);
            $pdo->prepare('DELETE rv FROM query_record_values rv INNER JOIN query_fields f ON f.id = rv.field_id WHERE f.project_id = ?')->execute([$projectId]);
            $pdo->prepare('DELETE FROM query_fields WHERE project_id = ?')->execute([$projectId]);
            $pdo->prepare('DELETE FROM query_projects WHERE id = ?')->execute([$projectId]);

            if ($failureInjector !== null) {
                $failureInjector($pdo, $impact);
            }

            audit_log('query_project_permanently_deleted', 'query_project', $projectId, true, [
                'project_name' => (string) $project['name'],
                'field_count' => (int) $impact['field_count'],
                'record_count' => (int) $impact['active_record_count'] + (int) $impact['deleted_record_count'],
                'value_count' => (int) $impact['value_count'],
                'query_log_count' => (int) $impact['query_log_count'],
                'import_batch_count' => (int) $impact['import_batch_count'],
            ]);

            if ($ownsTransaction) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            } elseif (!$ownsTransaction && $pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            }
            audit_log('query_project_permanent_delete_failed', 'query_project', $projectId, false, ['reason' => 'transaction_failed']);
            throw $exception;
        }

        $cleanupFailed = false;
        $importsDirectory = APP_ROOT . '/storage/imports';
        $root = realpath($importsDirectory);
        foreach ($previewFiles as $filename) {
            if ($root === false) {
                $cleanupFailed = true;
                break;
            }
            $path = $root . DIRECTORY_SEPARATOR . basename($filename);
            if (is_file($path) && !@unlink($path)) {
                $cleanupFailed = true;
                error_log('Failed to remove project import preview file: ' . basename($filename));
            }
        }
        foreach ($draftTokens as $token) {
            try {
                QueryCreationDraftService::deleteDraftFiles($token);
            } catch (Throwable $exception) {
                $cleanupFailed = true;
                error_log(
                    'Failed to remove project query-draft files: '
                    . get_class($exception)
                );
            }
        }

        return ['impact' => $impact, 'cleanup_warning' => $cleanupFailed];
    }
}
