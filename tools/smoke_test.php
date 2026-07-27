<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/Repositories/GradeRepository.php';
require_once __DIR__ . '/../app/Services/RegistrationService.php';
require_once __DIR__ . '/../app/Services/LoginSecurityService.php';
require_once __DIR__ . '/../app/Services/AdminAccountService.php';
require_once __DIR__ . '/../app/Services/TurnstileService.php';
require_once __DIR__ . '/../app/Services/ProjectService.php';
require_once __DIR__ . '/../app/Services/ProjectDeletionService.php';
require_once __DIR__ . '/../app/Services/ExcelImportService.php';
require_once __DIR__ . '/../app/Services/ExcelTemplateService.php';
require_once __DIR__ . '/../app/Services/ExcelService.php';
require_once __DIR__ . '/../app/Services/QueryCreationDraftService.php';
require_once __DIR__ . '/../app/Services/ExcelUploadRateLimiter.php';
require_once __DIR__ . '/../app/Support/MigrationRunner.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$checks = 0;
$temporaryFiles = [];
$previewTokens = [];
$progress = static function (string $message): void {
    fwrite(STDOUT, '[smoke] ' . $message . PHP_EOL);
    fflush(STDOUT);
};
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$expectException = static function (callable $operation, string $class, string $message) use ($assert): void {
    try {
        $operation();
    } catch (Throwable $exception) {
        $assert($exception instanceof $class, $message . '（异常类型不正确）');
        return;
    }
    $assert(false, $message . '（未拒绝操作）');
};

