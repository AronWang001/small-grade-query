<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Services/ExcelService.php';
require_once dirname(__DIR__) . '/Services/ProjectService.php';
require_once dirname(__DIR__) . '/Services/ProjectDeletionService.php';
require_once __DIR__ . '/AdminUserController.php';
require_once __DIR__ . '/SystemSettingsController.php';
require_once __DIR__ . '/../Services/EmailVerificationService.php';
require_once __DIR__ . '/../Services/MailService.php';
require_once __DIR__ . '/NewQueryController.php';

send_security_headers();
start_secure_session();
$admin = require_admin(true);

$isSuperAdmin = ($admin['role'] ?? '') === 'super_admin';
$tabs = ['dashboard', 'projects', 'records', 'imports', 'logs', 'security', 'new_query'];
if ($isSuperAdmin) {
    array_push($tabs, 'admin_users', 'login_security', 'system_settings');
}
$requestedTab = (string) ($_GET['tab'] ?? 'dashboard');
if (in_array($requestedTab, ['admin_users', 'login_security', 'system_settings'], true) && !$isSuperAdmin) {
    audit_log('admin_unauthorized_access', 'admin_user', (int) $admin['id'], false, ['attempted_tab' => $requestedTab]);
    flash('error', '您没有权限执行此操作。');
    redirect('admin.php?tab=dashboard');
}
$tab = in_array($_GET['tab'] ?? 'dashboard', $tabs, true)
    ? (string) ($_GET['tab'] ?? 'dashboard')
    : 'dashboard';
$mustChangePassword = (int) $admin['must_change_password'] === 1;
if ($mustChangePassword) {
    $tab = 'security';
}

function admin_page_url(string $tab, array $params = []): string
{
    return 'admin.php?' . http_build_query(array_merge(['tab' => $tab], $params));
}

function admin_icon(string $name): string
{
    $paths = [
        'home' => '<path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10.5V20h13v-9.5"/><path d="M9.5 20v-6h5v6"/>',
        'projects' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 8h10M7 12h6M7 16h8"/>',
        'records' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/>',
        'excel' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13l4 5M12 13l-4 5M15 13h2"/>',
        'logs' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2M4 4l2 2"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'chevron' => '<path d="m9 18 6-6-6-6"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'close' => '<path d="M6 6l12 12M18 6 6 18"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
        'upload' => '<path d="M12 3v12M7 8l5-5 5 5"/><path d="M4 15v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4"/>',
        'download' => '<path d="M12 3v12M7 10l5 5 5-5"/><path d="M4 19h16"/>',
        'key' => '<circle cx="8" cy="15" r="4"/><path d="m11 12 8-8M15 8l3 3M17 6l2 2"/>',
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7h.01"/>',
        'check' => '<path d="m5 12 4 4L19 6"/>',
        'database' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
        'collapse' => '<path d="m15 18-6-6 6-6"/>',
    ];
    $path = $paths[$name] ?? $paths['info'];
    return '<svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $path . '</svg>';
}

function admin_store_form_state(string $action, array $post): void
{
    unset(
        $post['csrf_token'], $post['old_password'], $post['new_password'], $post['new_password_confirmation'],
        $post['current_password'], $post['temporary_password'], $post['secret_key'],
        $post['turnstile_test_token'], $post['cf-turnstile-response']
    );
    $_SESSION['admin_form_state'] = [
        'action' => $action,
        'post' => $post,
        'created_at' => time(),
    ];
}

function admin_consume_form_state(): ?array
{
    $state = $_SESSION['admin_form_state'] ?? null;
    unset($_SESSION['admin_form_state']);
    if (!is_array($state) || (int) ($state['created_at'] ?? 0) < time() - 600) {
        return null;
    }
    return $state;
}

function admin_authorize_record_reveal(int $projectId, int $recordId): void
{
    $_SESSION['record_reveals'] ??= [];
    $_SESSION['record_reveals'][(string) $recordId] = [
        'project_id' => $projectId,
        'expires_at' => time() + 600,
    ];
}

function admin_record_reveal_allowed(int $projectId, int $recordId): bool
{
    $reveals = is_array($_SESSION['record_reveals'] ?? null) ? $_SESSION['record_reveals'] : [];
    foreach ($reveals as $key => $reveal) {
        if (!is_array($reveal) || (int) ($reveal['expires_at'] ?? 0) < time()) {
            unset($_SESSION['record_reveals'][$key]);
        }
    }
    $reveal = $_SESSION['record_reveals'][(string) $recordId] ?? null;
    return is_array($reveal)
        && (int) ($reveal['project_id'] ?? 0) === $projectId
        && (int) ($reveal['expires_at'] ?? 0) >= time();
}

