<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/AdminValidation.php';

final class AuthSecurityRepository
{
    public static function ipHash(string $ip): string
    {
        return hash_hmac('sha256', $ip, search_key());
    }

    public static function identifierHash(string $identifier): string
    {
        $phone = AdminValidation::normalizePhone($identifier);
        $normalized = $phone ?? AdminValidation::normalizeUsername($identifier);
        return hash_hmac('sha256', $normalized, search_key());
    }

    public static function blockForIp(string $ip, bool $forUpdate = false): ?array
    {
        $stmt = db()->prepare('SELECT * FROM auth_ip_blocks WHERE ip_hash = ?' . ($forUpdate ? ' FOR UPDATE' : ''));
        $stmt->execute([self::ipHash($ip)]);
        $block = $stmt->fetch();
        if (!is_array($block)) {
            return null;
        }
        try {
            $block['ip_plain'] = decrypt_value((string) $block['ip_encrypted']);
        } catch (Throwable) {
            $block['ip_plain'] = '无法解密';
        }
        return $block;
    }

    public static function isBlocked(string $ip): ?array
    {
        $block = self::blockForIp($ip);
        return is_array($block) && $block['blocked_until'] !== null && strtotime((string) $block['blocked_until']) > time()
            ? $block
            : null;
    }

    public static function recordPasswordFailure(string $ip): array
    {
        $pdo = db();
        $now = time();
        $ipHash = self::ipHash($ip);
        $ipEncrypted = encrypt_value($ip);
        
        // Atomic upsert to prevent race conditions
        $stmt = $pdo->prepare(
            'INSERT INTO auth_ip_blocks (ip_hash, ip_encrypted, reason, failed_count, last_failed_at)
             VALUES (?, ?, ?, 1, NOW())
             ON DUPLICATE KEY UPDATE
                 failed_count = IF(last_failed_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE), failed_count + 1, 1),
                 reason = VALUES(reason),
                 last_failed_at = NOW(),
                 ip_encrypted = VALUES(ip_encrypted)'
        );
        $stmt->execute([$ipHash, $ipEncrypted, 'invalid_credentials']);
        
        $block = self::blockForIp($ip);
        $count = is_array($block) ? (int) $block['failed_count'] : 1;
        $blockId = is_array($block) ? (int) $block['id'] : 0;
        
        $blockedUntil = null;
        if ($count >= 5) {
            $blockedUntil = date('Y-m-d H:i:s', $now + 3600);
            $pdo->prepare('UPDATE auth_ip_blocks SET blocked_at = NOW(), blocked_until = ? WHERE id = ?')
                ->execute([$blockedUntil, $blockId]);
        }
        
