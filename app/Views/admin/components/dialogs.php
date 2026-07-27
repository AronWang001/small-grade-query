<?php if (!$mustChangePassword): ?>
<dialog class="modal modal-medium" id="projectModal"<?= admin_dialog_attributes('projectModal', $openModalId) ?>>
    <form method="post" class="modal-card" data-modal-form>
        <header class="modal-header">
            <div><h2><?= (int) ($projectForm['id'] ?? 0) > 0 ? '编辑查询项目' : '新建查询项目' ?></h2><p>配置前台项目的名称、状态和展示说明。</p></div>
            <button type="button" class="icon-button" data-modal-close aria-label="关闭"><?= admin_icon('close') ?></button>
        </header>
        <div class="modal-body form-grid">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_project">
            <input type="hidden" name="return_tab" value="projects">
            <input type="hidden" name="project_id" value="<?= (int) ($projectForm['id'] ?? 0) ?>">
            <div class="field"><label for="project_name">项目名称</label><input class="control" id="project_name" name="name" required maxlength="150" value="<?= e($projectForm['name'] ?? '') ?>" autofocus></div>
            <div class="field"><label for="project_status">状态</label><select class="control" id="project_status" name="status"><?php foreach ($statusLabels as $value => $label): ?><option value="<?= e($value) ?>" <?= ($projectForm['status'] ?? 'disabled') === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label for="project_sort">排序</label><input class="control" id="project_sort" type="number" name="sort_order" value="<?= (int) ($projectForm['sort_order'] ?? 0) ?>"></div>
            <div class="field full"><label for="project_description">项目说明</label><textarea class="control" id="project_description" name="description"><?= e($projectForm['description'] ?? '') ?></textarea></div>
            <div class="field full"><label for="project_notice">前台提示</label><textarea class="control" id="project_notice" name="notice"><?= e($projectForm['notice'] ?? '') ?></textarea></div>
            <?php if ((int) ($projectForm['id'] ?? 0) > 0): ?><details class="advanced-settings full"><summary>高级信息</summary><p class="technical-id">系统标识：<?= e($projectForm['slug'] ?? '') ?>（只读，修改项目名称不会改变）</p></details><?php endif; ?>
        </div>
        <footer class="modal-footer"><button type="button" class="btn btn-ghost" data-modal-close>取消</button><button class="btn btn-primary" type="submit">保存项目</button></footer>
    </form>
</dialog>

<?php if ($selectedProject): ?>
<?php if ($isSuperAdmin && $projectDeletionPreview): ?>
<dialog class="modal modal-medium danger-modal" id="projectDeleteModal"<?= admin_dialog_attributes('projectDeleteModal', $openModalId) ?>>
    <form method="post" class="modal-card" data-permanent-delete-form>
        <header class="modal-header"><div><h2>永久删除查询项目</h2><p>此操作删除后无法恢复，请逐项确认影响范围。</p></div><button type="button" class="icon-button" data-modal-close aria-label="关闭"><?= admin_icon('close') ?></button></header>
        <div class="modal-body">
            <div class="danger-summary"><?= admin_icon('info') ?><div><strong><?= e($selectedProject['name']) ?></strong><p>此操作会永久删除该项目、全部字段、全部人员数据、全部查询记录和Excel导入记录。</p></div></div>
            <dl class="deletion-impact">
                <div><dt>项目字段</dt><dd><?= (int) $projectDeletionPreview['field_count'] ?></dd></div>
                <div><dt>有效记录</dt><dd><?= (int) $projectDeletionPreview['active_record_count'] ?></dd></div>
                <div><dt>已删除记录</dt><dd><?= (int) $projectDeletionPreview['deleted_record_count'] ?></dd></div>
                <div><dt>字段值</dt><dd><?= (int) $projectDeletionPreview['value_count'] ?></dd></div>
                <div><dt>查询日志</dt><dd><?= (int) $projectDeletionPreview['query_log_count'] ?></dd></div>
                <div><dt>导入批次</dt><dd><?= (int) $projectDeletionPreview['import_batch_count'] ?></dd></div>
                <div><dt>未提交预检文件</dt><dd><?= (int) $projectDeletionPreview['pending_preview_count'] ?></dd></div>
                <div><dt>最近查询</dt><dd><?= e($projectDeletionPreview['last_query_at'] ?? '—') ?></dd></div>
            </dl>
            <a class="btn btn-secondary btn-block" href="<?= e(admin_page_url('imports', ['project_id' => $selectedProjectId, 'modal' => 'export'])) ?>">删除前导出数据</a>
            <label class="confirm-checkbox"><input type="checkbox" name="acknowledge_irreversible" value="1" required data-delete-confirm-control><span>我已了解此操作无法恢复。</span></label>
            <div class="field"><label for="project_name_confirmation">输入完整项目名称</label><input class="control" id="project_name_confirmation" name="project_name_confirmation" required autocomplete="off" data-expected="<?= e($selectedProject['name']) ?>" data-delete-confirm-control></div>
            <div class="field"><label for="delete_confirmation">输入 DELETE</label><input class="control" id="delete_confirmation" name="delete_confirmation" required pattern="DELETE" autocomplete="off" data-delete-confirm-control></div>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="permanently_delete_project">
            <input type="hidden" name="return_tab" value="projects">
            <input type="hidden" name="project_id" value="<?= $selectedProjectId ?>">
        </div>
        <footer class="modal-footer"><button type="button" class="btn btn-ghost" data-modal-close autofocus>取消</button><button class="btn btn-danger" type="submit" data-permanent-delete-submit disabled>永久删除</button></footer>
    </form>