function admin_error_return_target(string $action, array $post): string
{
    $projectId = max(0, (int) ($post['project_id'] ?? 0));
    $recordId = max(0, (int) ($post['record_id'] ?? 0));
    $fieldId = max(0, (int) ($post['field_id'] ?? 0));
    return match ($action) {
        'change_password' => 'security&modal=password',
        'save_project' => 'projects' . ($projectId > 0 ? '&project_id=' . $projectId : '') . '&modal=project',
        'archive_project' => 'projects&project_id=' . $projectId,
        'permanently_delete_project' => 'projects&project_id=' . $projectId . '&modal=project_delete',
        'save_field' => 'projects&project_id=' . $projectId . '&modal=field' . ($fieldId > 0 ? '&field_id=' . $fieldId : ''),
        'delete_field' => 'projects&project_id=' . $projectId . '&modal=field_delete&field_id=' . $fieldId,
        'save_record' => 'records&project_id=' . $projectId . '&modal=' . ($recordId > 0 ? 'record_edit&record_id=' . $recordId : 'record_new'),
        'delete_records' => 'records&project_id=' . $projectId,
        'import_preview' => 'imports&project_id=' . $projectId . '&modal=import',
        'import_schema_confirm' => 'imports&project_id=' . $projectId . '&modal=import_schema',
        'import_commit' => 'imports&project_id=' . $projectId . '&modal=import_preview',
        'save_own_contact' => 'security&modal=profile',
        'save_admin_user' => 'admin_users&admin_user_id=' . (int) ($post['admin_user_id'] ?? 0) . '&modal=admin_edit',
        'reject_admin_registration' => 'admin_users&admin_user_id=' . (int) ($post['admin_user_id'] ?? 0) . '&modal=admin_reject',
        'reset_admin_password' => 'admin_users&admin_user_id=' . (int) ($post['admin_user_id'] ?? 0) . '&modal=admin_reset_password',
        'set_admin_status' => 'admin_users&admin_user_id=' . (int) ($post['admin_user_id'] ?? 0) . '&modal=admin_status',
        'delete_admin_registration' => 'admin_users&admin_user_id=' . (int) ($post['admin_user_id'] ?? 0) . '&modal=admin_delete',
        'save_turnstile_settings', 'clear_turnstile_secret', 'toggle_email_verification', 'save_mail_settings' => 'system_settings',
        'remove_ip_block' => 'login_security',
        default => in_array($post['return_tab'] ?? '', ['dashboard', 'projects', 'records', 'imports', 'logs', 'security', 'admin_users', 'login_security', 'system_settings'], true)
            ? (string) $post['return_tab']
            : 'dashboard',
    };
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $returnTab = in_array($_POST['return_tab'] ?? '', $tabs, true)
        ? (string) $_POST['return_tab']
        : 'dashboard';
    $action = (string) ($_POST['action'] ?? '');
    try {
        require_csrf();
        if ($mustChangePassword && $action !== 'change_password') {
            throw new PublicSafeException('首次登录必须先修改默认密码。');
        }

        switch ($action) {
            case 'change_password':
                $newPassword = (string) ($_POST['new_password'] ?? '');
                if (!hash_equals($newPassword, (string) ($_POST['new_password_confirmation'] ?? ''))) {
                    throw new PublicSafeException('两次输入的新密码不一致。');
                }
                $error = change_admin_password((int) $admin['id'], (string) ($_POST['old_password'] ?? ''), $newPassword);
                if ($error !== null) {
                    throw new PublicSafeException($error);
                }
                flash('success', '密码已修改，请使用新密码继续管理系统。');
                $returnTab = 'security';
                break;

            case 'save_project':
                $id = (int) ($_POST['project_id'] ?? 0);
                $savedProject = ProjectService::save($_POST, $id > 0 ? $id : null);
                $id = (int) $savedProject['id'];
                if (!$savedProject['created']) {
                    flash('success', '查询项目已更新。');
                    $returnTab = 'projects&project_id=' . $id;
                } else {
                    flash('success', '项目已创建，请上传Excel，系统将根据第一行表头自动创建字段。');
                    $returnTab = 'imports&project_id=' . $id . '&modal=import';
                }
                break;

            case 'set_project_status':
                $id = (int) ($_POST['project_id'] ?? 0);
                $status = (string) ($_POST['status'] ?? '');
                if (!in_array($status, ['enabled', 'disabled', 'archived'], true) || GradeRepository::project($id, true) === null) {
                    throw new PublicSafeException('项目或目标状态无效。');
                }
                db()->prepare('UPDATE query_projects SET status = ? WHERE id = ? AND deleted_at IS NULL')->execute([$status, $id]);
                audit_log('query_project_status_changed', 'query_project', $id, true, ['status' => $status]);
                flash('success', '项目状态已更新为“' . (['enabled' => '启用', 'disabled' => '停用', 'archived' => '已归档'][$status]) . '”。');
                $returnTab = 'projects';
                break;

            case 'archive_project':
                $id = (int) ($_POST['project_id'] ?? 0);
                if (GradeRepository::project($id, true) === null) {
                    throw new PublicSafeException('查询项目不存在。');
                }
                db()->prepare("UPDATE query_projects SET status = 'archived' WHERE id = ? AND deleted_at IS NULL")->execute([$id]);
                audit_log('query_project_archived', 'query_project', $id, true);
                flash('success', '项目已归档，全部历史数据仍然保留。');
                $returnTab = 'projects';
                break;

            case 'permanently_delete_project':
                $id = (int) ($_POST['project_id'] ?? 0);
                $deleted = ProjectDeletionService::deletePermanently(
                    $admin,
                    $id,
                    (string) ($_POST['project_name_confirmation'] ?? ''),
                    (string) ($_POST['delete_confirmation'] ?? ''),
                    isset($_POST['acknowledge_irreversible'])
                );
                flash($deleted['cleanup_warning'] ? 'warning' : 'success', $deleted['cleanup_warning']
                    ? '项目已永久删除，但有部分临时文件未能自动清理。'
                    : '查询项目及全部关联数据已永久删除。');
                $returnTab = 'projects';
                break;

            case 'save_field':
                $fieldId = (int) ($_POST['field_id'] ?? 0);
                $projectId = (int) ($_POST['project_id'] ?? 0);
                $fieldKey = trim((string) ($_POST['field_key'] ?? ''));
                $fieldLabel = trim((string) ($_POST['field_label'] ?? ''));
                $fieldType = (string) ($_POST['field_type'] ?? 'text');
                $fieldRole = (string) ($_POST['field_role'] ?? 'result');
                if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', $fieldKey) || $fieldLabel === '') {
                    throw new PublicSafeException('字段标识只能使用小写字母、数字和下划线，并以字母开头。');
                }
                if (!in_array($fieldType, ['text', 'password', 'number', 'date', 'select'], true) || !in_array($fieldRole, ['condition', 'result', 'both'], true)) {
                    throw new PublicSafeException('字段类型或用途无效。');
                }
                $options = array_values(array_unique(array_filter(
                    array_map('trim', preg_split('/\r?\n/', (string) ($_POST['options'] ?? '')) ?: []),
                    static fn (string $value): bool => $value !== ''
                )));
                if ($fieldType === 'select' && $options === []) {
                    throw new PublicSafeException('下拉字段至少需要一个选项。');
                }
                $params = [
                    $fieldKey,
                    $fieldLabel,
                    $fieldType,
                    $fieldRole,
                    isset($_POST['is_required']) ? 1 : 0,
                    isset($_POST['is_sensitive']) ? 1 : 0,
                    isset($_POST['is_visible']) ? 1 : 0,
                    json_encode($options, JSON_UNESCAPED_UNICODE),
                    trim((string) ($_POST['placeholder'] ?? '')),
                    trim((string) ($_POST['validation_rule'] ?? '')),
                    min(8, max(0, (int) ($_POST['decimal_places'] ?? 2))),
                    (int) ($_POST['sort_order'] ?? 0),
                ];
                if ($fieldId > 0) {
                    db()->prepare('UPDATE query_fields SET field_key=?, field_label=?, field_type=?, field_role=?, is_required=?, is_sensitive=?, is_visible=?, options_json=?, placeholder=?, validation_rule=?, decimal_places=?, sort_order=? WHERE id=? AND project_id=? AND deleted_at IS NULL')
                        ->execute([...$params, $fieldId, $projectId]);
                    audit_log('query_field_updated', 'query_field', $fieldId, true, ['project_id' => $projectId]);
                    flash('success', '字段已更新，前台将自动使用新配置。');
                } else {
                    db()->prepare('INSERT INTO query_fields (field_key, field_label, field_type, field_role, is_required, is_sensitive, is_visible, options_json, placeholder, validation_rule, decimal_places, sort_order, project_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                        ->execute([...$params, $projectId]);
                    $fieldId = (int) db()->lastInsertId();
                    audit_log('query_field_created', 'query_field', $fieldId, true, ['project_id' => $projectId]);
                    flash('success', '字段已创建。');
                }
                $returnTab = 'projects&project_id=' . $projectId . '&modal=fields';
                break;

            case 'delete_field':
                $fieldId = (int) ($_POST['field_id'] ?? 0);
                $projectId = (int) ($_POST['project_id'] ?? 0);
                $pdo = db();
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM query_record_values WHERE field_id = ?');
                $stmt->execute([$fieldId]);
                $valueCount = (int) $stmt->fetchColumn();
                if ($valueCount > 0) {
                    if (!isset($_POST['confirm_related'])) {
                        throw new PublicSafeException('该字段关联 ' . $valueCount . ' 个值，勾选确认后才能软删除。');
                    }
                    $pdo->prepare('UPDATE query_fields SET deleted_at = NOW(), is_visible = 0 WHERE id = ? AND project_id = ?')->execute([$fieldId, $projectId]);
                    audit_log('query_field_soft_deleted', 'query_field', $fieldId, true, ['value_count' => $valueCount]);
                    flash('warning', '字段已软删除，历史字段值仍保留。');
                } else {
                    $pdo->prepare('DELETE FROM query_fields WHERE id = ? AND project_id = ?')->execute([$fieldId, $projectId]);
                    audit_log('query_field_deleted', 'query_field', $fieldId);
                    flash('success', '未关联数据的字段已删除。');
                }
                $pdo->commit();
                $returnTab = 'projects&project_id=' . $projectId . '&modal=fields';
                break;

            case 'save_record':
                $projectId = (int) ($_POST['project_id'] ?? 0);
                $recordId = (int) ($_POST['record_id'] ?? 0);
                if ($recordId > 0 && !admin_record_reveal_allowed($projectId, $recordId)) {
                    throw new PublicSafeException('记录查看授权已过期，请重新点击“查看详情”。');
                }
                $savedId = GradeRepository::saveRecord(
                    $projectId,
                    is_array($_POST['values'] ?? null) ? $_POST['values'] : [],
                    $recordId > 0 ? $recordId : null
                );
                audit_log($recordId > 0 ? 'query_record_updated' : 'query_record_created', 'query_record', $savedId, true, ['project_id' => $projectId]);
                flash('success', $recordId > 0 ? '记录已更新。' : '记录已添加。');
                $returnTab = 'records&project_id=' . $projectId;
                break;

            case 'delete_records':
                $projectId = (int) ($_POST['project_id'] ?? 0);
                $recordIds = is_array($_POST['record_ids'] ?? null)
                    ? $_POST['record_ids']
                    : [(int) ($_POST['record_id'] ?? 0)];
                $deleted = GradeRepository::deleteRecords($projectId, $recordIds);
                if ($deleted < 1) {
                    throw new PublicSafeException('请选择需要删除的有效记录。');
                }
                audit_log('query_records_deleted', 'query_project', $projectId, true, ['count' => $deleted]);
                flash('success', '已软删除 ' . $deleted . ' 条记录。');
                $returnTab = 'records&project_id=' . $projectId;
                break;

            case 'reveal_record':
                $projectId = (int) ($_POST['project_id'] ?? 0);
                $recordId = (int) ($_POST['record_id'] ?? 0);
                if (GradeRepository::record($projectId, $recordId, true) === null) {
                    throw new PublicSafeException('记录不存在。');
                }
                admin_authorize_record_reveal($projectId, $recordId);
                audit_log('sensitive_information_viewed', 'query_record', $recordId, true, ['project_id' => $projectId]);
                $returnTab = 'records&project_id=' . $projectId . '&modal=record_view&record_id=' . $recordId;
                break;

            case 'import_preview':
                $projectId = (int) ($_POST['project_id'] ?? 0);
                $preview = ExcelService::createPreview(
                    (int) $admin['id'],
                    $projectId,
                    (string) ($_POST['import_mode'] ?? ''),
                    $_FILES['excel_file'] ?? []
                );
                $previewToken = (string) ($preview['token'] ?? $preview['batch']['token'] ?? '');
                if (!preg_match('/^[a-f0-9]{64}$/', $previewToken)) {
                    throw new PublicSafeException('无法恢复字段识别结果，请重新上传Excel。');
                }
                $_SESSION['import_preview_token'] = $previewToken;
                flash('success', 'Excel表头识别完成，请确认字段配置。');
                $returnTab = 'imports&project_id=' . $projectId . '&modal=import_schema';
                break;

            case 'import_schema_confirm':
                $token = (string) ($_POST['token'] ?? '');
                $preview = ExcelService::confirmSchema(
                    $token,
                    (int) $admin['id'],
                    is_array($_POST['columns'] ?? null) ? $_POST['columns'] : []
                );
                $_SESSION['import_preview_token'] = $token;
                flash('success', '字段配置已确认：有效 ' . (int) $preview['batch']['valid_rows'] . ' 行，错误 ' . (int) $preview['batch']['error_rows'] . ' 行。');
                $returnTab = 'imports&project_id=' . (int) $preview['batch']['project_id'] . '&modal=import_preview';
                break;

            case 'import_commit':
                if (!isset($_POST['confirm_import'])) {
                    throw new PublicSafeException('正式导入前需要再次确认。');
                }
                $token = (string) ($_POST['token'] ?? '');
                $preview = ExcelService::preview($token, (int) $admin['id']);
                if ($preview === null) {
                    throw new PublicSafeException('导入预检已过期，请重新上传。');
                }
                if ($preview['batch']['import_mode'] === 'replace' && !isset($_POST['confirm_replace'])) {
                    throw new PublicSafeException('覆盖全部数据必须再次勾选确认。');
                }
                $summary = ExcelService::commit($token, (int) $admin['id']);
                unset($_SESSION['import_preview_token']);
                flash('success', sprintf('导入完成：新增%d条，更新%d条，跳过%d条，错误%d条。', $summary['created'], $summary['updated'], $summary['skipped'], $summary['errors']));
                $returnTab = 'imports&project_id=' . (int) $preview['batch']['project_id'];
                break;

            case 'reveal_admin_user':
            case 'approve_admin_registration':
            case 'reject_admin_registration':
            case 'reset_admin_registration_pending':
            case 'save_admin_user':
            case 'set_admin_status':
            case 'reset_admin_password':
            case 'delete_admin_registration':
            case 'remove_ip_block':
            case 'save_own_contact':
                $returnTab = AdminUserController::handle($action, $admin);
                break;

            case 'save_turnstile_settings':
            case 'clear_turnstile_secret':
            case 'toggle_email_verification':
            case 'save_mail_settings':
                $returnTab = SystemSettingsController::handle($action, $admin);
                break;

            case 'test_turnstile_settings':
                SystemSettingsController::test($admin);

            default:
                throw new PublicSafeException('操作无效。');
        }
    } catch (Throwable $exception) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (!$exception instanceof PublicSafeException) {
            PublicErrorHandler::logException($exception, [
                'component' => 'admin',
                'action' => $action,
                'response_type' => 'html',
            ]);
        }
        $friendly = PublicErrorHandler::publicMessage(
            $exception,
            '操作失败，请检查输入或稍后重试。'
        );
        if ($exception instanceof PDOException && (string) $exception->getCode() === '23000') {
            $friendly = '项目或字段配置存在冲突，请刷新后重试。';
        }
        audit_log('admin_action_failed', null, null, false, ['action' => $action]);
        admin_store_form_state($action, $_POST);
        flash('error', $friendly);
        $returnTab = admin_error_return_target($action, $_POST);
    }
    redirect('admin.php?tab=' . $returnTab);
}

