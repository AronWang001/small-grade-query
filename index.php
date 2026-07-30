<?php

declare(strict_types=1);

require_once __DIR__ . "/app/Repositories/GradeRepository.php";

send_security_headers();
start_secure_session();

// ===== 结果令牌读取模式 =====
$resultToken = (string)($_GET["result"] ?? "");
$isResultMode = false;
$resultSnapshot = null;
$resultExpired = false;

if ($resultToken !== "") {
    // 校验令牌格式（48位十六进制=24字节随机数）
    if (preg_match("/^[a-f0-9]{48}$/", $resultToken) && isset($_SESSION["query_results"][$resultToken])) {
        $cached = $_SESSION["query_results"][$resultToken];
        // 检查是否过期（10分钟）
        if (($cached["expires_at"] ?? 0) > time()) {
            $resultSnapshot = $cached;
            $isResultMode = true;
        } else {
            $resultExpired = true;
            unset($_SESSION["query_results"][$resultToken]);
        }
    } else {
        $resultExpired = true;
    }
}

$projects = GradeRepository::projects();
$schemas = GradeRepository::publicSchemas($projects);

// 支持通过 ?project=slug 直达指定项目
$projectSlug = (string)($_GET["project"] ?? "");
$selectedProjectId = 0;
if ($projectSlug !== "") {
    foreach ($projects as $p) {
        if (($p["slug"] ?? "") === $projectSlug) {
            $selectedProjectId = (int)$p["id"];
            break;
        }
    }
}
if ($selectedProjectId === 0) {
    $selectedProjectId = (int) ($_POST["project_id"] ?? $_GET["project_id"] ?? ($projects[0]["id"] ?? 0));
}
if (!isset($schemas[(string) $selectedProjectId]) && $projects !== []) {
    $selectedProjectId = (int) $projects[0]["id"];
}
$selectedProject = $selectedProjectId > 0 ? GradeRepository::project($selectedProjectId) : null;
$selectedFields = $selectedProjectId > 0 ? array_values(array_filter(GradeRepository::fields($selectedProjectId), static fn (array $field): bool => in_array($field["field_role"], ["condition", "both"], true))) : [];
$result = null;
$message = null;
$messageType = "error";

// ===== POST 查询处理 =====
if ($_SERVER["REQUEST_METHOD"] === "POST" && !$isResultMode) {
    try {
        if (!verify_csrf($_POST["csrf_token"] ?? null)) {
            throw new PublicSafeException("页面已过期，请刷新后重试。");
        }
        $ipHash = hash_hmac("sha256", client_ip(), search_key());
        if (query_rate_limited($ipHash)) {
            $message = "查询操作过于频繁，请稍后再试。";
            $messageType = "warning";
        } else {
            $result = GradeRepository::publicQuery($selectedProjectId, is_array($_POST["conditions"] ?? null) ? $_POST["conditions"] : []);
            if ($result["status"] === "success") {
                // 记录查询日志（只写一次）
                write_query_log($selectedProjectId, (int) $result["record_id"], true);

                // 生成结果令牌并保存快照到Session
                $token = bin2hex(random_bytes(24));
                if (!isset($_SESSION["query_results"]) || !is_array($_SESSION["query_results"])) {
                    $_SESSION["query_results"] = [];
                }
                // 清理：最多保留最近3个结果
                $results = &$_SESSION["query_results"];
                if (count($results) >= 3) {
                    array_shift($results);
                }
                // 获取结果页提示：优先 result_notice，兼容 notice
                $resultNotice = $result["project"]["result_notice"] ?? $result["project"]["notice"] ?? "";
                $results[$token] = [
                    "project_id"     => $selectedProjectId,
                    "project_slug"   => $result["project"]["slug"] ?? "",
                    "project_name"   => $result["project"]["name"],
                    "result_notice"  => $resultNotice,
                    "results"        => $result["results"],
                    "generated_at"   => date("Y-m-d H:i"),
                    "expires_at"     => time() + 600, // 10分钟
                ];

                // 303重定向到结果页面，避免刷新时重复提交
                header("Location: index.php?result=" . $token, true, 303);
                exit;
            } elseif ($result["status"] === "multiple") {
                write_query_log($selectedProjectId ?: null, null, false);
                $message = "匹配到多条记录，请联系管理员核实查询条件。";
            } else {
                write_query_log($selectedProjectId ?: null, null, false);
                $message = "未查询到相关信息，请检查查询项目及查询条件是否填写正确。";
            }
        }
    } catch (Throwable $exception) {
        if (!$exception instanceof PublicSafeException) {
            PublicErrorHandler::logException($exception, [
                "component" => "public_query",
                "operation" => "query",
                "response_type" => "html",
            ]);
        }
        $message = PublicErrorHandler::publicMessage(
            $exception,
            "系统暂时无法完成查询，请稍后重试。"
        );
    }
}