</dialog>
<?php endif; ?>

<dialog class="modal modal-drawer" id="fieldsDrawer"<?= admin_dialog_attributes('fieldsDrawer', $openModalId) ?>>
    <div class="modal-card">
        <header class="modal-header">
            <div><h2>字段管理</h2><p><?= e($selectedProject['name']) ?></p></div>
            <button type="button" class="icon-button" data-modal-close aria-label="关闭"><?= admin_icon('close') ?></button>
        </header>
        <div class="modal-body">
            <p class="context-help">系统会在首次上传Excel时自动创建字段。此页面主要用于后续调整查询条件、字段显示和敏感属性。</p>
            <div class="field-summary">
                <span><strong><?= $conditionFieldCount ?></strong>查询条件字段</span>
                <span><strong><?= $resultFieldCount ?></strong>结果字段</span>
                <a class="btn btn-primary" href="<?= e(admin_page_url('projects', ['project_id' => $selectedProjectId, 'modal' => 'field'])) ?>"><?= admin_icon('plus') ?> 新建字段</a>
            </div>
            <?php if ($projectFields === []): ?>
                <div class="empty-state compact"><?= admin_icon('upload') ?><h3>待上传Excel</h3><p>直接上传已有Excel，系统会读取第一行表头并自动创建字段。</p><a class="btn btn-primary" href="<?= e(admin_page_url('imports', ['project_id' => $selectedProjectId, 'modal' => 'import'])) ?>">上传Excel并识别字段</a></div>
            <?php else: ?>
                <div class="field-list">
                    <?php foreach ($projectFields as $field): ?>
                    <article class="field-row">
                        <div class="field-name"><strong><?= e($field['field_label']) ?></strong><small><?= e($field['field_key']) ?></small></div>
                        <span><?= e($roleLabels[$field['field_role']] ?? $field['field_role']) ?></span>
                        <span><?= e($typeLabels[$field['field_type']] ?? $field['field_type']) ?></span>
                        <div class="field-flags">
                            <?php if ((int) $field['is_required']): ?><span>必填</span><?php endif; ?>
                            <?php if ((int) $field['is_sensitive']): ?><span>敏感</span><?php endif; ?>
                            <span><?= (int) $field['is_visible'] ? '显示' : '隐藏' ?></span>
                        </div>
                        <span class="sort-label">排序 <?= (int) $field['sort_order'] ?></span>
                        <div class="row-actions">
                            <a class="btn btn-small btn-secondary" href="<?= e(admin_page_url('projects', ['project_id' => $selectedProjectId, 'field_id' => (int) $field['id'], 'modal' => 'field'])) ?>">编辑</a>
                            <a class="btn btn-small btn-ghost danger-text" href="<?= e(admin_page_url('projects', ['project_id' => $selectedProjectId, 'field_id' => (int) $field['id'], 'modal' => 'field_delete'])) ?>">删除</a>
                        </div>
                    </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <footer class="modal-footer"><button type="button" class="btn btn-ghost" data-modal-close>关闭</button></footer>
    </div>
</dialog>