$flash = consume_flash();
$formState = admin_consume_form_state();
$stateAction = (string) ($formState['action'] ?? '');
$statePost = is_array($formState['post'] ?? null) ? $formState['post'] : [];

$projects = GradeRepository::projects(true);
$statusLabels = ['enabled' => '启用', 'disabled' => '停用', 'archived' => '已归档'];
$typeLabels = ['text' => '文本', 'password' => '密码', 'number' => '数字', 'date' => '日期', 'select' => '下拉选择'];
$roleLabels = ['condition' => '查询条件', 'result' => '结果展示', 'both' => '条件和结果'];
$modal = (string) ($_GET['modal'] ?? '');
$allowedModals = [
    'project', 'project_delete', 'fields', 'field', 'field_delete',
    'record_new', 'record_view', 'record_edit', 'record_delete',
    'import', 'import_schema', 'import_preview', 'export', 'log_detail', 'password', 'profile',
    'admin_detail', 'admin_edit', 'admin_reject', 'admin_reset_password', 'admin_status', 'admin_delete',
];
if (!in_array($modal, $allowedModals, true)) {
    $modal = '';
}

$hasExplicitProject = isset($_GET['project_id']) && (int) $_GET['project_id'] > 0;
$selectedProjectId = (int) ($_GET['project_id'] ?? ($projects[0]['id'] ?? 0));
$selectedProject = null;
foreach ($projects as $project) {
    if ((int) $project['id'] === $selectedProjectId) {
        $selectedProject = $project;
        break;
    }
}
$selectedProject ??= $selectedProjectId > 0 ? GradeRepository::project($selectedProjectId, true) : null;
$projectFields = $selectedProject ? GradeRepository::fields($selectedProjectId) : [];

