<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/AdminValidation.php';

final class AdminRepository
{
    public static function findById(int $id, bool $decryptSensitive = false, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT * FROM admin_users WHERE id = ?' . ($forUpdate ? ' FOR UPDATE' : '');
        $stmt = db()->prepare($sql);
        $stmt->execute([$id]);
        $admin = $stmt->fetch();
        if (!is_array($admin)) {
            return null;
        }
        if ($decryptSensitive) {
            foreach (['phone_encrypted' => 'phone_plain', 'email_encrypted' => 'email_plain', 'registration_ip_encrypted' => 'registration_ip_plain', 'last_login_ip' => 'last_login_ip_plain'] as $column => $key) {
                try {
                    $admin[$key] = decrypt_value($admin[$column] ?? null);
                } catch (Throwable) {
                    $admin[$key] = '无法解密';
                }
            }
        }
        return $admin;
    }

    public static function findByIdentifier(string $identifier): ?array
    {
        $phone = AdminValidation::normalizePhone($identifier);
        if ($phone !== null) {
            $stmt = db()->prepare('SELECT * FROM admin_users WHERE phone_hash = ? LIMIT 1');
            $stmt->execute([AdminValidation::phoneHash($phone)]);
        } else {
            $stmt = db()->prepare('SELECT * FROM admin_users WHERE username_normalized = ? LIMIT 1');
            $stmt->execute([AdminValidation::normalizeUsername($identifier)]);
        }
        $admin = $stmt->fetch();
        return is_array($admin) ? $admin : null;
    }

    public static function uniqueValuesExist(string $usernameNormalized, string $phoneHash, string $emailHash, ?int $excludeId = null): bool
    {
        $sql = 'SELECT id FROM admin_users WHERE (username_normalized = ? OR phone_hash = ? OR email_hash = ?)';
        $params = [$usernameNormalized, $phoneHash, $emailHash];
        if ($excludeId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeId;
        }
        $stmt = db()->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        return $stmt->fetchColumn() !== false;
    }

    public static function createPending(array $data): int
    {
        $stmt = db()->prepare('INSERT INTO admin_users (
            username, username_normalized, password_hash, role, status,
            phone_encrypted, phone_hash, phone_masked, email_encrypted, email_hash, email_masked,
            must_change_password, registration_ip_encrypted, registration_ip_hash,
            registration_user_agent, registration_device_type, registration_os_name, registration_browser_name
        ) VALUES (?, ?, ?, \'admin\', \'pending\', ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $data['username'], $data['username_normalized'], $data['password_hash'],
            $data['phone_encrypted'], $data['phone_hash'], $data['phone_masked'],
            $data['email_encrypted'], $data['email_hash'], $data['email_masked'],
            $data['registration_ip_encrypted'], $data['registration_ip_hash'],
            $data['registration_user_agent'], $data['registration_device_type'],
            $data['registration_os_name'], $data['registration_browser_name'],
        ]);
        return (int) db()->lastInsertId();
    }

    public static function counts(): array
    {
        $rows = db()->query('SELECT status, COUNT(*) AS total FROM admin_users GROUP BY status')->fetchAll();
        $counts = ['pending' => 0, 'active' => 0, 'rejected' => 0, 'disabled' => 0];
        foreach ($rows as $row) {
            if (isset($counts[$row['status']])) {
                $counts[$row['status']] = (int) $row['total'];
            }
        }
        return $counts;
    }

    public static function pendingCount(): int
    {
        return (int) db()->query("SELECT COUNT(*) FROM admin_users WHERE status = 'pending'")->fetchColumn();
    }

    public static function list(array $filters, int $page = 1, int $perPage = 20): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $where = ['1=1'];
        $params = [];
        if (!empty($filters['status']) && in_array($filters['status'], ['pending', 'active', 'rejected', 'disabled'], true)) {
            $where[] = 'status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['role']) && in_array($filters['role'], ['super_admin', 'admin'], true)) {
            $where[] = 'role = ?';
            $params[] = $filters['role'];
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $conditions = ['username LIKE ?'];
            $searchParams = ['%' . $search . '%'];
            $phone = AdminValidation::normalizePhone($search);
            if ($phone !== null) {
                $conditions[] = 'phone_hash = ?';
                $searchParams[] = AdminValidation::phoneHash($phone);
            }
            $email = AdminValidation::normalizeEmail($search);
            if ($email !== null) {
                $conditions[] = 'email_hash = ?';
                $searchParams[] = AdminValidation::emailHash($email);
            }
            $where[] = '(' . implode(' OR ', $conditions) . ')';
            array_push($params, ...$searchParams);
        }
        $whereSql = implode(' AND ', $where);
        $count = db()->prepare('SELECT COUNT(*) FROM admin_users WHERE ' . $whereSql);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $offset = ($page - 1) * $perPage;
        $stmt = db()->prepare("SELECT id, username, role, status, phone_masked, email_masked, must_change_password, last_login_at, created_at, approved_at, rejected_at, disabled_at FROM admin_users WHERE $whereSql ORDER BY FIELD(status, 'pending', 'active', 'disabled', 'rejected'), id DESC LIMIT $perPage OFFSET $offset");
        $stmt->execute($params);
        return [
            'admins' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    public static function updateLastLogin(int $adminId, string $ip): void
    {
        db()->prepare('UPDATE admin_users SET failed_attempts = 0, locked_until = NULL, last_login_at = NOW(), last_login_ip = ?, last_login_ip_hash = ? WHERE id = ?')
            ->execute([encrypt_value($ip), hash_hmac('sha256', $ip, search_key()), $adminId]);
    }

    public static function activeSuperCount(bool $forUpdate = false): int
    {
        $sql = "SELECT id FROM admin_users WHERE role = 'super_admin' AND status = 'active'" . ($forUpdate ? ' FOR UPDATE' : '');
        return count(db()->query($sql)->fetchAll());
    }
}