$schemaJson = json_encode($schemas, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: "{}";
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
    <title>成绩查询系统</title>
    <link rel="stylesheet" href="assets/app.css?v=<?= (int) filemtime(__DIR__ . "/assets/app.css") ?>">
    <script src="assets/app.js?v=<?= (int) filemtime(__DIR__ . "/assets/app.js") ?>" defer></script>
</head>
<body>
<div class="decoration"></div><div class="decoration bottom"></div>

<?php if ($isResultMode && $resultSnapshot !== null): ?>
<?php
    // ===== 结果模式：只显示查询结果，不输出任何表单元素 =====
    $projectName        = $resultSnapshot["project_name"];
    $projectSlugForReturn = $resultSnapshot["project_slug"] ?? "";
    $resultNotice       = $resultSnapshot["result_notice"] ?? "";
    $resultItems        = $resultSnapshot["results"] ?? [];
    $generatedAt        = $resultSnapshot["generated_at"] ?? date("Y-m-d H:i");
    $returnUrl          = "index.php" . ($projectSlugForReturn !== "" ? "?project=" . urlencode($projectSlugForReturn) : "");
    // 截图用的安全文件名（移除非法字符）
    $safeFilename = $projectName !== "" ? preg_replace("/[\\\\\\/:*?\"<>|]/u", "_", $projectName) : "成绩查询结果";
    $safeFilename = mb_substr($safeFilename, 0, 80);
?>
<main class="container result-page-container">
    <!-- 截图区域：只包含结果内容，不包含操作按钮 -->
    <section id="resultCapture" class="result-sheet" data-safe-filename="<?= e($safeFilename) ?>">
        <header class="result-hero">
            <div class="result-badge">
                <svg class="result-check-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <path d="m5 12 4 4L19 6"/>
                </svg>
                查询成功
            </div>
            <h2 class="result-project-name"><?= e($projectName) ?></h2>
            <?php if ($resultNotice !== ""): ?>
            <p class="result-hero-notice"><?= e($resultNotice) ?></p>
            <?php endif; ?>
        </header>

        <div class="result-content">
            <?php foreach ($resultItems as $item): ?>
            <div class="result-field">
                <span class="result-field-label"><?= e($item["label"]) ?></span>
                <span class="result-field-value"><?= e($item["value"]) ?></span>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if ($resultNotice !== ""): ?>
        <div class="result-notice-box"><?= e($resultNotice) ?></div>
        <?php endif; ?>

        <footer class="result-capture-footer">
            <div class="result-footer-line">生成时间：<?= e($generatedAt) ?></div>
            <div class="result-footer-brand">GitHub: AronWang001/small-grade-query</div>
        </footer>
    </section>

    <!-- 操作按钮区：不在截图范围内 -->
    <div class="result-actions">
        <a href="<?= e($returnUrl) ?>" class="result-btn result-btn-back">
            <svg class="result-btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="m15 18-6-6 6-6"/>
            </svg>
            返回查询
        </a>
        <button type="button" class="result-btn result-btn-save" data-save-result-image>
            <svg class="result-btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                <polyline points="7,10 12,15 17,10"/>
                <line x1="12" y1="15" x2="12" y2="3"/>
            </svg>
            保存截图
        </button>
    </div>

    <p class="result-privacy-hint">截图包含个人查询结果，请妥善保管。</p>

    <footer class="footer">
        <div>
            GitHub开源：
            <a href="https://github.com/AronWang001/small-grade-query"
               target="_blank"
               rel="noopener noreferrer">AronWang001/small-grade-query</a>
        </div>
    </footer>
</main>

<?php else: ?>
<?php
    // ===== 查询模式：显示查询表单 =====
    // 获取登录页提示：优先 login_notice，兼容旧的 notice
    $loginNotice = $selectedProject["login_notice"] ?? $selectedProject["notice"] ?? "请选择查询项目并填写查询条件";
?>
<main class="container">
    <header class="header">
        <h1>成绩查询系统</h1>
        <p class="subtitle"><?= e($loginNotice) ?></p>
    </header>

    <section class="card">
        <?php if ($projects === []): ?>
            <div class="alert alert-warning">当前没有启用的查询项目，请联系管理员。</div>
        <?php else: ?>
        <div class="search-box">
            <form action="index.php" method="post" id="searchForm" data-schemas="<?= e($schemaJson) ?>">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <div class="form-group">
                    <label for="project_id">查询项目</label>
                    <select class="form-control" id="project_id" name="project_id" required>
                        <?php foreach ($projects as $project): ?>
                            <option value="<?= (int) $project["id"] ?>" <?= (int) $project["id"] === $selectedProjectId ? "selected" : "" ?>><?= e($project["name"]) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="dynamicFields" aria-live="polite">
                    <?php foreach ($selectedFields as $field): ?>
                    <div class="form-group">
                        <label for="field_<?= e($field["field_key"]) ?>"><?= e($field["field_label"]) ?></label>
                        <?php $postedValue = (string) ($_POST["conditions"][$field["field_key"]] ?? ""); ?>
                        <?php if ($field["field_type"] === "select"): ?>
                            <select class="form-control" id="field_<?= e($field["field_key"]) ?>" name="conditions[<?= e($field["field_key"]) ?>]" <?= (int) $field["is_required"] === 1 ? "required" : "" ?>>
                                <option value="">请选择<?= e($field["field_label"]) ?></option>
                                <?php foreach ($field["options"] as $option): ?>
                                    <option value="<?= e($option) ?>" <?= hash_equals($postedValue, (string) $option) ? "selected" : "" ?>><?= e($option) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <?php $inputType = in_array($field["field_type"], ["password", "number", "date"], true) ? $field["field_type"] : "text"; ?>
                            <input class="form-control" type="<?= e($inputType) ?>" id="field_<?= e($field["field_key"]) ?>" name="conditions[<?= e($field["field_key"]) ?>]" value="<?= $inputType === "password" ? "" : e($postedValue) ?>" placeholder="<?= e($field["placeholder"]) ?>" <?= $inputType === "number" ? "step=\"any\"" : "" ?> <?= (int) $field["is_required"] === 1 ? "required" : "" ?>>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button class="btn" type="submit"><span>查询</span></button>
            </form>
        </div>
        <?php endif; ?>
    </section>

    <?php if ($message !== null): ?>
        <div class="alert alert-<?= e($messageType) ?>"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if ($resultExpired): ?>
        <div class="alert alert-warning">查询结果已失效，请重新查询。</div>
    <?php endif; ?>

    <footer class="footer">
        <div>
            GitHub开源：
            <a href="https://github.com/AronWang001/small-grade-query"
               target="_blank"
               rel="noopener noreferrer">AronWang001/small-grade-query</a>
        </div>
    </footer>
</main>
<?php endif; ?>

</body>
</html>