<dialog class="modal modal-large" id="fieldModal"<?= admin_dialog_attributes('fieldModal', $openModalId) ?>>
    <form method="post" class="modal-card" data-modal-form>
        <header class="modal-header"><div><h2><?= (int) ($fieldForm['id'] ?? 0) > 0 ? '编辑字段' : '新建字段' ?></h2><p><?= e($selectedProject['name']) ?> · 先完成常用设置，需要时再展开高级选项。</p></div><button type="button" class="icon-button" data-modal-close aria-label="关闭"><?= admin_icon('close') ?></button></header>
        <div class="modal-body">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_field">
            <input type="hidden" name="return_tab" value="projects">
            <input type="hidden" name="project_id" value="<?= $selectedProjectId ?>">
            <input type="hidden" name="field_id" value="<?= (int) ($fieldForm['id'] ?? 0) ?>">
            <section class="form-section">
                <h3>基础设置</h3>
                <div class="form-grid">
                    <div class="field"><label for="field_label">字段名称</label><input class="control" id="field_label" name="field_label" required value="<?= e($fieldForm['field_label'] ?? '') ?>" autofocus></div>
                    <div class="field"><label for="field_key">字段标识</label><input class="control" id="field_key" name="field_key" required pattern="[a-z][a-z0-9_]{1,63}" value="<?= e($fieldForm['field_key'] ?? '') ?>"><small>用于系统识别字段，例如姓名可填写 name，学号可填写 student_number。</small></div>
                    <div class="field"><label for="field_type">字段类型</label><select class="control" id="field_type" name="field_type" data-field-type><?php foreach ($typeLabels as $value => $label): ?><option value="<?= e($value) ?>" <?= ($fieldForm['field_type'] ?? 'text') === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
                    <div class="field"><label for="field_role">字段用途</label><select class="control" id="field_role" name="field_role"><?php foreach ($roleLabels as $value => $label): ?><option value="<?= e($value) ?>" <?= ($fieldForm['field_role'] ?? 'result') === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
                </div>
            </section>
            <section class="form-section">
                <h3>常用设置</h3>
                <div class="toggle-grid">
                    <label class="toggle-option"><input type="checkbox" name="is_required" value="1" <?= (int) ($fieldForm['is_required'] ?? 0) ? 'checked' : '' ?>><span><strong>必填</strong><small>录入或查询时不能为空</small></span></label>
                    <label class="toggle-option"><input type="checkbox" name="is_sensitive" value="1" <?= (int) ($fieldForm['is_sensitive'] ?? 0) ? 'checked' : '' ?>><span><strong>敏感字段</strong><small>加密保存并默认脱敏</small></span></label>
                    <label class="toggle-option"><input type="checkbox" name="is_visible" value="1" <?= (int) ($fieldForm['is_visible'] ?? 0) ? 'checked' : '' ?>><span><strong>结果中显示</strong><small>查询成功后展示该字段</small></span></label>
                </div>
                <div class="form-grid form-grid-spaced">
                    <div class="field"><label for="field_placeholder">输入提示</label><input class="control" id="field_placeholder" name="placeholder" value="<?= e($fieldForm['placeholder'] ?? '') ?>" placeholder="例如：请输入姓名"></div>
                    <div class="field"><label for="field_sort">排序</label><input class="control" id="field_sort" type="number" name="sort_order" value="<?= (int) ($fieldForm['sort_order'] ?? 0) ?>"></div>
                </div>
            </section>
            <details class="advanced-settings" <?= $stateAction === 'save_field' && (($statePost['validation_rule'] ?? '') !== '' || ($statePost['options'] ?? '') !== '') ? 'open' : '' ?>>
                <summary>高级设置</summary>
                <div class="form-grid">
                    <div class="field" data-number-setting><label for="decimal_places">小数位数</label><input class="control" id="decimal_places" type="number" min="0" max="8" name="decimal_places" value="<?= (int) ($fieldForm['decimal_places'] ?? 2) ?>"><small>仅数字字段生效，范围0至8。</small></div>
                    <div class="field"><label for="validation_rule">后端校验正则</label><input class="control" id="validation_rule" name="validation_rule" value="<?= e($fieldForm['validation_rule'] ?? '') ?>" placeholder="仅在确有需要时填写"><small>需包含正则分隔符，例如 <code>/^\d+$/</code>。</small></div>
                    <div class="field full" data-select-setting><label for="field_options">下拉选项</label><textarea class="control" id="field_options" name="options" placeholder="每行填写一个选项"><?= e(implode("\n", is_array($fieldForm['options'] ?? null) ? $fieldForm['options'] : [])) ?></textarea></div>
                </div>
            </details>
        </div>
        <footer class="modal-footer"><a class="btn btn-ghost" href="<?= e(admin_page_url('projects', ['project_id' => $selectedProjectId, 'modal' => 'fields'])) ?>">取消</a><button class="btn btn-primary" type="submit">保存字段</button></footer>
    </form>
</dialog>

<?php if ($editField): ?>
<dialog class="modal modal-small danger-modal" id="fieldDeleteModal"<?= admin_dialog_attributes('fieldDeleteModal', $openModalId) ?>>
    <form method="post" class="modal-card">
        <header class="modal-header"><div><h2>删除字段</h2><p>字段删除后不会继续出现在录入和查询页面。</p></div><button type="button" class="icon-button" data-modal-close aria-label="关闭"><?= admin_icon('close') ?></button></header>
        <div class="modal-body">
            <div class="danger-summary"><?= admin_icon('info') ?><div><strong><?= e($editField['field_label']) ?></strong><p>字段标识：<?= e($editField['field_key']) ?>，关联 <?= (int) $editField['value_count'] ?> 个历史值。</p></div></div>
            <?php if ((int) $editField['value_count'] > 0): ?>
                <label class="confirm-checkbox"><input type="checkbox" name="confirm_related" value="1" required><span>我已了解历史字段值仍会保留，继续软删除此字段。</span></label>
            <?php else: ?>
                <p class="danger-copy">此字段没有关联数据，将直接删除字段配置。</p>
            <?php endif; ?>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete_field">
            <input type="hidden" name="return_tab" value="projects">
            <input type="hidden" name="project_id" value="<?= $selectedProjectId ?>">
            <input type="hidden" name="field_id" value="<?= (int) $editField['id'] ?>">
        </div>
        <footer class="modal-footer"><a class="btn btn-ghost" href="<?= e(admin_page_url('projects', ['project_id' => $selectedProjectId, 'modal' => 'fields'])) ?>" autofocus>取消</a><button class="btn btn-danger" type="submit">删除字段</button></footer>
    </form>
</dialog>
<?php endif; ?>
<?php endif; ?>

