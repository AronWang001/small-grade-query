<?php

declare(strict_types=1);

require_once __DIR__ . '/../Services/RegistrationService.php';
require_once __DIR__ . '/../Services/TurnstileService.php';
require_once __DIR__ . '/../Services/EmailVerificationService.php';

final class RegistrationController
{
    public static function handle(): array
    {
        $emailVerificationEnabled = EmailVerificationService::isEnabled();
        $turnstileReady = $emailVerificationEnabled ? EmailVerificationService::isRegistrationVerificationReady() : true;

        $state = [
            'error' => null,
            'success' => null,
            'input' => ['username' => '', 'phone' => '', 'email' => ''],
            'turnstile' => SystemSettingsService::publicTurnstileConfig('register'),
            'field_errors' => [],
            'email_verification_enabled' => $emailVerificationEnabled,
            'turnstile_ready' => $turnstileReady,
        ];

        // Check for email code send action
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_email_code') {
            self::handleSendCode();
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') === 'send_email_code') {
            return $state;
        }

        foreach (array_keys($state['input']) as $key) {
            $state['input'][$key] = trim((string) ($_POST[$key] ?? ''));
        }

        if (!verify_csrf($_POST['csrf_token'] ?? null)) {
            $state['error'] = '页面已过期，请刷新后重试。';
            return $state;
        }

        $ip = client_ip();
        try {
            RegistrationService::assertRateAllowed($ip);
            $config = SystemSettingsService::turnstileConfig();
            if (SystemSettingsService::pageEnabled($config, 'register')) {
                $verification = (new TurnstileService($config))->verify((string) ($_POST['cf-turnstile-response'] ?? ''), 'admin_register', $ip);
                if (!$verification['success']) {
                    AuthSecurityRepository::recordRegistrationAttempt($ip, false);
                    audit_log('turnstile_register_failed', 'admin_user', null, false, ['reason' => $verification['reason']]);
                    $state['error'] = (string) $verification['message'];
                    return $state;
                }
            }
            RegistrationService::register($_POST, $ip);
            $state['success'] = '注册申请已提交，请等待超级管理员审核。审核通过后可使用用户名或手机号登录。';
            $state['input'] = ['username' => '', 'phone' => '', 'email' => ''];
        } catch (Throwable $exception) {
            try {
                AuthSecurityRepository::recordRegistrationAttempt($ip, false);
            } catch (Throwable $logException) {
                PublicErrorHandler::logException($logException, [
                    'component' => 'registration',
                    'operation' => 'rate_log',
                ]);
            }
            if (!$exception instanceof PublicSafeException) {
                PublicErrorHandler::logException($exception, [
                    'component' => 'registration',
                    'operation' => 'submit',
                ]);
            }
            $state['error'] = PublicErrorHandler::publicMessage(
                $exception,
                '注册申请提交失败，请稍后重试。'
            );
        }
        return $state;
    }

    private static function handleSendCode(): never
    {
        header('Content-Type: application/json; charset=UTF-8');

        try {
            if (!verify_csrf($_POST['csrf_token'] ?? null)) {
                throw new PublicSafeException('页面已过期，请刷新后重试。');
            }

            $featureStatus = EmailVerificationService::getFeatureStatus();
            if ($featureStatus === 'unavailable') {
                throw new PublicSafeException('安全验证服务暂时不可用，请联系管理员。');
            }
            if ($featureStatus !== 'enabled') {
                throw new PublicSafeException('邮箱验证功能未启用。');
            }

            $email = trim((string) ($_POST['email'] ?? ''));
            $normalizedEmail = AdminValidation::normalizeEmail($email);
            if ($normalizedEmail === null) {
                throw new PublicSafeException('请输入有效的邮箱地址。');
            }

            $ip = client_ip();

            // Verify Turnstile
            $config = SystemSettingsService::turnstileConfig();
            if (
                empty($config['enabled'])
                || empty($config['site_key_configured'])
                || empty($config['secret_configured'])
                || !empty($config['secret_decryption_failed'])
            ) {
                throw new PublicSafeException('安全验证服务暂时不可用，请联系管理员。');
            }

            $verification = (new TurnstileService($config))->verify(
                (string) ($_POST['cf-turnstile-response'] ?? ''),
                'admin_email_code',
                $ip
            );
            if (!$verification['success']) {
                audit_log('admin_email_code_requested', 'email_verification', null, false, [
                    'reason' => 'turnstile_failed',
                ]);
                throw new PublicSafeException((string) $verification['message']);
            }

            $sessionHash = hash_hmac('sha256', session_id(), search_key());

            $result = EmailVerificationService::createCode(
                $normalizedEmail,
                $ip,
                $sessionHash,
                EmailVerificationService::PURPOSE_ADMIN_REGISTRATION
            );

            echo json_encode([
                'success' => true,
                'message' => '如邮箱可以正常接收邮件，验证码将发送至该邮箱。',
                'verification_token' => $result['verification_token'],
                'next_available_at' => $result['next_available_at'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            if (!$e instanceof PublicSafeException) {
                PublicErrorHandler::logException($e, [
                    'component' => 'email_verification',
                    'operation' => 'send_code_endpoint',
                    'response_type' => 'json',
                ]);
            }
            echo json_encode([
                'success' => false,
                'message' => PublicErrorHandler::publicMessage(
                    $e,
                    '验证码发送失败，请稍后重试。'
                ),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        exit;
    }
}
