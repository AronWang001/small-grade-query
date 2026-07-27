<section class="filter-bar">
                    <form method="get" class="compact-filter">
                        <input type="hidden" name="tab" value="records">
                        <div class="field">
                            <label for="records_project">查询项目</label>
                            <select class="control" id="records_project" name="project_id" data-auto-submit>
                                <?php foreach ($projects as $project): ?>
                                    <option value="<?= (int) $project['id'] ?>" <?= (int) $project['id'] === $selectedProjectId ? 'selected' : '' ?>><?= e($project['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field filter-search">
                            <label for="records_search">搜索人员</label>
                            <input class="control" id="records_search" name="search" value="<?= e($_GET['search'] ?? '') ?>" placeholder="姓名、班级或完整敏感字段">
                        </div>
                        <button class="btn btn-primary" type="submit"><?= admin_icon('search') ?> 搜索</button>
                        <?php if (trim((string) ($_GET['search'] ?? '')) !== ''): ?>
                            <a class="btn btn-ghost" href="<?= e(admin_page_url('records', ['project_id' => $selectedProjectId])) ?>">清空</a>
                        <?php endif; ?>
                    </form>
                </section>

                <?php if (!$selectedProject || !$recordPage): ?>
                    <section class="panel"><div class="empty-state"><?= admin_icon('database') ?><h2>还没有可管理的查询项目</h2><p>请先创建查询项目并配置字段。</p><a class="btn btn-primary" href="<?= e(admin_page_url('projects', ['modal' => 'project'])) ?>">新建查询项目</a></div></section>
                <?php elseif ($recordPage['records'] === []): ?>
                    <section class="panel">
                        <div class="empty-state">
                            <?= admin_icon('records') ?>
                            <h2>当前项目还没有数据</h2>
                            <p>可以手动新增，也可以通过Excel批量导入。</p>
                            <div class="empty-actions">
                                <a class="btn btn-primary" href="<?= e(admin_page_url('records', ['project_id' => $selectedProjectId, 'modal' => 'record_new'])) ?>" data-modal-target="#recordModal">新增记录</a>
                                <a class="btn btn-secondary" href="<?= e(admin_page_url('imports', ['project_id' => $selectedProjectId, 'modal' => 'import'])) ?>">导入Excel</a>
                            </div>
                        </div>
                    </section>
                <?php else: ?>
                    <section class="panel">
                        <form id="bulkDeleteForm"
                              method="post"
                              data-confirm-title="批量删除记录"
                              data-confirm-kind="bulk-delete"
                              data-confirm-label="删除所选记录"
                              data-confirm-tone="danger">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete_records">
                            <input type="hidden" name="return_tab" value="records">
                            <input type="hidden" name="project_id" value="<?= $selectedProjectId ?>">
                        </form>
                        <div class="list-toolbar">
                            <div><strong>共 <?= (int) $recordPage['total'] ?> 条记录</strong><span>敏感字段默认脱敏显示</span></div>
                            <button class="btn btn-danger btn-small" type="submit" form="bulkDeleteForm">批量删除</button>
                        </div>
                        <div class="desktop-table">
                            <div class="table-wrap">
                                <table>
                                    <thead>
                                    <tr>
                                        <th><input class="bulk-toggle" type="checkbox" aria-label="选择当前页全部记录"></th>
                                        <?php foreach ($projectFields as $field): ?><th><?= e($field['field_label']) ?></th><?php endforeach; ?>
                                        <th>成功查询</th><th>最近查询</th><th class="action-column">操作</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($recordPage['records'] as $record): ?>
                                        <tr>
                                            <td><input type="checkbox" form="bulkDeleteForm" name="record_ids[]" value="<?= (int) $record['id'] ?>" aria-label="选择记录<?= (int) $record['id'] ?>"></td>
                                            <?php foreach ($projectFields as $field): ?>
                                                <td><?= e(GradeRepository::formatValue((string) ($record['values'][$field['field_key']] ?? ''), $field)) ?></td>
                                            <?php endforeach; ?>
                                            <td><?= (int) $record['query_count'] ?></td>
                                            <td><?= e($record['last_query_at'] ?? '—') ?></td>
                                            <td>
                                                <form method="post" class="inline-form">
                                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="reveal_record">
                                                    <input type="hidden" name="return_tab" value="records">
                                                    <input type="hidden" name="project_id" value="<?= $selectedProjectId ?>">
                                                    <input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>">
                                                    <button class="btn btn-small btn-secondary" type="submit">查看详情</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="mobile-cards">
                            <?php $mobileFields = array_slice($projectFields, 0, 3); ?>
                            <?php foreach ($recordPage['records'] as $record): ?>
                                <article class="mobile-list-card record-card">
                                    <div class="mobile-card-head">
                                        <label class="mobile-check"><input type="checkbox" form="bulkDeleteForm" name="record_ids[]" value="<?= (int) $record['id'] ?>"><span>选择</span></label>
                                        <span class="record-id">#<?= (int) $record['id'] ?></span>
                                    </div>
                                    <dl class="record-preview">
                                        <?php foreach ($mobileFields as $field): ?>
                                            <div><dt><?= e($field['field_label']) ?></dt><dd><?= e(GradeRepository::formatValue((string) ($record['values'][$field['field_key']] ?? ''), $field)) ?></dd></div>
                                        <?php endforeach; ?>
                                    </dl>
                                    <div class="mobile-card-foot">
                                        <span>查询 <?= (int) $record['query_count'] ?> 次</span>
                                        <span><?= e($record['last_query_at'] ?? '尚未查询') ?></span>
                                    </div>
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="reveal_record">
                                        <input type="hidden" name="return_tab" value="records">
                                        <input type="hidden" name="project_id" value="<?= $selectedProjectId ?>">
                                        <input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>">
                                        <button class="btn btn-secondary btn-block" type="submit">查看详情</button>
                                    </form>
                                </article>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($recordPage['pages'] > 1): ?>
                            <nav class="pagination" aria-label="数据分页">
                                <?php if ($recordPage['page'] > 1): ?><a href="<?= e(admin_page_url('records', ['project_id' => $selectedProjectId, 'search' => $_GET['search'] ?? '', 'page' => $recordPage['page'] - 1])) ?>">上一页</a><?php endif; ?>
                                <?php for ($page = 1; $page <= $recordPage['pages']; $page++): ?>
                                    <a class="page-number <?= $page === $recordPage['page'] ? 'current' : '' ?>" href="<?= e(admin_page_url('records', ['project_id' => $selectedProjectId, 'search' => $_GET['search'] ?? '', 'page' => $page])) ?>"><?= $page ?></a>
                                <?php endfor; ?>
                                <span class="mobile-page-status">第 <?= (int) $recordPage['page'] ?> / <?= (int) $recordPage['pages'] ?> 页</span>
                                <?php if ($recordPage['page'] < $recordPage['pages']): ?><a href="<?= e(admin_page_url('records', ['project_id' => $selectedProjectId, 'search' => $_GET['search'] ?? '', 'page' => $recordPage['page'] + 1])) ?>">下一页</a><?php endif; ?>
                            </nav>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>