<?php if ($selectedProject): ?>
<dialog class="modal modal-large" id="recordModal"<?= admin_dialog_attributes('recordModal', $openModalId) ?>>
    <form method="post" class="modal-card" data-modal-form>
        <header class="modal-header"><div><h2><?= $modal === 'record_edit' ? '编辑记录' : '新增记录' ?></h2><p><?= e($selectedProject['name']) ?> · 字段由当前项目配置自动生成。</p></div><button type="button" class="icon-button" data-modal-close aria-label="关闭"><?= admin_icon('close') ?></button></header>
        <div class="modal-body">
            <?php if ($modal === 'record_edit' && $recordFull === null): ?>
                <div class="inline-alert error"><?= admin_icon('info') ?><span><?= e($recordModalError ?? '记录不存在或已删除。') ?></span></div>
            <?php else: ?>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="save_record">
                <input type="hidden" name="return_tab" value="records">
                <input type="hidden" name="project_id" value="<?= $selectedProjectId ?>">
                <input type="hidden" name="record_id" value="<?= $modal === 'record_edit' ? $recordId : 0 ?>">
                <div class="form-grid record-fields">
                <?php foreach ($projectFields as $index => $field): $value = (string) ($recordFormValues[$field['field_key']] ?? ''); ?>
                    <div class="field">
                        <label for="record_<?= e($field['field_key']) ?>"><?= e($field['field_label']) ?><?= (int) $field['is_required'] ? ' *' : '' ?></label>
                        <?php if ($field['field_type'] === 'select'): ?>
                            <select class="control" id="record_<?= e($field['field_key']) ?>" name="values[<?= e($field['field_key']) ?>]" <?= (int) $field['is_required'] ? 'required' : '' ?> <?= $index === 0 ? 'autofocus' : '' ?>>
                                <option value="">请选择</option>
                                <?php foreach ($field['options'] as $option): ?><option value="<?= e($option) ?>" <?= $value === $option ? 'selected' : '' ?>><?= e($option) ?></option><?php endforeach; ?>
                            </select>
                        <?php else: $inputType = in_array($field['field_type'], ['number', 'date'], true) ? $field['field_type'] : 'text'; ?>
                            <input class="control" id="record_<?= e($field['field_key']) ?>" type="<?= e($inputType) ?>" <?= $inputType === 'number' ? 'step="any"' : '' ?> name="values[<?= e($field['field_key']) ?>]" value="<?= e($value) ?>" <?= (int) $field['is_required'] ? 'required' : '' ?> <?= $index === 0 ? 'autofocus' : '' ?> autocomplete="off">
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <footer class="modal-footer"><button type="button" class="btn btn-ghost" data-modal-close>取消</button><?php if ($modal !== 'record_edit' || $recordFull !== null): ?><button class="btn btn-primary" type="submit">保存<?= $modal === 'record_edit' ? '修改' : '记录' ?></button><?php endif; ?></footer>
    </form>
</dialog>

<dialog class="modal modal-large" id="recordViewModal"<?= admin_dialog_attributes('recordViewModal', $openModalId) ?>>
    <div class="modal-card">
        <header class="modal-header"><div><h2>记录详情</h2><p><?= e($selectedProject['name']) ?><?= $recordId > 0 ? ' · 记录 #' . $recordId : '' ?></p></div><button type="button" class="icon-button" data-modal-close aria-label="关闭"><?= admin_icon('close') ?></button></header>
        <div class="modal-body">
            <?php if ($recordFull === null): ?>
                <div class="inline-alert error"><?= admin_icon('info') ?><span><?= e($recordModalError ?? '记录不存在或已删除。') ?></span></div>
            <?php else: ?>
                <div class="record-stats">
                    <span><strong><?= (int) $recordStats['query_count'] ?></strong>累计成功查询</span>
                    <span><strong><?= e($recordStats['last_query_at'] ?? '—') ?></strong>最近查询时间</span>
                </div>
                <dl class="detail-grid">
                    <?php foreach ($projectFields as $field): ?><div><dt><?= e($field['field_label']) ?><?php if ((int) $field['is_sensitive']): ?><span class="sensitive-mark">敏感</span><?php endif; ?></dt><dd><?= e(GradeRepository::formatValue((string) ($recordFull['values'][$field['field_key']] ?? ''), $field)) ?></dd></div><?php endforeach; ?>
                </dl>
                <?php if ($recordLogs && $recordLogs['logs'] !== []): ?>
                    <details class="detail-subsection"><summary>最近查询详情</summary><div class="mini-log-list"><?php foreach ($recordLogs['logs'] as $log): ?><div><span><?= e($log['created_at']) ?></span><span><?= e($log['ip_plain']) ?></span><span><?= e($log['device_type']) ?> · <?= e($log['browser_name']) ?></span></div><?php endforeach; ?></div></details>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <footer class="modal-footer">
            <button type="button" class="btn btn-ghost" data-modal-close>关闭</button>
            <?php if ($recordFull !== null): ?>
                <a class="btn btn-ghost danger-text" href="<?= e(admin_page_url('records', ['project_id' => $selectedProjectId, 'record_id' => $recordId, 'modal' => 'record_delete'])) ?>">删除记录</a>
                <a class="btn btn-secondary" href="<?= e(admin_page_url('logs', ['project_id' => $selectedProjectId, 'record_id' => $recordId])) ?>">查看查询详情</a>
                <a class="btn btn-primary" href="<?= e(admin_page_url('records', ['project_id' => $selectedProjectId, 'record_id' => $recordId, 'modal' => 'record_edit'])) ?>">编辑记录</a>
            <?php endif; ?>
        </footer>
    </div>
</dialog>