$editProject = $tab === 'projects' && $modal === 'project' && $hasExplicitProject && $selectedProject !== null
    ? $selectedProject
    : null;
$projectForm = $editProject ?? [
    'id' => 0,
    'name' => '',
    'description' => '',
    'notice' => '',
    'status' => 'disabled',
    'sort_order' => 0,
];
if ($stateAction === 'save_project') {
    $projectForm = array_merge($projectForm, $statePost);
}

$editField = null;
$fieldId = (int) ($_GET['field_id'] ?? 0);
if ($selectedProject && $fieldId > 0) {
    foreach ($projectFields as $field) {
        if ((int) $field['id'] === $fieldId) {
            $editField = $field;
            break;
        }
    }
}
$fieldForm = $editField ?? [
    'id' => 0,
    'field_label' => '',
    'field_key' => '',
    'field_type' => 'text',
    'field_role' => 'result',
    'placeholder' => '',
    'sort_order' => 0,
    'decimal_places' => 2,
    'validation_rule' => '',
    'options' => [],
    'is_required' => 1,
    'is_sensitive' => 0,
    'is_visible' => 1,
    'value_count' => 0,
];
if ($stateAction === 'save_field') {
    $fieldForm = array_merge($fieldForm, $statePost);
    $fieldForm['options'] = preg_split('/\r?\n/', (string) ($statePost['options'] ?? '')) ?: [];
    $fieldForm['is_required'] = isset($statePost['is_required']) ? 1 : 0;
    $fieldForm['is_sensitive'] = isset($statePost['is_sensitive']) ? 1 : 0;
    $fieldForm['is_visible'] = isset($statePost['is_visible']) ? 1 : 0;
}

