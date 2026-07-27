<?php

declare(strict_types=1);

require_once __DIR__ . '/app/Services/ExcelService.php';

send_security_headers();
start_secure_session();
$admin = require_admin();
$action = (string) ($_POST['action'] ?? '');

try {
    if ($action === 'template' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        ExcelService::outputTemplate((int) ($_POST['project_id'] ?? 0));
    }
    if ($action === 'export' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $includeSensitive = isset($_POST['include_sensitive']);
        if ($includeSensitive && !isset($_POST['confirm_sensitive_export'])) {
            throw new PublicSafeException('导出完整敏感字段前必须确认风险提示。');
        }
        ExcelService::outputExport(
            (int) ($_POST['project_id'] ?? 0),
            trim((string) ($_POST['search'] ?? '')),
            $includeSensitive
        );
    }
    throw new PublicSafeException('下载请求无效。');
} catch (Throwable $exception) {
    if (!$exception instanceof PublicSafeException) {
        PublicErrorHandler::logException($exception, [
            'component' => 'admin_download',
            'action' => $action,
            'response_type' => 'download',
        ]);
    }
    flash('error', PublicErrorHandler::publicMessage(
        $exception,
        '文件生成失败，请稍后重试。'
    ));
    redirect('admin.php?tab=imports');
}