<?php if ($deleteRecord): ?>
<dialog class="modal modal-small danger-modal" id="recordDeleteModal"<?= admin_dialog_attributes('recordDeleteModal', $openModalId) ?>>
    <form method="post" class="modal-card">
        <header class="modal-header"><div><h2>删除记录</h2><p>删除后该记录不会在前台查询中出现。</p></div><button type="button" class="icon-button" data-modal-close aria-label="关闭"><?= admin_icon('close') ?></button></header>
        <div class="modal-body">
            <div class="danger-summary"><?= admin_icon('info') ?><div><strong>记录 #<?= $recordId ?></strong><p><?php $firstPreviewField = $projectFields[0] ?? null; ?><?= $firstPreviewField ? e($firstPreviewField['field_label'] . '：' . ($deleteRecord['values'][$firstPreviewField['field_key']] ?? '—')) : '确认删除这条记录。' ?></p></div></div>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete_records">
            <input type="hidden" name="return_tab" value="records">
            <input type="hidden" name="project_id" value="<?= $selectedProjectId ?>">
            <input type="hidden" name="record_id" value="<?= $recordId ?>">
        </div>
        <footer class="modal-footer"><button type="button" class="btn btn-ghost" data-modal-close autofocus>取消</button><button class="btn btn-danger" type="submit">删除记录</button></footer>
    </form>
</dialog>
<?php endif; ?>

<dialog class="modal modal-medium" id="importModal"<?= admin_dialog_attributes('importModal', $openModalId) ?>>
    <form method="post" enctype="multipart/form-data" class="modal-card" data-modal-form>
        <header class="modal-header"><div><span class="modal-step">步骤 1 / 4</span><h2>选择Excel文件</h2><p>系统会先识别第一行表头，确认字段前不会写入业务数据。</p></div><button type="button" class="icon-button" data-modal-close aria-label="关闭"><?= admin_icon('close') ?></button></header>
        <div class="modal-body form-stack">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="import_preview">
            <input type="hidden" name="return_tab" value="imports">
            <input type="hidden" name="project_id" value="<?= $selectedProjectId ?>">
            <div class="field"><label for="import_mode">导入模式</label><select class="control" id="import_mode" name="import_mode" data-import-mode><option value="add" <?= ($statePost['import_mode'] ?? '') === 'add' ? 'selected' : '' ?>>仅新增</option><option value="upsert" <?= ($statePost['import_mode'] ?? '') === 'upsert' ? 'selected' : '' ?>>更新或新增</option><option value="replace" <?= ($statePost['import_mode'] ?? '') === 'replace' ? 'selected' : '' ?>>覆盖该项目全部数据</option></select></div>
            <div class="replace-warning" data-replace-warning hidden><?= admin_icon('info') ?><span>覆盖模式会在正式导入时先软删除当前项目全部现有记录，请务必先备份。</span></div>
            <div class="field file-field"><label for="excel_file">Excel文件</label><input class="control" id="excel_file" type="file" name="excel_file" accept=".xlsx,.xls" required autofocus><small>仅支持 xlsx/xls，最大5MB、5000行；公式会被拒绝。</small></div>
        </div>
        <footer class="modal-footer"><button type="button" class="btn btn-ghost" data-modal-close>取消</button><button class="btn btn-primary" type="submit">上传并识别字段</button></footer>
    </form>
</dialog>