$conditionFieldCount = count(array_filter($projectFields, static fn (array $field): bool => in_array($field['field_role'], ['condition', 'both'], true)));
$resultFieldCount = count(array_filter($projectFields, static fn (array $field): bool => in_array($field['field_role'], ['result', 'both'], true)));

$recordPage = null;
$recordFull = null;
$recordStats = ['query_count' => 0, 'last_query_at' => null];
$recordLogs = null;
$recordModalError = null;
$recordId = (int) ($_GET['record_id'] ?? 0);
if ($tab === 'records' && $selectedProject) {
    $recordPage = GradeRepository::listRecords(
        $selectedProjectId,
        trim((string) ($_GET['search'] ?? '')),
        max(1, (int) ($_GET['page'] ?? 1))
    );
    if (in_array($modal, ['record_view', 'record_edit'], true) && $recordId > 0) {
        if (admin_record_reveal_allowed($selectedProjectId, $recordId)) {
            $recordFull = GradeRepository::record($selectedProjectId, $recordId, false);
            if ($recordFull !== null) {
                $stats = db()->prepare('SELECT COUNT(*) AS query_count, MAX(created_at) AS last_query_at FROM query_logs WHERE record_id = ? AND is_success = 1');
                $stats->execute([$recordId]);
                $recordStats = $stats->fetch() ?: $recordStats;
                $recordLogs = GradeRepository::queryLogs(['record_id' => $recordId], 1, 5);
            }
        } else {
            $recordModalError = '记录查看授权已过期，请关闭弹窗后重新点击“查看详情”。';
        }
    }
}

