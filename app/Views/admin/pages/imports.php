<section class="filter-bar project-picker">
                    <form method="get" class="compact-filter">
                        <input type="hidden" name="tab" value="imports">
                        <div class="field">
                            <label for="imports_project">查询项目</label>
                            <select class="control" id="imports_project" name="project_id">
                                <?php foreach ($projects as $project): ?>
                                    <option value="<?= (int) $project['id'] ?>" <?= (int) $project['id'] === $selectedProjectId ? 'selected' : '' ?>><?= e($project['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button class="btn btn-secondary" type="submit">切换项目</button>
                    </form>
                </section>
                <?php if (!$selectedProject): ?>
                    <section class="panel"><div class="empty-state"><?= admin_icon('excel') ?><h2>暂无可用项目</h2><p>请先创建查询项目并配置字段。</p></div></section>
                <?php else: ?>
                    <section class="excel-workflow">
                        <article class="workflow-card">
                            <span class="step-number">1</span>
                            <span class="workflow-icon"><?= admin_icon('download') ?></span>
                            <h2><?= $projectFields === [] ? '下载填写示例' : '下载当前模板' ?></h2>
                            <p><?= $projectFields === [] ? '示例表头可以自由修改；上传后系统会自动识别并创建字段。' : '模板包含经过校验的隐藏字段标识，可准确匹配当前项目。' ?></p>
                            <form method="post" action="admin_download.php" style="display:inline"><input type="hidden" name="action" value="template"><input type="hidden" name="project_id" value="<?= $selectedProjectId ?>"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button type="submit" class="btn btn-secondary btn-block"><?= $projectFields === [] ? '下载Excel填写示例' : '下载当前项目模板' ?></button></form>
                        </article>
                        <article class="workflow-card">
                            <span class="step-number">2</span>
                            <span class="workflow-icon"><?= admin_icon('upload') ?></span>
                            <h2>导入数据</h2>
                            <p><?= $projectFields === [] ? '无需先配置字段，上传后确认字段用途并预检数据。' : '可匹配已有字段、确认新增列，再预检并正式导入。' ?></p>
                            <a class="btn btn-primary btn-block" href="<?= e(admin_page_url('imports', ['project_id' => $selectedProjectId, 'modal' => 'import'])) ?>" data-modal-target="#importModal">选择Excel文件</a>
                        </article>
                        <article class="workflow-card">
                            <span class="step-number">3</span>
                            <span class="workflow-icon"><?= admin_icon('download') ?></span>
                            <h2>导出数据</h2>
                            <p><?= $projectFields === [] ? '当前项目尚未导入数据。' : '导出全部或筛选数据；完整敏感字段需要再次确认。' ?></p>
                            <?php if ($projectFields === []): ?><button class="btn btn-secondary btn-block" type="button" disabled>暂无数据可导出</button><?php else: ?><a class="btn btn-secondary btn-block" href="<?= e(admin_page_url('imports', ['project_id' => $selectedProjectId, 'modal' => 'export'])) ?>" data-modal-target="#exportModal">设置并导出</a><?php endif; ?>
                        </article>
                    </section>
                <?php endif; ?>
