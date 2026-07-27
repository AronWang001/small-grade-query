<?php

declare(strict_types=1);

require_once __DIR__ . '/FieldDetectionService.php';
require_once __DIR__ . '/ExcelSecurityInspector.php';
require_once __DIR__ . '/ExcelUploadRateLimiter.php';

final class ExcelImportService
{
    public static function createSchemaPreview(int $adminId, int $projectId, string $mode, array $upload): array
    {
        self::validateMode($mode);
        ExcelSecurityInspector::assertUploadPresent($upload);
        $attemptId = ExcelUploadRateLimiter::accept($adminId, 'existing_project_import');
        try {
            $file = ExcelSecurityInspector::validateBeforeLoad($upload);
            $project = GradeRepository::project($projectId, true);
            if ($project === null) {
                throw new InvalidArgumentException('查询项目不存在。');
            }
            $existingFields = GradeRepository::fields($projectId);
            $extension = mb_strtolower(
                pathinfo((string) ($upload['name'] ?? 'upload.xlsx'), PATHINFO_EXTENSION),
                'UTF-8'
            );
            $reader = ExcelSecurityInspector::createReader($extension);
            $spreadsheet = $reader->load($file);
            try {
                ExcelSecurityInspector::validateSheet($spreadsheet->getSheet(0));
                $inspection = FieldDetectionService::inspectWorkbook(
                    $spreadsheet,
                    $project,
                    $existingFields
                );
            } finally {
                $spreadsheet->disconnectWorksheets();
            }

            $token = bin2hex(random_bytes(32));
            $payload = array_merge($inspection, [
                'stage' => 'schema',
                'project_id' => $projectId,
                'mode' => $mode,
                'created_at' => date(DATE_ATOM),
            ]);
            $path = self::writePayload($token, $payload);
            $stmt = db()->prepare(
                'INSERT INTO import_batches
                    (token, admin_id, project_id, import_mode, original_filename,
                     preview_path, total_rows, valid_rows, error_rows, expires_at,
                     purpose)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, DATE_ADD(NOW(), INTERVAL 1 HOUR), ?)'
            );
            $stmt->execute([
                $token,
                $adminId,
                $projectId,
                $mode,
                mb_substr(
                    basename((string) ($upload['name'] ?? 'upload.xlsx')),
                    0,
                    255,
                    'UTF-8'
                ),
                basename($path),
                count($inspection['raw_rows']),
                'import',
            ]);
            audit_log('excel_schema_detected', 'query_project', $projectId, true, [
                'columns' => count($inspection['columns']),
                'rows' => count($inspection['raw_rows']),
                'new_fields' => count(array_filter(
                    $inspection['columns'],
                    static fn (array $column): bool => (int) $column['existing_field_id'] === 0
                )),
            ]);
            return self::preview($token, $adminId)
                ?? throw new RuntimeException('无法读取字段识别结果。');
        } catch (Throwable $exception) {
            ExcelUploadRateLimiter::markFailed($attemptId, $adminId);
            throw $exception;
        }
    }

    public static function confirmSchema(string $token, int $adminId, array $submittedColumns, ?callable $progress = null): array
    {
        $preview = self::preview($token, $adminId);
        if ($preview === null || ($preview['payload']['stage'] ?? '') !== 'schema') {
            throw new InvalidArgumentException('字段识别结果已过期，请重新上传Excel。');
        }
        $batch = $preview['batch'];
        $payload = $preview['payload'];
        if ($progress !== null) $progress('字段确认：已读取预检文件');
        $projectId = (int) $batch['project_id'];
        $project = GradeRepository::project($projectId, true);
        if ($project === null) {
            throw new InvalidArgumentException('查询项目不存在。');
        }
        $existingFields = GradeRepository::fields($projectId);
        if (!hash_equals((string) $payload['schema_hash'], FieldDetectionService::projectSchemaHash($project, $existingFields))) {
            throw new RuntimeException('项目字段已发生变化，请重新上传Excel。');
        }

        $existingById = [];
        foreach ($existingFields as $field) {
            $existingById[(int) $field['id']] = $field;
        }
        $usedExistingIds = [];
        $usedKeys = array_fill_keys(array_map(static fn (array $field): string => (string) $field['field_key'], $existingFields), true);
        $confirmedColumns = [];
        $newDefinitions = [];
        foreach ($payload['columns'] as $detected) {
            $columnIndex = (int) $detected['column_index'];
            $submitted = is_array($submittedColumns[(string) $columnIndex] ?? null)
                ? $submittedColumns[(string) $columnIndex]
                : (is_array($submittedColumns[$columnIndex] ?? null) ? $submittedColumns[$columnIndex] : []);
            $mapping = (string) ($submitted['mapping'] ?? $detected['mapping'] ?? 'new');
            if ($mapping === 'ignore') {
                continue;
            }
            if (str_starts_with($mapping, 'existing:')) {
                $fieldId = (int) substr($mapping, 9);
                if (!isset($existingById[$fieldId]) || isset($usedExistingIds[$fieldId])) {
                    throw new InvalidArgumentException('字段“' . $detected['excel_header'] . '”映射的已有字段无效或重复。');
                }
                $field = $existingById[$fieldId];
                $usedExistingIds[$fieldId] = true;
                $confirmedColumns[] = self::existingColumn($detected, $field);
                continue;
            }
            if ($mapping !== 'new') {
                throw new InvalidArgumentException('字段映射选项无效。');
            }

            $fieldKey = (string) $detected['field_key'];
            $fieldLabel = trim((string) ($submitted['field_label'] ?? $detected['field_label']));
            $fieldType = (string) ($submitted['field_type'] ?? $detected['field_type']);
            if ($fieldLabel === '' || mb_strlen($fieldLabel, 'UTF-8') > 100) {
                throw new InvalidArgumentException('字段名称不能为空且不能超过100个字符。');
            }
            if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', $fieldKey) || !in_array($fieldType, ['text', 'number', 'date'], true)) {
                throw new InvalidArgumentException('自动生成的字段配置无效，请重新上传Excel。');
            }
            if (isset($usedKeys[$fieldKey]) && !in_array($fieldKey, array_column($newDefinitions, 'field_key'), true)) {
                throw new InvalidArgumentException('字段标识“' . $fieldKey . '”已存在，请将该列映射到已有字段。');
            }
            if (in_array($fieldKey, array_column($newDefinitions, 'field_key'), true)) {
                throw new InvalidArgumentException('字段标识“' . $fieldKey . '”重复。');
            }
            $isCondition = isset($submitted['is_condition']);
            $isVisible = isset($submitted['is_visible']);
            $fieldRole = $isCondition ? ($isVisible ? 'both' : 'condition') : 'result';
            $definition = [
                'field_key' => $fieldKey,
                'field_label' => $fieldLabel,
                'field_type' => $fieldType,
                'field_role' => $fieldRole,
                'is_required' => isset($submitted['is_required']) ? 1 : 0,
                'is_sensitive' => isset($submitted['is_sensitive']) ? 1 : 0,
                'is_visible' => $isVisible ? 1 : 0,
                'options' => [],
                'options_json' => '[]',
                'placeholder' => '',
                'validation_rule' => '',
                'decimal_places' => $fieldType === 'number' ? min(8, max(0, (int) ($detected['decimal_places'] ?? 2))) : 0,
                'sort_order' => (int) $detected['sort_order'],
                'id' => 0,
            ];
            $newDefinitions[] = $definition;
            $confirmedColumns[] = array_merge($detected, $definition, ['mapping' => 'new', 'existing_field_id' => 0]);
            $usedKeys[$fieldKey] = true;
        }
        if ($progress !== null) $progress('字段确认：配置校验完成');
        if ($confirmedColumns === []) {
            throw new InvalidArgumentException('请至少保留一个Excel字段。');
        }

        $mappedExistingIds = array_values(array_filter(array_map(
            static fn (array $column): int => (int) ($column['existing_field_id'] ?? 0),
            $confirmedColumns
        )));
        foreach ($existingFields as $field) {
            $isRequiredCondition = (int) $field['is_required'] === 1 && in_array($field['field_role'], ['condition', 'both'], true);
            if ($isRequiredCondition && !in_array((int) $field['id'], $mappedExistingIds, true)) {
                throw new InvalidArgumentException('本次Excel缺少必填查询条件“' . $field['field_label'] . '”，不能继续导入。');
            }
        }

        $prospectiveFields = array_merge($existingFields, $newDefinitions);
        $conditionFields = array_values(array_filter($prospectiveFields, static fn (array $field): bool => in_array($field['field_role'], ['condition', 'both'], true)));
        if ($conditionFields === []) {
            throw new InvalidArgumentException('请至少选择一个字段作为查询条件。');
        }
        $mappedConditionKeys = array_values(array_filter(array_map(
            static fn (array $column): string => in_array($column['field_role'], ['condition', 'both'], true) ? (string) $column['field_key'] : '',
            $confirmedColumns
        )));
        if ($mappedConditionKeys === []) {
            throw new InvalidArgumentException('本次Excel至少需要包含一个查询条件字段。');
        }

        [$rows, $errors] = self::validateRows($payload['raw_rows'], $confirmedColumns, $prospectiveFields);
        if ($progress !== null) $progress('字段确认：数据行校验完成');
        if ($rows === [] && $errors === []) {
            throw new InvalidArgumentException('Excel中没有可导入的数据。');
        }
        if ((string) $batch['import_mode'] === 'replace' && $errors !== []) {
            throw new InvalidArgumentException('覆盖导入存在错误行，必须修正所有错误后重新上传。');
        }

        $warnings = is_array($payload['warnings'] ?? null) ? $payload['warnings'] : [];
        if (count($conditionFields) < 2) {
            $warnings[] = '当前项目仅配置一个查询条件。为降低重名或重复编号风险，建议设置至少两个查询条件。';
        }
        $payload = array_merge($payload, [
            'stage' => 'data',
            'columns' => $confirmedColumns,
            'new_fields' => $newDefinitions,
            'rows' => $rows,
            'errors' => $errors,
            'warnings' => array_values(array_unique($warnings)),
            'imported_keys' => array_values(array_unique(array_column($confirmedColumns, 'field_key'))),
        ]);
        self::replacePayload((string) $batch['preview_path'], $payload);
        if ($progress !== null) $progress('字段确认：预检文件已更新');
        db()->prepare('UPDATE import_batches SET valid_rows = ?, error_rows = ?, total_rows = ? WHERE id = ? AND committed_at IS NULL')
            ->execute([count($rows), count($errors), count($rows) + count($errors), (int) $batch['id']]);
        if ($progress !== null) $progress('字段确认：批次统计已更新');
        audit_log('excel_schema_confirmed', 'query_project', $projectId, true, [
            'new_fields' => count($newDefinitions), 'valid_rows' => count($rows), 'error_rows' => count($errors),
        ]);
        if ($progress !== null) $progress('字段确认：审计日志已写入');
        return self::preview($token, $adminId) ?? throw new RuntimeException('无法读取数据预检结果。');
    }

    public static function preview(string $token, int $adminId): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $stmt = db()->prepare('SELECT * FROM import_batches WHERE token = ? AND admin_id = ? AND committed_at IS NULL AND expires_at > NOW()');
        $stmt->execute([$token, $adminId]);
        $batch = $stmt->fetch();
        if (!is_array($batch)) {
            return null;
        }
        $payload = self::readPayload((string) $batch['preview_path']);
        if (!is_array($payload) || (int) ($payload['project_id'] ?? 0) !== (int) $batch['project_id']) {
            return null;
        }
        $fields = GradeRepository::fields((int) $batch['project_id']);
        if (($payload['stage'] ?? '') === 'data' && is_array($payload['new_fields'] ?? null)) {
            array_push($fields, ...$payload['new_fields']);
            usort($fields, static fn (array $left, array $right): int => [(int) $left['sort_order'], (int) ($left['id'] ?? 0)] <=> [(int) $right['sort_order'], (int) ($right['id'] ?? 0)]);
        }
        return ['batch' => $batch, 'payload' => $payload, 'fields' => $fields];
    }

    public static function commitImport(string $token, int $adminId, ?callable $failureInjector = null): array
    {
        $preview = self::preview($token, $adminId);
        if ($preview === null || ($preview['payload']['stage'] ?? '') !== 'data') {
            throw new InvalidArgumentException('导入预检已过期或尚未确认字段，请重新上传。');
        }
        $batch = $preview['batch'];
        $payload = $preview['payload'];
        $projectId = (int) $batch['project_id'];
        $mode = (string) $batch['import_mode'];
        $pdo = db();
        $ownsTransaction = !$pdo->inTransaction();
        $savepoint = 'excel_import_' . bin2hex(random_bytes(4));
        $created = 0;
        $updated = 0;
        $skipped = count($payload['errors'] ?? []);
        try {
            if ($ownsTransaction) {
                $pdo->beginTransaction();
            } else {
                $pdo->exec('SAVEPOINT ' . $savepoint);
            }
            $batchLock = $pdo->prepare('SELECT * FROM import_batches WHERE id = ? AND token = ? AND admin_id = ? FOR UPDATE');
            $batchLock->execute([(int) $batch['id'], $token, $adminId]);
            $lockedBatch = $batchLock->fetch();
            if (!is_array($lockedBatch) || $lockedBatch['committed_at'] !== null || strtotime((string) $lockedBatch['expires_at']) <= time()) {
                throw new RuntimeException('该导入任务已经提交或已过期。');
            }
            $projectLock = $pdo->prepare('SELECT * FROM query_projects WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
            $projectLock->execute([$projectId]);
            $project = $projectLock->fetch();
            if (!is_array($project)) {
                throw new InvalidArgumentException('查询项目不存在。');
            }
            $currentFields = GradeRepository::fields($projectId);
            if (!hash_equals((string) $payload['schema_hash'], FieldDetectionService::projectSchemaHash($project, $currentFields))) {
                throw new RuntimeException('项目字段已在预检后发生变化，请重新上传Excel。');
            }

            $insertField = $pdo->prepare('INSERT INTO query_fields (project_id, field_key, field_label, field_type, field_role, is_required, is_sensitive, is_visible, options_json, placeholder, validation_rule, decimal_places, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($payload['new_fields'] ?? [] as $field) {
                $insertField->execute([
                    $projectId, $field['field_key'], $field['field_label'], $field['field_type'], $field['field_role'],
                    (int) $field['is_required'], (int) $field['is_sensitive'], (int) $field['is_visible'],
                    $field['options_json'] ?? '[]', '', '', (int) $field['decimal_places'], (int) $field['sort_order'],
                ]);
            }
            $fields = GradeRepository::fields($projectId);
            if (array_filter($fields, static fn (array $field): bool => in_array($field['field_role'], ['condition', 'both'], true)) === []) {
                throw new InvalidArgumentException('请至少选择一个字段作为查询条件。');
            }
            if ($mode === 'replace') {
                $pdo->prepare('UPDATE query_records SET deleted_at = NOW() WHERE project_id = ? AND deleted_at IS NULL')->execute([$projectId]);
            }
            foreach ($payload['rows'] as $row) {
                $values = is_array($row['values'] ?? null) ? $row['values'] : [];
                foreach ($fields as $field) {
                    $value = trim((string) ($values[$field['field_key']] ?? ''));
                    $error = GradeRepository::validateFieldValue($field, $value);
                    if ($error !== null) {
                        throw new InvalidArgumentException('第' . (int) $row['source_row'] . '行：' . $error);
                    }
                    $values[$field['field_key']] = $value;
                }
                $recordHash = GradeRepository::recordHash($fields, $values);
                $existing = $pdo->prepare('SELECT id FROM query_records WHERE project_id = ? AND record_hash = ? AND deleted_at IS NULL LIMIT 1 FOR UPDATE');
                $existing->execute([$projectId, $recordHash]);
                $recordId = $existing->fetchColumn();
                if ($recordId !== false && $mode === 'add') {
                    $skipped++;
                    continue;
                }
                if ($recordId !== false && $mode === 'upsert') {
                    $currentValues = GradeRepository::recordValues((int) $recordId, false);
                    foreach ($payload['imported_keys'] as $key) {
                        $currentValues[(string) $key] = (string) ($values[(string) $key] ?? '');
                    }
                    GradeRepository::saveRecord($projectId, $currentValues, (int) $recordId);
                    $updated++;
                } else {
                    GradeRepository::saveRecord($projectId, $values);
                    $created++;
                }
            }
            if ($failureInjector !== null) {
                $failureInjector($pdo, $payload);
            }
            $pdo->prepare('UPDATE import_batches SET committed_at = NOW() WHERE id = ?')->execute([(int) $batch['id']]);
            audit_log('excel_import_commit', 'query_project', $projectId, true, [
                'mode' => $mode, 'created' => $created, 'updated' => $updated, 'skipped' => $skipped,
                'created_fields' => count($payload['new_fields'] ?? []),
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
            audit_log('excel_import_commit', 'query_project', $projectId, false, ['mode' => $mode]);
            throw $exception;
        }

        $path = self::payloadPath((string) $batch['preview_path']);
        if ($path !== null && is_file($path) && !@unlink($path)) {
            error_log('Failed to remove committed Excel preview: ' . basename($path));
        }
        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'errors' => count($payload['errors'] ?? []), 'fields_created' => count($payload['new_fields'] ?? [])];
    }

    private static function validateRows(array $rawRows, array $columns, array $fields): array
    {
        $rows = [];
        $errors = [];
        $seen = [];
        foreach ($rawRows as $rawRow) {
            $values = [];
            $rowErrors = [];
            $rowEmpty = true;
            foreach ($columns as $column) {
                $cell = $rawRow['cells'][(string) $column['column_index']] ?? ['value' => '', 'formula' => false];
                $value = trim((string) ($cell['value'] ?? ''));
                if (!empty($cell['formula'])) {
                    $rowErrors[] = $column['field_label'] . '不允许使用公式';
                }
                if ($value !== '') {
                    $rowEmpty = false;
                }
                $values[(string) $column['field_key']] = $value;
            }
            if ($rowEmpty && $rowErrors === []) {
                continue;
            }
            foreach ($fields as $field) {
                $value = (string) ($values[$field['field_key']] ?? '');
                $error = GradeRepository::validateFieldValue($field, $value);
                if ($error !== null) {
                    $rowErrors[] = $error;
                }
                $values[(string) $field['field_key']] = $value;
            }
            try {
                $fingerprint = GradeRepository::recordHash($fields, $values);
                if (isset($seen[$fingerprint])) {
                    $rowErrors[] = '与第' . $seen[$fingerprint] . '行查询条件重复';
                } else {
                    $seen[$fingerprint] = (int) $rawRow['source_row'];
                }
            } catch (Throwable $exception) {
                PublicErrorHandler::logException($exception, [
                    'component' => 'excel_import',
                    'operation' => 'record_fingerprint',
                ]);
                $rowErrors[] = '该行查询条件无法安全处理';
            }
            if ($rowErrors !== []) {
                $errors[] = ['row' => (int) $rawRow['source_row'], 'reason' => implode('；', array_unique($rowErrors))];
            } else {
                $rows[] = ['source_row' => (int) $rawRow['source_row'], 'values' => $values];
            }
        }
        return [$rows, $errors];
    }

    private static function existingColumn(array $detected, array $field): array
    {
        return array_merge($detected, [
            'field_label' => (string) $field['field_label'], 'field_key' => (string) $field['field_key'],
            'field_type' => (string) $field['field_type'], 'field_role' => (string) $field['field_role'],
            'is_required' => (int) $field['is_required'], 'is_sensitive' => (int) $field['is_sensitive'],
            'is_visible' => (int) $field['is_visible'], 'decimal_places' => (int) $field['decimal_places'],
            'sort_order' => (int) $field['sort_order'], 'existing_field_id' => (int) $field['id'],
            'mapping' => 'existing:' . (int) $field['id'],
        ]);
    }

    private static function validateMode(string $mode): void
    {
        if (!in_array($mode, ['add', 'upsert', 'replace'], true)) {
            throw new InvalidArgumentException('导入模式无效。');
        }
    }

    private static function writePayload(string $token, array $payload): string
    {
        $directory = APP_ROOT . '/storage/imports';
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('无法创建导入预检目录。');
        }
        $path = $directory . '/' . $token . '.json';
        self::writePayloadFile($path, $payload);
        @chmod($path, 0660);
        return $path;
    }

    private static function replacePayload(string $filename, array $payload): void
    {
        $path = self::payloadPath($filename);
        if ($path === null) {
            throw new RuntimeException('导入预检文件无效。');
        }
        self::writePayloadFile($path, $payload);
    }

    private static function writePayloadFile(string $path, array $payload): void
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || file_put_contents($path, encrypt_value($encoded), LOCK_EX) === false) {
            throw new RuntimeException('无法保存导入预检结果。');
        }
    }

    private static function readPayload(string $filename): ?array
    {
        $path = self::payloadPath($filename);
        if ($path === null || !is_file($path)) {
            return null;
        }
        $encoded = file_get_contents($path);
        if (!is_string($encoded)) {
            return null;
        }
        try {
            $payload = json_decode(decrypt_value($encoded), true);
        } catch (Throwable) {
            return null;
        }
        return is_array($payload) ? $payload : null;
    }

    private static function payloadPath(string $filename): ?string
    {
        $basename = basename($filename);
        if (!preg_match('/^[a-f0-9]{64}\.json$/', $basename)) {
            return null;
        }
        return APP_ROOT . '/storage/imports/' . $basename;
    }
}
