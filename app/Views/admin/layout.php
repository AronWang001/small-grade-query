<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <title><?= e($pageTitle) ?> - 成绩查询管理系统</title>
    <link rel="icon" href="favicon.png" type="image/png">
    <link rel="stylesheet" href="assets/admin.css?v=<?= (int) filemtime(APP_ROOT . '/assets/admin.css') ?>">
    <?php if ($tab === 'system_settings' && $isSuperAdmin): ?><script src="https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit" defer></script><?php endif; ?>
    <script src="assets/admin.js?v=<?= (int) filemtime(APP_ROOT . '/assets/admin.js') ?>" defer></script>
</head>
<body class="<?= $mustChangePassword ? 'force-password-page' : '' ?>">
<div class="ambient ambient-one"></div>
<div class="ambient ambient-two"></div>
<div id="toastHost" class="toast-host" popover="manual" aria-live="polite">
    <?php if ($flash): ?>
        <div class="toast toast-<?= e($flash['type']) ?>" role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>" aria-live="<?= $flash['type'] === 'error' ? 'assertive' : 'polite' ?>" data-toast data-toast-message="<?= e($flash['message']) ?>">
            <span class="toast-icon"><?= $flash['type'] === 'success' ? admin_icon('check') : admin_icon('info') ?></span>
            <div>
                <strong><?= ['success' => '操作成功', 'error' => '操作失败', 'warning' => '请注意', 'info' => '系统提示'][$flash['type']] ?? '系统提示' ?></strong>
                <p><?= e($flash['message']) ?></p>
            </div>
            <button type="button" data-toast-close aria-label="关闭提示"><?= admin_icon('close') ?></button>
        </div>
    <?php endif; ?>
</div>

