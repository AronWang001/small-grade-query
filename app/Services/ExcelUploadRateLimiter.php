<?php

declare(strict_types=1);

final class ExcelUploadRateLimiter
{
    private const ADMIN_PER_MINUTE = 5;
    private const ADMIN_PER_HOUR = 30;
    private const IP_PER_HOUR = 50;

    /** @var list<string> */
    private const PURPOSES = ['new_query', 'existing_project_import'];

    /**
     * Record an accepted attempt before any upload file is copied or parsed.
     *
     * Named MySQL locks serialize concurrent checks for the same administrator
     * and IP, preventing parallel requests from bypassing the count limits.
     */
    public static function accept(int $adminId, string $purpose): int
    {
        if ($adminId <= 0 || !in_array($purpose, self::PURPOSES, true)) {
            throw new InvalidArgumentException('Invalid upload rate-limit context.');
        }

        $pdo = db();
        $ipHash = hash_hmac('sha256', client_ip(), search_key());
        $locks = [
            'excel_admin_' . hash('sha256', (string) $adminId),
            'excel_ip_' . $ipHash,
        ];
        sort($locks, SORT_STRING);
        $acquired = [];

        try {
            foreach ($locks as $lock) {
                $stmt = $pdo->prepare('SELECT GET_LOCK(?, 5)');
                $stmt->execute([substr($lock, 0, 64)]);
                if ((int) $stmt->fetchColumn() !== 1) {
                    throw new PublicSafeException('上传请求繁忙，请稍后再试。');
                }
                $acquired[] = substr($lock, 0, 64);
            }

            $adminMinute = self::count(
                $pdo,
                'admin_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)',
                [$adminId]
            );
            if ($adminMinute >= self::ADMIN_PER_MINUTE) {
                if (self::recordRejected($pdo, $adminId, $ipHash, $purpose)) {
                    self::auditRejected($adminId, 'admin_per_minute');
                }
                throw new PublicSafeException('上传过于频繁，请稍后再试。');
            }

            $adminHour = self::count(
                $pdo,
                'admin_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)',
                [$adminId]
            );
            if ($adminHour >= self::ADMIN_PER_HOUR) {
                if (self::recordRejected($pdo, $adminId, $ipHash, $purpose)) {
                    self::auditRejected($adminId, 'admin_per_hour');
                }
                throw new PublicSafeException('上传次数已达上限，请稍后再试。');
            }

            $ipHour = self::count(
                $pdo,
                'ip_hash = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)',
                [$ipHash]
            );
            if ($ipHour >= self::IP_PER_HOUR) {
                if (self::recordRejected($pdo, $adminId, $ipHash, $purpose)) {
                    self::auditRejected($adminId, 'ip_per_hour');
                }
                throw new PublicSafeException('当前网络上传次数过多，请稍后再试。');
            }

            $stmt = $pdo->prepare(
                "INSERT INTO excel_upload_attempts
                    (admin_id, ip_hash, purpose, status, created_at)
                 VALUES (?, ?, ?, 'accepted', NOW())"
            );
            $stmt->execute([$adminId, $ipHash, $purpose]);
            return (int) $pdo->lastInsertId();
        } finally {
            foreach (array_reverse($acquired) as $lock) {
                try {
                    $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                    $stmt->execute([$lock]);
                } catch (Throwable $exception) {
                    error_log('Failed to release Excel upload rate lock: ' . get_class($exception));
                }
            }
        }
    }

    public static function markFailed(int $attemptId, int $adminId): void
    {
        if ($attemptId <= 0 || $adminId <= 0) {
            return;
        }
        $stmt = db()->prepare(
            "UPDATE excel_upload_attempts
             SET status = 'failed'
             WHERE id = ? AND admin_id = ? AND status = 'accepted'"
        );
        $stmt->execute([$attemptId, $adminId]);
    }

    private static function count(PDO $pdo, string $where, array $parameters): int
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM excel_upload_attempts
             WHERE status IN ('accepted', 'failed') AND " . $where
        );
        $stmt->execute($parameters);
        return (int) $stmt->fetchColumn();
    }

    private static function recordRejected(
        PDO $pdo,
        int $adminId,
        string $ipHash,
        string $purpose
    ): bool {
        $existing = $pdo->prepare(
            "SELECT id
             FROM excel_upload_attempts
             WHERE admin_id = ?
               AND ip_hash = ?
               AND purpose = ?
               AND status = 'rejected'
               AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)
             LIMIT 1"
        );
        $existing->execute([$adminId, $ipHash, $purpose]);
        if ($existing->fetchColumn() !== false) {
            return false;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO excel_upload_attempts
                (admin_id, ip_hash, purpose, status, created_at)
             VALUES (?, ?, ?, 'rejected', NOW())"
        );
        $stmt->execute([$adminId, $ipHash, $purpose]);
        return true;
    }

    private static function auditRejected(int $adminId, string $reason): void
    {
        audit_log('excel_upload_rate_limited', 'admin_user', $adminId, false, [
            'reason' => $reason,
        ]);
    }
}