        return ['failed_count' => $count, 'blocked_until' => $blockedUntil, 'block_id' => $blockId];
    }
    public static function resetUnblockedFailures(string $ip): void
    {
        db()->prepare('UPDATE auth_ip_blocks SET failed_count = 0, blocked_at = NULL, blocked_until = NULL WHERE ip_hash = ? AND (blocked_until IS NULL OR blocked_until <= NOW())')
            ->execute([self::ipHash($ip)]);
    }

    public static function recordLoginAttempt(string $identifier, string $ip, ?int $adminId, bool $success, string $reason): void
    {
        $ua = request_user_agent();
        $device = parse_user_agent($ua);
        $identifierHash = self::identifierHash($identifier);
        $stmt = db()->prepare('INSERT INTO admin_login_attempts (username_hash, identifier_hash, ip_hash, ip_encrypted, admin_id, is_success, failure_reason, user_agent, device_type, os_name, browser_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$identifierHash, $identifierHash, self::ipHash($ip), encrypt_value($ip), $adminId, $success ? 1 : 0, $reason, $ua, $device['device_type'], $device['os_name'], $device['browser_name']]);
    }

    public static function registrationRateStatus(string $ip): array
    {
        $hash = self::ipHash($ip);
        $hour = db()->prepare('SELECT COUNT(*) FROM admin_registration_attempts WHERE ip_hash = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)');
        $hour->execute([$hash]);
        $day = db()->prepare('SELECT COUNT(*) FROM admin_registration_attempts WHERE ip_hash = ? AND was_created = 1 AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)');
        $day->execute([$hash]);
        return ['hour_submissions' => (int) $hour->fetchColumn(), 'day_created' => (int) $day->fetchColumn()];
    }

    public static function recordRegistrationAttempt(string $ip, bool $created): void
    {
        db()->prepare('INSERT INTO admin_registration_attempts (ip_hash, ip_encrypted, was_created) VALUES (?, ?, ?)')
            ->execute([self::ipHash($ip), encrypt_value($ip), $created ? 1 : 0]);
    }

    public static function activeBlockCount(): int
    {
        return (int) db()->query('SELECT COUNT(*) FROM auth_ip_blocks WHERE blocked_until > NOW()')->fetchColumn();
    }

    public static function listBlocks(bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM auth_ip_blocks' . ($activeOnly ? ' WHERE blocked_until > NOW()' : '') . ' ORDER BY COALESCE(blocked_until, last_failed_at) DESC LIMIT 100';
        $rows = db()->query($sql)->fetchAll();
        foreach ($rows as &$row) {
            try {
                $row['ip_plain'] = decrypt_value((string) $row['ip_encrypted']);
            } catch (Throwable) {
                $row['ip_plain'] = '无法解密';
            }
        }
        unset($row);
        return $rows;
    }

    public static function listAttempts(array $filters, int $page = 1, int $perPage = 30): array
    {
        $page = max(1, $page);
        $where = ['1=1'];
        $params = [];
        if (($filters['result'] ?? '') !== '') {
            $where[] = 'a.is_success = ?';
            $params[] = (int) $filters['result'];
        }
        if (!empty($filters['admin_id'])) {
            $where[] = 'a.admin_id = ?';
            $params[] = (int) $filters['admin_id'];
        }
        if (!empty($filters['reason'])) {
            $where[] = 'a.failure_reason = ?';
            $params[] = (string) $filters['reason'];
        }
        if (!empty($filters['ip']) && filter_var($filters['ip'], FILTER_VALIDATE_IP)) {
            $where[] = 'a.ip_hash = ?';
            $params[] = self::ipHash((string) $filters['ip']);
        }
        if (!empty($filters['start_date'])) {
            $where[] = 'a.created_at >= ?';
            $params[] = (string) $filters['start_date'] . ' 00:00:00';
        }
        if (!empty($filters['end_date'])) {
            $where[] = 'a.created_at <= ?';
            $params[] = (string) $filters['end_date'] . ' 23:59:59';
        }
        $whereSql = implode(' AND ', $where);
        $count = db()->prepare("SELECT COUNT(*) FROM admin_login_attempts a WHERE $whereSql");
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $offset = ($page - 1) * $perPage;
        $stmt = db()->prepare("SELECT a.*, u.username FROM admin_login_attempts a LEFT JOIN admin_users u ON u.id = a.admin_id WHERE $whereSql ORDER BY a.id DESC LIMIT $perPage OFFSET $offset");
        $stmt->execute($params);
        $attempts = $stmt->fetchAll();
        foreach ($attempts as &$attempt) {
            try {
                $attempt['ip_plain'] = decrypt_value($attempt['ip_encrypted'] ?? null);
            } catch (Throwable) {
                $attempt['ip_plain'] = '无法解密';
            }
        }
        unset($attempt);
        return ['attempts' => $attempts, 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $perPage))];
    }

    public static function unblock(int $blockId): bool
    {
        $stmt = db()->prepare('UPDATE auth_ip_blocks SET failed_count = 0, blocked_at = NULL, blocked_until = NULL WHERE id = ?');
        $stmt->execute([$blockId]);
        return $stmt->rowCount() > 0;
    }
}
