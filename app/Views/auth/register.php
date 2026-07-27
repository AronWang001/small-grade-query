<?php declare(strict_types=1);

$pageTitle = '申请管理员账号';
$pageSubtitle = '注册后需等待超级管理员审核';
$pageClass = 'register-page';
$toastErrorTitle = '提交失败';
$toastSuccessTitle = '申请已提交';

$emailVerificationEnabled = $emailVerificationEnabled ?? false;
$turnstileReady = $turnstileReady ?? false;

ob_start();
?>
<?php if ($success !== null): ?>
    <div class="registration-success">
        <strong>申请已进入审核队列</strong>
        <p><?= e($success) ?></p>
        <a class="btn" href="login.php">返回登录</a>
    </div>
<?php else: ?>
<form method="post" action="register.php" autocomplete="on" class="auth-form registration-form" <?= $turnstile['enabled'] ? 'data-turnstile-form data-turnstile-required="true" data-turnstile-pending-message="请先完成人机验证，再提交注册申请。" data-turnstile-success-message="人机验证已完成，可以提交注册申请。"' : '' ?>>
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="email_code_token" id="email_code_token" value="">

    <div class="form-group<?= ($fieldErrors['username'] ?? null) !== null ? ' has-error' : '' ?>">
        <label for="register_username">用户名</label>
        <input class="form-control" type="text" id="register_username" name="username" required minlength="4" maxlength="30" pattern="[\u4e00-\u9fa5A-Za-z0-9_]+" placeholder="请输入用户名" value="<?= e($input['username']) ?>" autocomplete="username" autofocus>
        <?php if (($fieldErrors['username'] ?? null) !== null): ?>
        <span class="field-error"><?= e($fieldErrors['username']) ?></span>
        <?php endif; ?>
    </div>

    <div class="form-group<?= ($fieldErrors['phone'] ?? null) !== null ? ' has-error' : '' ?>">
        <label for="register_phone">手机号</label>
        <input class="form-control" type="tel" id="register_phone" name="phone" required maxlength="24" inputmode="tel" placeholder="请输入手机号" value="<?= e($input['phone']) ?>" autocomplete="tel">
        <?php if (($fieldErrors['phone'] ?? null) !== null): ?>
        <span class="field-error"><?= e($fieldErrors['phone']) ?></span>
        <?php endif; ?>
    </div>

    <div class="form-group<?= ($fieldErrors['email'] ?? null) !== null ? ' has-error' : '' ?>">
        <label for="register_email">邮箱</label>
        <input class="form-control" type="email" id="register_email" name="email" required maxlength="254" placeholder="请输入邮箱地址" value="<?= e($input['email']) ?>" autocomplete="email" data-email-input>
        <?php if (($fieldErrors['email'] ?? null) !== null): ?>
        <span class="field-error"><?= e($fieldErrors['email']) ?></span>
        <?php endif; ?>
    </div>

    <?php if ($emailVerificationEnabled): ?>
    <div class="form-group<?= ($fieldErrors['email_code'] ?? null) !== null ? ' has-error' : '' ?>" id="email_code_group">
        <label for="register_email_code">邮箱验证码</label>
        <div class="email-code-row">
            <input class="form-control code-input" type="text" id="register_email_code" name="email_code" maxlength="6" inputmode="numeric" autocomplete="one-time-code" placeholder="请输入邮箱验证码" value="">
            <button type="button" class="btn btn-code" id="email_code_send_btn" data-email-code-trigger data-email-field="register_email" aria-label="获取邮箱验证码">获取验证码</button>
        </div>
        <?php if (($fieldErrors['email_code'] ?? null) !== null): ?>
        <span class="field-error"><?= e($fieldErrors['email_code']) ?></span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="form-group<?= ($fieldErrors['password'] ?? null) !== null ? ' has-error' : '' ?>">
        <label for="register_password">密码</label>
        <input class="form-control" type="password" id="register_password" name="password" required minlength="8" maxlength="128" placeholder="请输入至少8位密码" autocomplete="new-password">
        <?php if (($fieldErrors['password'] ?? null) !== null): ?>
        <span class="field-error"><?= e($fieldErrors['password']) ?></span>
        <?php endif; ?>
    </div>

    <div class="form-group<?= ($fieldErrors['password_confirmation'] ?? null) !== null ? ' has-error' : '' ?>">
        <label for="register_password_confirmation">确认密码</label>
        <input class="form-control" type="password" id="register_password_confirmation" name="password_confirmation" required minlength="8" maxlength="128" placeholder="请再次输入密码" autocomplete="new-password">
        <?php if (($fieldErrors['password_confirmation'] ?? null) !== null): ?>
        <span class="field-error"><?= e($fieldErrors['password_confirmation']) ?></span>
        <?php endif; ?>
    </div>

    <?php if ($turnstile['enabled']): ?>
    <div class="turnstile-shell">
        <div data-turnstile-widget data-site-key="<?= e($turnstile['site_key']) ?>" data-theme="<?= e($turnstile['theme']) ?>" data-size="<?= e($turnstile['size']) ?>" data-action="admin_register"></div>
        <p data-turnstile-error hidden>人机验证加载失败，请检查网络后刷新页面。</p>
        <p class="turnstile-status" data-turnstile-status aria-live="polite" tabindex="-1">请先完成人机验证，再提交注册申请。</p>
    </div>
    <?php endif; ?>

    <button class="btn<?= $turnstile['enabled'] ? ' is-turnstile-locked' : '' ?>" type="submit" data-auth-submit <?= $turnstile['enabled'] ? 'disabled aria-disabled="true"' : '' ?>><span>提交注册申请</span></button>

    <p class="auth-switch">已有账号？<a href="login.php">返回登录</a></p>
</form>

<?php if ($turnstile['enabled'] && $emailVerificationEnabled): ?>
<dialog class="modal modal-medium" id="turnstileEmailDialog">
    <div class="modal-card">
        <header class="modal-header">
            <div><h2>完成安全验证</h2><p>验证通过后将向该邮箱发送验证码</p></div>
            <button type="button" class="icon-button" data-modal-close aria-label="关闭">&times;</button>
        </header>
        <div class="modal-body">
            <div class="turnstile-dialog-widget" id="turnstileEmailWidget" data-site-key="<?= e($turnstile['site_key']) ?>" data-theme="<?= e($turnstile['theme']) ?>" data-size="normal" data-action="admin_email_code"></div>
            <div id="turnstileEmailStatus" style="display:none;"><p>正在验证...</p></div>
        </div>
        <footer class="modal-footer">
            <button class="btn btn-ghost" type="button" data-modal-close>关闭</button>
        </footer>
    </div>
</dialog>
<?php endif; ?>
<?php endif; ?>
<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
