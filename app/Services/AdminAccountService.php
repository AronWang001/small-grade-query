<?php

declare(strict_types=1);

require_once __DIR__ . '/../Repositories/AdminRepository.php';
require_once __DIR__ . '/../Support/AuthorizationException.php';

final class AdminAccountService
{
    public static function requireSuper(array $actor, string $attemptedAction): void
    {
        if (($actor['role'] ?? '') === 'super_admin' && ($actor['status'] ?? '') === 'active') {
            return;
        }
        audit_log('admin_unauthorized_access', 'admin_user', (int) ($actor['id'] ?? 0), false, ['attempted_action' => $attemptedAction]);
        throw new AuthorizationException('您没有权限执行此操作。');
    }

    public static function approve(array $actor, int $targetId): void
    {
        self::requireSuper($actor, 'approve_admin_registration');
        $pdo = db();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $target = AdminRepository::findById($targetId, false, true);
            if ($target === null || !in_array($target['status'], ['pending', 'rejected'], true)) {
                throw new InvalidArgumentException('该注册申请当前不能通过审核。');
            }
            $pdo->prepare("UPDATE admin_users SET status='active', role='admin', approved_by=?, approved_at=NOW(), rejected_by=NULL, rejected_at=NULL, rejection_reason=NULL, disabled_by=NULL, disabled_at=NULL WHERE id=?")
                ->execute([(int) $actor['id'], $targetId]);
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        audit_log('admin_registration_approved', 'admin_user', $targetId, true, ['username' => $target['username']]);
    }

    public static function reject(array $actor, int $targetId, string $reason): void
    {
        self::requireSuper($actor, 'reject_admin_registration');
        $reason = trim($reason);
        if (mb_strlen($reason, 'UTF-8') > 300) {
            throw new InvalidArgumentException('拒绝原因不能超过300个字符。');
        }
        $pdo = db();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $target = AdminRepository::findById($targetId, false, true);
            if ($target === null || !in_array($target['status'], ['pending', 'rejected'], true)) {
                throw new InvalidArgumentException('该注册申请当前不能拒绝。');
            }
            $pdo->prepare("UPDATE admin_users SET status='rejected', role='admin', rejected_by=?, rejected_at=NOW(), rejection_reason=?, approved_by=NULL, approved_at=NULL, disabled_by=NULL, disabled_at=NULL WHERE id=?")
                ->execute([(int) $actor['id'], $reason, $targetId]);
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        audit_log('admin_registration_rejected', 'admin_user', $targetId, true, ['reason_length' => mb_strlen($reason, 'UTF-8')]);
    }

    public static function resetPending(array $actor, int $targetId): void
    {
        self::requireSuper($actor, 'reset_admin_registration_pending');
        $pdo = db();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $target = AdminRepository::findById($targetId, false, true);
            if ($target === null || $target['status'] !== 'rejected') {
                throw new InvalidArgumentException('只有已拒绝的申请可以重新设为待审核。');
            }
            $pdo->prepare("UPDATE admin_users SET status='pending', rejected_by=NULL, rejected_at=NULL, rejection_reason=NULL WHERE id=?")->execute([$targetId]);
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        audit_log('admin_registration_reset_pending', 'admin_user', $targetId);
    }

