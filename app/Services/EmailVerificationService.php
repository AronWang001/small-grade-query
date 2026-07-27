<?php

declare(strict_types=1);

require_once __DIR__ . '/MailService.php';
require_once __DIR__ . '/SystemSettingsService.php';

final class EmailVerificationService
{
    public const PURPOSE_ADMIN_REGISTRATION = 'admin_registration';
    public const CODE_VALIDITY_MINUTES = 10;
    public const RESEND_INTERVAL_SECONDS = 60;
    public const MAX_ATTEMPTS = 5;
    private static ?string $featureStatusOverrideForTesting = null;

    /**
     * Returns the feature status: disabled, enabled, or unavailable.
     * - disabled: Super admin explicitly turned off email verification.
     * - enabled: Feature is on and all dependencies are healthy.
     * - unavailable: DB error, missing table, bad APP_KEY, misconfigured mail/Turnstile, etc.
     */
    public static function getFeatureStatus(): string
    {
        if (self::$featureStatusOverrideForTesting !== null) {
            return self::$featureStatusOverrideForTesting;
        }
        try {
            $stmt = db()->prepare("SELECT value_text FROM system_settings WHERE setting_key = ?");
            $stmt->execute(['registration_email_verification_enabled']);
            $row = $stmt->fetch();
            $enabled = is_array($row) && ($row['value_text'] ?? '0') === '1';

            $turnstileConfig = SystemSettingsService::turnstileConfig();
            if (!empty($turnstileConfig['secret_decryption_failed'])) {
                return 'unavailable';
            }

            if (!$enabled) {
                return 'disabled';
            }

            // Verify all dependencies are healthy
            if (!self::isRegistrationVerificationReady()) {
                return 'unavailable';
            }

            // Verify the table exists and has required columns
            self::assertTableReady();

            // Verify APP_KEY is valid
            try {
                app_key();
            } catch (Throwable) {
                return 'unavailable';
            }

            return 'enabled';
        } catch (Throwable) {
            return 'unavailable';
        }
    }

    /**
     * Legacy compatibility. Prefer getFeatureStatus().
     */
    public static function isEnabled(): bool
    {
        return self::getFeatureStatus() === 'enabled';
    }

    /**
     * Isolated CLI test seam. Web requests and non-testing environments can
     * never use it to bypass the database-backed feature readiness checks.
     */
    public static function setFeatureStatusOverrideForTesting(?string $status): void
    {
        if (PHP_SAPI !== 'cli' || app_env() !== 'testing') {
            throw new LogicException('Email verification test override is unavailable.');
        }
        if ($status !== null && !in_array($status, ['disabled', 'enabled', 'unavailable'], true)) {
            throw new InvalidArgumentException('Invalid email verification test status.');
        }
        self::$featureStatusOverrideForTesting = $status;
    }

    private static function assertTableReady(): void
    {
        $pdo = db();
        $result = $pdo->query("SHOW TABLES LIKE 'admin_email_verification_codes'");
        if ($result->rowCount() === 0) {
            throw new RuntimeException('admin_email_verification_codes table missing');
        }
        // Verify required columns exist
        $columns = $pdo->query("SHOW COLUMNS FROM admin_email_verification_codes")->fetchAll(PDO::FETCH_COLUMN);
        $required = ['id', 'email_hash', 'email_encrypted', 'code_hash', 'session_hash',
                      'verification_token', 'purpose', 'status', 'attempts', 'max_attempts',
                      'sent_at', 'expires_at', 'consumed_at'];
        foreach ($required as $col) {
            if (!in_array($col, $columns, true)) {
                throw new RuntimeException("Missing required column: $col");
            }
        }
    }

    public static function isRegistrationVerificationReady(): bool
    {
        try {
            $mailConfig = SystemSettingsService::mailConfig();
            $turnstileConfig = SystemSettingsService::turnstileConfig();
            return ($mailConfig['fully_configured'] ?? false)
                && !empty($turnstileConfig['enabled'])
                && !empty($turnstileConfig['site_key_configured'])
                && !empty($turnstileConfig['secret_configured'])
                && !empty($turnstileConfig['allowed_hostnames']);
        } catch (Throwable) {
            return false;
        }
    }

    // ============================================================
    // Rate limiting
    // ============================================================

