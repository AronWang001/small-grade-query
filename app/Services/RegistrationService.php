<?php

declare(strict_types=1);

require_once __DIR__ . '/../Repositories/AdminRepository.php';
require_once __DIR__ . '/../Repositories/AuthSecurityRepository.php';
require_once __DIR__ . '/EmailVerificationService.php';

final class RegistrationService
{
    public static function assertRateAllowed(string $ip): void
    {
        $rate = AuthSecurityRepository::registrationRateStatus($ip);
        if ($rate['hour_submissions'] >= 5 || $rate['day_created'] >= 3) {
            throw new PublicSafeException('注册操作过于频繁，请稍后再试。');
        }
    }

    public static function register(array $input, string $ip): int
    {
        $username = (string) ($input['username'] ?? '');
        $phoneInput = (string) ($input['phone'] ?? '');
        $emailInput = (string) ($input['email'] ?? '');
        $password = (string) ($input['password'] ?? '');
        $confirmation = (string) ($input['password_confirmation'] ?? '');
        $emailCodeInput = (string) ($input['email_code'] ?? '');
        $emailCodeToken = (string) ($input['email_code_token'] ?? '');

        // Step 0: Check email verification feature status
        $featureStatus = EmailVerificationService::getFeatureStatus();
        if ($featureStatus === 'unavailable') {
            throw new PublicSafeException('安全验证服务暂时不可用，请联系管理员。');
        }
        $emailVerificationEnabled = ($featureStatus === 'enabled');

        // Step 1: Validate all fields
        $fieldErrors = [];

        $usernameError = AdminValidation::validateUsername($username);
        if ($usernameError !== null) {
            $fieldErrors['username'] = $usernameError;
        }

        $phone = AdminValidation::normalizePhone($phoneInput);
        if ($phone === null) {
            $fieldErrors['phone'] = '请输入有效的中国大陆手机号。';
        }

        $email = AdminValidation::normalizeEmail($emailInput);
        if ($email === null) {
            $fieldErrors['email'] = '请输入有效的邮箱地址。';
        }

        if (!hash_equals($password, $confirmation)) {
            $fieldErrors['password_confirmation'] = '两次输入的密码不一致。';
        }

        $passwordError = AdminValidation::validatePassword($password, $username, $phone ?? '');
        if ($passwordError !== null) {
            $fieldErrors['password'] = $passwordError;
        }

        if ($fieldErrors !== []) {
            throw new PublicSafeException('请检查表单中的错误。');
        }

        if ($username === '' || $phone === null || $email === null) {
            throw new PublicSafeException('请完整填写所有必填字段。');
        }

        // Step 2: Prepare hashes
        $usernameNormalized = AdminValidation::normalizeUsername($username);
        $phoneHash = AdminValidation::phoneHash($phone);
        $emailHash = AdminValidation::emailHash($email);
        $sessionHash = hash_hmac('sha256', session_id(), search_key());

        // Step 3: Check uniqueness
        if (AdminRepository::uniqueValuesExist($usernameNormalized, $phoneHash, $emailHash)) {
            throw new PublicSafeException('用户名、手机号或邮箱已被使用，请检查后重新填写。');
        }

        // Step 4: Begin transaction for atomic registration + code consumption
        $ua = request_user_agent();
        $device = parse_user_agent($ua);
        $pdo = db();
        $ownsTransaction = !$pdo->inTransaction();
        if ($emailVerificationEnabled && !$ownsTransaction) {
            throw new RuntimeException('Verified registration cannot be nested in another transaction.');
        }
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            // Step 4a: If email verification is enabled, verify the code within the transaction
            $verificationRow = null;
            if ($emailVerificationEnabled) {
                $verification = EmailVerificationService::verifyForRegistration(
                    $email,
                    $emailCodeInput,
                    $sessionHash,
                    $emailCodeToken,
                    EmailVerificationService::PURPOSE_ADMIN_REGISTRATION
                );
                if (empty($verification['success'])) {
                    if ($ownsTransaction) {
                        $pdo->commit();
                    }
                    throw new PublicSafeException('邮箱验证码错误或已失效，请重新获取。');
                }
                $verificationRow = $verification['row'] ?? null;
                if (!is_array($verificationRow)) {
                    throw new RuntimeException('Email verification succeeded without a locked row.');
                }
            }

            // Step 4b: Create pending admin account
            $adminId = AdminRepository::createPending([
                'username' => $username,
                'username_normalized' => $usernameNormalized,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'phone_encrypted' => encrypt_value($phone),
                'phone_hash' => $phoneHash,
                'phone_masked' => AdminValidation::maskPhone($phone),
                'email_encrypted' => encrypt_value($email),
                'email_hash' => $emailHash,
                'email_masked' => AdminValidation::maskEmail($email),
                'registration_ip_encrypted' => encrypt_value($ip),
                'registration_ip_hash' => AuthSecurityRepository::ipHash($ip),
                'registration_user_agent' => $ua,
                'registration_device_type' => $device['device_type'],
                'registration_os_name' => $device['os_name'],
                'registration_browser_name' => $device['browser_name'],
            ]);

            // Step 4c: Consume the verification code atomically (same transaction)
            if ($verificationRow !== null) {
                EmailVerificationService::consumeInTransaction($verificationRow);
            }

            // Step 4d: Record registration attempt
            AuthSecurityRepository::recordRegistrationAttempt($ip, true);

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (PDOException $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ((string) $exception->getCode() === '23000') {
                throw new PublicSafeException('用户名、手机号或邮箱已被使用，请检查后重新填写。');
            }
            throw $exception;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        // Step 5: Audit log
        audit_log('admin_registered', 'admin_user', $adminId, true, [
            'username' => $username,
            'phone' => AdminValidation::maskPhone($phone),
            'email' => AdminValidation::maskEmail($email),
            'email_verified' => $emailVerificationEnabled,
        ]);

        return $adminId;
    }
}
