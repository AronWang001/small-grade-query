<?php declare(strict_types=1);

$emailVerificationEnabled = $emailVerificationEnabled ?? false;
$emailVerificationStatus = $emailVerificationStatus ?? ($emailVerificationEnabled ? 'enabled' : 'disabled');
$mailConfig = $mailConfig ?? ['fully_configured' => false, 'enabled' => false, 'host_configured' => false, 'auth_configured' => false, 'password_configured' => false];
$mailStatus = $mailStatus ?? ['fully_configured' => false, 'enabled' => false];
$turnstileReady = $turnstileReady ?? false;
?>
<section class="settings-status-grid">
    <article><span>人机验证</span><strong class="<?= $turnstileConfig['enabled'] ? 'text-success' : 'text-muted' ?>"><?= $turnstileConfig['enabled'] ? '已启用' : '已关闭' ?></strong></article>
    <article><span>登录页面</span><strong><?= $turnstileConfig['enabled'] && $turnstileConfig['login_enabled'] ? '启用' : '关闭' ?></strong></article>
    <article><span>注册页面</span><strong><?= $turnstileConfig['enabled'] && $turnstileConfig['register_enabled'] ? '启用' : '关闭' ?></strong></article>
    <article><span>密钥状态</span><strong><?= !empty($turnstileConfig['secret_decryption_failed']) ? '配置异常' : ($turnstileConfig['site_key_configured'] && $turnstileConfig['secret_configured'] ? '已配置' : '未完整配置') ?></strong></article>
    <article><span>邮箱验证码</span><strong class="<?= $emailVerificationEnabled ? 'text-success' : 'text-muted' ?>"><?= $emailVerificationStatus === 'unavailable' ? '配置不可用' : ($emailVerificationEnabled ? '已启用' : '未启用') ?></strong></article>
    <article><span>邮件服务</span><strong class="<?= $mailStatus['fully_configured'] ? 'text-success' : 'text-muted' ?>"><?= $mailStatus['fully_configured'] ? '配置正常' : '配置不完整' ?></strong></article>
</section>

<?php if (!empty($turnstileConfig['secret_decryption_failed'])): ?>
<div class="alert alert-error" role="alert">
    Turnstile密钥无法使用，请重新保存安全验证配置。系统不会在配置异常时绕过人机验证。
</div>
<?php endif; ?>

<!-- SMTP 邮件配置 -->
<section class="panel settings-panel">
    <div class="panel-heading"><div><h2>SMTP邮件发送配置</h2><p>配置后用于发送邮箱验证码等邮件。数据库设置优先于.env，密码使用APP_KEY加密保存。</p></div></div>
    <form method="post" class="settings-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_mail_settings">

        <label class="toggle-option settings-master-toggle">
            <input type="checkbox" name="mail_enabled" value="1" <?= $mailConfig['enabled'] ? 'checked' : '' ?>>
            <span><strong>启用邮件发送服务</strong><small>关闭后注册邮箱验证码等功能将无法发送邮件。</small></span>
        </label>

        <div class="form-grid form-grid-spaced" style="margin-top:16px;">
            <div class="field"><label for="mail_host">SMTP服务器地址</label><input class="control" id="mail_host" name="mail_host" maxlength="255" value="<?= e($mailConfig['host']) ?>" placeholder="smtp.example.com"></div>
            <div class="field"><label for="mail_port">端口</label><input class="control" type="number" id="mail_port" name="mail_port" min="1" max="65535" value="<?= (int) $mailConfig['port'] ?>"></div>
            <div class="field"><label for="mail_encryption">加密方式</label><select class="control" id="mail_encryption" name="mail_encryption">
                <option value="tls" <?= $mailConfig['encryption'] === 'tls' ? 'selected' : '' ?>>TLS</option>
                <option value="ssl" <?= $mailConfig['encryption'] === 'ssl' ? 'selected' : '' ?>>SSL</option>
                <option value="none" <?= $mailConfig['encryption'] === 'none' ? 'selected' : '' ?>>无</option>
            </select></div>
            <div class="field full"><label for="mail_username">SMTP用户名</label><input class="control" id="mail_username" name="mail_username" maxlength="255" value="<?= e($mailConfig['username']) ?>" placeholder="user@example.com" autocomplete="off"></div>
            <div class="field full"><label for="mail_password">SMTP密码</label><input class="control" type="password" id="mail_password" name="mail_password" maxlength="255" autocomplete="new-password" placeholder="<?= $mailConfig['password_configured'] ? '留空表示不修改现有密码' : '' ?>"><small>当前：<?= $mailConfig['password_configured'] ? '已配置（不会显示明文）' : '未配置' ?></small></div>
            <div class="field"><label for="mail_from_address">发件人邮箱</label><input class="control" type="email" id="mail_from_address" name="mail_from_address" maxlength="254" value="<?= e($mailConfig['from_address']) ?>" placeholder="noreply@example.com"></div>
            <div class="field"><label for="mail_from_name">发件人名称</label><input class="control" id="mail_from_name" name="mail_from_name" maxlength="100" value="<?= e($mailConfig['from_name']) ?>" placeholder="成绩查询系统"></div>
        </div>

        <div class="settings-actions"><button class="btn btn-primary" type="submit">保存邮件配置</button></div>
    </form>