    public static function checkSendRate(string $email, string $ip, string $sessionHash): void
    {
        $emailHash = self::hashEmail($email);
        $ipHash = hash_hmac('sha256', $ip, search_key());
        $encryptedIp = encrypt_value($ip);

        // Per email: 60s cooldown
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM admin_email_verification_codes WHERE email_hash = ? AND sent_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)"
        );
        $stmt->execute([$emailHash, self::RESEND_INTERVAL_SECONDS]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new PublicSafeException('验证码发送过于频繁，请' . self::RESEND_INTERVAL_SECONDS . '秒后再试。');
        }

        // Per email: 5 per hour
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM admin_email_verification_codes WHERE email_hash = ? AND sent_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
        $stmt->execute([$emailHash]);
        if ((int) $stmt->fetchColumn() >= 5) {
            throw new PublicSafeException('该邮箱验证码发送次数已达上限，请稍后再试。');
        }

        // Per email: 10 per day
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM admin_email_verification_codes WHERE email_hash = ? AND sent_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)"
        );
        $stmt->execute([$emailHash]);
        if ((int) $stmt->fetchColumn() >= 10) {
            throw new PublicSafeException('该邮箱今日验证码发送次数已达上限，请明天再试。');
        }

        // Per IP: 10 per hour
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM admin_email_verification_codes WHERE ip_hash = ? AND sent_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
        $stmt->execute([$ipHash]);
        if ((int) $stmt->fetchColumn() >= 10) {
            throw new PublicSafeException('验证码发送过于频繁，请稍后再试。');
        }

        // Per IP: 30 per day
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM admin_email_verification_codes WHERE ip_hash = ? AND sent_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)"
        );
        $stmt->execute([$ipHash]);
        if ((int) $stmt->fetchColumn() >= 30) {
            throw new PublicSafeException('今日验证码发送次数已达上限，请明天再试。');
        }

        // Per session: 5 per hour
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM admin_email_verification_codes WHERE session_hash = ? AND sent_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
        $stmt->execute([$sessionHash]);
        if ((int) $stmt->fetchColumn() >= 5) {
            throw new PublicSafeException('验证码发送过于频繁，请稍后再试。');
        }
    }

    // ============================================================
    // Create and send code
    // ============================================================

    public static function createCode(string $email, string $ip, string $sessionHash, string $purpose): array
    {
        $status = self::getFeatureStatus();
        if ($status !== 'enabled') {
            throw new PublicSafeException('邮箱验证服务暂不可用，请联系管理员。');
        }

        $mail = new MailService();
        if (!$mail->isConfigured()) {
            throw new PublicSafeException('邮箱验证服务暂不可用，请联系管理员。');
        }

        self::checkSendRate($email, $ip, $sessionHash);

        // Invalidate all previous codes for this email + purpose
        $emailHash = self::hashEmail($email);
        $pdo = db();
        $pdo->prepare(
            "UPDATE admin_email_verification_codes SET status = 'invalidated' WHERE email_hash = ? AND purpose = ? AND status = 'pending'"
        )->execute([$emailHash, $purpose]);

        // Generate 6-digit code using random_int
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $codeHash = self::hashCode($code);
        $verificationToken = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + self::CODE_VALIDITY_MINUTES * 60);

        $ipHash = hash_hmac('sha256', $ip, search_key());
        $encryptedIp = encrypt_value($ip);

        $stmt = $pdo->prepare(
            'INSERT INTO admin_email_verification_codes
             (email_hash, email_encrypted, code_hash, verification_token, session_hash, ip_hash, ip_encrypted, purpose, status, attempts, max_attempts, sent_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, NOW(), ?)'
        );
        $stmt->execute([
            $emailHash,
            encrypt_value($email),
            $codeHash,
            $verificationToken,
            $sessionHash,
            $ipHash,
            $encryptedIp,
            $purpose,
            'pending',
            self::MAX_ATTEMPTS,
            $expiresAt,
        ]);
        $codeId = (int) $pdo->lastInsertId();

        // Send email
        try {
            $sent = $mail->sendVerificationCode($email, $code);
        } catch (Throwable $e) {
            // Mark as invalidated on send failure
            $pdo->prepare(
                "UPDATE admin_email_verification_codes SET status = 'invalidated' WHERE id = ?"
            )->execute([$codeId]);
            PublicErrorHandler::logException($e, [
                'component' => 'email_verification',
                'operation' => 'send_code',
            ]);
            audit_log('admin_email_code_send_failed', 'email_verification', $codeId, false, [
                'email_masked' => AdminValidation::maskEmail($email),
                'error_type' => get_class($e),
            ]);
            throw new PublicSafeException('验证码发送失败，请稍后重试。');
        }

        if (!$sent) {
            $pdo->prepare(
                "UPDATE admin_email_verification_codes SET status = 'invalidated' WHERE id = ?"
            )->execute([$codeId]);
            audit_log('admin_email_code_send_failed', 'email_verification', $codeId, false, [
                'email_masked' => AdminValidation::maskEmail($email),
                'reason' => 'send_returned_false',
            ]);
            throw new PublicSafeException('验证码发送失败，请稍后重试。');
        }

        audit_log('admin_email_code_sent', 'email_verification', $codeId, true, [
            'email_masked' => AdminValidation::maskEmail($email),
            'purpose' => $purpose,
        ]);

        return [
            'verification_token' => $verificationToken,
            'expires_at' => $expiresAt,
            'next_available_at' => time() + self::RESEND_INTERVAL_SECONDS,
        ];
    }

    // ============================================================
    // Verify code within a transaction (must be called inside an
    // existing transaction alongside admin account creation)
    // ============================================================

    /**
     * Verify the code and return the verification row for further processing.
     * This method should be called INSIDE a database transaction that also
     * creates the admin account. It does NOT mark the code as consumed yet;
     * call consumeInTransaction() after successful account creation.
     *
     * Business failures are returned rather than thrown so the caller can
     * commit attempt/status changes before presenting a safe error.
     *
     * @return array{success:bool,reason:string,row:?array,attempts?:int,status?:string}
     */
    public static function verifyForRegistration(
        string $email,
        string $code,
        string $sessionHash,
        string $verificationToken,
        string $purpose
    ): array {
        $status = self::getFeatureStatus();
        if ($status !== 'enabled') {
            throw new PublicSafeException('邮箱验证服务暂不可用，请联系管理员。');
        }
        if (!db()->inTransaction()) {
            throw new RuntimeException('Email verification requires an active transaction.');
        }

        $emailHash = self::hashEmail($email);
        $codeHash = self::hashCode($code);

        // SELECT ... FOR UPDATE: lock the row and read ALL required columns
        $stmt = db()->prepare(
            'SELECT id, email_hash, email_encrypted, code_hash, session_hash,
                    verification_token, purpose, status, attempts, max_attempts,
                    sent_at, expires_at, consumed_at, created_at
             FROM admin_email_verification_codes
             WHERE email_hash = ?
               AND verification_token = ?
               AND purpose = ?
             ORDER BY id DESC
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute([$emailHash, $verificationToken, $purpose]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            audit_log('admin_email_code_failed', 'email_verification', null, false, [
                'email_masked' => AdminValidation::maskEmail($email),
                'reason' => 'no_matching_code',
            ]);
            return ['success' => false, 'reason' => 'no_matching_code', 'row' => null];
        }

        // Check status
        if (($row['status'] ?? '') !== 'pending') {
            audit_log('admin_email_code_failed', 'email_verification', (int) $row['id'], false, [
                'email_masked' => AdminValidation::maskEmail($email),
                'reason' => 'status_not_pending',
                'actual_status' => $row['status'] ?? 'unknown',
            ]);
            return ['success' => false, 'reason' => 'status_not_pending', 'row' => $row];
        }

        // Check not already consumed
        if (($row['consumed_at'] ?? null) !== null) {
            audit_log('admin_email_code_failed', 'email_verification', (int) $row['id'], false, [
                'email_masked' => AdminValidation::maskEmail($email),
                'reason' => 'already_consumed',
            ]);
            return ['success' => false, 'reason' => 'already_consumed', 'row' => $row];
        }

        // Check expiry
        if (strtotime((string) $row['expires_at']) < time()) {
            $expired = db()->prepare(
                "UPDATE admin_email_verification_codes
                 SET status = 'expired'
                 WHERE id = ? AND status = 'pending' AND consumed_at IS NULL"
            );
            $expired->execute([(int) $row['id']]);
            audit_log('admin_email_code_expired', 'email_verification', (int) $row['id'], false, [
                'email_masked' => AdminValidation::maskEmail($email),
            ]);
            return ['success' => false, 'reason' => 'expired', 'row' => $row, 'status' => 'expired'];
        }

        // Check attempts
        if ((int) ($row['attempts'] ?? 0) >= (int) ($row['max_attempts'] ?? self::MAX_ATTEMPTS)) {
            $invalidate = db()->prepare(
                "UPDATE admin_email_verification_codes
                 SET status = 'invalidated'
                 WHERE id = ? AND status = 'pending' AND consumed_at IS NULL"
            );
            $invalidate->execute([(int) $row['id']]);
            audit_log('admin_email_code_failed', 'email_verification', (int) $row['id'], false, [
                'email_masked' => AdminValidation::maskEmail($email),
                'reason' => 'max_attempts_exceeded',
            ]);
            return [
                'success' => false,
                'reason' => 'max_attempts_exceeded',
                'row' => $row,
                'attempts' => (int) $row['attempts'],
                'status' => 'invalidated',
            ];
        }

        // Check session binding
        if (!isset($row['session_hash']) || !hash_equals($sessionHash, (string) $row['session_hash'])) {
            audit_log('admin_email_code_failed', 'email_verification', (int) $row['id'], false, [
                'email_masked' => AdminValidation::maskEmail($email),
                'reason' => 'session_mismatch',
            ]);
            return ['success' => false, 'reason' => 'session_mismatch', 'row' => $row];
        }

        // Check verification_token
        if (!isset($row['verification_token']) || !hash_equals($verificationToken, (string) $row['verification_token'])) {
            audit_log('admin_email_code_failed', 'email_verification', (int) $row['id'], false, [
                'email_masked' => AdminValidation::maskEmail($email),
                'reason' => 'token_mismatch',
            ]);
            return ['success' => false, 'reason' => 'token_mismatch', 'row' => $row];
        }

        // Check code hash
        if (!isset($row['code_hash']) || !hash_equals($codeHash, (string) $row['code_hash'])) {
            $increment = db()->prepare(
                "UPDATE admin_email_verification_codes
                 SET status = CASE
                         WHEN attempts + 1 >= max_attempts THEN 'invalidated'
                         ELSE status
                     END,
                     attempts = attempts + 1
                 WHERE id = ?
                   AND status = 'pending'
                   AND consumed_at IS NULL"
            );
            $increment->execute([(int) $row['id']]);
            if ($increment->rowCount() !== 1) {
                return ['success' => false, 'reason' => 'state_changed', 'row' => $row];
            }

            $current = db()->prepare(
                'SELECT attempts, status
                 FROM admin_email_verification_codes
                 WHERE id = ?
                 FOR UPDATE'
            );
            $current->execute([(int) $row['id']]);
            $currentState = $current->fetch(PDO::FETCH_ASSOC);
            if (!is_array($currentState)) {
                throw new RuntimeException('Email verification state disappeared after update.');
            }

            audit_log('admin_email_code_failed', 'email_verification', (int) $row['id'], false, [
                'email_masked' => AdminValidation::maskEmail($email),
                'reason' => 'wrong_code',
            ]);
            return [
                'success' => false,
                'reason' => 'wrong_code',
                'row' => $row,
                'attempts' => (int) $currentState['attempts'],
                'status' => (string) $currentState['status'],
            ];
        }

        // Check email HMAC
        if (!hash_equals($emailHash, (string) ($row['email_hash'] ?? 'x'))) {
            return ['success' => false, 'reason' => 'email_mismatch', 'row' => $row];
        }

        audit_log('admin_email_code_verified', 'email_verification', (int) $row['id'], true, [
            'email_masked' => AdminValidation::maskEmail($email),
            'purpose' => $purpose,
        ]);

        return ['success' => true, 'reason' => 'verified', 'row' => $row];
    }

    /**
     * Consume the verification code atomically within the current transaction.
     * Must be called AFTER successful admin account creation, within the same transaction.
     *
     * @return bool true if consumed successfully
     */
    public static function consumeInTransaction(array $verificationRow): bool
    {
        $stmt = db()->prepare(
            "UPDATE admin_email_verification_codes
             SET status = 'consumed', consumed_at = NOW()
             WHERE id = ?
               AND status = 'pending'
               AND consumed_at IS NULL"
        );
        $stmt->execute([(int) $verificationRow['id']]);

        if ($stmt->rowCount() !== 1) {
            throw new PublicSafeException('邮箱验证码错误或已失效，请重新获取。');
        }

        audit_log('admin_email_code_consumed', 'email_verification', (int) $verificationRow['id'], true, [
            'purpose' => $verificationRow['purpose'] ?? 'unknown',
        ]);

        return true;
    }

    // ============================================================
    // Legacy methods for backward compatibility
    // ============================================================

    /**
     * @deprecated Use verifyForRegistration() + consumeInTransaction() within a transaction.
     */
    public static function verify(string $email, string $code, string $sessionHash, string $purpose): bool
    {
        try {
            $emailHash = self::hashEmail($email);
            $codeHash = self::hashCode($code);

            // Read ALL required columns
            $stmt = db()->prepare(
                "SELECT id, email_hash, code_hash, session_hash, verification_token,
                        purpose, status, attempts, max_attempts, sent_at, expires_at,
                        consumed_at, created_at
                 FROM admin_email_verification_codes
                 WHERE email_hash = ? AND purpose = ? AND status = 'pending'
                 ORDER BY created_at DESC LIMIT 1"
            );
            $stmt->execute([$emailHash, $purpose]);
            $row = $stmt->fetch();

            if (!is_array($row)) {
                return false;
            }

            // Session check
            if (!isset($row['session_hash']) || !hash_equals($sessionHash, (string) $row['session_hash'])) {
                return false;
            }

            // Expiry
            if (strtotime((string) $row['expires_at']) < time()) {
                db()->prepare("UPDATE admin_email_verification_codes SET status = 'expired' WHERE id = ?")
                    ->execute([(int) $row['id']]);
                return false;
            }

            // Attempts
            if ((int) ($row['attempts'] ?? 0) >= (int) ($row['max_attempts'] ?? self::MAX_ATTEMPTS)) {
                db()->prepare("UPDATE admin_email_verification_codes SET status = 'invalidated' WHERE id = ?")
                    ->execute([(int) $row['id']]);
                return false;
            }

            // Atomic increment attempts
            db()->prepare(
                "UPDATE admin_email_verification_codes SET attempts = attempts + 1 WHERE id = ?"
            )->execute([(int) $row['id']]);

            // Code hash check
            if (!isset($row['code_hash']) || !hash_equals($codeHash, (string) $row['code_hash'])) {
                return false;
            }

            // Mark as verified (NOT consumed - consume() should be called separately)
            db()->prepare(
                "UPDATE admin_email_verification_codes SET status = 'verified' WHERE id = ?"
            )->execute([(int) $row['id']]);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @deprecated Use consumeInTransaction() within the same transaction as account creation.
     */
    public static function consume(string $email, string $sessionHash, string $purpose): bool
    {
        $emailHash = self::hashEmail($email);
        $stmt = db()->prepare(
            "UPDATE admin_email_verification_codes
             SET status = 'consumed', consumed_at = NOW()
             WHERE email_hash = ? AND session_hash = ? AND purpose = ?
               AND status = 'verified' AND consumed_at IS NULL"
        );
        $stmt->execute([$emailHash, $sessionHash, $purpose]);
        $affected = $stmt->rowCount();
        if ($affected > 0) {
            audit_log('admin_email_code_consumed', 'email_verification', null, true, [
                'email_masked' => AdminValidation::maskEmail($email),
                'purpose' => $purpose,
            ]);
        }
        return $affected > 0;
    }

    // ============================================================
    // Helpers
    // ============================================================

    public static function getNextAvailableAt(string $email): ?int
    {
        try {
            $emailHash = self::hashEmail($email);
            $stmt = db()->prepare(
                "SELECT MAX(UNIX_TIMESTAMP(sent_at)) as last_sent
                 FROM admin_email_verification_codes WHERE email_hash = ?"
            );
            $stmt->execute([$emailHash]);
            $lastSent = $stmt->fetchColumn();
            if ($lastSent === false || $lastSent === null) {
                return null;
            }
            $next = (int) $lastSent + self::RESEND_INTERVAL_SECONDS;
            return $next > time() ? $next : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function hashEmail(string $email): string
    {
        return hash_hmac('sha256', strtolower(trim($email)), search_key());
    }

    private static function hashCode(string $code): string
    {
        return hash_hmac('sha256', $code, app_key());
    }
}