<?php if ($importPreview && ($importPreview['payload']['stage'] ?? '') === 'schema'): $schemaPayload = $importPreview['payload']; $schemaBatch = $importPreview['batch']; ?>
<dialog class="modal modal-xlarge" id="importSchemaModal"<?= admin_dialog_attributes('importSchemaModal', $openModalId) ?>>
    <form method="post" class="modal-card" data-modal-form>
        <header class="modal-header"><div><span class="modal-step">步骤 2 / 4</span><h2>字段识别结果</h2><p>确认Excel列如何匹配或创建系统字段；字段标识由系统生成且不可编辑。</p></div><button type="button" class="icon-button" data-modal-close aria-label="关闭"><?= admin_icon('close') ?></button></header>
        <div class="modal-body">
            <?php foreach ($schemaPayload['warnings'] ?? [] as $warning): ?><div class="inline-alert warning"><?= admin_icon('info') ?><span><?= e($warning) ?></span></div><?php endforeach; ?>
            <div class="schema-table-wrap">
                <table class="schema-table">
                    <thead><tr><th>Excel表头</th><th>字段映射</th><th>字段名称 / 标识</th><th>类型</th><th>查询条件</th><th>必填</th><th>敏感</th><th>结果显示</th></tr></thead>
                    <tbody>
                    <?php foreach ($schemaPayload['columns'] as $column):
                        $columnIndex = (int) $column['column_index'];
                        $savedColumn = is_array($statePost['columns'][$columnIndex] ?? null) ? $statePost['columns'][$columnIndex] : [];
                        $mappingValue = (string) ($savedColumn['mapping'] ?? $column['mapping']);
                        $isExistingMapping = str_starts_with($mappingValue, 'existing:');
                    ?>
                        <tr>
                            <td><strong><?= e($column['excel_header']) ?></strong><small>第<?= e($column['column_letter']) ?>列</small></td>
                            <td><select class="control schema-mapping" name="columns[<?= $columnIndex ?>][mapping]" data-schema-mapping>
                                <option value="new" <?= $mappingValue === 'new' ? 'selected' : '' ?>>作为新字段创建</option>
                                <?php foreach ($projectFields as $existing): ?><option value="existing:<?= (int) $existing['id'] ?>" <?= $mappingValue === 'existing:' . (int) $existing['id'] ? 'selected' : '' ?>>映射到：<?= e($existing['field_label']) ?></option><?php endforeach; ?>
                                <option value="ignore" <?= $mappingValue === 'ignore' ? 'selected' : '' ?>>忽略此列</option>
                            </select></td>
                            <td><input class="control" name="columns[<?= $columnIndex ?>][field_label]" maxlength="100" value="<?= e($savedColumn['field_label'] ?? $column['field_label']) ?>" <?= $isExistingMapping ? 'readonly' : '' ?>><small class="technical-id"><?= e($column['field_key']) ?></small></td>
                            <td><select class="control" name="columns[<?= $columnIndex ?>][field_type]" <?= $isExistingMapping ? 'disabled' : '' ?>><?php foreach (['text' => '文本', 'number' => '数字', 'date' => '日期'] as $value => $label): ?><option value="<?= $value ?>" <?= ($savedColumn['field_type'] ?? $column['field_type']) === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?><?php if (($column['field_type'] ?? '') === 'select'): ?><option value="select" selected disabled>下拉选择（沿用已有）</option><?php endif; ?></select></td>
                            <td><input type="checkbox" name="columns[<?= $columnIndex ?>][is_condition]" value="1" <?= isset($savedColumn['is_condition']) || ($savedColumn === [] && in_array($column['field_role'], ['condition', 'both'], true)) ? 'checked' : '' ?> <?= $isExistingMapping ? 'disabled' : '' ?> aria-label="<?= e($column['field_label']) ?>作为查询条件"></td>
                            <td><input type="checkbox" name="columns[<?= $columnIndex ?>][is_required]" value="1" <?= isset($savedColumn['is_required']) || ($savedColumn === [] && (int) $column['is_required'] === 1) ? 'checked' : '' ?> <?= $isExistingMapping ? 'disabled' : '' ?> aria-label="<?= e($column['field_label']) ?>必填"></td>
                            <td><input type="checkbox" name="columns[<?= $columnIndex ?>][is_sensitive]" value="1" <?= isset($savedColumn['is_sensitive']) || ($savedColumn === [] && (int) $column['is_sensitive'] === 1) ? 'checked' : '' ?> <?= $isExistingMapping ? 'disabled' : '' ?> aria-label="<?= e($column['field_label']) ?>敏感"></td>
                            <td><input type="checkbox" name="columns[<?= $columnIndex ?>][is_visible]" value="1" <?= isset($savedColumn['is_visible']) || ($savedColumn === [] && (int) $column['is_visible'] === 1) ? 'checked' : '' ?> <?= $isExistingMapping ? 'disabled' : '' ?> aria-label="<?= e($column['field_label']) ?>结果显示"></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="context-help">至少选择一个查询条件；建议使用姓名＋身份证号或姓名＋学号两个条件。敏感字段会继续使用加密和HMAC摘要保存。</p>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="import_schema_confirm">
            <input type="hidden" name="return_tab" value="imports">
            <input type="hidden" name="project_id" value="<?= $selectedProjectId ?>">
            <input type="hidden" name="token" value="<?= e($schemaBatch['token']) ?>">
        </div>
        <footer class="modal-footer"><a class="btn btn-ghost" href="<?= e(admin_page_url('imports', ['project_id' => $selectedProjectId, 'modal' => 'import'])) ?>">返回重新上传</a><button class="btn btn-primary" type="submit">保存字段并继续预检</button></footer>
    </form>
</dialog>
<?php endif; ?>

