<?php

declare(strict_types=1);

require_once __DIR__ . '/../Services/AdminAccountService.php';
require_once __DIR__ . '/../Services/TurnstileService.php';
require_once __DIR__ . '/../Services/EmailVerificationService.php';
require_once __DIR__ . '/../Services/MailService.php';

final class SystemSettingsController
{
    public static function supports(string $action): bool
    {
        return in_array($action, [
            'save_turnstile_settings',
            'clear_turnstile_secret',
            'test_turnstile_settings',
            'toggle_email_verification',
            'save_mail_settings',
        ], true);
    }

    public static function handle(string $action, array $actor): string
    {
        AdminAccountService::requireSuper($actor, $action);

        if ($action === 'toggle_email_verification') {
            return self::toggleEmailVerification($actor);
        }
        if ($action === 'save_mail_settings') {
            return self::saveMailSettings($actor);
        }
        if ($action === 'save_turnstile_settings') {
            SystemSettingsService::save((int) $actor['id'], $_POST);
            flash('success', 'Cloudflare Turnstile设置已保存并立即生效。');
            return 'system_settings';
        }
        if ($action === 'clear_turnstile_secret') {
            SystemSettingsService::clearSecret((int) $actor['id']);
            flash('success', 'Turnstile Secret Key已清除。');
            return 'system_settings';
        }
        throw new PublicSafeException('系统设置操作无效。');
    }

    private static function toggleEmailVerification(array $actor): string
    {
        $enabled = ($_POST['enabled'] ?? '0') === '1';

        if ($enabled) {
            $turnstileConfig = SystemSettingsService::turnstileConfig();
            if (!empty($turnstileConfig['secret_decryption_failed'])) {
                flash('error', 'Turnstile密钥无法使用，请重新保存安全验证配置。');
                return 'system_settings';
            }
            if (empty($turnstileConfig['enabled'])) {
                flash('error', '请先开启Cloudflare人机验证总开关。');
                return 'system_settings';
            }
            if (empty($turnstileConfig['site_key_configured']) || empty($turnstileConfig['secret_configured'])) {
                flash('error', '请先完成Cloudflare人机验证Site Key和Secret Key配置。');
                return 'system_settings';
            }
            if (empty($turnstileConfig['allowed_hostnames'])) {
                flash('error', '请先配置Cloudflare人机验证允许域名。');
                return 'system_settings';
            }
            $mailConfig = SystemSettingsService::mailConfig();
            if (!$mailConfig['fully_configured']) {
                flash('error', '请先完成SMTP邮件发送服务配置。');
                return 'system_settings';
            }
        }

        $pdo = db();
        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, value_text, value_type, is_secret, updated_by) VALUES ('registration_email_verification_enabled', ?, 'bool', 0, ?) ON DUPLICATE KEY UPDATE value_text = VALUES(value_text), updated_by = VALUES(updated_by), updated_at = NOW()");
        $stmt->execute([$enabled ? '1' : '0', (int) $actor['id']]);

        audit_log(
            $enabled ? 'registration_email_verification_enabled' : 'registration_email_verification_disabled',
            'system_setting',
            null,
            true
        );

        flash('success', $enabled ? '注册邮箱验证码已启用。' : '注册邮箱验证码已关闭。');
        return 'system_settings';
    }

    private static function saveMailSettings(array $actor): string
    {
        try {
            SystemSettingsService::saveMailConfig((int) $actor['id'], $_POST);
            flash('success', 'SMTP邮件配置已保存。');
        } catch (Throwable $exception) {
            if (!$exception instanceof PublicSafeException) {
                PublicErrorHandler::logException($exception, [
                    'component' => 'system_settings',
                    'operation' => 'save_mail',
                    'response_type' => 'html',
                ]);
            }
            flash('error', PublicErrorHandler::publicMessage(
                $exception,
                'SMTP邮件配置保存失败，请稍后重试。'
            ));
        }
        return 'system_settings';
    }

    public static function test(array $actor): never
    {
        header('Content-Type: application/json; charset=UTF-8');
        try {
            AdminAccountService::requireSuper($actor, 'test_turnstile_settings');
            $recent = db()->prepare("SELECT COUNT(*) FROM admin_audit_logs WHERE admin_id=? AND action IN ('turnstile_configuration_test_success','turnstile_configuration_test_failed') AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)");
            $recent->execute([(int) $actor['id']]);
            if ((int) $recent->fetchColumn() >= 5) {
                throw new PublicSafeException('配置测试过于频繁，请稍后再试。');
            }
            $config = SystemSettingsService::validateInput($_POST);
            if ($config['site_key'] === '' || $config['secret_key'] === '' || $config['allowed_hostnames'] === []) {
                throw new PublicSafeException('测试配置前请填写Site Key、Secret Key和至少一个允许域名。');
            }
            $result = (new TurnstileService($config))->verify((string) ($_POST['turnstile_test_token'] ?? ''), 'admin_settings_test', client_ip());
            $success = (bool) $result['success'];
            $message = $success ? 'Cloudflare Turnstile配置测试成功。' : '配置测试失败，请检查Site Key、Secret Key和允许域名。';
            SystemSettingsService::storeTestResult((int) $actor['id'], $success, $message);
            audit_log($success ? 'turnstile_configuration_test_success' : 'turnstile_configuration_test_failed', 'system_setting', null, $success, ['reason' => $result['reason']]);
            echo json_encode(['success' => $success, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $exception) {
            audit_log('turnstile_configuration_test_failed', 'system_setting', null, false, ['reason' => 'request_failed']);
            if (!$exception instanceof PublicSafeException) {
                PublicErrorHandler::logException($exception, [
                    'component' => 'system_settings',
                    'operation' => 'test_turnstile',
                    'response_type' => 'json',
                ]);
            }
            echo json_encode([
                'success' => false,
                'message' => PublicErrorHandler::publicMessage(
                    $exception,
                    '配置测试失败，请稍后重试。'
                ),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        exit;
    }
}
