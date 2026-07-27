<?php

declare(strict_types=1);

require_once __DIR__ . '/../Services/LoginSecurityService.php';
require_once __DIR__ . '/../Services/TurnstileService.php';

final class AuthController
{
    public static function handle(): array
    {
        $state = [
            'error' => null,
            'identifier' => '',
            'turnstile' => SystemSettingsService::publicTurnstileConfig('login'),
        ];
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $state;
        }
        $state['identifier'] = trim((string) ($_POST['login_identifier'] ?? ''));
        if (!verify_csrf($_POST['csrf_token'] ?? null)) {
            $state['error'] = '页面已过期，请刷新后重试。';
            return $state;
        }
        $ip = client_ip();
        if (LoginSecurityService::blocked($ip) !== null) {
            AuthSecurityRepository::recordLoginAttempt($state['identifier'], $ip, null, false, 'ip_blocked');
            audit_log('admin_login_blocked', 'auth_ip_block', null, false);
            $state['error'] = '登录尝试次数过多，请60分钟后再试。';
            return $state;
        }
        $password = (string) ($_POST['password'] ?? '');
        if ($state['identifier'] === '' || $password === '' || mb_strlen($state['identifier'], 'UTF-8') > 100 || strlen($password) > 256) {
            $state['error'] = '用户名、手机号或密码错误。';
            return $state;
        }
        $config = SystemSettingsService::turnstileConfig();
        if (SystemSettingsService::pageEnabled($config, 'login')) {
            $verification = (new TurnstileService($config))->verify((string) ($_POST['cf-turnstile-response'] ?? ''), 'admin_login', $ip);
            if (!$verification['success']) {
                LoginSecurityService::recordTurnstileFailure($state['identifier'], $ip, (string) $verification['reason']);
                $state['error'] = (string) $verification['message'];
                return $state;
            }
        }
        $result = LoginSecurityService::authenticate($state['identifier'], $password, $ip);
        if ($result['success']) {
            redirect('admin.php');
        }
        $state['error'] = (string) $result['message'];
        return $state;
    }
}
