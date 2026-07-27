<?php
/**
 * 新建查询向导页面
 * 
 * 五步向导流程：
 *   步骤1：上传Excel表格
 *   步骤2：确认字段类型
 *   步骤3：设置查询条件和显示结果
 *   步骤4：设置查询名称和页面提示
 *   步骤5：预览确认并发布
 */

// 获取当前管理员的未完成草稿
$drafts = getDraftsForRender();
$hasDrafts = !empty($drafts);
?>
<div class="wizard-container" id="queryWizard">
    <!-- 步骤指示器 -->
    <nav class="wizard-steps" aria-label="新建查询步骤">
        <ol>
            <li class="wizard-step-item active" data-step="1">
                <span class="wizard-step-num">1</span>
                <span class="wizard-step-label">上传表格</span>
            </li>
            <li class="wizard-step-item" data-step="2">
                <span class="wizard-step-num">2</span>
                <span class="wizard-step-label">确认字段</span>
            </li>
            <li class="wizard-step-item" data-step="3">
                <span class="wizard-step-num">3</span>
                <span class="wizard-step-label">查询设置</span>
            </li>
            <li class="wizard-step-item" data-step="4">
                <span class="wizard-step-num">4</span>
                <span class="wizard-step-label">页面设置</span>
            </li>
            <li class="wizard-step-item" data-step="5">
                <span class="wizard-step-num">5</span>
                <span class="wizard-step-label">预览发布</span>
            </li>
        </ol>
        <div class="wizard-step-progress" aria-hidden="true">
            <div class="wizard-step-progress-bar" style="width:0%"></div>
        </div>
    </nav>

    <!-- 手机端进度文字 -->
    <div class="wizard-mobile-step" aria-live="polite">步骤 1/5</div>

    <!-- 草稿恢复提示 -->
    <?php if ($hasDrafts): ?>
    <div class="wizard-draft-notice" id="draftNotice">
        <div class="wizard-draft-notice-inner">
            <span class="wizard-draft-icon">&#128196;</span>
            <div>
                <strong>您有未完成的查询</strong>
                <p>上次创建的草稿仍可继续编辑，也可以删除后新建。</p>
            </div>
            <div class="wizard-draft-actions">
                <?php foreach ($drafts as $draft): ?>
                <button type="button" class="btn btn-secondary btn-small" data-draft-id="<?= (int)$draft['id'] ?>" data-draft-token="<?= e($draft['token']) ?>" data-action="resume-draft">
                    继续编辑「<?= e(mb_substr($draft['suggested_name'] ?? $draft['original_filename'] ?? '未命名', 0, 20, 'UTF-8')) ?>」
                </button>
                <button type="button" class="btn btn-ghost btn-small" data-draft-id="<?= (int)$draft['id'] ?>" data-draft-token="<?= e($draft['token']) ?>" data-action="delete-draft" style="color:var(--danger)">
                    删除
                </button>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- 向导内容区域 -->
    <div class="wizard-panels" id="wizardPanels">
        <!-- 步骤1：上传Excel -->
        <div class="wizard-panel active" data-panel="1" id="panelStep1">
            <div class="wizard-panel-header">
                <h2>上传需要发布的查询表格</h2>
                <p>第一行作为字段名称，第二行开始填写需要查询的数据。支持 .xlsx 和 .xls 格式，最大 5MB，最多 5000 条数据。</p>
            </div>

            <!-- 文件拖放上传区域 -->
            <div class="wizard-upload-zone" id="uploadZone">
                <div class="wizard-upload-zone-inner">
                    <svg class="wizard-upload-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M12 3v12M7 8l5-5 5 5"/><path d="M4 15v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4"/>
                    </svg>
                    <strong>将 Excel 文件拖到这里</strong>
                    <span>或点击选择文件</span>
                    <input type="file" id="excelFileInput" accept=".xlsx,.xls" aria-label="选择Excel文件">
                    <small>支持 .xlsx .xls · 最大 5MB · 最多 5000 条 · 只读取第一个工作表</small>
                </div>
            </div>

            <!-- 上传后的文件信息 -->
            <div class="wizard-file-info" id="fileInfo" hidden>
                <div class="wizard-file-card">
                    <span class="wizard-file-icon">&#128203;</span>
                    <div>
                        <strong id="fileName">-</strong>
                        <small id="fileMeta">-</small>
                    </div>
                    <button type="button" class="btn btn-ghost btn-small" id="reuploadBtn">重新选择</button>
                </div>
            </div>

            <!-- 上传后的数据预览 -->
            <div class="wizard-preview-section" id="uploadPreview" hidden>
                <div class="wizard-preview-stats" id="previewStats"></div>
                <div class="wizard-preview-table-wrap" id="previewTableWrap"></div>
                <div class="wizard-preview-warnings" id="previewWarnings" hidden></div>
                <div class="wizard-preview-errors" id="previewErrors" hidden></div>
            </div>

            <!-- 下载示例模板 -->
            <div class="wizard-template-link">
                <p>不确定表格格式？<form method="post" action="admin_download.php" style="display:inline" target="_blank"><input type="hidden" name="action" value="template"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button type="submit" class="btn-link" style="border:none;background:none;color:inherit;cursor:pointer;padding:0;text-decoration:underline">下载填写示例</button></form></p>
            </div>
        </div>

        <!-- 步骤2：确认字段 -->
        <div class="wizard-panel" data-panel="2" id="panelStep2">
            <div class="wizard-panel-header">
                <h2>确认表格字段</h2>
                <p id="step2Summary">系统已自动识别字段类型和敏感属性，您可以在此调整。</p>
            </div>
            <div class="wizard-fields-table-wrap" id="fieldsTableWrap">
                <table class="wizard-fields-table" id="fieldsTable">
                    <thead>
                        <tr>
                            <th>表格字段</th>
                            <th>示例数据</th>
                            <th>字段类型</th>
                            <th>敏感</th>
                            <th class="wizard-advanced-col">高级</th>
                        </tr>
                    </thead>
                    <tbody id="fieldsTableBody"></tbody>
                </table>
            </div>
        </div>

        <!-- 步骤3：查询设置 -->
        <div class="wizard-panel" data-panel="3" id="panelStep3">
            <div class="wizard-panel-header">
                <h2>设置查询方式</h2>
                <p>选择学生查询时需要填写的条件和查询成功后显示的内容。</p>
            </div>
            <div class="wizard-query-layout">
                <div class="wizard-query-main">
                    <div class="wizard-card">
                        <h3>查询时需要填写</h3>
                        <p class="wizard-card-desc">系统已根据数据特征自动推荐，您可以调整。</p>
                        <div class="wizard-check-grid" id="conditionFields"></div>
                        <div class="wizard-safety-notice" id="safetyNotice" hidden>
                            <strong>&#9888;</strong>
                            <span>当前查询条件容易匹配到多人或被批量猜测，请增加学号、身份证号、手机号等识别字段。</span>
                        </div>
                    </div>
                    <div class="wizard-card">
                        <h3>查询成功后显示</h3>
                        <p class="wizard-card-desc">敏感字段默认不显示，您可以手动调整。</p>
                        <div class="wizard-check-grid" id="resultFields"></div>
                    </div>
                </div>
                <div class="wizard-query-preview">
                    <div class="wizard-card wizard-preview-card">
                        <h3>前台预览</h3>
                        <div class="wizard-preview-form" id="previewForm">
                            <div class="wizard-preview-form-title" id="previewFormTitle">查询项目</div>
                            <div id="previewFormFields"></div>
                            <button type="button" class="btn btn-primary wizard-preview-btn" disabled>立即查询</button>
                        </div>
                    </div>
                    <button type="button" class="btn btn-ghost btn-small wizard-preview-toggle" id="previewToggle" hidden>查看预览</button>
                </div>
            </div>
        </div>

        <!-- 步骤4：页面设置 -->
        <div class="wizard-panel" data-panel="4" id="panelStep4">
            <div class="wizard-panel-header">
                <h2>完善查询信息</h2>
                <p>设置查询名称、提示语和发布方式。</p>
            </div>
            <div class="wizard-form">
                <div class="form-group">
                    <label for="wizardName">查询名称 <span class="required">*</span></label>
                    <input type="text" class="form-control" id="wizardName" name="name" maxlength="150" required placeholder="例如：2026年期末综合成绩查询">
                    <small>自动根据文件名生成，可以修改。最多150个字符。</small>
                </div>
                <div class="form-group">
                    <label for="wizardLoginNotice">查询登录页提示</label>
                    <textarea class="form-control" id="wizardLoginNotice" name="login_notice" rows="2" maxlength="2000" placeholder="例如：请输入本人姓名和身份证号进行查询。如信息有误，请联系管理员。"></textarea>
                    <small>显示在查询表单上方的说明文字。</small>
                </div>
                <div class="form-group">
                    <label for="wizardResultNotice">查询结果页提示</label>
                    <textarea class="form-control" id="wizardResultNotice" name="result_notice" rows="2" maxlength="2000" placeholder="例如：如对成绩有疑问，请在3个工作日内联系教务处。"></textarea>
                    <small>显示在查询结果上方的说明文字。</small>
                </div>
                <div class="form-group">
                    <label for="wizardDescription">项目说明（后台备注）</label>
                    <textarea class="form-control" id="wizardDescription" name="description" rows="2" maxlength="5000" placeholder="可选，仅在后台可见。"></textarea>
                </div>
                <div class="form-group">
                    <label>发布方式</label>
                    <div class="wizard-radio-group">
                        <label class="wizard-radio">
                            <input type="radio" name="publish_now" value="1" checked>
                            <span>
                                <strong>立即发布</strong>
                                <small>保存后前台立即可查询</small>
                            </span>
                        </label>
                        <label class="wizard-radio">
                            <input type="radio" name="publish_now" value="0">
                            <span>
                                <strong>暂存但不开放查询</strong>
                                <small>仅后台可见，后续可手动启用</small>
                            </span>
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <!-- 步骤5：预览并发布 -->
        <div class="wizard-panel" data-panel="5" id="panelStep5">
            <div class="wizard-panel-header">
                <h2>确认并发布查询</h2>
                <p>请检查以下信息，确认无误后点击发布。</p>
            </div>
            <div class="wizard-publish-summary" id="publishSummary">
                <!-- 动态填充 -->
            </div>
            <div class="wizard-publish-data" id="publishDataPreview">
                <h3>数据预览（前10条）</h3>
                <div class="wizard-preview-table-wrap" id="publishTableWrap"></div>
            </div>
        </div>
    </div>

    <!-- 发布成功页面 -->
    <div class="wizard-panels" id="wizardSuccess" hidden>
        <div class="wizard-success-card">
            <div class="wizard-success-icon">&#10003;</div>
            <h2>查询已发布</h2>
            <div class="wizard-success-info" id="successInfo"></div>
            <div class="wizard-success-actions">
                <a class="btn btn-primary" id="successFrontLink" href="index.php" target="_blank">预览查询页面</a>
                <button type="button" class="btn btn-secondary" id="successCopyLink" data-clipboard-text="">复制查询链接</button>
                <a class="btn btn-secondary" href="<?= e(admin_page_url('projects')) ?>">返回查询列表</a>
            </div>
        </div>
    </div>

    <!-- 底部操作栏 -->
    <div class="wizard-footer" id="wizardFooter">
        <div class="wizard-footer-inner">
            <button type="button" class="btn btn-secondary" id="wizardPrev" hidden>
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
                上一步
            </button>
            <button type="button" class="btn btn-primary" id="wizardNext" disabled>
                下一步
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
            </button>
            <button type="button" class="btn btn-primary" id="wizardPublish" hidden>发布查询</button>
            <button type="button" class="btn btn-secondary" id="wizardCancel">取消</button>
        </div>
    </div>
</div>

<!-- 隐藏的CSRF token（AJAX请求使用） -->
<input type="hidden" id="wizardCsrfToken" value="<?= e(csrf_token()) ?>">

<!-- 加载状态遮罩 -->
<div class="wizard-loading" id="wizardLoading" hidden>
    <div class="wizard-loading-spinner"></div>
    <p id="wizardLoadingText">处理中...</p>
</div>

<!-- 确认发布对话框 -->
<dialog class="modal" id="confirmPublishDialog">
    <div class="modal-card">
        <div class="modal-header">
            <h2>确认发布</h2>
            <button type="button" class="modal-close" data-close-dialog aria-label="关闭">&#10005;</button>
        </div>
        <div class="modal-body">
            <p id="confirmPublishText">将创建查询项目并导入数据，是否继续？</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-close-dialog>取消</button>
            <button type="button" class="btn btn-primary" id="confirmPublishBtn">确认发布</button>
        </div>
    </div>
</dialog>