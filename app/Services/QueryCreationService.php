<?php

declare(strict_types=1);

/**
 * 查询创建服务
 * 
 * 易查分风格的五步向导核心业务逻辑：
 *   步骤1：上传 Excel → 验证 → 解析表头
 *   步骤2：确认字段类型和敏感属性
 *   步骤3：设置查询条件和显示结果
 *   步骤4：设置查询名称、页面提示
 *   步骤5：预览并发布
 * 
 * 发布前不创建正式项目，所有中间数据保存在草稿表 + 加密预览文件中。
 */

require_once __DIR__ . '/QueryCreationDraftService.php';
require_once __DIR__ . '/ExcelSecurityInspector.php';
require_once __DIR__ . '/ExcelUploadRateLimiter.php';
require_once __DIR__ . '/FieldDetectionService.php';
require_once __DIR__ . '/ProjectService.php';
require_once __DIR__ . '/../Repositories/GradeRepository.php';

final class QueryCreationService
{
    // ============================================================
    // 步骤1：上传 Excel 并解析表头
    // ============================================================

    /**
     * 处理 Excel 上传，验证文件、解析表头、提取数据预览
     * 
     * @param int   $adminId 管理员 ID
     * @param array $upload  $_FILES['excel_file']
     * @return array ['token' => ..., 'columns' => ..., 'preview_rows' => ..., ...]
     */
    public static function step1Upload(int $adminId, array $upload): array
    {
        ExcelSecurityInspector::assertUploadPresent($upload);
        $attemptId = ExcelUploadRateLimiter::accept($adminId, 'new_query');
        $draft = null;
        $spreadsheet = null;
        try {
            $tmpPath = ExcelSecurityInspector::validateBeforeLoad($upload);
            $originalName = (string) ($upload['name'] ?? 'upload.xlsx');
            $extension = mb_strtolower(pathinfo($originalName, PATHINFO_EXTENSION), 'UTF-8');
            $suggestedName = pathinfo($originalName ?: '查询项目', PATHINFO_FILENAME);
            if ($suggestedName === '' || $suggestedName === 'upload') {
                $suggestedName = '查询项目' . date('Ymd');
            }
            $suggestedName = mb_substr($suggestedName, 0, 150, 'UTF-8');

            $draft = QueryCreationDraftService::create(
                $adminId,
                $originalName,
                $suggestedName
            );
            $savedPath = QueryCreationDraftService::storeSourceFile(
                $draft['token'],
                $tmpPath,
                $extension
            );

            $reader = ExcelSecurityInspector::createReader($extension);
            $spreadsheet = $reader->load($savedPath);
            $sheet = $spreadsheet->getSheet(0);
            ExcelSecurityInspector::validateSheet($sheet);

            $highestRow = $sheet->getHighestDataRow();
            $highestCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

            // 检查合并单元格
            if (!empty($sheet->getMergeCells())) {
                throw new \InvalidArgumentException('检测到合并单元格。请取消合并后重新上传，第一行必须是普通表头。');
            }

            // 解析表头
            $columns = [];
            $seenHeaders = [];
            for ($col = 1; $col <= $highestCol; $col++) {
                $header = trim((string)($sheet->getCell([$col, 1])->getValue() ?? ''));
                $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);

                if ($header === '') {
                    // 检查该列下方是否有数据
                    $hasData = false;
                    for ($r = 2; $r <= min($highestRow, 20); $r++) {
                        $v = $sheet->getCell([$col, $r])->getValue();
                        if ($v !== null && trim((string)$v) !== '') { $hasData = true; break; }
                    }
                    if ($hasData) {
                        throw new \InvalidArgumentException("第 {$letter} 列表头为空但下方有数据，请填写表头名称。");
                    }
                    continue;
                }

                if (mb_strlen($header, 'UTF-8') > 100) {
                    throw new \InvalidArgumentException("第 {$letter} 列表头超过 100 个字符。");
                }

                $normalized = mb_strtolower(trim(preg_replace('/[\s_-]+/u', ' ', $header) ?? $header), 'UTF-8');
                if (isset($seenHeaders[$normalized])) {
                    $prevCol = $seenHeaders[$normalized];
                    throw new \InvalidArgumentException('表头"' . $header . '"与第 ' . $prevCol . ' 列重复，请修改为不同名称。');
                }
                $seenHeaders[$normalized] = $col;

                // 智能推断字段属性
                $inferred = self::inferField($header);

                $columns[] = [
                    'column_index' => $col,
                    'column_letter' => $letter,
                    'header' => $header,
                    'label' => $header,
                    'key' => $inferred['key'],
                    'type' => $inferred['type'],
                    'sensitive' => $inferred['sensitive'],
                    'decimal_places' => $inferred['type'] === 'number' ? 2 : 0,
                    'options' => [],
                    'validation_rule' => '',
                    'sort_order' => $col - 1,
                ];
            }

            if (empty($columns)) {
                throw new \InvalidArgumentException('未检测到任何有效表头，请检查 Excel 文件。');
            }

            // 将完整数据加密写入草稿；发布阶段不再依赖原始Excel。
            $previewRows = [];
            $rawRows = [];
            for ($row = 2; $row <= $highestRow; $row++) {
                $rowData = [];
                foreach ($columns as $c) {
                    $val = $sheet->getCell([$c['column_index'], $row])->getValue();
                    $rowData[(string) $c['column_index']] = $val !== null
                        ? trim((string) $val)
                        : '';
                }
                $rawRows[] = $rowData;
                if (count($previewRows) < 10) {
                    $previewRows[] = array_values($rowData);
                }
            }

            $totalRows = $highestRow - 1;
            $payload = [
                'columns' => $columns,
                'preview_rows' => $previewRows,
                'raw_rows' => $rawRows,
                'total_rows' => $totalRows,
                'sheet_name' => $sheet->getTitle(),
            ];
            QueryCreationDraftService::savePreviewFile(
                $draft['id'],
                $draft['token'],
                $payload
            );

            // 保存步骤数据（不含完整数据行）
            QueryCreationDraftService::updateStepData($draft['id'], [
                'columns' => $columns,
                'total_rows' => $totalRows,
                'suggested_name' => $suggestedName,
            ]);

            audit_log('query_creation_file_uploaded', 'query_creation_draft', $draft['id'], true, [
                'columns' => count($columns),
                'rows' => $totalRows,
            ]);

            // 加密草稿和数据库记录已经就绪，立即精准删除原始Excel。
            $spreadsheet->disconnectWorksheets();
            $spreadsheet = null;
            QueryCreationDraftService::deleteSourceFiles($draft['token']);

            return [
                'success' => true,
                'token' => $draft['token'],
                'draft_id' => $draft['id'],
                'columns' => $columns,
                'preview_rows' => $previewRows,
                'total_rows' => $totalRows,
                'suggested_name' => $suggestedName,
                'sheet_name' => $sheet->getTitle(),
            ];
        } catch (Throwable $exception) {
            ExcelUploadRateLimiter::markFailed($attemptId, $adminId);
            if (is_array($draft)) {
                try {
                    QueryCreationDraftService::deleteDraftFiles((string) $draft['token']);
                    db()->prepare(
                        'DELETE FROM query_creation_drafts
                         WHERE id = ? AND admin_id = ? AND published_at IS NULL'
                    )->execute([(int) $draft['id'], $adminId]);
                } catch (Throwable $cleanupException) {
                    error_log('New query upload cleanup failed: ' . get_class($cleanupException));
                }
            }
            throw $exception;
        } finally {
            if ($spreadsheet instanceof \PhpOffice\PhpSpreadsheet\Spreadsheet) {
                $spreadsheet->disconnectWorksheets();
            }
        }
    }

    /**
     * 步骤2：更新字段配置（类型、敏感属性、标签等）
     */
    public static function step2ConfirmFields(int $draftId, array $columns): void
    {
        $stepData = QueryCreationDraftService::getStepData($draftId);
        $stepData['columns'] = $columns;
        QueryCreationDraftService::updateStepData($draftId, $stepData);
        QueryCreationDraftService::updateStep($draftId, 2);

        audit_log('query_creation_schema_confirmed', 'query_creation_draft', $draftId, true, [
            'field_count' => count($columns),
        ]);
    }

    /**
     * 步骤3：设置查询条件和显示结果
     * 
     * @param array $settings ['conditions' => [['column_index' => 1, 'required' => true], ...], 'results' => [2, 3, ...]]
     */
    public static function step3QuerySettings(int $draftId, array $settings): void
    {
        $stepData = QueryCreationDraftService::getStepData($draftId);
        $columns = $stepData['columns'] ?? [];

        // 验证至少有一个查询条件
        $conditions = $settings['conditions'] ?? [];
        if (empty($conditions)) {
            // 自动推荐
            $autoRecommended = self::recommendConditions($columns);
            if (empty($autoRecommended)) {
                throw new \InvalidArgumentException('至少需要选择一个查询条件。');
            }
            $conditions = $autoRecommended;
        }

        // 安全检查：低区分度字段组合
        $weakFields = ['name' => '姓名', 'class_name' => '班级', 'gender' => '性别'];
        $hasStrongField = false;
        foreach ($conditions as $cond) {
            $colIdx = is_array($cond) ? ($cond['column_index'] ?? $cond) : $cond;
            foreach ($columns as $col) {
                if ((int)$col['column_index'] === (int)$colIdx) {
                    $key = $col['key'];
                    if (!isset($weakFields[$key]) && preg_match('/(学号|身份证|手机|准考证|student|id_card|phone)/iu', $col['header'])) {
                        $hasStrongField = true;
                    }
                }
            }
        }

        $stepData['query_settings'] = [
            'conditions' => $conditions,
            'results' => $settings['results'] ?? [],
            'has_strong_condition' => $hasStrongField,
            'security_warning' => !$hasStrongField ? '当前查询条件容易匹配到多人或被批量猜测，建议增加学号、身份证号等识别字段。' : null,
        ];

        QueryCreationDraftService::updateStepData($draftId, $stepData);
        QueryCreationDraftService::updateStep($draftId, 3);
    }

    /**
     * 步骤4：设置页面信息
     */
    public static function step4PageSettings(int $draftId, array $pageSettings): void
    {
        $stepData = QueryCreationDraftService::getStepData($draftId);

        $queryName = trim((string)($pageSettings['name'] ?? ''));
        if ($queryName === '' || mb_strlen($queryName, 'UTF-8') > 150) {
            throw new \InvalidArgumentException('请填写 150 个字符以内的查询名称。');
        }

        $stepData['page_settings'] = [
            'name' => $queryName,
            'login_notice' => trim((string)($pageSettings['login_notice'] ?? '')),
            'result_notice' => trim((string)($pageSettings['result_notice'] ?? '')),
            'description' => trim((string)($pageSettings['description'] ?? '')),
            'status' => $pageSettings['publish_now'] ? 'enabled' : 'disabled',
        ];

        QueryCreationDraftService::updateStepData($draftId, $stepData);
        QueryCreationDraftService::updateStep($draftId, 4);
    }

    /**
     * 步骤5：最终发布
     * 
     * 在单个数据库事务中完成：创建项目 → 创建字段 → 导入数据 → 标记草稿已发布
     */
    public static function step5Publish(int $draftId, int $adminId): array
    {
        $pdo = db();
        if ($pdo->inTransaction()) {
            throw new RuntimeException('发布操作不能嵌套在其他事务中。');
        }
        $pdo->beginTransaction();
        try {
            $draftCheck = $pdo->prepare(
                'SELECT id, token, admin_id, current_step, original_filename,
                        preview_path, step_data_json, expires_at,
                        published_at, published_project_id
                 FROM query_creation_drafts
                 WHERE id = ? AND admin_id = ?
                 FOR UPDATE'
            );
            $draftCheck->execute([$draftId, $adminId]);
            $lockedDraft = $draftCheck->fetch(PDO::FETCH_ASSOC);
            if (!is_array($lockedDraft)) {
                throw new RuntimeException('草稿不存在或无权访问。');
            }

            if (
                !empty($lockedDraft['published_at'])
                || !empty($lockedDraft['published_project_id'])
            ) {
                $projectId = (int) $lockedDraft['published_project_id'];
                $projectStmt = $pdo->prepare(
                    'SELECT id, name, slug, status FROM query_projects WHERE id = ?'
                );
                $projectStmt->execute([$projectId]);
                $project = $projectStmt->fetch(PDO::FETCH_ASSOC);
                if (!is_array($project)) {
                    throw new RuntimeException('已发布项目不存在，请联系管理员检查数据。');
                }
                $fieldStmt = $pdo->prepare(
                    'SELECT COUNT(*) FROM query_fields
                     WHERE project_id = ? AND deleted_at IS NULL'
                );
                $fieldStmt->execute([$projectId]);
                $recordStmt = $pdo->prepare(
                    'SELECT COUNT(*) FROM query_records
                     WHERE project_id = ? AND deleted_at IS NULL'
                );
                $recordStmt->execute([$projectId]);
                $fieldCount = (int) $fieldStmt->fetchColumn();
                $recordCount = (int) $recordStmt->fetchColumn();
                $pdo->commit();
                return [
                    'success' => true,
                    'already_published' => true,
                    'project_id' => $projectId,
                    'slug' => (string) $project['slug'],
                    'name' => (string) $project['name'],
                    'status' => (string) $project['status'],
                    'imported_rows' => $recordCount,
                    'error_count' => 0,
                    'errors' => [],
                    'field_count' => $fieldCount,
                ];
            }
            if (
                !empty($lockedDraft['expires_at'])
                && strtotime((string) $lockedDraft['expires_at']) < time()
            ) {
                throw new RuntimeException('草稿已过期。');
            }
            if ((int) $lockedDraft['current_step'] < 4) {
                throw new RuntimeException('请先完成发布前的全部设置。');
            }

            $stepData = json_decode((string) $lockedDraft['step_data_json'], true);
            if (!is_array($stepData)) {
                throw new RuntimeException('草稿配置不完整，请重新上传Excel。');
            }
            $columns = is_array($stepData['columns'] ?? null) ? $stepData['columns'] : [];
            $pageSettings = is_array($stepData['page_settings'] ?? null)
                ? $stepData['page_settings']
                : [];
            $querySettings = is_array($stepData['query_settings'] ?? null)
                ? $stepData['query_settings']
                : [];
            $previewData = QueryCreationDraftService::readPreviewFile(
                (string) $lockedDraft['preview_path']
            );
            $rawRows = is_array($previewData['raw_rows'] ?? null)
                ? $previewData['raw_rows']
                : [];
            if ($previewData === null || $columns === [] || $rawRows === []) {
                throw new RuntimeException('草稿数据不完整，请重新上传Excel。');
            }

            $conditions = is_array($querySettings['conditions'] ?? null)
                ? $querySettings['conditions']
                : [];
            $results = is_array($querySettings['results'] ?? null)
                ? $querySettings['results']
                : [];
            if ($conditions === []) {
                throw new RuntimeException('请至少设置一个查询条件。');
            }
            if ($results === []) {
                foreach ($columns as $column) {
                    if (
                        !self::isSensitiveByDefault((string) $column['header'])
                        && ($column['type'] ?? 'text') !== 'date'
                    ) {
                        $results[] = (int) $column['column_index'];
                    }
                }
            }

            $slug = ProjectService::generateUniqueSlug($pdo);
            $name = trim((string) ($pageSettings['name'] ?? '查询项目'));
            $status = in_array(
                (string) ($pageSettings['status'] ?? 'enabled'),
                ['enabled', 'disabled'],
                true
            ) ? (string) ($pageSettings['status'] ?? 'enabled') : 'enabled';
            $loginNotice = trim((string) ($pageSettings['login_notice'] ?? ''));
            $resultNotice = trim((string) ($pageSettings['result_notice'] ?? ''));
            $description = trim((string) ($pageSettings['description'] ?? ''));

            $maxSort = (int) $pdo->query(
                'SELECT COALESCE(MAX(sort_order), 0)
                 FROM query_projects WHERE deleted_at IS NULL'
            )->fetchColumn();
            $sortOrder = $maxSort + 10;

            $pdo->prepare(
                'INSERT INTO query_projects
                    (name, slug, description, notice, login_notice, result_notice,
                     status, sort_order, published_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $name,
                $slug,
                $description,
                '',
                $loginNotice,
                $resultNotice,
                $status,
                $sortOrder,
                $status === 'enabled' ? date('Y-m-d H:i:s') : null,
            ]);
            $projectId = (int) $pdo->lastInsertId();

            $sort = 0;
            $insertField = $pdo->prepare(
                'INSERT INTO query_fields
                    (project_id, field_key, field_label, field_type, field_role,
                     is_required, is_sensitive, is_visible, decimal_places, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $fieldKeyUsed = [];
            $columnFieldMap = [];
            foreach ($columns as $col) {
                $colIdx = (int) $col['column_index'];
                $isCondition = false;
                $isResult = false;
                foreach ($conditions as $c) {
                    if (
                        (int) (is_array($c) ? ($c['column_index'] ?? 0) : $c)
                        === $colIdx
                    ) {
                        $isCondition = true;
                    }
                }
                foreach ($results as $r) {
                    if ((int) $r === $colIdx) {
                        $isResult = true;
                    }
                }
                if (!$isCondition && !$isResult) {
                    continue;
                }
                $role = $isCondition && $isResult ? 'both' : ($isCondition ? 'condition' : 'result');
                $required = $isCondition;
                $key = (string) $col['key'];
                $orig = $key;
                $cnt = 1;
                while (isset($fieldKeyUsed[$key])) {
                    $key = $orig . '_' . $cnt++;
                }
                $fieldKeyUsed[$key] = true;
                $sensitive = (int) ($col['sensitive'] ?? 0);
                $visible = $sensitive ? 0 : 1;
                $insertField->execute([
                    $projectId,
                    $key,
                    (string) $col['label'],
                    (string) ($col['type'] ?? 'text'),
                    $role,
                    $required ? 1 : 0,
                    $sensitive,
                    $visible,
                    ($col['type'] ?? 'text') === 'number'
                        ? (int) ($col['decimal_places'] ?? 2)
                        : 0,
                    $sort++,
                ]);
                $columnFieldMap[$colIdx] = [
                    'field_id' => (int) $pdo->lastInsertId(),
                    'key' => $key,
                    'type' => (string) ($col['type'] ?? 'text'),
                    'label' => (string) $col['label'],
                    'sensitive' => $sensitive,
                    'required' => $required ? 1 : 0,
                ];
            }
            if ($columnFieldMap === []) {
                throw new RuntimeException('没有可创建的字段，请检查查询条件配置。');
            }

            $imported = 0;
            $errors = [];
            $insRecord = $pdo->prepare(
                'INSERT INTO query_records (project_id, record_hash) VALUES (?, ?)'
            );
            $insValue = $pdo->prepare(
                'INSERT INTO query_record_values
                    (record_id, field_id, value_text, value_number, value_date,
                     value_encrypted, search_hash)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $chkDup = $pdo->prepare(
                'SELECT id FROM query_records
                 WHERE project_id = ? AND record_hash = ? AND deleted_at IS NULL
                 LIMIT 1'
            );

            foreach ($rawRows as $rowOffset => $sourceRow) {
                if (!is_array($sourceRow)) {
                    continue;
                }
                $excelRow = $rowOffset + 2;
                $rowValues = [];
                $hasData = false;
                $rowOk = true;

                foreach ($columnFieldMap as $columnIndex => $field) {
                    $raw = $sourceRow[(string) $columnIndex]
                        ?? $sourceRow[$columnIndex]
                        ?? '';
                    $value = trim((string) $raw);
                    if ($value !== '') {
                        $hasData = true;
                    }
                    $text = null;
                    $number = null;
                    $date = null;
                    $encrypted = null;
                    $searchHash = null;

                    if ($value === '' && (int) $field['required'] === 1) {
                        $errors[] = sprintf(
                            '第%d行“%s”为必填项，已跳过。',
                            $excelRow,
                            $field['label']
                        );
                        $rowOk = false;
                        break;
                    }
                    if ($value !== '') {
                        if ($field['type'] === 'number') {
                            if (!is_numeric($value)) {
                                $errors[] = sprintf(
                                    '第%d行“%s”不是有效数字。',
                                    $excelRow,
                                    $field['label']
                                );
                                $rowOk = false;
                                break;
                            }
                            $number = normalize_value($value, 'number');
                            $searchHash = value_search_hash($number, 'number');
                        } elseif ($field['type'] === 'date') {
                            $date = normalize_value($value, 'date');
                            if ($date === '') {
                                $errors[] = sprintf(
                                    '第%d行“%s”不是有效日期。',
                                    $excelRow,
                                    $field['label']
                                );
                                $rowOk = false;
                                break;
                            }
                            $searchHash = value_search_hash($date, 'date');
                        } else {
                            $searchHash = value_search_hash($value, 'text');
                            if ((int) $field['sensitive'] === 1) {
                                $encrypted = encrypt_value($value);
                            } else {
                                $text = $value;
                            }
                        }
                    }
                    $rowValues[] = [
                        'field_id' => (int) $field['field_id'],
                        'value_text' => $text,
                        'value_number' => $number,
                        'value_date' => $date,
                        'value_encrypted' => $encrypted,
                        'search_hash' => $searchHash,
                        'record_part' => (int) $field['field_id'] . ':' . ($searchHash ?? ''),
                    ];
                }

                if (!$rowOk || !$hasData) {
                    continue;
                }
                $parts = array_column($rowValues, 'record_part');
                sort($parts, SORT_STRING);
                $recordHash = hash('sha256', implode('|', $parts));
                $chkDup->execute([$projectId, $recordHash]);
                if ($chkDup->fetchColumn() !== false) {
                    $errors[] = sprintf('第%d行数据重复，已跳过。', $excelRow);
                    continue;
                }

                $insRecord->execute([$projectId, $recordHash]);
                $recordId = (int) $pdo->lastInsertId();
                foreach ($rowValues as $rowValue) {
                    $insValue->execute([
                        $recordId,
                        $rowValue['field_id'],
                        $rowValue['value_text'],
                        $rowValue['value_number'],
                        $rowValue['value_date'],
                        $rowValue['value_encrypted'],
                        $rowValue['search_hash'],
                    ]);
                }
                $imported++;
            }

            $batchToken = bin2hex(random_bytes(32));
            $batch = $pdo->prepare(
                'INSERT INTO import_batches
                    (token, admin_id, project_id, draft_token, purpose, import_mode,
                     original_filename, preview_path, total_rows, valid_rows,
                     error_rows, expires_at, committed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
            );
            $batch->execute([
                $batchToken,
                $adminId,
                $projectId,
                (string) $lockedDraft['token'],
                'new_query',
                'add',
                mb_substr(
                    basename((string) $lockedDraft['original_filename']),
                    0,
                    255,
                    'UTF-8'
                ),
                '',
                count($rawRows),
                $imported,
                count($errors),
            ]);

            QueryCreationDraftService::markPublished(
                $pdo,
                $draftId,
                $adminId,
                $projectId
            );

            audit_log('query_creation_published', 'query_project', $projectId, true, [
                'draft_id' => $draftId,
                'name' => $name,
                'slug' => $slug,
                'imported' => $imported,
                'errors' => count($errors),
                'status' => $status,
            ]);
            $pdo->commit();

            $cleanupWarning = false;
            try {
                QueryCreationDraftService::deleteDraftFiles(
                    (string) $lockedDraft['token']
                );
            } catch (Throwable $cleanupException) {
                $cleanupWarning = true;
                error_log(
                    'Published query draft cleanup failed: '
                    . get_class($cleanupException)
                );
            }

            return [
                'success' => true,
                'already_published' => false,
                'project_id' => $projectId,
                'slug' => $slug,
                'name' => $name,
                'status' => $status,
                'imported_rows' => $imported,
                'error_count' => count($errors),
                'errors' => array_slice($errors, 0, 20),
                'field_count' => count($columnFieldMap),
                'cleanup_warning' => $cleanupWarning,
            ];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            audit_log('query_creation_failed', 'query_creation_draft', $draftId, false, [
                'failure_class' => get_class($exception),
            ]);
            throw $exception;
        }
    }

    // ============================================================
    // 辅助方法
    // ============================================================

    /**
     * 智能推断字段属性
     */
    private static function inferField(string $header): array
    {
        // 身份证/证件 → 文本+敏感
        if (preg_match('/(身份证|证件号|id\s*card|护照|passport)/iu', $header)) {
            return ['key' => 'id_card', 'type' => 'text', 'sensitive' => true];
        }
        // 手机/电话 → 文本+敏感
        if (preg_match('/(手机|电话|phone|mobile|邮箱|email|地址)/iu', $header)) {
            return ['key' => self::genKey($header), 'type' => 'text', 'sensitive' => true];
        }
        // 姓名
        if (preg_match('/^(姓名|名字|name)$/iu', $header)) {
            return ['key' => 'name', 'type' => 'text', 'sensitive' => false];
        }
        // 学号/准考证号 → 文本（非数字，避免精度丢失）
        if (preg_match('/(学号|准考证号|考生号|student\s*number)/iu', $header)) {
            return ['key' => 'student_number', 'type' => 'text', 'sensitive' => false];
        }
        // 班级
        if (preg_match('/(班级|class|年级|grade)/iu', $header)) {
            return ['key' => 'class_name', 'type' => 'text', 'sensitive' => false];
        }
        // 成绩/分数/绩点/排名 → 数字
        if (preg_match('/(成绩|分数|得分|score|绩点|gpa|排名|rank|学分|credit)/iu', $header)) {
            return ['key' => self::genKey($header), 'type' => 'number', 'sensitive' => false];
        }
        // 日期
        if (preg_match('/(日期|时间|date|time)/iu', $header)) {
            return ['key' => self::genKey($header), 'type' => 'date', 'sensitive' => false];
        }
        // 默认文本
        return ['key' => self::genKey($header), 'type' => 'text', 'sensitive' => false];
    }

    /**
     * 自动推荐查询条件
     * 优先级：姓名+身份证 > 姓名+学号 > 姓名+手机号 > 身份证 > 学号
     */
    public static function recommendConditions(array $columns): array
    {
        $byKey = [];
        foreach ($columns as $col) {
            $byKey[$col['key']] = $col;
        }

        // 第一优先：姓名 + 身份证号
        if (isset($byKey['name']) && isset($byKey['id_card'])) {
            return [
                ['column_index' => $byKey['name']['column_index'], 'required' => true],
                ['column_index' => $byKey['id_card']['column_index'], 'required' => true],
            ];
        }
        // 姓名 + 学号
        if (isset($byKey['name']) && isset($byKey['student_number'])) {
            return [
                ['column_index' => $byKey['name']['column_index'], 'required' => true],
                ['column_index' => $byKey['student_number']['column_index'], 'required' => true],
            ];
        }
        // 手机号
        if (isset($byKey['phone'])) {
            return [['column_index' => $byKey['phone']['column_index'], 'required' => true]];
        }
        // 身份证
        if (isset($byKey['id_card'])) {
            return [['column_index' => $byKey['id_card']['column_index'], 'required' => true]];
        }
        // 学号
        if (isset($byKey['student_number'])) {
            return [['column_index' => $byKey['student_number']['column_index'], 'required' => true]];
        }
        // 最后：姓名
        if (isset($byKey['name'])) {
            return [['column_index' => $byKey['name']['column_index'], 'required' => true]];
        }

        return [];
    }

    /**
     * 默认敏感字段判断
     */
    public static function isSensitiveByDefault(string $header): bool
    {
        return (bool)preg_match('/(身份证|证件|手机|电话|phone|邮箱|email|护照|passport|地址)/iu', $header);
    }

    private static function genKey(string $header): string
    {
        if (preg_match('/^[\x20-\x7E]+$/', $header)) {
            $k = mb_strtolower($header, 'UTF-8');
            $k = preg_replace('/[\s-]+/', '_', $k) ?? '';
            $k = preg_replace('/[^a-z0-9_]+/', '', $k) ?? '';
            return trim($k, '_') ?: 'field';
        }
        return 'field_' . bin2hex(random_bytes(3));
    }

}