    public static function updateAccount(array $actor, int $targetId, array $input): void
    {
        self::requireSuper($actor, 'update_admin_account');
        $pdo = db();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $target = AdminRepository::findById($targetId, true, true);
            if ($target === null) {
                throw new InvalidArgumentException('管理员账号不存在。');
            }
            $username = (string) ($input['username'] ?? '');
            $sameUsername = AdminValidation::normalizeUsername($username) === (string) $target['username_normalized'];
            $usernameError = AdminValidation::validateUsername($username, $sameUsername);
            if ($usernameError !== null) {
                throw new InvalidArgumentException($usernameError);
            }
            $role = (string) ($input['role'] ?? $target['role']);
            if (!in_array($role, ['super_admin', 'admin'], true)) {
                throw new InvalidArgumentException('管理员角色无效。');
            }
            $phone = trim((string) ($input['phone'] ?? '')) === '' ? (string) ($target['phone_plain'] ?? '') : AdminValidation::normalizePhone((string) $input['phone']);
            $email = trim((string) ($input['email'] ?? '')) === '' ? (string) ($target['email_plain'] ?? '') : AdminValidation::normalizeEmail((string) $input['email']);
            if ($phone === null || ($phone !== '' && AdminValidation::normalizePhone($phone) === null)) {
                throw new InvalidArgumentException('请输入有效的中国大陆手机号。');
            }
            if ($email === null || ($email !== '' && AdminValidation::normalizeEmail($email) === null)) {
                throw new InvalidArgumentException('请输入有效的邮箱地址。');
            }
            $phone = $phone === '' ? '' : (string) AdminValidation::normalizePhone($phone);
            $email = $email === '' ? '' : (string) AdminValidation::normalizeEmail($email);
            $usernameNormalized = AdminValidation::normalizeUsername($username);
            $phoneHash = $phone === '' ? null : AdminValidation::phoneHash($phone);
            $emailHash = $email === '' ? null : AdminValidation::emailHash($email);
            $duplicateSql = 'SELECT id FROM admin_users WHERE id <> ? AND (username_normalized = ?';
            $duplicateParams = [$targetId, $usernameNormalized];
            if ($phoneHash !== null) {
                $duplicateSql .= ' OR phone_hash = ?';
                $duplicateParams[] = $phoneHash;
            }
            if ($emailHash !== null) {
                $duplicateSql .= ' OR email_hash = ?';
                $duplicateParams[] = $emailHash;
            }
            $duplicateSql .= ') LIMIT 1 FOR UPDATE';
            $duplicate = $pdo->prepare($duplicateSql);
            $duplicate->execute($duplicateParams);
            if ($duplicate->fetchColumn() !== false) {
                throw new InvalidArgumentException('用户名、手机号或邮箱已被使用，请检查后重新填写。');
            }

            $usernameChanged = !$sameUsername;
            $roleChanged = $role !== $target['role'];
            if (($usernameChanged && $targetId === (int) $actor['id']) || $roleChanged) {
                self::verifyActorPassword($actor, (string) ($input['current_password'] ?? ''));
            }
            if ($roleChanged && $target['role'] === 'super_admin' && $role === 'admin' && $target['status'] === 'active') {
                self::assertNotLastActiveSuper($targetId);
            }

            $pdo->prepare('UPDATE admin_users SET username=?, username_normalized=?, phone_encrypted=?, phone_hash=?, phone_masked=?, email_encrypted=?, email_hash=?, email_masked=?, role=? WHERE id=?')
                ->execute([
                    $username, $usernameNormalized,
                    $phone === '' ? null : encrypt_value($phone), $phoneHash, $phone === '' ? '未设置' : AdminValidation::maskPhone($phone),
                    $email === '' ? null : encrypt_value($email), $emailHash, $email === '' ? '未设置' : AdminValidation::maskEmail($email),
                    $role, $targetId,
                ]);
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        audit_log('admin_account_updated', 'admin_user', $targetId, true, ['role' => $role]);
        if ($usernameChanged) {
            audit_log('admin_username_changed', 'admin_user', $targetId, true, ['from' => $target['username'], 'to' => $username]);
        }
        if ($phone !== (string) ($target['phone_plain'] ?? '')) {
            audit_log('admin_phone_changed', 'admin_user', $targetId, true, ['from' => $target['phone_masked'] ?? '未设置', 'to' => $phone === '' ? '未设置' : AdminValidation::maskPhone($phone)]);
        }
        if ($email !== (string) ($target['email_plain'] ?? '')) {
            audit_log('admin_email_changed', 'admin_user', $targetId, true, ['from' => $target['email_masked'] ?? '未设置', 'to' => $email === '' ? '未设置' : AdminValidation::maskEmail($email)]);
        }
        if ($roleChanged) {
            audit_log('admin_role_changed', 'admin_user', $targetId, true, ['from' => $target['role'], 'to' => $role]);
        }
    }