$recordFormValues = is_array($recordFull['values'] ?? null) ? $recordFull['values'] : [];
if ($stateAction === 'save_record' && is_array($statePost['values'] ?? null)) {
    $recordFormValues = $statePost['values'];
}
$deleteRecord = null;
if ($tab === 'records' && $selectedProject && $modal === 'record_delete' && $recordId > 0) {
    $deleteRecord = GradeRepository::record($selectedProjectId, $recordId, true);
}

$importPreview = null;
if ($tab === 'imports' && isset($_SESSION['import_preview_token'])) {
    $importPreview = ExcelService::preview((string) $_SESSION['import_preview_token'], (int) $admin['id']);
    if ($importPreview === null) {
        unset($_SESSION['import_preview_token']);
    }
}

$projectDeletionPreview = null;
if ($tab === 'projects' && $modal === 'project_delete' && $selectedProject && $isSuperAdmin) {
    $projectDeletionPreview = ProjectDeletionService::previewDeletion($selectedProjectId);
}

$logFilters = [
    'project_id' => $_GET['project_id'] ?? '',
    'record_id' => $_GET['record_id'] ?? '',
    'record_search' => trim((string) ($_GET['record_search'] ?? '')),
    'ip' => trim((string) ($_GET['ip'] ?? '')),
    'success' => $_GET['success'] ?? '',
    'device_type' => $_GET['device_type'] ?? '',
    'start_date' => $_GET['start_date'] ?? '',
    'end_date' => $_GET['end_date'] ?? '',
];
$queryDate = trim((string) ($_GET['query_date'] ?? ''));
if ($queryDate !== '' && $logFilters['start_date'] === '' && $logFilters['end_date'] === '') {
    $logFilters['start_date'] = $queryDate;
    $logFilters['end_date'] = $queryDate;
}
$logPage = $tab === 'logs'
    ? GradeRepository::queryLogs($logFilters, max(1, (int) ($_GET['page'] ?? 1)))
    : null;
$logDetail = null;
$logId = (int) ($_GET['log_id'] ?? 0);
if ($tab === 'logs' && $modal === 'log_detail' && $logId > 0) {
    $stmt = db()->prepare('SELECT l.*, p.name AS project_name FROM query_logs l LEFT JOIN query_projects p ON p.id = l.project_id WHERE l.id = ? LIMIT 1');
    $stmt->execute([$logId]);
    $logDetail = $stmt->fetch();
    if (is_array($logDetail)) {
        try {
            $logDetail['ip_plain'] = decrypt_value((string) $logDetail['ip_address']);
        } catch (Throwable) {
            $logDetail['ip_plain'] = '无法解密';
        }
    } else {
        $logDetail = null;
    }
}

$dashboard = [];
$recentLogs = [];
$unconfiguredProjects = 0;
if ($tab === 'dashboard') {
    $dashboard['enabled_projects'] = (int) db()->query("SELECT COUNT(*) FROM query_projects WHERE status='enabled' AND deleted_at IS NULL")->fetchColumn();
    $dashboard['records'] = (int) db()->query('SELECT COUNT(*) FROM query_records WHERE deleted_at IS NULL')->fetchColumn();
    $dashboard['today_total'] = (int) db()->query('SELECT COUNT(*) FROM query_logs WHERE created_at >= CURDATE()')->fetchColumn();
    $dashboard['today_success'] = (int) db()->query('SELECT COUNT(*) FROM query_logs WHERE is_success=1 AND created_at >= CURDATE()')->fetchColumn();
    $dashboard['success_rate'] = $dashboard['today_total'] > 0
        ? (int) round($dashboard['today_success'] * 100 / $dashboard['today_total'])
        : 0;
    $recentLogs = db()->query('SELECT l.*, p.name AS project_name FROM query_logs l LEFT JOIN query_projects p ON p.id=l.project_id ORDER BY l.id DESC LIMIT 8')->fetchAll();
    $unconfiguredProjects = (int) db()->query('SELECT COUNT(*) FROM query_projects p WHERE p.deleted_at IS NULL AND NOT EXISTS (SELECT 1 FROM query_fields f WHERE f.project_id=p.id AND f.deleted_at IS NULL)')->fetchColumn();
}

$auditLogs = $tab === 'security' && $isSuperAdmin
    ? db()->query('SELECT a.*, u.username FROM admin_audit_logs a LEFT JOIN admin_users u ON u.id=a.admin_id ORDER BY a.id DESC LIMIT 50')->fetchAll()
    : [];

