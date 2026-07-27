<section class="panel">
                    <?php if ($projects === []): ?>
                        <div class="empty-state">
                            <?= admin_icon('projects') ?>
                            <h2>还没有查询项目</h2>
                            <p>创建第一个查询项目后，前台即可开始使用。</p>
                            <a class="btn btn-primary" href="<?= e(admin_page_url('new_query')) ?>" data-modal-target="#projectModal"><?= admin_icon('plus') ?> 新建查询</a>
                        </div>
                    <?php else: ?>
                        <div class="desktop-table">
                            <div class="table-wrap">
                                <table>
                                    <thead><tr><th>项目</th><th>状态</th><th>字段</th><th>排序</th><th>数据量</th><th>查询次数</th><th class="action-column">操作</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($projects as $project): ?>
                                        <tr>
                                            <td><strong><?= e($project['name']) ?></strong><small class="technical-id"><?= e($project['slug']) ?></small></td>
                                            <td><span class="badge badge-<?= (int) $project['field_count'] === 0 ? 'pending' : e($project['status']) ?>"><?= (int) $project['field_count'] === 0 ? '待上传Excel' : e($statusLabels[$project['status']] ?? $project['status']) ?></span></td>
                                            <td><?= (int) $project['field_count'] ?></td>
                                            <td><?= (int) $project['sort_order'] ?></td>
                                            <td><?= (int) $project['record_count'] ?></td>
                                            <td><?= (int) $project['query_count'] ?></td>
                                            <td><?php render_project_actions($project); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="mobile-cards">
                            <?php foreach ($projects as $project): ?>
                                <article class="mobile-list-card">
                                    <div class="mobile-card-head">
                                        <div><h3><?= e($project['name']) ?></h3><small><?= e($project['slug']) ?></small></div>
                                        <span class="badge badge-<?= (int) $project['field_count'] === 0 ? 'pending' : e($project['status']) ?>"><?= (int) $project['field_count'] === 0 ? '待上传Excel' : e($statusLabels[$project['status']] ?? $project['status']) ?></span>
                                    </div>
                                    <dl class="mobile-metrics">
                                        <div><dt>数据量</dt><dd><?= (int) $project['record_count'] ?></dd></div>
                                        <div><dt>查询次数</dt><dd><?= (int) $project['query_count'] ?></dd></div>
                                        <div><dt>字段</dt><dd><?= (int) $project['field_count'] ?></dd></div>
                                        <div><dt>排序</dt><dd><?= (int) $project['sort_order'] ?></dd></div>
                                    </dl>
                                    <?php render_project_actions($project); ?>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
