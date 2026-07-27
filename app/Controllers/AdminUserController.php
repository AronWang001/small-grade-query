<?php

declare(strict_types=1);

require_once __DIR__ . '/../Services/AdminAccountService.php';
require_once __DIR__ . '/../Repositories/AuthSecurityRepository.php';

final class AdminUserController
{
    private const ACTIONS = [
        'reveal_admin_user', 'approve_admin_registration', 'reject_admin_registration',
        'reset_admin_registration_pending', 'save_admin_user', 'set_admin_status',
        'reset_admin_password', 'delete_admin_registration', 'remove_ip_block',
        'save_own_contact',
    ];

    public static function supports(string $action): bool
    {
        return in_array($action, self::ACTIONS, true);
    }

    public static function handle(string $action, array $actor): string
    {
        if ($action === 'save_own_contact') {
            AdminAccountService::updateOwnContact($actor, (string) ($_POST['phone'] ?? ''), (string) ($_POST['email'] ?? ''), (string) ($_POST['current_password'] ?? ''));
            flash('success', '手机号和邮箱已更新。');
            return 'security';
        }

        AdminAccountService::requireSuper($actor, $action);
        $targetId = (int) ($_POST['admin_user_id'] ?? 0);
        return match ($action) {
            'reveal_admin_user' => self::reveal($targetId),
            'approve_admin_registration' => self::approve($actor, $targetId),
            'reject_admin_registration' => self::reject($actor, $targetId),
            'reset_admin_registration_pending' => self::resetPending($actor, $targetId),
            'save_admin_user' => self::save($actor, $targetId),
            'set_admin_status' => self::setStatus($actor, $targetId),
            'reset_admin_password' => self::resetPassword($actor, $targetId),
            'delete_admin_registration' => self::delete($actor, $targetId),
            'remove_ip_block' => self::unblock($actor, (int) ($_POST['block_id'] ?? 0)),
            default => throw new InvalidArgumentException('管理员账号操作无效。'),
        };
    }

    public static function revealAllowed(int $targetId): bool
    {
        $reveal = $_SESSION['admin_user_reveals'][(string) $targetId] ?? null;
        return is_array($reveal) && (int) ($reveal['expires_at'] ?? 0) >= time();
    }

    private static function reveal(int $targetId): string
    {
        if (AdminRepository::findById($targetId) === null) {
            throw new InvalidArgumentException('管理员账号不存在。');
        }
        $_SESSION['admin_user_reveals'] ??= [];
        $_SESSION['admin_user_reveals'][(string) $targetId] = ['expires_at' => time() + 600];
        audit_log('admin_sensitive_contact_viewed', 'admin_user', $targetId);
        return 'admin_users&admin_user_id=' . $targetId . '&modal=admin_detail';
    }

    private static function approve(array $actor, int $targetId): string
    {
        AdminAccountService::approve($actor, $targetId);
        flash('success', '注册申请已通过，该管理员现在可以登录。');
        return 'admin_users&status=pending';
    }

    private static function reject(array $actor, int $targetId): string
    {
        AdminAccountService::reject($actor, $targetId, (string) ($_POST['rejection_reason'] ?? ''));
        flash('success', '注册申请已拒绝。');
        return 'admin_users&status=pending';
    }

    private static function resetPending(array $actor, int $targetId): string
    {
        AdminAccountService::resetPending($actor, $targetId);
        flash('success', '该申请已重新设为待审核。');
        return 'admin_users&status=pending';
    }

    private static function save(array $actor, int $targetId): string
    {
        AdminAccountService::updateAccount($actor, $targetId, $_POST);
        if ($targetId === (int) $actor['id']) {
            $_SESSION['admin_role'] = (string) ($_POST['role'] ?? $actor['role']);
        }
        flash('success', '管理员账号资料已更新。');
        return 'admin_users';
    }

    private static function setStatus(array $actor, int $targetId): string
    {
        $status = (string) ($_POST['status'] ?? '');
        AdminAccountService::setStatus($actor, $targetId, $status, (string) ($_POST['current_password'] ?? ''));
        flash('success', $status === 'disabled' ? '管理员账号已停用。' : '管理员账号已启用。');
        return 'admin_users';
    }

    private static function resetPassword(array $actor, int $targetId): string
    {
        $password = AdminAccountService::resetPassword($actor, $targetId, (string) ($_POST['password_mode'] ?? 'manual'), (string) ($_POST['temporary_password'] ?? ''), (string) ($_POST['current_password'] ?? ''));
        $params = ['admin_user_id' => $targetId];
        if ($password !== null) {
            $params['one_time_password'] = AdminAccountService::storeOneTimePassword($password, (int) $actor['id']);
        }
        flash('success', '临时密码已设置，该管理员下次登录必须修改密码。');
        return 'admin_users&' . http_build_query($params);
    }

    private static function delete(array $actor, int $targetId): string
    {
        AdminAccountService::deleteRegistration($actor, $targetId, (string) ($_POST['current_password'] ?? ''));
        flash('success', '未产生历史活动的注册申请已永久删除。');
        return 'admin_users';
    }

    private static function unblock(array $actor, int $blockId): string
    {
        if (!AuthSecurityRepository::unblock($blockId)) {
            throw new InvalidArgumentException('IP封禁记录不存在或已经解除。');
        }
        audit_log('admin_ip_block_removed', 'auth_ip_block', $blockId);
        flash('success', 'IP封禁已手动解除。');
        return 'login_security';
    }
}