    public static function updateOwnContact(array $actor, string $phoneInput, string $emailInput, string $currentPassword): void
    {
        self::verifyActorPassword($actor, $currentPassword);
        $phone = AdminValidation::normalizePhone($phoneInput);
        $email = AdminValidation::normalizeEmail($emailInput);
        if ($phone === null) {
            throw new InvalidArgumentException('请输入有效的中国大陆手机号。');
        }
        if ($email === null) {
            throw new InvalidArgumentException('请输入有效的邮箱地址。');
        }
        if (AdminRepository::uniqueValuesExist((string) $actor['username_normalized'], AdminValidation::phoneHash($phone), AdminValidation::emailHash($email), (int) $actor['id'])) {
            throw new InvalidArgumentException('用户名、手机号或邮箱已被使用，请检查后重新填写。');
        }
        db()->prepare('UPDATE admin_users SET phone_encrypted=?, phone_hash=?, phone_masked=?, email_encrypted=?, email_hash=?, email_masked=? WHERE id=?')
            ->execute([encrypt_value($phone), AdminValidation::phoneHash($phone), AdminValidation::maskPhone($phone), encrypt_value($email), AdminValidation::emailHash($email), AdminValidation::maskEmail($email), (int) $actor['id']]);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        audit_log('admin_phone_changed', 'admin_user', (int) $actor['id'], true, ['to' => AdminValidation::maskPhone($phone)]);
        audit_log('admin_email_changed', 'admin_user', (int) $actor['id'], true, ['to' => AdminValidation::maskEmail($email)]);
    }

    public static function setStatus(array $actor, int $targetId, string $status, string $currentPassword): void
    {
        self::requireSuper($actor, 'change_admin_status');
        if (!in_array($status, ['active', 'disabled'], true)) {
            throw new InvalidArgumentException('账号目标状态无效。');
        }
        self::verifyActorPassword($actor, $currentPassword);
        $pdo = db();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $target = AdminRepository::findById($targetId, false, true);
            if ($target === null || !in_array($target['status'], ['active', 'disabled'], true)) {
                throw new InvalidArgumentException('该管理员账号当前不能执行此操作。');
            }
            if ($status === 'disabled' && $target['role'] === 'super_admin' && $target['status'] === 'active') {
                self::assertNotLastActiveSuper($targetId);
            }
            if ($status === 'disabled') {
                $pdo->prepare("UPDATE admin_users SET status='disabled', disabled_by=?, disabled_at=NOW() WHERE id=?")->execute([(int) $actor['id'], $targetId]);
            } else {
                $pdo->prepare("UPDATE admin_users SET status='active', disabled_by=NULL, disabled_at=NULL WHERE id=?")->execute([$targetId]);
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        audit_log($status === 'disabled' ? 'admin_account_disabled' : 'admin_account_enabled', 'admin_user', $targetId);
    }

    public static function resetPassword(array $actor, int $targetId, string $mode, string $manualPassword, string $currentPassword): ?string
    {
        self::requireSuper($actor, 'reset_admin_password');
        self::verifyActorPassword($actor, $currentPassword);
        if ($targetId === (int) $actor['id']) {
            throw new InvalidArgumentException('请使用“修改自己的密码”功能更新当前账号密码。');
        }
        $pdo = db();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $target = AdminRepository::findById($targetId, true, true);
            if ($target === null) {
                throw new InvalidArgumentException('管理员账号不存在。');
            }
            $generated = $mode === 'generated';
            $password = $generated ? self::generateTemporaryPassword() : $manualPassword;
            $error = AdminValidation::validatePassword($password, (string) $target['username'], (string) ($target['phone_plain'] ?? ''));
            if ($error !== null) {
                throw new InvalidArgumentException($error);
            }
            $pdo->prepare('UPDATE admin_users SET password_hash=?, must_change_password=1, password_changed_at=NULL, failed_attempts=0, locked_until=NULL WHERE id=?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $targetId]);
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        audit_log('admin_password_reset', 'admin_user', $targetId, true, ['generated' => $generated]);
        return $generated ? $password : null;
    }

    public static function deleteRegistration(array $actor, int $targetId, string $currentPassword): void
    {
        self::requireSuper($actor, 'delete_admin_registration');
        self::verifyActorPassword($actor, $currentPassword);
        $pdo = db();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $target = AdminRepository::findById($targetId, false, true);
            if ($target === null || !in_array($target['status'], ['pending', 'rejected'], true) || $target['last_login_at'] !== null || $target['role'] === 'super_admin') {
                throw new InvalidArgumentException('该账号已有历史活动，只能停用，不能物理删除。');
            }
            $audit = $pdo->prepare('SELECT COUNT(*) FROM admin_audit_logs WHERE admin_id = ?');
            $audit->execute([$targetId]);
            if ((int) $audit->fetchColumn() > 0) {
                throw new InvalidArgumentException('该账号已有操作审计记录，不能物理删除。');
            }
            $pdo->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$targetId]);
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        audit_log('admin_registration_deleted', 'admin_user', $targetId, true, ['username' => $target['username']]);
    }

