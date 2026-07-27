<section class="stats-grid" aria-label="系统统计">
                    <article class="stat-card stat-blue">
                        <span class="stat-icon"><?= admin_icon('projects') ?></span>
                        <div><span>启用项目</span><strong data-count="<?= $dashboard['enabled_projects'] ?>"><?= $dashboard['enabled_projects'] ?></strong></div>
                    </article>
                    <article class="stat-card stat-green">
                        <span class="stat-icon"><?= admin_icon('database') ?></span>
                        <div><span>有效记录</span><strong data-count="<?= $dashboard['records'] ?>"><?= $dashboard['records'] ?></strong></div>
                    </article>
                    <article class="stat-card stat-cyan">
                        <span class="stat-icon"><?= admin_icon('logs') ?></span>
                        <div><span>今日查询</span><strong data-count="<?= $dashboard['today_total'] ?>"><?= $dashboard['today_total'] ?></strong></div>
                    </article>
                    <article class="stat-card stat-violet">
                        <span class="stat-icon"><?= admin_icon('check') ?></span>
                        <div><span>今日成功率</span><strong data-count="<?= $dashboard['success_rate'] ?>" data-count-suffix="%"><?= $dashboard['success_rate'] ?>%</strong></div>
                    </article>
                </section>

                <section class="panel">
                    <div class="panel-heading">
                        <div><h2>常用操作</h2><p>从最常用的管理任务开始</p></div>
                    </div>
                    <div class="quick-actions">
                        <a href="<?= e(admin_page_url('new_query')) ?>"><?= admin_icon('plus') ?><span><strong>新建查询</strong><small>配置新的前台查询内容</small></span></a>
                        <a href="<?= e(admin_page_url('records')) ?>"><?= admin_icon('records') ?><span><strong>新增数据</strong><small>手动维护人员记录</small></span></a>
                        <a href="<?= e(admin_page_url('imports')) ?>"><?= admin_icon('upload') ?><span><strong>导入Excel</strong><small>批量预检并写入数据</small></span></a>
                        <a href="<?= e(admin_page_url('logs')) ?>"><?= admin_icon('logs') ?><span><strong>查看查询记录</strong><small>了解查询和设备情况</small></span></a>
                    </div>
                </section>

                <div class="dashboard-columns">
                    <section class="panel">
                        <div class="panel-heading">
                            <div><h2>最近查询记录</h2><p>显示最近<?= count($recentLogs) ?>条访问</p></div>
                            <a class="text-link" href="<?= e(admin_page_url('logs')) ?>">查看全部</a>
                        </div>
                        <?php if ($recentLogs === []): ?>
                            <div class="empty-state compact"><?= admin_icon('logs') ?><strong>暂无查询记录</strong><p>用户完成查询后，记录会显示在这里。</p></div>
                        <?php else: ?>
                            <div class="recent-list">
                                <?php foreach ($recentLogs as $log): ?>
                                <div class="recent-item">
                                    <span class="result-dot <?= (int) $log['is_success'] === 1 ? 'success' : 'failed' ?>"></span>
                                    <div><strong><?= e($log['project_name'] ?? '未知项目') ?></strong><small><?= e($log['created_at']) ?> · <?= e($log['device_type']) ?></small></div>
                                    <span class="badge badge-<?= (int) $log['is_success'] === 1 ? 'success' : 'failed' ?>"><?= (int) $log['is_success'] === 1 ? '成功' : '失败' ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                    <section class="panel system-tips">
                        <div class="panel-heading"><div><h2>系统提示</h2><p>需要关注的配置状态</p></div></div>
                        <div class="tip-item <?= $dashboard['enabled_projects'] > 0 ? 'ok' : 'warn' ?>">
                            <?= admin_icon($dashboard['enabled_projects'] > 0 ? 'check' : 'info') ?>
                            <div><strong><?= $dashboard['enabled_projects'] ?> 个项目正在前台启用</strong><p><?= $dashboard['enabled_projects'] > 0 ? '前台查询项目已正常开放。' : '请启用至少一个查询项目。' ?></p></div>
                        </div>
                        <div class="tip-item <?= $unconfiguredProjects === 0 ? 'ok' : 'warn' ?>">
                            <?= admin_icon($unconfiguredProjects === 0 ? 'check' : 'info') ?>
                            <div><strong><?= $unconfiguredProjects === 0 ? '所有项目均已配置字段' : $unconfiguredProjects . ' 个项目尚未配置字段' ?></strong><p>项目至少需要一个查询条件字段。</p></div>
                        </div>
                        <div class="tip-item ok">
                            <?= admin_icon('shield') ?>
                            <div><strong>管理员密码状态正常</strong><p>当前账号已完成首次密码修改。</p></div>
                        </div>
                    </section>
                </div>
