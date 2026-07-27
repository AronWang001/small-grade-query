<?php declare(strict_types=1);

$pageTitle = '管理员登录';
$pageSubtitle = '使用用户名或手机号登录';
$pageClass = 'login-page';
$toastErrorTitle = '登录失败';

ob_start();
?>
<form method="post" action="login.php" autocomplete="on" class="auth-form" <?= $turnstile['enabled'] ? 'data-turnstile-form data-turnstile-required="true" data-turnstile-pending-message="请先完成人机验证，再登录。" data-turnstile-success-message="人机验证已完成，可以登录。"' : '' ?>>
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

    <div class="form-group">
        <label for="login_identifier">用户名或手机号</label>
        <input class="form-control" type="text" id="login_identifier" name="login_identifier" required autocomplete="username" maxlength="100" placeholder="请输入用户名或手机号" value="<?= e($identifier) ?>" autofocus>
    </div>

    <div class="form-group">
        <label for="password">密码</label>
        <input class="form-control" type="password" id="password" name="password" required autocomplete="current-password" maxlength="256" placeholder="请输入密码">
    </div>

    <?php if ($turnstile['enabled']): ?>
    <div class="turnstile-shell">
        <div data-turnstile-widget data-site-key="<?= e($turnstile['site_key']) ?>" data-theme="<?= e($turnstile['theme']) ?>" data-size="<?= e($turnstile['size']) ?>" data-action="admin_login"></div>
        <p data-turnstile-error hidden>人机验证加载失败，请检查网络后刷新页面。</p>
        <p class="turnstile-status" data-turnstile-status aria-live="polite" tabindex="-1">请先完成人机验证，再登录。</p>
    </div>
    <?php endif; ?>

    <button class="btn<?= $turnstile['enabled'] ? ' is-turnstile-locked' : '' ?>" type="submit" data-auth-submit <?= $turnstile['enabled'] ? 'disabled aria-disabled="true"' : '' ?>><span>登录</span></button>

    <p class="auth-switch">还没有管理员账号？<a href="register.php">申请注册</a></p>
</form>
<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
