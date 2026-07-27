<?php

declare(strict_types=1);

/**
 * 新建查询向导控制器
 */

require_once __DIR__ . '/../Services/QueryCreationService.php';
require_once __DIR__ . '/../Services/QueryCreationDraftService.php';

// 仅处理 AJAX POST 请求
$isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
    || ($_POST['ajax'] ?? '') === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax) {
    header('Content-Type: application/json; charset=UTF-8');
    
    $action = (string)($_POST['action'] ?? '');
    
    try {
        if (!verify_csrf($_POST['csrf_token'] ?? null)) {
            throw new PublicSafeException('页面已过期，请刷新后重试。');
        }
        
        // Verify admin authentication
        $admin = current_admin();
        if ($admin === null || ((int)($admin['id'] ?? 0)) <= 0) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => '请先登录。'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $adminId = (int)$admin['id'];
        
        $result = match ($action) {
            'new_query_step1' => handleStep1($adminId),
            'new_query_step2' => handleStep2($adminId),
            'new_query_step3' => handleStep3($adminId),
            'new_query_step4' => handleStep4($adminId),
            'new_query_step5' => handleStep5($adminId),
            'new_query_delete_draft' => handleDeleteDraft($adminId),
            'new_query_get_draft' => handleGetDraft($adminId),
            'new_query_preview' => handlePreview($adminId),
            default => throw new PublicSafeException('未知操作。'),
        };
        
        echo json_encode(['success' => true] + ($result ?? []), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        if (!$e instanceof PublicSafeException) {
            PublicErrorHandler::logException($e, [
                'component' => 'new_query',
                'action' => $action,
                'response_type' => 'json',
            ]);
        }
        $message = PublicErrorHandler::publicMessage($e);
        echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

function getDraftsForRender(): array
{
    $admin = current_admin();
    return QueryCreationDraftService::listUnpublished((int)$admin['id']);
}

function handleStep1(int $adminId): array
{
    if (empty($_FILES['excel_file'])) {
        throw new PublicSafeException('请选择要上传的Excel文件。');
    }
    
    $result = QueryCreationService::step1Upload($adminId, $_FILES['excel_file']);
    
    return [
        'token' => $result['token'],
        'draft_id' => $result['draft_id'],
        'current_step' => 1,
        'columns' => $result['columns'],
        'preview_rows' => $result['preview_rows'],
        'total_rows' => $result['total_rows'],
        'suggested_name' => $result['suggested_name'],
        'sheet_name' => $result['sheet_name'],
        'original_filename' => $_FILES['excel_file']['name'] ?? '',
        'warnings' => $result['warnings'] ?? [],
        'errors' => $result['errors'] ?? [],
    ];
}

function handleStep2(int $adminId): array
{
    $draftId = (int)($_POST['draft_id'] ?? 0);
    $token = (string)($_POST['token'] ?? '');
    $columnsJson = (string)($_POST['columns'] ?? '');
    
    if ($draftId <= 0 || $token === '') {
        throw new PublicSafeException('草稿信息不完整，请返回重新上传。');
    }
    
    $draft = QueryCreationDraftService::findByToken($token, $adminId);
    if ($draft === null || (int)$draft['id'] !== $draftId) {
        throw new PublicSafeException('草稿不存在或已过期。');
    }
    
    $columns = json_decode($columnsJson, true);
    if (!is_array($columns) || empty($columns)) {
        throw new PublicSafeException('字段数据无效。');
    }
    
    QueryCreationService::step2ConfirmFields($draftId, $columns);
    $recommended = QueryCreationService::recommendConditions($columns);
    
    return [
        'draft_id' => $draftId,
        'token' => $token,
        'current_step' => 2,
        'recommended_conditions' => $recommended,
    ];
}

function handleStep3(int $adminId): array
{
    $draftId = (int)($_POST['draft_id'] ?? 0);
    $token = (string)($_POST['token'] ?? '');
    $conditionsJson = (string)($_POST['conditions'] ?? '');
    $resultsJson = (string)($_POST['results'] ?? '');
    
    if ($draftId <= 0 || $token === '') {
        throw new PublicSafeException('草稿信息不完整。');
    }
    
    $draft = QueryCreationDraftService::findByToken($token, $adminId);
    if ($draft === null || (int)$draft['id'] !== $draftId) {
        throw new PublicSafeException('草稿不存在或已过期。');
    }
    
    $conditions = json_decode($conditionsJson, true);
    $results = json_decode($resultsJson, true);
    
    if (!is_array($conditions) || empty($conditions)) {
        throw new PublicSafeException('请至少选择一个查询条件。');
    }
    
    QueryCreationService::step3QuerySettings($draftId, ['conditions' => $conditions, 'results' => $results]);
    
    return ['draft_id' => $draftId, 'token' => $token, 'current_step' => 3];
}

function handleStep4(int $adminId): array
{
    $draftId = (int)($_POST['draft_id'] ?? 0);
    $token = (string)($_POST['token'] ?? '');
    $name = trim((string)($_POST['name'] ?? ''));
    $loginNotice = trim((string)($_POST['login_notice'] ?? ''));
    $resultNotice = trim((string)($_POST['result_notice'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $publishNow = ($_POST['publish_now'] ?? '1') === '1';
    
    if ($draftId <= 0 || $token === '') {
        throw new PublicSafeException('草稿信息不完整。');
    }
    if ($name === '' || mb_strlen($name, 'UTF-8') > 150) {
        throw new PublicSafeException('请填写150个字符以内的查询名称。');
    }
    
    $draft = QueryCreationDraftService::findByToken($token, $adminId);
    if ($draft === null || (int)$draft['id'] !== $draftId) {
        throw new PublicSafeException('草稿不存在或已过期。');
    }
    
    QueryCreationService::step4PageSettings($draftId, [
        'name' => $name, 'login_notice' => $loginNotice, 'result_notice' => $resultNotice,
        'description' => $description, 'publish_now' => $publishNow,
    ]);
    
    return ['draft_id' => $draftId, 'token' => $token, 'current_step' => 4];
}

function handleStep5(int $adminId): array
{
    $draftId = (int)($_POST['draft_id'] ?? 0);
    $token = (string)($_POST['token'] ?? '');
    
    if ($draftId <= 0 || $token === '') {
        throw new PublicSafeException('草稿信息不完整。');
    }
    
    $draft = QueryCreationDraftService::findOwnedByToken($token, $adminId, true);
    if ($draft === null || (int)$draft['id'] !== $draftId) {
        throw new PublicSafeException('草稿不存在或已过期。');
    }
    
    $result = QueryCreationService::step5Publish($draftId, $adminId);
    
    return [
        'draft_id' => $draftId, 'token' => $token, 'current_step' => 5,
        'project_id' => $result['project_id'], 'slug' => $result['slug'],
        'name' => $result['name'], 'status' => $result['status'],
        'imported_rows' => $result['imported_rows'], 'error_count' => $result['error_count'],
        'field_count' => $result['field_count'],
        'already_published' => (bool)($result['already_published'] ?? false),
    ];
}

function handleDeleteDraft(int $adminId): array
{
    $draftId = (int)($_POST['draft_id'] ?? 0);
    $token = (string)($_POST['token'] ?? '');
    
    if ($draftId <= 0) throw new PublicSafeException('草稿信息无效。');
    
    $draft = QueryCreationDraftService::findByToken($token, $adminId);
    if ($draft === null || (int)$draft['id'] !== $draftId) {
        throw new PublicSafeException('草稿不存在或已过期。');
    }
    
    QueryCreationDraftService::delete($draftId);
    return ['message' => '草稿已删除。'];
}

function handleGetDraft(int $adminId): array
{
    $draftId = (int)($_POST['draft_id'] ?? 0);
    $token = (string)($_POST['token'] ?? '');
    
    if ($draftId <= 0 || $token === '') throw new PublicSafeException('草稿信息无效。');
    
    $draft = QueryCreationDraftService::findByToken($token, $adminId);
    if ($draft === null) throw new PublicSafeException('草稿不存在或已过期。');
    
    $stepData = QueryCreationDraftService::getStepData($draftId);
    $previewData = null;
    if (!empty($draft['preview_path'])) {
        $previewData = QueryCreationDraftService::readPreviewFile($draft['preview_path']);
    }
    
    return [
        'draft_id' => (int)$draft['id'], 'token' => $token,
        'current_step' => (int)$draft['current_step'],
        'original_filename' => $draft['original_filename'] ?? '',
        'suggested_name' => $draft['suggested_name'] ?? '',
        'expires_at' => $draft['expires_at'] ?? '',
        'step_data' => $stepData,
        'preview' => $previewData ? [
            'columns' => $previewData['columns'] ?? [],
            'preview_rows' => $previewData['preview_rows'] ?? [],
            'total_rows' => $previewData['total_rows'] ?? 0,
            'sheet_name' => $previewData['sheet_name'] ?? '',
        ] : null,
    ];
}

function handlePreview(int $adminId): array
{
    $draftId = (int)($_POST['draft_id'] ?? 0);
    $token = (string)($_POST['token'] ?? '');
    
    if ($draftId <= 0 || $token === '') throw new PublicSafeException('草稿信息无效。');
    
    $draft = QueryCreationDraftService::findByToken($token, $adminId);
    if ($draft === null) throw new PublicSafeException('草稿不存在或已过期。');
    
    $previewToken = bin2hex(random_bytes(16));
    $_SESSION['query_preview_tokens'] ??= [];
    $_SESSION['query_preview_tokens'][$previewToken] = [
        'draft_id' => $draftId, 'admin_id' => $adminId, 'expires_at' => time() + 600,
    ];
    
    return ['preview_token' => $previewToken, 'preview_url' => 'index.php?preview=' . $previewToken];
}