    public static function storeOneTimePassword(string $password, int $actorId): string
    {
        $directory = APP_ROOT . '/storage/admin-secrets';
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('无法创建一次性密码存储目录。');
        }
        $token = bin2hex(random_bytes(32));
        $payload = json_encode(['password' => $password, 'actor_id' => $actorId, 'expires_at' => time() + 180], JSON_UNESCAPED_SLASHES);
        if (!is_string($payload) || file_put_contents($directory . '/' . $token . '.secret', encrypt_value($payload), LOCK_EX) === false) {
            throw new RuntimeException('无法准备一次性临时密码。');
        }
        @chmod($directory . '/' . $token . '.secret', 0660);
        return $token;
    }

    public static function consumeOneTimePassword(string $token, int $actorId): ?string
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $path = APP_ROOT . '/storage/admin-secrets/' . $token . '.secret';
        if (!is_file($path)) {
            return null;
        }
        $encoded = file_get_contents($path);
        @unlink($path);
        if (!is_string($encoded)) {
            return null;
        }
        try {
            $payload = json_decode(decrypt_value($encoded), true);
        } catch (Throwable) {
            return null;
        }
        return is_array($payload) && (int) ($payload['actor_id'] ?? 0) === $actorId && (int) ($payload['expires_at'] ?? 0) >= time()
            ? (string) ($payload['password'] ?? '')
            : null;
    }

    private static function assertNotLastActiveSuper(int $targetId): void
    {
        $activeIds = db()->query("SELECT id FROM admin_users WHERE role='super_admin' AND status='active' FOR UPDATE")->fetchAll(PDO::FETCH_COLUMN);
        if (count($activeIds) <= 1 && in_array($targetId, array_map('intval', $activeIds), true)) {
            throw new InvalidArgumentException('系统必须至少保留一个正常状态的超级管理员。');
        }
    }

    private static function verifyActorPassword(array $actor, string $password): void
    {
        $stmt = db()->prepare('SELECT password_hash FROM admin_users WHERE id = ?');
        $stmt->execute([(int) $actor['id']]);
        $hash = $stmt->fetchColumn();
        if (!is_string($hash) || !password_verify($password, $hash)) {
            throw new InvalidArgumentException('当前超级管理员密码不正确。');
        }
    }

    private static function generateTemporaryPassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $bytes = random_bytes(16);
        $password = 'Aa7';
        for ($i = 0; $i < 13; $i++) {
            $password .= $alphabet[ord($bytes[$i]) % strlen($alphabet)];
        }
        return substr($password, 3, 5) . 'A' . substr($password, 8, 4) . 'a7' . substr($password, 12);
    }
}
