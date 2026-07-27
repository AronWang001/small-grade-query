<?php declare(strict_types=1);
// Reusable form field component
// Expects: $name, $label, $type, $attrs (array), $error (optional), $hint (optional)
$type = $type ?? 'text';
$id = $id ?? ('field_' . $name);
$value = $value ?? '';
$required = $required ?? false;
$attrs = $attrs ?? [];
$attrStr = '';
foreach ($attrs as $k => $v) {
    $attrStr .= ' ' . e($k) . '="' . e((string) $v) . '"';
}
?>
<div class="form-group<?= ($error ?? null) !== null ? ' has-error' : '' ?>">
    <label for="<?= e($id) ?>"><?= e($label) ?></label>
    <?php if ($type === 'email-code'): ?>
    <div class="email-code-row">
        <input class="form-control" type="text" id="<?= e($id) ?>" name="<?= e($name) ?>"<?= $required ? ' required' : '' ?> maxlength="6" inputmode="numeric" autocomplete="one-time-code" placeholder="请输入邮箱验证码" value="<?= e($value) ?>"<?= $attrStr ?>>
        <button type="button" class="btn btn-code" id="<?= e($id) ?>_send" data-email-code-trigger data-email-field="<?= e($emailFieldId ?? '') ?>">获取验证码</button>
    </div>
    <?php else: ?>
    <input class="form-control" type="<?= e($type) ?>" id="<?= e($id) ?>" name="<?= e($name) ?>"<?= $required ? ' required' : '' ?> placeholder="<?= e($placeholder ?? '') ?>" value="<?= e($value) ?>"<?= $attrStr ?>>
    <?php endif; ?>
    <?php if (($error ?? null) !== null): ?>
    <span class="field-error"><?= e($error) ?></span>
    <?php elseif (($hint ?? null) !== null): ?>
    <span class="field-hint"><?= e($hint) ?></span>
    <?php endif; ?>
</div>
