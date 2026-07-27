<?php

declare(strict_types=1);

require_once __DIR__ . '/../Repositories/AdminRepository.php';
require_once __DIR__ . '/../Repositories/AuthSecurityRepository.php';

final class LoginSecurityService
{
    public static function blocked(string $ip): ?array
    {
        return AuthSecurityRepository::isBlocked($ip);
    }

    public static function recordTurnstileFailure(string $identifier, string $ip, string $reason = 'turnstile_failed'): void
    {
        $admin = AdminRepository::findByIdentifier($identifier);
        AuthSecurityRepository::recordLoginAttempt($identifier, $ip, is_array($admin) ? (int) $admin['id'] : null, false, $reason);
        audit_log('turnstile_login_failed', 'admin_user', is_array($admin) ? (int) $admin['id'] : null, false, ['reason' => $reason]);
    }

    public static function authenticate(string $identifier, string $password, string $ip): array
    {
        $block = self::blocked($ip);
        if ($block !== null) {
            AuthSecurityRepository::recordLoginAttempt($identifier, $ip, null, false, 'ip_blocked');
            audit_log('admin_login_blocked', 'auth_ip_block', (int) $block['id'], false);
            return ['success' => false, 'code' => 'ip_blocked', 'message' => '登录尝试次数过多，请60分钟后再试。'];
        }

        $admin = AdminRepository::findByIdentifier($identifier);
        static $dummyHash = null;
        if (!is_string($dummyHash)) {
            $dummyHash = password_hash('invalid-' . bin2hex(random_bytes(18)), PASSWORD_DEFAULT);
        }
        $hash = is_array($admin) ? (string) $admin['password_hash'] : $dummyHash;
        $validPassword = password_verify($password, $hash);
        if (!$validPassword || !is_array($admin)) {
            AuthSecurityRepository::recordLoginAttempt($identifier, $ip, is_array($admin) ? (int) $admin['id'] : null, false, 'invalid_credentials');
            $failure = AuthSecurityRepository::recordPasswordFailure($ip);
            if ($failure['blocked_until'] !== null) {
                audit_log('admin_ip_block_created', 'auth_ip_block', (int) $failure['block_id'], true, ['failed_count' => (int) $failure['failed_count'], 'blocked_until' => $failure['blocked_until']]);
            }
            audit_log('admin_login_failed', 'admin_user', is_array($admin) ? (int) $admin['id'] : null, false, ['reason' => 'invalid_credentials']);
            usleep(random_int(150000, 320000));
            return $failure['blocked_until'] !== null
                ? ['success' => false, 'code' => 'ip_blocked', 'message' => '登录尝试次数过多，请60分钟后再试。']
                : ['success' => false, 'code' => 'invalid_credentials', 'message' => '用户名、手机号或密码错误。'];
        }

        $status = (string) $admin['status'];
        if ($status !== 'active') {
            $messages = [
                'pending' => '您的管理员账号正在等待超级管理员审核。',
                'rejected' => '您的管理员注册申请未通过，请联系超级管理员。',
                'disabled' => '您的管理员账号已停用，请联系超级管理员。',
            ];
            AuthSecurityRepository::recordLoginAttempt($identifier, $ip, (int) $admin['id'], false, $status);
            audit_log('admin_login_failed', 'admin_user', (int) $admin['id'], false, ['reason' => $status]);
            return ['success' => false, 'code' => $status, 'message' => $messages[$status] ?? '该管理员账号当前不可登录。'];
        }

        AuthSecurityRepository::resetUnblockedFailures($ip);
        AuthSecurityRepository::recordLoginAttempt($identifier, $ip, (int) $admin['id'], true, 'success');
        AdminRepository::updateLastLogin((int) $admin['id'], $ip);
        start_secure_session();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['admin_id'] = (int) $admin['id'];
        $_SESSION['admin_role'] = (string) $admin['role'];
        $_SESSION['admin_started_at'] = time();
        $_SESSION['admin_last_activity'] = time();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        audit_log('admin_login_success', 'admin_user', (int) $admin['id']);
        return ['success' => true, 'code' => 'success', 'message' => '', 'admin' => $admin];
    }
}