</section>

<!-- 注册邮箱验证码开关 -->
<section class="panel settings-panel">
    <div class="panel-heading"><div><h2>注册邮箱验证码</h2><p>开启后，管理员注册时需要完成人机验证并通过邮箱验证码确认身份。</p></div></div>
    <form method="post" class="settings-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="toggle_email_verification">
        <label class="toggle-option settings-master-toggle">
            <input type="checkbox" name="enabled" value="1" <?= $emailVerificationEnabled ? 'checked' : '' ?> onchange="this.form.submit()" <?= !$turnstileReady && !$emailVerificationEnabled ? 'disabled' : '' ?>>
            <span><strong>启用注册邮箱验证码</strong><small>注册时发送邮箱验证码，发送前需要先完成Cloudflare人机验证。<?= !$turnstileReady && !$emailVerificationEnabled ? '请先完成Cloudflare人机验证和邮件发送配置。' : '' ?></small></span>
        </label>
    </form>
</section>

<!-- Cloudflare Turnstile 配置 -->
<section class="panel settings-panel">
    <div class="panel-heading"><div><h2>Cloudflare人机验证</h2><p>数据库设置优先于.env默认值，Secret Key使用APP_KEY加密保存。</p></div></div>
    <form method="post" id="turnstileSettingsForm" class="settings-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_turnstile_settings" data-settings-action><input type="hidden" name="turnstile_test_token" value="" data-turnstile-test-token>
        <label class="toggle-option settings-master-toggle"><input type="checkbox" name="enabled" value="1" <?= $turnstileConfig['enabled'] ? 'checked' : '' ?> data-turnstile-master><span><strong>启用Cloudflare Turnstile人机验证</strong><small>关闭后页面不会加载组件，CSRF、IP封禁和注册频率限制仍然生效。</small></span></label>
        <div class="settings-dependent" data-turnstile-dependent>
            <div class="toggle-grid"><label class="toggle-option"><input type="checkbox" name="login_enabled" value="1" <?= $turnstileConfig['login_enabled'] ? 'checked' : '' ?>><span><strong>登录页面启用</strong><small>登录前必须通过人机验证</small></span></label><label class="toggle-option"><input type="checkbox" name="register_enabled" value="1" <?= $turnstileConfig['register_enabled'] ? 'checked' : '' ?>><span><strong>注册页面启用</strong><small>提交申请前必须通过人机验证</small></span></label></div>
        </div>
        <div class="form-grid form-grid-spaced">
            <div class="field full"><label for="turnstile_site_key">Site Key</label><input class="control" id="turnstile_site_key" name="site_key" maxlength="255" value="<?= e($turnstileConfig['site_key']) ?>"><small>Site Key用于在登录和注册页面加载Cloudflare Turnstile组件，可以出现在网页前端。</small></div>
            <div class="field full"><label for="turnstile_secret_key">Secret Key</label><input class="control" type="password" id="turnstile_secret_key" name="secret_key" maxlength="255" autocomplete="new-password" placeholder="留空表示不修改现有Secret Key"><small>Secret Key仅用于服务器向Cloudflare验证令牌，不会发送到浏览器。当前：<?= !empty($turnstileConfig['secret_decryption_failed']) ? '无法解密，请重新输入并保存' : ($turnstileConfig['secret_configured'] ? '已配置' : '未配置') ?></small></div>
            <div class="field full"><label for="turnstile_hosts">允许的验证域名</label><textarea class="control" id="turnstile_hosts" name="allowed_hostnames" rows="4" placeholder="example.com&#10;www.example.com"><?= e(implode("
", $turnstileConfig['allowed_hostnames'])) ?></textarea><small>一行一个，不填写协议、路径、端口或通配符。</small><?php if (app_env() === 'production' && array_intersect($turnstileConfig['allowed_hostnames'], ['localhost', '127.0.0.1'])): ?><span class="field-warning">生产环境中包含本地域名，请确认用途。</span><?php endif; ?></div>
            <div class="field"><label for="turnstile_theme">组件主题</label><select class="control" id="turnstile_theme" name="theme"><option value="auto" <?= $turnstileConfig['theme'] === 'auto' ? 'selected' : '' ?>>跟随系统</option><option value="light" <?= $turnstileConfig['theme'] === 'light' ? 'selected' : '' ?>>浅色</option><option value="dark" <?= $turnstileConfig['theme'] === 'dark' ? 'selected' : '' ?>>深色</option></select></div>
            <div class="field"><label for="turnstile_size">组件尺寸</label><select class="control" id="turnstile_size" name="size"><option value="normal" <?= $turnstileConfig['size'] === 'normal' ? 'selected' : '' ?>>普通</option><option value="compact" <?= $turnstileConfig['size'] === 'compact' ? 'selected' : '' ?>>紧凑</option></select></div>
        </div>
        <details class="settings-technical"><summary>配置状态和最近测试</summary><dl class="detail-grid"><div><dt>Site Key</dt><dd><?= $turnstileConfig['site_key_configured'] ? '已配置' : '未配置' ?></dd></div><div><dt>Secret Key</dt><dd><?= !empty($turnstileConfig['secret_decryption_failed']) ? '配置异常，需要重新保存' : ($turnstileConfig['secret_configured'] ? '已配置（不会显示明文）' : '未配置') ?></dd></div><div><dt>最后测试时间</dt><dd><?= e($turnstileConfig['last_tested_at'] ?: '尚未测试') ?></dd></div><div><dt>最近测试结果</dt><dd><?= e($turnstileConfig['last_test_status'] === 'success' ? '成功' : ($turnstileConfig['last_test_status'] === 'failed' ? '失败' : '暂无')) ?></dd></div><div><dt>最后修改时间</dt><dd><?= e($turnstileConfig['updated_at'] ?: '尚未保存到数据库') ?></dd></div><div><dt>配置来源</dt><dd><?= $turnstileConfig['source'] === 'database' ? '数据库设置' : '.env默认值' ?></dd></div></dl></details>
        <div class="settings-actions"><button class="btn btn-secondary" type="button" data-test-turnstile>测试配置</button><button class="btn btn-primary" type="submit">保存设置</button></div>
    </form>
    <?php if ($turnstileConfig['secret_configured']): ?><form method="post" class="settings-danger-action" data-confirm-title="清除Turnstile Secret Key" data-confirm-message="清除后将无法进行人机验证。必须先关闭总开关才能执行。" data-confirm-label="清除Secret Key" data-confirm-tone="danger"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="clear_turnstile_secret"><button class="btn btn-danger btn-small" type="submit">清除Secret Key</button></form><?php endif; ?>
</section>

<dialog class="modal modal-medium" id="turnstileTestModal"><div class="modal-card"><header class="modal-header"><div><h2>测试Turnstile配置</h2><p>测试不会自动保存或启用当前设置。</p></div><button type="button" class="icon-button" data-modal-close aria-label="关闭"><?= admin_icon('close') ?></button></header><div class="modal-body"><div class="turnstile-test-widget" data-turnstile-test-widget></div><div class="turnstile-test-result" data-turnstile-test-result><p>请完成人机验证，系统会自动提交测试。</p></div></div><footer class="modal-footer"><button class="btn btn-ghost" type="button" data-modal-close>关闭</button></footer></div></dialog>