<?php if ($importPreview && ($importPreview['payload']['stage'] ?? '') === 'data'): $payload = $importPreview['payload']; $batch = $importPreview['batch']; ?>
<dialog class="modal modal-xlarge" id="importPreviewModal"<?= admin_dialog_attributes('importPreviewModal', $openModalId) ?>>
    <div class="modal-card">
        <header class="modal-header"><div><span class="modal-step">步骤 3 / 4</span><h2>数据预检结果</h2><p>预检结果1小时后失效，请确认数据和错误行。</p></div><button type="button" class="icon-button" data-modal-close aria-label="关闭"><?= admin_icon('close') ?></button></header>
        <div class="modal-body">
            <?php foreach ($payload['warnings'] ?? [] as $warning): ?><div class="inline-alert warning"><?= admin_icon('info') ?><span><?= e($warning) ?></span></div><?php endforeach; ?>
            <div class="preview-summary"><span><strong><?= (int) $batch['valid_rows'] ?></strong>有效行</span><span class="<?= (int) $batch['error_rows'] > 0 ? 'has-error' : '' ?>"><strong><?= (int) $batch['error_rows'] ?></strong>错误行</span><span><strong><?= e(['add' => '仅新增', 'upsert' => '更新或新增', 'replace' => '覆盖全部'][$batch['import_mode']] ?? $batch['import_mode']) ?></strong>导入模式</span></div>
            <?php if ($batch['import_mode'] === 'replace'): ?><div class="replace-warning"><?= admin_icon('info') ?><span>正式提交后会先删除当前项目中的现有数据，再写入Excel数据。该操作不可直接撤销。</span></div><?php endif; ?>
            <?php if (!empty($payload['errors'])): ?><details class="preview-errors" open><summary>错误行及原因</summary><div><?php foreach (array_slice($payload['errors'], 0, 100) as $error): ?><p>第<?= (int) $error['row'] ?>行：<?= e($error['reason']) ?></p><?php endforeach; ?></div></details><?php endif; ?>
            <h3 class="preview-title">前10条有效数据</h3>
            <div class="table-wrap preview-table"><table><thead><tr><th>原行号</th><?php foreach ($importPreview['fields'] as $field): ?><th><?= e($field['field_label']) ?></th><?php endforeach; ?></tr></thead><tbody><?php foreach (array_slice($payload['rows'], 0, 10) as $row): ?><tr><td><?= (int) $row['source_row'] ?></td><?php foreach ($importPreview['fields'] as $field): $value = (string) ($row['values'][$field['field_key']] ?? ''); ?><td><?= e((int) $field['is_sensitive'] ? mask_sensitive($value) : $value) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div>
        </div>
        <footer class="modal-footer">
            <a class="btn btn-ghost" href="<?= e(admin_page_url('imports', ['project_id' => $selectedProjectId, 'modal' => 'import'])) ?>">返回修改</a>
            <form method="post"
                  data-confirm-title="<?= $batch['import_mode'] === 'replace' ? '确认覆盖导入' : '确认正式导入' ?>"
                  data-confirm-message="<?= $batch['import_mode'] === 'replace' ? '此操作会先删除当前项目中的现有数据，再写入Excel中的数据。该操作不可直接撤销。' : '预检通过的数据将正式写入数据库。请确认项目和导入模式无误。' ?>"
                  data-confirm-label="确认导入"
                  data-confirm-tone="<?= $batch['import_mode'] === 'replace' ? 'danger' : 'primary' ?>">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="import_commit">
                <input type="hidden" name="return_tab" value="imports">
                <input type="hidden" name="project_id" value="<?= $selectedProjectId ?>">
                <input type="hidden" name="token" value="<?= e($batch['token']) ?>">
                <input type="hidden" name="confirm_import" value="1">
                <?php if ($batch['import_mode'] === 'replace'): ?><label class="confirm-checkbox compact"><input type="checkbox" name="confirm_replace" value="1" required><span>我确认覆盖当前项目全部数据</span></label><?php endif; ?>
                <button class="btn <?= $batch['import_mode'] === 'replace' ? 'btn-danger' : 'btn-primary' ?>" type="submit">确认正式导入</button>
            </form>
        </footer>
    </div>
</dialog>
<?php endif; ?>

<dialog class="modal modal-medium" id="exportModal"<?= admin_dialog_attributes('exportModal', $openModalId) ?>>
    <form method="post"
          action="admin_download.php"
          class="modal-card"
          data-confirm-title="确认导出数据"
          data-confirm-kind="export"
          data-confirm-label="确认导出">
        <header class="modal-header"><div><h2>导出数据</h2><p>选择导出范围以及是否包含完整敏感字段。</p></div><button type="button" class="icon-button" data-modal-close aria-label="关闭"><?= admin_icon('close') ?></button></header>
        <div class="modal-body form-stack">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="export">
            <input type="hidden" name="project_id" value="<?= $selectedProjectId ?>">
            <fieldset class="choice-group"><legend>导出范围</legend><label><input type="radio" name="export_scope" value="all" checked><span><strong>全部数据</strong><small>导出当前项目全部有效记录</small></span></label><label><input type="radio" name="export_scope" value="filtered"><span><strong>筛选结果</strong><small>按下面的搜索内容导出</small></span></label></fieldset>
            <div class="field" data-export-search hidden><label for="export_search">筛选内容</label><input class="control" id="export_search" name="search" placeholder="姓名、班级或完整敏感字段"></div>
            <label class="toggle-option sensitive-export-toggle"><input type="checkbox" name="include_sensitive" value="1" data-sensitive-export><span><strong>包含完整敏感字段</strong><small>导出操作和风险选择都会写入管理员审计日志</small></span></label>
            <label class="confirm-checkbox" data-sensitive-ack hidden><input type="checkbox" name="confirm_sensitive_export" value="1"><span>我确认当前环境安全，并了解导出文件需要妥善保管。</span></label>
        </div>
        <footer class="modal-footer"><button type="button" class="btn btn-ghost" data-modal-close>取消</button><button class="btn btn-primary" type="submit"><?= admin_icon('download') ?> 导出Excel</button></footer>
    </form>
</dialog>
<?php endif; ?>

<?php if ($logDetail): ?>
<dialog class="modal modal-medium" id="logDetailModal"<?= admin_dialog_attributes('logDetailModal', $openModalId) ?>>
    <div class="modal-card">
        <header class="modal-header"><div><h2>查询记录详情</h2><p>日志 #<?= (int) $logDetail['id'] ?></p></div><button type="button" class="icon-button" data-modal-close aria-label="关闭"><?= admin_icon('close') ?></button></header>
        <div class="modal-body">
            <dl class="detail-grid log-detail-grid">
                <div><dt>查询时间</dt><dd><?= e($logDetail['created_at']) ?></dd></div>
                <div><dt>查询项目</dt><dd><?= e($logDetail['project_name'] ?? '未知项目') ?></dd></div>
                <div><dt>查询结果</dt><dd><span class="badge badge-<?= (int) $logDetail['is_success'] ? 'success' : 'failed' ?>"><?= (int) $logDetail['is_success'] ? '成功' : '失败' ?></span></dd></div>
                <div><dt>关联记录</dt><dd><?= e($logDetail['record_id'] ?? '—') ?></dd></div>
                <div><dt>IP地址</dt><dd><?= e($logDetail['ip_plain']) ?></dd></div>
                <div><dt>设备类型</dt><dd><?= e($logDetail['device_type']) ?></dd></div>
                <div><dt>操作系统</dt><dd><?= e($logDetail['os_name']) ?></dd></div>
                <div><dt>浏览器</dt><dd><?= e($logDetail['browser_name']) ?></dd></div>
                <div class="full"><dt>User-Agent摘要</dt><dd class="user-agent-summary"><?= e(mb_substr((string) $logDetail['user_agent'], 0, 320, 'UTF-8')) ?><?= mb_strlen((string) $logDetail['user_agent'], 'UTF-8') > 320 ? '…' : '' ?></dd></div>
            </dl>
        </div>
        <footer class="modal-footer"><button type="button" class="btn btn-primary" data-modal-close>关闭</button></footer>
    </div>