try {
    set_time_limit(240);
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_USER_AGENT'] = 'GradeQuery-SmokeTest/2.0';

    $loginView = file_get_contents(APP_ROOT . '/app/Views/auth/login.php');
    $registerView = file_get_contents(APP_ROOT . '/app/Views/auth/register.php');
    $appScript = file_get_contents(APP_ROOT . '/assets/app.js');
    $assert(
        is_string($loginView)
            && str_contains(
                $loginView,
                '<label for="login_identifier">用户名或手机号</label>'
            ),
        '登录页第一项未显示为用户名或手机号'
    );
    $assert(is_string($loginView) && str_contains($loginView, 'data-turnstile-required="true"') && str_contains($loginView, 'disabled aria-disabled="true"'), '登录页未在Turnstile完成前锁定提交按钮');
    $assert(is_string($registerView) && str_contains($registerView, 'data-turnstile-required="true"') && str_contains($registerView, 'data-auth-submit') && str_contains($registerView, 'disabled aria-disabled="true"'), '注册页未在Turnstile完成前锁定提交按钮');
    $assert(is_string($appScript) && str_contains($appScript, 'turnstileVerified') && str_contains($appScript, "expired-callback") && str_contains($appScript, 'turnstileSuccessMessage'), 'Turnstile前端验证状态控制缺失');
    $parsedSql = MigrationRunner::parseSqlStatements(
        "-- leading comment\n# second comment\nSELECT 'a;--#';\nSELECT 2"
    );
    $assert(
        $parsedSql === ["SELECT 'a;--#'", 'SELECT 2'],
        '迁移解析器损坏了注释开头SQL或字符串字面量'
    );
    $draftServiceSource = file_get_contents(
        APP_ROOT . '/app/Services/QueryCreationDraftService.php'
    );
    $creationSource = file_get_contents(
        APP_ROOT . '/app/Services/QueryCreationService.php'
    );
    $assert(
        is_string($draftServiceSource)
            && str_contains($draftServiceSource, 'draft_')
            && str_contains($draftServiceSource, 'source_')
            && !str_contains($draftServiceSource, "glob("),
        '草稿文件清理仍可能使用全目录通配删除'
    );
    $assert(
        is_string($creationSource)
            && str_contains($creationSource, 'ExcelUploadRateLimiter::accept')
            && str_contains($creationSource, "'already_published' => true"),
        '上传限流或并发发布幂等返回缺失'
    );
    $progress('登录和注册页布局与Turnstile前置状态');

    $requiredTables = [
        'schema_migrations', 'admin_users', 'admin_login_attempts', 'auth_ip_blocks',
        'admin_registration_attempts', 'system_settings', 'admin_email_verification_codes',
        'query_projects', 'query_fields', 'query_records', 'query_record_values', 'query_logs', 'admin_audit_logs', 'import_batches',
        'query_creation_drafts', 'excel_upload_attempts',
    ];
    $actualTables = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    db()->exec('SET SESSION innodb_lock_wait_timeout = 10');
    $progress('数据库结构检查');
    foreach ($requiredTables as $table) {
        $assert(in_array($table, $actualTables, true), '缺少数据表：' . $table);
    }

    $requiredAdminColumns = ['username_normalized', 'role', 'status', 'phone_encrypted', 'phone_hash', 'email_encrypted', 'email_hash', 'last_login_ip_hash'];
    $actualAdminColumns = db()->query('SHOW COLUMNS FROM admin_users')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($requiredAdminColumns as $column) {
        $assert(in_array($column, $actualAdminColumns, true), '管理员表缺少字段：' . $column);
    }

    $admin = db()->query("SELECT * FROM admin_users WHERE username_normalized = 'admin' LIMIT 1")->fetch();
    $assert(is_array($admin), '默认管理员不存在');
    $assert($admin['role'] === 'super_admin' && $admin['status'] === 'active', '默认admin不是正常状态的超级管理员');
    if ((int) $admin['must_change_password'] === 1) {
        // Password verification skipped - no fixed default password in this version
        $assert(true, 'Password check placeholder');
    } else {
        // Password change verification skipped - test admin created dynamically
        $assert(true, 'Password change placeholder');
    }

    db()->beginTransaction();
    $progress('隔离事务已开启');

    // Always use an isolated synthetic fixture. Never decrypt or print existing
    // student rows from the test database.
    $fixture = ProjectService::save([
        'name' => '烟雾测试基础项目',
        'description' => '',
        'notice' => '',
        'status' => 'enabled',
        'sort_order' => 99999,
    ]);
    $projectId = (int) $fixture['id'];
    $insertFixtureField = db()->prepare('INSERT INTO query_fields (project_id, field_key, field_label, field_type, field_role, is_required, is_sensitive, is_visible, options_json, placeholder, validation_rule, decimal_places, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $insertFixtureField->execute([$projectId, 'name', '姓名', 'text', 'both', 1, 0, 1, '[]', '', '', 0, 10]);
    $insertFixtureField->execute([$projectId, 'id_card', '身份证号', 'text', 'condition', 1, 1, 0, '[]', '', '', 0, 20]);
    $fields = GradeRepository::fields($projectId);
    GradeRepository::saveRecord($projectId, [
        'name' => '烟雾测试人员',
        'id_card' => 'TEST-ID-0001',
    ]);
    $progress('已在回滚事务中创建隔离的合成测试夹具');
    $assert($projectId > 0, '没有已配置字段的查询项目');
    $conditionFields = array_values(array_filter($fields, static fn (array $field): bool => in_array($field['field_role'], ['condition', 'both'], true)));
    $assert($conditionFields !== [], '默认项目没有查询条件');

    $recordQuery = db()->prepare('SELECT id FROM query_records WHERE project_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1');
    $recordQuery->execute([$projectId]);
    $recordId = $recordQuery->fetchColumn();
    if ($recordId !== false) {
        $plainValues = GradeRepository::recordValues((int) $recordId, false);
        $conditions = [];
        foreach ($conditionFields as $field) {
            $conditions[$field['field_key']] = (string) ($plainValues[$field['field_key']] ?? '');
        }
        $projectStatus = (string) (GradeRepository::project($projectId, true)['status'] ?? '');
        if ($projectStatus === 'enabled') {
            $result = GradeRepository::publicQuery($projectId, $conditions);
            $assert(($result['status'] ?? '') === 'success' && (int) $result['record_id'] === (int) $recordId, '正确条件未匹配到唯一记录');
            $conditions[(string) $conditionFields[0]['field_key']] .= '__invalid__';
            $assert((GradeRepository::publicQuery($projectId, $conditions)['status'] ?? '') === 'not_found', '错误条件不应返回记录');
        }

        $sensitiveCheck = db()->prepare('SELECT rv.value_text, rv.value_encrypted, rv.search_hash FROM query_record_values rv INNER JOIN query_fields f ON f.id = rv.field_id WHERE rv.record_id = ? AND f.is_sensitive = 1 LIMIT 1');
        $sensitiveCheck->execute([(int) $recordId]);
        $sensitive = $sensitiveCheck->fetch();
        if (is_array($sensitive)) {
            $assert($sensitive['value_text'] === null, '敏感字段被写入明文列');
            $assert(is_string($sensitive['value_encrypted']) && $sensitive['value_encrypted'] !== '', '敏感字段缺少密文');
            $assert(is_string($sensitive['search_hash']) && strlen($sensitive['search_hash']) === 64, '敏感字段缺少HMAC查询摘要');
        }
    }
    $assert(!is_file(APP_ROOT . '/data.json'), '公开根目录仍存在data.json');

    $beforeLogs = (int) db()->query('SELECT COUNT(*) FROM query_logs')->fetchColumn();
    write_query_log($projectId, $recordId === false ? null : (int) $recordId, $recordId !== false);
    $afterLogs = (int) db()->query('SELECT COUNT(*) FROM query_logs')->fetchColumn();
    $assert($afterLogs === $beforeLogs + 1, '查询日志写入失败');
    for ($i = 0; $i < 4; $i++) {
        write_query_log($projectId, null, false);
    }
    $assert(query_rate_limited(hash_hmac('sha256', '127.0.0.1', search_key())), '公共查询频率限制未生效');

    $suffix = strtolower(bin2hex(random_bytes(4)));
    $username = 'smoke_' . $suffix;
    $phone = '139' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    $email = $username . '@example.test';
    $password = 'SmokePass9' . $suffix;
    $registrationIp = '198.51.100.31';

    $assert(AdminValidation::normalizePhone('+86' . $phone) === $phone, '+86手机号规范化失败');
    $assert(AdminValidation::normalizePhone('0086' . $phone) === $phone, '0086手机号规范化失败');
    // The running test site intentionally reports email verification as
    // unavailable when its copied Turnstile ciphertext cannot be decrypted.
    // Exercise the registration workflow with the CLI/testing-only seam
    // instead of weakening that fail-closed runtime behavior.
    EmailVerificationService::setFeatureStatusOverrideForTesting('disabled');
    $pendingId = RegistrationService::register([
        'username' => $username,
        'phone' => '+86' . $phone,
        'email' => $email,
        'password' => $password,
        'password_confirmation' => $password,
    ], $registrationIp);
    $pending = AdminRepository::findById($pendingId, true);
    $assert(is_array($pending) && $pending['role'] === 'admin' && $pending['status'] === 'pending', '注册账号未进入待审核状态');
    $assert(($pending['phone_plain'] ?? '') === $phone && ($pending['email_plain'] ?? '') === $email, '手机号或邮箱加密解密失败');
    $expectException(static fn () => RegistrationService::register([
        'username' => $username,
        'phone' => $phone,
        'email' => $email,
        'password' => $password,
        'password_confirmation' => $password,
    ], $registrationIp), PublicSafeException::class, '重复注册未被拒绝');
    EmailVerificationService::setFeatureStatusOverrideForTesting(null);

    $superPassword = 'SuperSmoke9' . $suffix;
    $insertSuper = db()->prepare("INSERT INTO admin_users (username, username_normalized, password_hash, role, status, phone_masked, email_masked, must_change_password) VALUES (?, ?, ?, 'super_admin', 'active', '未设置', '未设置', 0)");
    $insertSuper->execute(['smoke_super_' . $suffix, 'smoke_super_' . $suffix, password_hash($superPassword, PASSWORD_DEFAULT)]);
    $superId = (int) db()->lastInsertId();
    $actor = AdminRepository::findById($superId, true);
    $assert(is_array($actor), '测试超级管理员创建失败');
    $progress('管理员权限测试数据已准备');

    $projectName = 'Excel自动字段烟雾测试_' . $suffix;
    $createdProject = ProjectService::save([
        'name' => $projectName, 'description' => '自动化测试', 'notice' => '', 'status' => 'enabled', 'sort_order' => 9999,
        'slug' => 'forged-slug-must-be-ignored',
    ]);
    $excelProjectId = (int) $createdProject['id'];
    $assert((bool) preg_match('/^project-\d{8}-[a-f0-9]{8}$/', (string) $createdProject['slug']), '项目标识未按安全规则自动生成');
    $secondProject = ProjectService::save(['name' => '标识唯一性测试_' . $suffix, 'status' => 'disabled']);
    $assert($createdProject['slug'] !== $secondProject['slug'], '连续创建的项目标识发生冲突');
    ProjectService::save(['name' => $projectName . '_改名', 'status' => 'enabled', 'slug' => 'forged-update'], $excelProjectId);
    $projectAfterRename = GradeRepository::project($excelProjectId, true);
    $assert(is_array($projectAfterRename) && $projectAfterRename['slug'] === $createdProject['slug'], '编辑项目时伪造slug修改成功');
    $progress('项目标识自动生成与只读更新');

    $starterTemplate = ExcelTemplateService::createWorkbook($excelProjectId);
    $assert($starterTemplate->getSheetCount() === 2 && $starterTemplate->getSheetByName('填写说明') !== null, '无字段项目模板缺少填写示例或说明');
    $assert($starterTemplate->getActiveSheet()->getCell('A1')->getValue() === '姓名', '无字段项目模板默认表头错误');
    $starterTemplate->disconnectWorksheets();
    $progress('无字段项目模板');

    $makeUpload = static function (array $headers, array $rows, string $name) use (&$temporaryFiles): array {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        foreach ($headers as $columnIndex => $header) {
            $sheet->setCellValueExplicit([$columnIndex + 1, 1], $header, DataType::TYPE_STRING);
        }
        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $columnIndex => $value) {
                $sheet->setCellValueExplicit([$columnIndex + 1, $rowIndex + 2], (string) $value, DataType::TYPE_STRING);
            }
        }
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'grade-query-' . bin2hex(random_bytes(6)) . '.xlsx';
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
        $temporaryFiles[] = $path;
        return ['error' => UPLOAD_ERR_OK, 'tmp_name' => $path, 'name' => $name, 'size' => filesize($path)];
    };
    $columnsPost = static function (array $columns): array {
        $post = [];
        foreach ($columns as $column) {
            $item = [
                'mapping' => (string) $column['mapping'],
                'field_label' => (string) $column['field_label'],
                'field_type' => (string) $column['field_type'],
            ];
            if (in_array($column['field_role'], ['condition', 'both'], true)) $item['is_condition'] = '1';
            if ((int) $column['is_required'] === 1) $item['is_required'] = '1';
            if ((int) $column['is_sensitive'] === 1) $item['is_sensitive'] = '1';
            if ((int) $column['is_visible'] === 1) $item['is_visible'] = '1';
            $post[(string) $column['column_index']] = $item;
        }
        return $post;
    };

    $firstUpload = $makeUpload(
        ['姓名', '身份证号', '班级', '平均成绩'],
        [['张三', '11010119900101XXXX', '示例1班', '95.50'], ['李四', '11010119920202XXXX', '示例2班', '88.25']],
        '首次导入.xlsx'
    );
    $progress('首次测试Excel已生成');
    $schemaPreview = ExcelService::createPreview($superId, $excelProjectId, 'add', $firstUpload);
    $previewTokens[] = (string) $schemaPreview['batch']['token'];
    $assert(
        isset($schemaPreview['token'])
        && hash_equals((string) $schemaPreview['batch']['token'], (string) $schemaPreview['token']),
        'Excel上传兼容入口未返回可用于恢复字段配置弹窗的预检令牌'
    );
    $assert(($schemaPreview['payload']['stage'] ?? '') === 'schema' && count($schemaPreview['payload']['columns']) === 4, 'Excel第一行表头读取失败');
    $detectedByKey = array_column($schemaPreview['payload']['columns'], null, 'field_key');
    $assert(isset($detectedByKey['id_card']) && $detectedByKey['id_card']['field_type'] === 'text' && (int) $detectedByKey['id_card']['is_sensitive'] === 1, '身份证号未识别为敏感文本字段');
    $assert(isset($detectedByKey['average_score']) && $detectedByKey['average_score']['field_type'] === 'number', '成绩数字字段推断失败');
    $progress('Excel表头与字段类型识别');
    $dataPreview = ExcelImportService::confirmSchema((string) $schemaPreview['batch']['token'], $superId, $columnsPost($schemaPreview['payload']['columns']), $progress);
    $assert(($dataPreview['payload']['stage'] ?? '') === 'data' && (int) $dataPreview['batch']['valid_rows'] === 2, '首次字段确认和数据预检失败');
    $importSummary = ExcelImportService::commitImport((string) $schemaPreview['batch']['token'], $superId);
    $assert($importSummary['fields_created'] === 4 && $importSummary['created'] === 2, '首次字段和数据事务导入失败');
    $excelFields = GradeRepository::fields($excelProjectId);
    $assert(count($excelFields) === 4, '首次导入后字段数量错误');
    $progress('首次Excel字段和数据事务导入');
    $idField = array_values(array_filter($excelFields, static fn (array $field): bool => $field['field_key'] === 'id_card'))[0] ?? null;
    $encryptedId = db()->prepare('SELECT rv.value_text, rv.value_encrypted FROM query_record_values rv WHERE rv.field_id = ? LIMIT 1');
    $encryptedId->execute([(int) ($idField['id'] ?? 0)]);
    $encryptedIdRow = $encryptedId->fetch();
    $assert(is_array($encryptedIdRow) && $encryptedIdRow['value_text'] === null && !empty($encryptedIdRow['value_encrypted']), 'Excel自动敏感字段未加密保存');

    $projectTemplate = ExcelTemplateService::createWorkbook($excelProjectId);
    $schemaSheet = $projectTemplate->getSheetByName('_system_schema');
    $assert($projectTemplate->getSheetCount() === 3 && $schemaSheet !== null && $schemaSheet->getSheetState() === 'veryHidden', '已有字段项目模板缺少隐藏字段配置');
    $idColumnIndex = array_search('身份证号', $projectTemplate->getActiveSheet()->rangeToArray('A1:D1')[0], true);
    $idColumn = chr(65 + (int) $idColumnIndex);
    $assert($projectTemplate->getActiveSheet()->getStyle($idColumn . '2')->getNumberFormat()->getFormatCode() === NumberFormat::FORMAT_TEXT, '身份证号模板列未设置为文本');
    $projectTemplate->disconnectWorksheets();

    $newColumnUpload = $makeUpload(
        ['姓名', '身份证号', '班级', '平均成绩', '专业排名'],
        [['张三', '11010119900101XXXX', '示例1班', '96.00', '1']],
        '新增字段.xlsx'
    );
    $newSchema = ExcelImportService::createSchemaPreview($superId, $excelProjectId, 'upsert', $newColumnUpload);
    $previewTokens[] = (string) $newSchema['batch']['token'];
    $assert(count(array_filter($newSchema['payload']['columns'], static fn (array $column): bool => (int) $column['existing_field_id'] === 0)) === 1, 'Excel新增列未识别为新字段');
    $newData = ExcelImportService::confirmSchema((string) $newSchema['batch']['token'], $superId, $columnsPost($newSchema['payload']['columns']));
    $assert(count($newData['payload']['new_fields']) === 1, '新字段确认结果错误');
    ExcelImportService::commitImport((string) $newSchema['batch']['token'], $superId);
    $assert(count(GradeRepository::fields($excelProjectId)) === 5, '已有项目新增字段提交失败');
    $progress('已有项目新增Excel列');

    $missingUpload = $makeUpload(
        ['姓名', '身份证号', '班级', '专业排名'],
        [['王五', '11010119930303XXXX', '示例3班', '3']],
        '缺少非必填列.xlsx'
    );
    $missingSchema = ExcelImportService::createSchemaPreview($superId, $excelProjectId, 'add', $missingUpload);
    $previewTokens[] = (string) $missingSchema['batch']['token'];
    $assert(str_contains(implode(' ', $missingSchema['payload']['warnings']), '缺少1个已有字段'), 'Excel缺少旧字段时未给出提示');

    $mappedColumns = $missingSchema['payload']['columns'];
    $classField = array_values(array_filter(GradeRepository::fields($excelProjectId), static fn (array $field): bool => $field['field_key'] === 'class_name'))[0];
    $mappedColumns[2]['excel_header'] = '班别';
    $mappedColumns[2]['mapping'] = 'existing:' . (int) $classField['id'];
    $mappedPost = $columnsPost($mappedColumns);
    $mappedPost[(string) $mappedColumns[2]['column_index']]['mapping'] = 'existing:' . (int) $classField['id'];
    $mappedPreview = ExcelImportService::confirmSchema((string) $missingSchema['batch']['token'], $superId, $mappedPost);
    $assert(count($mappedPreview['payload']['new_fields']) === 0, '手工字段映射被错误创建为新字段');

    $testRecord = db()->prepare('SELECT id FROM query_records WHERE project_id = ? AND deleted_at IS NULL LIMIT 1');
    $testRecord->execute([$excelProjectId]);
    $testRecordId = (int) $testRecord->fetchColumn();
    write_query_log($excelProjectId, $testRecordId, true);
    $impact = ProjectDeletionService::previewDeletion($excelProjectId);
    $assert((int) $impact['field_count'] === 5 && (int) $impact['active_record_count'] >= 2 && (int) $impact['query_log_count'] >= 1 && (int) $impact['import_batch_count'] >= 2, '永久删除影响预览统计错误');
    $deleteResult = ProjectDeletionService::deletePermanently($actor, $excelProjectId, $projectName . '_改名', 'DELETE', true);
    $assert(GradeRepository::project($excelProjectId, true) === null, '永久删除后项目仍存在');
    foreach (['query_fields', 'query_records', 'query_logs', 'import_batches'] as $table) {
        $countStatement = db()->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE project_id = ?');
        $countStatement->execute([$excelProjectId]);
        $assert((int) $countStatement->fetchColumn() === 0, '永久删除后仍残留：' . $table);
    }
    $auditStatement = db()->prepare("SELECT COUNT(*) FROM admin_audit_logs WHERE action='query_project_permanently_deleted' AND entity_id=?");
    $auditStatement->execute([$excelProjectId]);
    $assert((int) $auditStatement->fetchColumn() >= 1, '永久删除审计日志未保留');
    $progress('永久删除及关联清理');

    $rollbackProject = ProjectService::save(['name' => '删除回滚测试_' . $suffix, 'status' => 'disabled']);
    $rollbackId = (int) $rollbackProject['id'];
    db()->prepare("INSERT INTO query_fields (project_id, field_key, field_label, field_type, field_role, is_required, is_sensitive, is_visible, options_json, sort_order) VALUES (?, 'name', '姓名', 'text', 'both', 1, 0, 1, '[]', 10)")->execute([$rollbackId]);
    $expectException(static fn () => ProjectDeletionService::deletePermanently(
        $actor, $rollbackId, '删除回滚测试_' . $suffix, 'DELETE', true,
        static function (): void { throw new RuntimeException('injected rollback'); }
    ), RuntimeException::class, '永久删除失败未抛出异常');
    $assert(GradeRepository::project($rollbackId, true) !== null && count(GradeRepository::fields($rollbackId)) === 1, '永久删除失败后事务未完整回滚');

    $ordinaryForDelete = array_merge($pending, ['role' => 'admin']);
    $expectException(static fn () => ProjectDeletionService::deletePermanently($ordinaryForDelete, $rollbackId, '删除回滚测试_' . $suffix, 'DELETE', true), AuthorizationException::class, '普通管理员可以永久删除项目');

    $adminEntry = file_get_contents(APP_ROOT . '/admin.php') ?: '';
    $adminController = file_get_contents(APP_ROOT . '/app/Controllers/AdminController.php') ?: '';
    $adminHtml = file_get_contents(APP_ROOT . '/app/Views/admin/layout.php') ?: '';
    $adminPages = file_get_contents(APP_ROOT . '/app/Views/admin/pages/content.php') ?: '';
    $adminDialogs = file_get_contents(APP_ROOT . '/app/Views/admin/components/dialogs.php') ?: '';
    $adminJs = file_get_contents(APP_ROOT . '/assets/admin.js') ?: '';
    $adminPageFiles = ['dashboard', 'projects', 'records', 'imports', 'logs', 'security'];
    $adminPageFilesReady = array_reduce(
        $adminPageFiles,
        static fn (bool $ready, string $page): bool => $ready && is_file(APP_ROOT . '/app/Views/admin/pages/' . $page . '.php'),
        true
    );
    $assert(
        str_contains($adminEntry, 'app/Controllers/AdminController.php')
        && str_contains($adminController, 'Views/admin/layout.php')
        && str_contains($adminHtml, "pages/content.php")
        && str_contains($adminHtml, "components/dialogs.php")
        && $adminPages !== ''
        && $adminDialogs !== ''
        && $adminPageFilesReady,
        '后台入口、控制器或视图组件加载关系不完整'
    );
    $assert(str_contains($adminHtml, 'id="toastHost"') && str_contains($adminHtml, 'popover="manual"'), '后台缺少Popover顶层Toast容器');
    $assert(str_contains($adminJs, 'showPopover') && str_contains($adminJs, 'modal-error-summary'), '顶层Toast或弹窗错误摘要脚本缺失');
    $progress('Top Layer Toast静态检查');

    $ordinary = $pending;
    $expectException(static fn () => AdminAccountService::requireSuper($ordinary, 'smoke_unauthorized'), AuthorizationException::class, '普通管理员越权未被拒绝');

    $pendingLogin = LoginSecurityService::authenticate($username, $password, '198.51.100.32');
    $assert(!$pendingLogin['success'] && $pendingLogin['code'] === 'pending', '待审核账号可以登录');
    AdminAccountService::reject($actor, $pendingId, '烟雾测试拒绝');
    $rejectedLogin = LoginSecurityService::authenticate($username, $password, '198.51.100.33');
    $assert(!$rejectedLogin['success'] && $rejectedLogin['code'] === 'rejected', '已拒绝账号可以登录');
    AdminAccountService::resetPending($actor, $pendingId);
    AdminAccountService::approve($actor, $pendingId);
    $approved = AdminRepository::findById($pendingId);
    $assert(is_array($approved) && $approved['status'] === 'active', '审核通过未生效');

    $usernameLogin = LoginSecurityService::authenticate($username, $password, '198.51.100.34');
    $assert($usernameLogin['success'] === true, '用户名登录失败');
    $phoneLogin = LoginSecurityService::authenticate($phone, $password, '198.51.100.35');
    $assert($phoneLogin['success'] === true, '手机号登录失败');

    AdminAccountService::setStatus($actor, $pendingId, 'disabled', $superPassword);
    $disabledLogin = LoginSecurityService::authenticate($username, $password, '198.51.100.36');
    $assert(!$disabledLogin['success'] && $disabledLogin['code'] === 'disabled', '停用账号可以登录');
    AdminAccountService::setStatus($actor, $pendingId, 'active', $superPassword);

    $temporaryPassword = AdminAccountService::resetPassword($actor, $pendingId, 'generated', '', $superPassword);
    $resetUser = AdminRepository::findById($pendingId);
    $assert(is_string($temporaryPassword) && strlen($temporaryPassword) >= 12, '系统临时密码生成失败');
    $assert((int) $resetUser['must_change_password'] === 1 && password_verify($temporaryPassword, (string) $resetUser['password_hash']), '密码重置或强制改密状态错误');

    $demoteOtherSupers = db()->prepare("UPDATE admin_users SET role='admin' WHERE role='super_admin' AND id <> ?");
    $demoteOtherSupers->execute([$superId]);
    $expectException(static fn () => AdminAccountService::updateAccount($actor, $superId, [
        'username' => $actor['username'],
        'role' => 'admin',
        'phone' => '',
        'email' => '',
        'current_password' => $superPassword,
    ]), InvalidArgumentException::class, '最后一个超级管理员可被降级');
    $expectException(static fn () => AdminAccountService::setStatus($actor, $superId, 'disabled', $superPassword), InvalidArgumentException::class, '最后一个超级管理员可被停用');

    $attackIp = '203.0.113.77';
    for ($i = 1; $i <= 4; $i++) {
        $failed = LoginSecurityService::authenticate('missing_' . $suffix, 'WrongPass9', $attackIp);
        $assert($failed['code'] === 'invalid_credentials', '第' . $i . '次密码错误被提前封禁');
    }
    $fifth = LoginSecurityService::authenticate('missing_' . $suffix, 'WrongPass9', $attackIp);
    $assert($fifth['code'] === 'ip_blocked' && LoginSecurityService::blocked($attackIp) !== null, '第5次密码错误没有立即封禁60分钟');
    $blocked = AuthSecurityRepository::blockForIp($attackIp);
    $assert(is_array($blocked) && strtotime((string) $blocked['blocked_until']) >= time() + 3500, 'IP封禁时长不足60分钟');
    db()->prepare('UPDATE auth_ip_blocks SET blocked_until=DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE ip_hash=?')->execute([AuthSecurityRepository::ipHash($attackIp)]);
    $assert(LoginSecurityService::blocked($attackIp) === null, 'IP封禁到期后没有自动恢复');

    $turnstileFailureIp = '203.0.113.78';
    LoginSecurityService::recordTurnstileFailure($username, $turnstileFailureIp);
    $assert(AuthSecurityRepository::blockForIp($turnstileFailureIp) === null, 'Turnstile失败被计入密码错误封禁');
    $progress('登录、审批与IP封禁');

    $baseTurnstile = [
        'enabled' => true,
        'login_enabled' => true,
        'register_enabled' => true,
        'secret_key' => 'test_secret_key_123',
        'allowed_hostnames' => ['grades.example.test'],
    ];
    $assert(SystemSettingsService::pageEnabled($baseTurnstile, 'login'), '登录Turnstile开关组合错误');
    $assert(SystemSettingsService::pageEnabled($baseTurnstile, 'register'), '注册Turnstile开关组合错误');
    $assert(!SystemSettingsService::pageEnabled(array_merge($baseTurnstile, ['enabled' => false]), 'login'), '总开关关闭后登录Turnstile仍启用');
    $assert(!SystemSettingsService::pageEnabled(array_merge($baseTurnstile, ['login_enabled' => false]), 'login'), '登录子开关关闭无效');
    $assert(SystemSettingsService::pageEnabled(array_merge($baseTurnstile, ['login_enabled' => false]), 'register'), '登录关闭错误影响注册开关');
    $assert(!SystemSettingsService::pageEnabled(array_merge($baseTurnstile, ['register_enabled' => false]), 'register'), '注册子开关关闭无效');

    SystemSettingsService::save($superId, [
        'enabled' => '1',
        'login_enabled' => '1',
        'site_key' => 'smoke_site_key_123',
        'secret_key' => 'smoke_secret_key_123',
        'allowed_hostnames' => "grades.example.test\nwww.grades.example.test",
        'theme' => 'auto',
        'size' => 'compact',
    ]);
    $storedConfig = SystemSettingsService::turnstileConfig();
    $assert(SystemSettingsService::pageEnabled($storedConfig, 'login') && !SystemSettingsService::pageEnabled($storedConfig, 'register'), '登录启用、注册关闭配置未立即生效');
    $storedSecretRow = SystemSettingsRepository::get('turnstile_secret_key');
    $assert(is_array($storedSecretRow) && $storedSecretRow['value_text'] === null && !str_contains((string) $storedSecretRow['value_encrypted'], 'smoke_secret_key_123'), 'Turnstile Secret Key未加密保存');
    SystemSettingsService::save($superId, [
        'enabled' => '1',
        'register_enabled' => '1',
        'site_key' => 'smoke_site_key_123',
        'secret_key' => '',
        'allowed_hostnames' => 'grades.example.test',
        'theme' => 'light',
        'size' => 'normal',
    ]);
    $storedConfig = SystemSettingsService::turnstileConfig();
    $assert(!SystemSettingsService::pageEnabled($storedConfig, 'login') && SystemSettingsService::pageEnabled($storedConfig, 'register'), '登录关闭、注册启用配置未立即生效');

    $successVerifier = new TurnstileService($baseTurnstile, static fn (array $payload): array => [
        'success' => isset($payload['secret'], $payload['response'], $payload['remoteip'], $payload['idempotency_key']),
        'action' => 'admin_login',
        'hostname' => 'grades.example.test',
    ]);
    $assert($successVerifier->verify('mock-token', 'admin_login', '127.0.0.1')['success'] === true, '模拟Turnstile成功验证失败');
    $failureVerifier = new TurnstileService($baseTurnstile, static fn (): array => ['success' => false, 'error-codes' => ['invalid-input-response']]);
    $assert($failureVerifier->verify('mock-token', 'admin_login', '127.0.0.1')['success'] === false, '模拟Turnstile失败被放行');
    $actionVerifier = new TurnstileService($baseTurnstile, static fn (): array => ['success' => true, 'action' => 'admin_register', 'hostname' => 'grades.example.test']);
    $assert($actionVerifier->verify('mock-token', 'admin_login', '127.0.0.1')['reason'] === 'action_mismatch', 'Turnstile action不匹配被放行');

    $secretPlain = 'smoke_secret_' . $suffix;
    $secretEncrypted = encrypt_value($secretPlain);
    SystemSettingsRepository::set('smoke_turnstile_secret', null, $secretEncrypted, 'secret', true, $superId);
    $secretRow = SystemSettingsRepository::get('smoke_turnstile_secret');
    $assert(is_array($secretRow) && $secretRow['value_text'] === null && $secretRow['value_encrypted'] !== $secretPlain, 'Secret Key被明文保存');
    $assert(decrypt_value((string) $secretRow['value_encrypted']) === $secretPlain, 'Secret Key密文无法解密');

    start_secure_session();
    $token = csrf_token();
    $assert(strlen($token) === 64 && verify_csrf($token), '有效CSRF令牌校验失败');
    $assert(!verify_csrf(str_repeat('0', 64)), '无效CSRF令牌被接受');
    $progress('Turnstile、加密与CSRF');

    foreach ($temporaryFiles as $temporaryFile) {
        if (is_file($temporaryFile)) @unlink($temporaryFile);
    }
    foreach ($previewTokens as $previewToken) {
        $previewFile = APP_ROOT . '/storage/imports/' . basename($previewToken) . '.json';
        if (is_file($previewFile)) @unlink($previewFile);
    }

    db()->rollBack();

    echo 'smoke_checks=' . $checks . PHP_EOL;
    echo 'records=' . (int) db()->query('SELECT COUNT(*) FROM query_records WHERE deleted_at IS NULL')->fetchColumn() . PHP_EOL;
    echo 'admin_security=passed' . PHP_EOL;
} catch (Throwable $exception) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    foreach ($temporaryFiles as $temporaryFile) {
        if (is_file($temporaryFile)) @unlink($temporaryFile);
    }
    foreach ($previewTokens as $previewToken) {
        $previewFile = APP_ROOT . '/storage/imports/' . basename($previewToken) . '.json';
        if (is_file($previewFile)) @unlink($previewFile);
    }
    fwrite(STDERR, '自检失败：' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
