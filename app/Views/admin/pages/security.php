<section class="security-grid">
                    <article class="panel account-card">
                        <span class="large-avatar"><?= admin_icon('user') ?></span>
                        <div><h2><?= e($admin['username']) ?></h2><p><?= e($adminRoleLabels[$admin['role']] ?? '管理员') ?></p><small><?= e($admin['phone_masked'] ?: '手机号未设置') ?> · <?= e($admin['email_masked'] ?: '邮箱未设置') ?></small></div>
                        <div class="account-actions"><a class="btn btn-secondary" href="<?= e(admin_page_url('security', ['modal' => 'profile'])) ?>" data-modal-target="#profileModal">修改联系方式</a><a class="btn btn-primary" href="<?= e(admin_page_url('security', ['modal' => 'password'])) ?>" data-modal-target="#passwordModal">修改密码</a></div>
                    </article>
                    <article class="panel security-status">
                        <div class="panel-heading"><div><h2>安全状态</h2><p>当前管理员会话保护</p></div></div>
                        <ul>
                            <li><?= admin_icon('check') ?><span>已启用CSRF和安全Session Cookie</span></li>
                            <li><?= admin_icon('check') ?><span>闲置30分钟自动退出</span></li>
                            <li><?= admin_icon('check') ?><span>登录失败频率限制已启用</span></li>
                        </ul>
                    </article>
                </section>
                <?php if ($isSuperAdmin): ?><details class="panel audit-panel">
                    <summary><span><?= admin_icon('logs') ?><strong>管理员操作日志</strong></span><small>最近50条，点击展开</small></summary>
                    <div class="desktop-table">
                        <div class="table-wrap">
                            <table><thead><tr><th>时间</th><th>管理员</th><th>操作</th><th>对象</th><th>结果</th></tr></thead><tbody>
                            <?php foreach ($auditLogs as $log): ?>
                                <tr><td><?= e($log['created_at']) ?></td><td><?= e($log['username'] ?? '未知') ?></td><td><?= e($log['action']) ?></td><td><?= e(($log['entity_type'] ?? '—') . ' ' . ($log['entity_id'] ?? '')) ?></td><td><span class="badge badge-<?= (int) $log['is_success'] ? 'success' : 'failed' ?>"><?= (int) $log['is_success'] ? '成功' : '失败' ?></span></td></tr>
                            <?php endforeach; ?>
                            </tbody></table>
                        </div>
                    </div>
                    <div class="mobile-cards audit-cards">
                        <?php foreach ($auditLogs as $log): ?><article class="mobile-list-card"><div class="mobile-card-head"><strong><?= e($log['action']) ?></strong><span class="badge badge-<?= (int) $log['is_success'] ? 'success' : 'failed' ?>"><?= (int) $log['is_success'] ? '成功' : '失败' ?></span></div><p><?= e($log['created_at']) ?> · <?= e($log['username'] ?? '未知') ?></p><small><?= e(($log['entity_type'] ?? '—') . ' ' . ($log['entity_id'] ?? '')) ?></small></article><?php endforeach; ?>
                    </div>
                </details><?php endif; ?>