</dialog>
<?php endif; ?>
<?php endif; ?>

<dialog class="modal modal-small" id="passwordModal"<?= admin_dialog_attributes('passwordModal', $openModalId, $mustChangePassword) ?>>
    <form method="post" class="modal-card" data-modal-form>
        <header class="modal-header">
            <div><h2><?= $mustChangePassword ? '首次登录，请修改密码' : '修改管理员密码' ?></h2><p><?= $mustChangePassword ? '完成修改后才能进入后台。' : '定期更新密码有助于保护系统数据。' ?></p></div>
            <?php if (!$mustChangePassword): ?><button type="button" class="icon-button" data-modal-close aria-label="关闭"><?= admin_icon('close') ?></button><?php endif; ?>
        </header>
        <div class="modal-body form-stack">
            <?php if ($mustChangePassword && $flash): ?><div class="inline-alert <?= $flash['type'] === 'error' ? 'error' : 'warning' ?>"><?= admin_icon('info') ?><span><?= e($flash['message']) ?></span></div><?php endif; ?>
            <?php if ($mustChangePassword): ?><div class="inline-alert warning"><?= admin_icon('shield') ?><span>当前使用默认密码，此弹窗不可跳过。</span></div><?php endif; ?>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="change_password">
            <input type="hidden" name="return_tab" value="security">
            <div class="field"><label for="old_password">原密码</label><input class="control" id="old_password" type="password" name="old_password" required autocomplete="current-password" autofocus></div>
            <div class="field"><label for="new_password">新密码</label><input class="control" id="new_password" type="password" name="new_password" required minlength="8" autocomplete="new-password"><small>至少8位，同时包含字母和数字，不能使用默认密码。</small></div>
            <div class="field"><label for="new_password_confirmation">确认新密码</label><input class="control" id="new_password_confirmation" type="password" name="new_password_confirmation" required minlength="8" autocomplete="new-password"></div>
        </div>
        <footer class="modal-footer"><?php if (!$mustChangePassword): ?><button type="button" class="btn btn-ghost" data-modal-close>取消</button><?php endif; ?><button class="btn btn-primary" type="submit">确认修改密码</button></footer>
    </form>
</dialog>

<?php if (!$mustChangePassword): ?>
<dialog class="modal modal-medium" id="profileModal"<?= admin_dialog_attributes('profileModal', $openModalId) ?>>
    <form method="post" class="modal-card" data-modal-form>
        <header class="modal-header"><div><h2>修改联系方式</h2><p>手机号和邮箱将加密保存，列表中只显示脱敏内容。</p></div><button type="button" class="icon-button" data-modal-close aria-label="关闭"><?= admin_icon('close') ?></button></header>
        <div class="modal-body form-stack">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_own_contact"><input type="hidden" name="return_tab" value="security">
            <div class="field"><label for="own_phone">新手机号</label><input class="control" id="own_phone" name="phone" required inputmode="tel" maxlength="24" placeholder="当前：<?= e($admin['phone_masked'] ?: '未设置') ?>" autofocus></div>
            <div class="field"><label for="own_email">新邮箱</label><input class="control" type="email" id="own_email" name="email" required maxlength="254" placeholder="当前：<?= e($admin['email_masked'] ?: '未设置') ?>"></div>
            <div class="field"><label for="own_contact_password">当前密码</label><input class="control" type="password" id="own_contact_password" name="current_password" required autocomplete="current-password"></div>
        </div>
        <footer class="modal-footer"><button class="btn btn-ghost" type="button" data-modal-close>取消</button><button class="btn btn-primary" type="submit">保存联系方式</button></footer>
    </form>
</dialog>
<?php endif; ?>

<?php if (!$mustChangePassword): ?>
<dialog class="modal modal-small confirm-modal" id="confirmModal" aria-labelledby="confirmTitle">
    <div class="modal-card">
        <header class="modal-header"><div><h2 id="confirmTitle">确认操作</h2><p id="confirmDescription">请确认是否继续。</p></div></header>
        <div class="modal-body"><div class="confirm-visual"><?= admin_icon('info') ?></div></div>
        <footer class="modal-footer"><button type="button" class="btn btn-ghost" id="confirmCancel" autofocus>取消</button><button type="button" class="btn btn-danger" id="confirmProceed">确认</button></footer>
    </div>
</dialog>
<?php endif; ?>
