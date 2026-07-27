<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

final class SystemSettingsRepository
{
    public static function tableExists(): bool
    {
        try {
            $stmt = db()->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'system_settings'");
            $stmt->execute();
            return (int) $stmt->fetchColumn() === 1;
        } catch (Throwable) {
            return false;
        }
    }

    public static function get(string $key): ?array
    {
        if (!self::tableExists()) {
            return null;
        }
        $stmt = db()->prepare('SELECT * FROM system_settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public static function byPrefix(string $prefix): array
    {
        if (!self::tableExists()) {
            return [];
        }
        $stmt = db()->prepare('SELECT * FROM system_settings WHERE setting_key LIKE ? ORDER BY setting_key');
        $stmt->execute([$prefix . '%']);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[$row['setting_key']] = $row;
        }
        return $rows;
    }

    public static function set(string $key, ?string $valueText, ?string $valueEncrypted, string $type, bool $secret, int $adminId): void
    {
        $stmt = db()->prepare('INSERT INTO system_settings (setting_key, value_text, value_encrypted, value_type, is_secret, updated_by) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE value_text = VALUES(value_text), value_encrypted = VALUES(value_encrypted), value_type = VALUES(value_type), is_secret = VALUES(is_secret), updated_by = VALUES(updated_by), updated_at = NOW()');
        $stmt->execute([$key, $valueText, $valueEncrypted, $type, $secret ? 1 : 0, $adminId]);
    }

    public static function clearSecret(string $key, int $adminId): void
    {
        self::set($key, null, null, 'secret', true, $adminId);
    }
}
