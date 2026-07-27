<?php declare(strict_types=1);
?>
<?php if ($error !== null || ($success ?? null) !== null): ?>
<div class="auth-toast <?= $error !== null ? 'auth-toast-error' : 'auth-toast-success' ?>" role="<?= $error !== null ? 'alert' : 'status' ?>" data-auth-toast>
    <strong><?= e($error !== null ? ($toastErrorTitle ?? '操作失败') : ($toastSuccessTitle ?? '操作成功')) ?></strong>
    <span><?= e($error ?? $success) ?></span>
    <button type="button" aria-label="关闭">&times;</button>
</div>
<?php endif; ?>
