<section class="filter-bar sticky-filter">
                    <form method="get" class="log-filter">
                        <input type="hidden" name="tab" value="logs">
                        <div class="field"><label for="log_project">查询项目</label><select class="control" id="log_project" name="project_id"><option value="">全部项目</option><?php foreach ($projects as $project): ?><option value="<?= (int) $project['id'] ?>" <?= (string) ($_GET['project_id'] ?? '') === (string) $project['id'] ? 'selected' : '' ?>><?= e($project['name']) ?></option><?php endforeach; ?></select></div>
                        <div class="field"><label for="record_search">姓名或记录</label><input class="control" id="record_search" name="record_search" value="<?= e($_GET['record_search'] ?? '') ?>" placeholder="输入姓名或记录ID"></div>
                        <div class="field"><label for="log_success">查询结果</label><select class="control" id="log_success" name="success"><option value="">全部结果</option><option value="1" <?= ($_GET['success'] ?? '') === '1' ? 'selected' : '' ?>>成功</option><option value="0" <?= ($_GET['success'] ?? '') === '0' ? 'selected' : '' ?>>失败</option></select></div>
                        <div class="field"><label for="query_date">查询日期</label><input class="control" id="query_date" type="date" name="query_date" value="<?= e($_GET['query_date'] ?? '') ?>"></div>
                        <div class="filter-actions">
                            <button class="btn btn-primary" type="submit"><?= admin_icon('search') ?> 筛选</button>
                            <a class="btn btn-ghost" href="<?= e(admin_page_url('logs')) ?>">清空</a>
                        </div>
                        <details class="advanced-filter">
                            <summary>更多筛选</summary>
                            <div class="advanced-filter-grid">
                                <div class="field"><label for="record_id">精确记录ID</label><input class="control" id="record_id" type="number" name="record_id" value="<?= e($_GET['record_id'] ?? '') ?>"></div>
                                <div class="field"><label for="log_ip">精确IP地址</label><input class="control" id="log_ip" name="ip" value="<?= e($_GET['ip'] ?? '') ?>"></div>
                                <div class="field"><label for="device_type">设备类型</label><select class="control" id="device_type" name="device_type"><option value="">全部设备</option><?php foreach (['手机', '平板', '电脑', '其他'] as $device): ?><option value="<?= e($device) ?>" <?= ($_GET['device_type'] ?? '') === $device ? 'selected' : '' ?>><?= e($device) ?></option><?php endforeach; ?></select></div>
                                <div class="field"><label for="start_date">开始日期</label><input class="control" id="start_date" type="date" name="start_date" value="<?= e($_GET['start_date'] ?? '') ?>"></div>
                                <div class="field"><label for="end_date">结束日期</label><input class="control" id="end_date" type="date" name="end_date" value="<?= e($_GET['end_date'] ?? '') ?>"></div>
                            </div>
                        </details>
                    </form>
                    <?php
                    $filterTags = [];
                    if (($logFilters['project_id'] ?? '') !== '') {
                        foreach ($projects as $project) {
                            if ((string) $project['id'] === (string) $logFilters['project_id']) {
                                $filterTags[] = '项目：' . $project['name'];
                            }
                        }
                    }
                    if ($logFilters['record_search'] !== '') $filterTags[] = '人员：' . $logFilters['record_search'];
                    if ($logFilters['success'] !== '') $filterTags[] = '结果：' . ((int) $logFilters['success'] === 1 ? '成功' : '失败');
                    if ($queryDate !== '') $filterTags[] = '日期：' . $queryDate;
                    if ($logFilters['device_type'] !== '') $filterTags[] = '设备：' . $logFilters['device_type'];
                    if ($logFilters['ip'] !== '') $filterTags[] = 'IP：' . $logFilters['ip'];
                    ?>
                    <?php if ($filterTags !== []): ?><div class="filter-tags"><?php foreach ($filterTags as $tag): ?><span><?= e($tag) ?></span><?php endforeach; ?></div><?php endif; ?>
                </section>
                <p class="context-help">设备信息根据浏览器 User-Agent 识别，仅能判断大致设备类型、系统和浏览器，不能准确识别具体个人设备。</p>
                <section class="panel">
                    <?php if ($logPage['logs'] === []): ?>
                        <div class="empty-state compact"><?= admin_icon('logs') ?><h2>暂无查询记录</h2><p>调整筛选条件后再试，或等待用户完成查询。</p></div>
                    <?php else: ?>
                        <div class="desktop-table">
                            <div class="table-wrap">
                                <table>
                                    <thead><tr><th>查询时间</th><th>查询项目</th><th>记录</th><th>IP地址</th><th>设备</th><th>结果</th><th class="action-column">操作</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($logPage['logs'] as $log): ?>
                                        <tr>
                                            <td><?= e($log['created_at']) ?></td>
                                            <td><?= e($log['project_name'] ?? '未知项目') ?></td>
                                            <td><?= e($log['record_id'] ?? '—') ?></td>
                                            <td><?= e($log['ip_plain']) ?></td>
                                            <td><?= e($log['device_type']) ?> · <?= e($log['os_name']) ?></td>
                                            <td><span class="badge badge-<?= (int) $log['is_success'] ? 'success' : 'failed' ?>"><?= (int) $log['is_success'] ? '成功' : '失败' ?></span></td>
                                            <td><a class="btn btn-small btn-secondary" href="<?= e(admin_page_url('logs', array_merge($_GET, ['log_id' => (int) $log['id'], 'modal' => 'log_detail']))) ?>">查看详情</a></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="mobile-cards">
                            <?php foreach ($logPage['logs'] as $log): ?>
                                <article class="mobile-list-card log-card">
                                    <div class="mobile-card-head"><strong><?= e($log['project_name'] ?? '未知项目') ?></strong><span class="badge badge-<?= (int) $log['is_success'] ? 'success' : 'failed' ?>"><?= (int) $log['is_success'] ? '成功' : '失败' ?></span></div>
                                    <dl>
                                        <div><dt>时间</dt><dd><?= e($log['created_at']) ?></dd></div>
                                        <div><dt>IP</dt><dd><?= e($log['ip_plain']) ?></dd></div>
                                        <div><dt>设备</dt><dd><?= e($log['device_type']) ?> · <?= e($log['os_name']) ?></dd></div>
                                    </dl>
                                    <a class="btn btn-secondary btn-block" href="<?= e(admin_page_url('logs', array_merge($_GET, ['log_id' => (int) $log['id'], 'modal' => 'log_detail']))) ?>">查看详情</a>
                                </article>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($logPage['pages'] > 1): ?>
                            <nav class="pagination" aria-label="日志分页">
                                <?php if ($logPage['page'] > 1): $params = $_GET; $params['page'] = $logPage['page'] - 1; ?><a href="<?= e('admin.php?' . http_build_query($params)) ?>">上一页</a><?php endif; ?>
                                <?php for ($page = 1; $page <= $logPage['pages']; $page++): $params = $_GET; $params['page'] = $page; ?><a class="page-number <?= $page === $logPage['page'] ? 'current' : '' ?>" href="<?= e('admin.php?' . http_build_query($params)) ?>"><?= $page ?></a><?php endfor; ?>
                                <span class="mobile-page-status">第 <?= (int) $logPage['page'] ?> / <?= (int) $logPage['pages'] ?> 页</span>
                                <?php if ($logPage['page'] < $logPage['pages']): $params = $_GET; $params['page'] = $logPage['page'] + 1; ?><a href="<?= e('admin.php?' . http_build_query($params)) ?>">下一页</a><?php endif; ?>
                            </nav>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>