<?php if (!$mustChangePassword): ?>
<div class="admin-layout">
    <aside class="sidebar" id="adminSidebar" aria-label="后台主导航">
        <div class="sidebar-brand">
            <span class="brand-mark" aria-hidden="true"><span></span></span>
            <span class="brand-copy">
                <strong>成绩查询</strong>
                <small>管理系统</small>
            </span>
        </div>
        
            <?php
            $navItems = [
                'dashboard' => ['首页概览', 'home'],
                'projects' => ['查询项目', 'projects'],
                'records' => ['数据管理', 'records'],
                'imports' => ['导入导出', 'excel'],
                'logs' => ['查询记录', 'logs'],
            ];
            if ($isSuperAdmin) {
                $navItems['admin_users'] = ['管理员账号', 'user'];
                $navItems['login_security'] = ['登录安全', 'shield'];
                $navItems['system_settings'] = ['系统设置', 'key'];
            }
            ?>
            <a class="nav-item nav-item-primary" href="<?= e(admin_page_url('new_query')) ?>">
                <?= admin_icon('plus') ?>
                <span>新建查询</span>
            </a>
            <?php foreach ($navItems as $key => [$label, $icon]): ?>
                <a class="nav-item <?= $tab === $key ? 'active' : '' ?>"
                   href="<?= e(admin_page_url($key)) ?>"
                   <?= $tab === $key ? 'aria-current="page"' : '' ?>>
                     <?= admin_icon($icon) ?>
                     <span><?= e($label) ?></span>
                     <?php if ($key === 'admin_users' && $pendingAdminCount > 0): ?><b class="nav-count" aria-label="<?= $pendingAdminCount ?>个待审核"><?= $pendingAdminCount > 99 ? '99+' : $pendingAdminCount ?></b><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-footer">
            <button class="sidebar-collapse" id="sidebarCollapse" type="button" aria-label="收起侧边栏" aria-expanded="true">
                <?= admin_icon('collapse') ?>
                <span>收起导航</span>
            </button>
            <small>
                GitHub开源：
                <a href="https://github.com/AronWang001/grade-query-system"
                   target="_blank"
                   rel="noopener noreferrer">AronWang001/grade-query-system</a>
            </small>
        </div>
    </aside>

    <div class="mobile-nav-backdrop" id="mobileNavBackdrop" hidden></div>

    <div class="admin-main">
        <header class="topbar">
            <div class="topbar-left">
                <button class="mobile-menu-button" id="mobileMenuButton" type="button" aria-label="打开导航菜单" aria-controls="adminSidebar" aria-expanded="false">
                    <?= admin_icon('menu') ?>
                </button>
                <div class="page-identity">
                    <div class="mobile-brand">成绩查询管理系统</div>
                    <strong><?= e($pageTitle) ?></strong>
                </div>
            </div>
            <details class="admin-menu" id="adminMenu">
                <summary>
                    <span class="avatar"><?= admin_icon('user') ?></span>
                    <span class="admin-name"><?= e($admin['username']) ?></span>
                    <span class="menu-caret">⌄</span>
                </summary>
                <div class="admin-menu-panel">
                    <a href="<?= e(admin_page_url('security')) ?>"><?= admin_icon('key') ?><span>账号与安全</span></a>
                    <a href="index.php"><?= admin_icon('home') ?><span>返回前台</span></a>
                    <form method="post"
                          action="logout.php"
                          data-confirm-title="退出登录"
                          data-confirm-message="确认结束当前管理员会话吗？未保存的表单内容将会丢失。"
                          data-confirm-label="退出登录"
                          data-confirm-tone="danger">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <button type="submit" class="admin-menu-danger"><?= admin_icon('chevron') ?><span>退出登录</span></button>
                    </form>
                    <p>会话闲置30分钟后自动退出</p>
                </div>
            </details>
        </header>

        <main class="page-content">
            <header class="page-header">
                <div>
                    <h1><?= e($pageTitle) ?></h1>
                    <p><?= e($pageDescription) ?></p>
                </div>
                <div class="page-primary-action">
                    <?php if ($tab === 'dashboard' || $tab === 'projects'): ?>
                        <a class="btn btn-primary" href="<?= e(admin_page_url('new_query')) ?>"><?= admin_icon('plus') ?> 新建查询</a>
                    <?php elseif ($tab === 'projects'): ?>
                        <a class="btn btn-primary" href="<?= e(admin_page_url('new_query')) ?>"><?= admin_icon('plus') ?> 新建查询</a>
                    <?php elseif ($tab === 'records' && $selectedProject): ?>
                        <a class="btn btn-primary" href="<?= e(admin_page_url('records', ['project_id' => $selectedProjectId, 'modal' => 'record_new'])) ?>" data-modal-target="#recordModal"><?= admin_icon('plus') ?> 新增记录</a>
                    <?php elseif ($tab === 'imports' && $selectedProject): ?>
                        <a class="btn btn-primary" href="<?= e(admin_page_url('imports', ['project_id' => $selectedProjectId, 'modal' => 'import'])) ?>" data-modal-target="#importModal"><?= admin_icon('upload') ?> 上传Excel</a>
                    <?php elseif ($tab === 'logs'): ?>
                        <a class="btn btn-secondary" href="<?= e(admin_page_url('logs')) ?>">清空筛选</a>
                    <?php elseif ($tab === 'security'): ?>
                        <a class="btn btn-primary" href="<?= e(admin_page_url('security', ['modal' => 'password'])) ?>" data-modal-target="#passwordModal"><?= admin_icon('key') ?> 修改密码</a>
                    <?php endif; ?>
                </div>
            </header>

            <?php require __DIR__ . '/pages/content.php'; ?>
        </main>
    </div>
</div>
<?php else: ?>
<main class="forced-password-shell">
    <div class="forced-brand"><span class="brand-mark"><span></span></span><div><strong>成绩查询管理系统</strong><small>首次登录安全设置</small></div></div>
    <p>为了保护系统数据，使用其他功能前必须先修改默认密码。</p>
</main>
<?php endif; ?>

<?php require __DIR__ . '/components/dialogs.php'; ?>
</body>
</html>