$pendingAdminCount = $isSuperAdmin ? AdminRepository::pendingCount() : 0;
$adminRoleLabels = ['super_admin' => '超级管理员', 'admin' => '管理员'];
$adminStatusLabels = ['pending' => '待审核', 'active' => '正常', 'rejected' => '审核未通过', 'disabled' => '已停用'];
$adminUserCounts = ['pending' => 0, 'active' => 0, 'rejected' => 0, 'disabled' => 0];
$adminUserPage = ['admins' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
$adminFilters = ['search' => '', 'role' => '', 'status' => ''];
$adminTarget = null;
$adminTargetFull = null;
$adminSameIpCount = 0;
$adminOneTimePassword = null;
$activeIpBlocks = $isSuperAdmin ? AuthSecurityRepository::activeBlockCount() : 0;
if ($tab === 'admin_users' && $isSuperAdmin) {
    $adminFilters = [
        'search' => trim((string) ($_GET['search'] ?? '')),
        'role' => (string) ($_GET['role'] ?? ''),
        'status' => (string) ($_GET['status'] ?? ''),
    ];
    $adminUserCounts = AdminRepository::counts();
    $adminUserPage = AdminRepository::list($adminFilters, max(1, (int) ($_GET['page'] ?? 1)));
    $adminTargetId = (int) ($_GET['admin_user_id'] ?? 0);
    if ($adminTargetId > 0) {
        $adminTarget = AdminRepository::findById($adminTargetId);
        if ($adminTarget !== null && AdminUserController::revealAllowed($adminTargetId)) {
            $adminTargetFull = AdminRepository::findById($adminTargetId, true);
            if ($adminTargetFull !== null && !empty($adminTargetFull['registration_ip_hash'])) {
                $sameIp = db()->prepare('SELECT COUNT(*) FROM admin_users WHERE registration_ip_hash = ?');
                $sameIp->execute([$adminTargetFull['registration_ip_hash']]);
                $adminSameIpCount = (int) $sameIp->fetchColumn();
            }
        }
    }
    $oneTimeToken = (string) ($_GET['one_time_password'] ?? '');
    if ($oneTimeToken !== '') {
        $adminOneTimePassword = AdminAccountService::consumeOneTimePassword($oneTimeToken, (int) $admin['id']);
    }
}

$ipBlocks = [];
$securityFilters = ['ip' => '', 'admin_id' => '', 'result' => '', 'reason' => '', 'start_date' => '', 'end_date' => ''];
$loginAttemptPage = ['attempts' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
$securityAdminOptions = [];
$recentPasswordFailures = 0;
$recentLoginSuccess = 0;
$loginFailureLabels = [
    'success' => '登录成功', 'invalid_credentials' => '用户名、手机号或密码错误',
    'pending' => '账号待审核', 'rejected' => '审核未通过', 'disabled' => '账号已停用',
    'turnstile_failed' => '人机验证失败', 'missing_token' => '未完成人机验证',
    'verification_failed' => '人机验证未通过', 'token_expired' => '人机验证已过期',
    'action_mismatch' => '验证场景不匹配', 'hostname_mismatch' => '验证域名不匹配',
    'configuration_missing' => '验证配置不完整', 'service_unavailable' => '验证服务不可用',
    'ip_blocked' => 'IP已封禁',
];
if ($tab === 'login_security' && $isSuperAdmin) {
    audit_log('admin_login_security_viewed', 'auth_ip_block');
    $ipBlocks = AuthSecurityRepository::listBlocks(true);
    $securityFilters = [
        'ip' => trim((string) ($_GET['ip'] ?? '')),
        'admin_id' => (string) ($_GET['admin_id'] ?? ''),
        'result' => (string) ($_GET['result'] ?? ''),
        'reason' => (string) ($_GET['reason'] ?? ''),
        'start_date' => (string) ($_GET['start_date'] ?? ''),
        'end_date' => (string) ($_GET['end_date'] ?? ''),
    ];
    $loginAttemptPage = AuthSecurityRepository::listAttempts($securityFilters, max(1, (int) ($_GET['page'] ?? 1)));
    $securityAdminOptions = db()->query('SELECT id, username FROM admin_users ORDER BY username')->fetchAll();
    $recentPasswordFailures = (int) db()->query("SELECT COUNT(*) FROM admin_login_attempts WHERE failure_reason='invalid_credentials' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)")->fetchColumn();
    $recentLoginSuccess = (int) db()->query('SELECT COUNT(*) FROM admin_login_attempts WHERE is_success=1 AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)')->fetchColumn();
}

$turnstileConfig = SystemSettingsService::turnstileConfig();
$emailVerificationStatus = EmailVerificationService::getFeatureStatus();
$emailVerificationEnabled = $emailVerificationStatus === 'enabled';
$turnstileReady = EmailVerificationService::isRegistrationVerificationReady();
$mailConfig = SystemSettingsService::mailConfig();
$mailStatus = (new MailService())->getConfigStatus();
if ($tab === 'system_settings' && $isSuperAdmin) {
    audit_log('turnstile_settings_viewed', 'system_setting', null, true, ['secret_configured' => (bool) $turnstileConfig['secret_configured']]);
}

// 加载新建查询草稿
$drafts = [];
$hasDrafts = false;
if ($tab === 'new_query') {
    $drafts = getDraftsForRender();
    $hasDrafts = !empty($drafts);
}
$pageMeta = [
    'dashboard' => ['首页概览', '快速了解系统状态和最近查询情况'],
    'projects' => ['查询项目', '管理前台可供用户选择的查询内容'],
    'records' => ['数据管理', '查看和维护各查询项目中的人员数据'],
    'imports' => ['导入导出', '批量导入、导出和下载数据模板'],
    'logs' => ['查询记录', '筛选和查看用户查询及设备信息'],
    'new_query' => ['新建查询', '上传Excel并按照向导快速创建查询项目'],
    'security' => ['账号与安全', '修改密码并查看管理员操作记录'],
    'admin_users' => ['管理员账号', '审核注册申请并管理管理员角色和状态'],
    'login_security' => ['登录安全', '查看密码错误、IP封禁和管理员登录记录'],
    'system_settings' => ['系统设置', '配置登录、注册和Cloudflare人机验证'],
];
[$pageTitle, $pageDescription] = $pageMeta[$tab];

$openModalMap = [
    'project' => 'projectModal',
    'project_delete' => 'projectDeleteModal',
    'fields' => 'fieldsDrawer',
    'field' => 'fieldModal',
    'field_delete' => 'fieldDeleteModal',
    'record_new' => 'recordModal',
    'record_view' => 'recordViewModal',
    'record_edit' => 'recordModal',
    'record_delete' => 'recordDeleteModal',
    'import' => 'importModal',
    'import_schema' => 'importSchemaModal',
    'import_preview' => 'importPreviewModal',
    'export' => 'exportModal',
    'log_detail' => 'logDetailModal',
    'password' => 'passwordModal',
    'profile' => 'profileModal',
    'admin_detail' => 'adminDetailModal',
    'admin_edit' => 'adminEditModal',
    'admin_reject' => 'adminRejectModal',
    'admin_reset_password' => 'adminResetPasswordModal',
    'admin_status' => 'adminStatusModal',
    'admin_delete' => 'adminDeleteModal',
];
$openModalId = $mustChangePassword ? 'passwordModal' : ($openModalMap[$modal] ?? '');

function admin_dialog_attributes(string $id, string $openModalId, bool $locked = false): string
{
    $attributes = $id === $openModalId ? ' open data-auto-open="true"' : '';
    if ($locked) {
        $attributes .= ' data-dismissible="false"';
    }
    return $attributes;
}

function render_project_actions(array $project): void
{
    global $isSuperAdmin;
    $id = (int) $project['id'];
    $status = (string) $project['status'];
    ?>
    <div class="row-actions">
        <a class="btn btn-small btn-secondary" href="<?= e(admin_page_url('projects', ['project_id' => $id, 'modal' => 'project'])) ?>">编辑</a>
        <a class="btn btn-small btn-secondary" href="<?= e(admin_page_url('projects', ['project_id' => $id, 'modal' => 'fields'])) ?>">字段</a>
        <a class="btn btn-small btn-primary" href="<?= e(admin_page_url('imports', ['project_id' => $id, 'modal' => 'import'])) ?>">上传Excel</a>
        <details class="action-menu">
            <summary class="btn btn-small btn-ghost">更多</summary>
            <div class="action-menu-panel">
                <?php if ($status !== 'enabled'): ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="set_project_status">
                    <input type="hidden" name="return_tab" value="projects">
                    <input type="hidden" name="project_id" value="<?= $id ?>">
                    <input type="hidden" name="status" value="enabled">
                    <button type="submit">启用</button>
                </form>
                <?php endif; ?>
                <?php if ($status !== 'disabled'): ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="set_project_status">
                    <input type="hidden" name="return_tab" value="projects">
                    <input type="hidden" name="project_id" value="<?= $id ?>">
                    <input type="hidden" name="status" value="disabled">
                    <button type="submit">停用</button>
                </form>
                <?php endif; ?>
                <?php if ($status !== 'archived'): ?>
                <form method="post"
                      data-confirm-title="归档查询项目"
                      data-confirm-message="归档后项目不会出现在前台，但历史数据和查询日志会保留。"
                      data-confirm-label="确认归档"
                      data-confirm-tone="danger">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="set_project_status">
                    <input type="hidden" name="return_tab" value="projects">
                    <input type="hidden" name="project_id" value="<?= $id ?>">
                    <input type="hidden" name="status" value="archived">
                    <button type="submit">归档</button>
                </form>
                <?php endif; ?>
                <?php if ($isSuperAdmin): ?>
                <a class="danger-link" href="<?= e(admin_page_url('projects', ['project_id' => $id, 'modal' => 'project_delete'])) ?>">永久删除</a>
                <?php endif; ?>
            </div>
        </details>
    </div>
    <?php
}

require dirname(__DIR__) . '/Views/admin/layout.php';
