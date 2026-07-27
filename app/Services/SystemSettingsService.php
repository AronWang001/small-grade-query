<?php

declare(strict_types=1);

require_once __DIR__ . '/../Repositories/SystemSettingsRepository.php';
require_once __DIR__ . '/../Support/AdminValidation.php';

final class SystemSettingsService
{
    public static function turnstileConfig(): array
    {
        $defaults = [
            'enabled' => self::envBool('TURNSTILE_ENABLED', false),
            'login_enabled' => self::envBool('TURNSTILE_LOGIN_ENABLED', true),
            'register_enabled' => self::envBool('TURNSTILE_REGISTER_ENABLED', true),
            'site_key' => trim(env_value('TURNSTILE_SITE_KEY', '') ?? ''),
            'secret_key' => trim(env_value('TURNSTILE_SECRET_KEY', '') ?? ''),
            'allowed_hostnames' => self::envHostnames(),
            'theme' => self::validChoice(env_value('TURNSTILE_THEME', 'auto') ?? 'auto', ['auto', 'light', 'dark'], 'auto'),
            'size' => self::validChoice(env_value('TURNSTILE_SIZE', 'normal') ?? 'normal', ['normal', 'compact'], 'normal'),
            'last_tested_at' => null,
            'last_test_status' => null,
            'last_test_message' => null,
            'updated_at' => null,
            'updated_by' => null,
            'source' => 'env',
            'secret_decryption_failed' => false,
        ];
        $rows = SystemSettingsRepository::byPrefix('turnstile_');
        if (!isset($rows['turnstile_enabled'])) {
            $defaults['secret_configured'] = $defaults['secret_key'] !== '';
            $defaults['site_key_configured'] = $defaults['site_key'] !== '';
            return $defaults;
        }
        $value = static fn (string $key, ?string $fallback = null): ?string => isset($rows[$key]) ? (string) ($rows[$key]['value_text'] ?? '') : $fallback;
        $config = $defaults;
        $config['source'] = 'database';
        $config['enabled'] = $value('turnstile_enabled', '0') === '1';
        $config['login_enabled'] = $value('turnstile_login_enabled', '1') === '1';
        $config['register_enabled'] = $value('turnstile_register_enabled', '1') === '1';
        $config['site_key'] = trim((string) $value('turnstile_site_key', ''));
        $config['allowed_hostnames'] = json_decode((string) $value('turnstile_allowed_hostnames', '[]'), true);
        if (!is_array($config['allowed_hostnames'])) {
            $config['allowed_hostnames'] = [];
        }
        $config['theme'] = self::validChoice((string) $value('turnstile_theme', 'auto'), ['auto', 'light', 'dark'], 'auto');
        $config['size'] = self::validChoice((string) $value('turnstile_size', 'normal'), ['normal', 'compact'], 'normal');
        $config['last_tested_at'] = $value('turnstile_last_tested_at');
        $config['last_test_status'] = $value('turnstile_last_test_status');
        $config['last_test_message'] = $value('turnstile_last_test_message');
        $config['updated_at'] = $rows['turnstile_enabled']['updated_at'] ?? null;
        $config['updated_by'] = $rows['turnstile_enabled']['updated_by'] ?? null;
        $secretRow = $rows['turnstile_secret_key'] ?? null;
        $config['secret_key'] = '';
        $config['secret_decryption_failed'] = false;
        if (is_array($secretRow) && !empty($secretRow['value_encrypted'])) {
            try {
                $config['secret_key'] = decrypt_value((string) $secretRow['value_encrypted']);
            } catch (Throwable $exception) {
                $config['secret_decryption_failed'] = true;
                PublicErrorHandler::logException($exception, [
                    'component' => 'system_settings',
                    'operation' => 'decrypt',
                    'setting_key' => 'turnstile_secret_key',
                ]);
            }
        }
        $config['secret_configured'] = $config['secret_key'] !== '';
        $config['site_key_configured'] = $config['site_key'] !== '';
        return $config;
    }

    public static function publicTurnstileConfig(string $page): array
    {
        $config = self::turnstileConfig();
        return [
            'enabled' => self::pageEnabled($config, $page),
            'site_key' => (string) $config['site_key'],
            'theme' => (string) $config['theme'],
            'size' => (string) $config['size'],
            'configuration_unavailable' => !empty($config['secret_decryption_failed']),
        ];
    }

    public static function pageEnabled(array $config, string $page): bool
    {
        $child = $page === 'login' ? 'login_enabled' : 'register_enabled';
        return !empty($config['enabled']) && !empty($config[$child]);
    }

    public static function validateInput(array $input, bool $allowExistingSecret = true): array
    {
        $current = self::turnstileConfig();
        $siteKey = trim((string) ($input['site_key'] ?? ''));
        $secret = trim((string) ($input['secret_key'] ?? ''));
        if ($secret === '' && $allowExistingSecret) {
            $secret = (string) $current['secret_key'];
        }
        if ($siteKey !== '' && !preg_match('/^[A-Za-z0-9_-]{10,255}$/', $siteKey)) {
            throw new PublicSafeException('Site Key格式不正确。');
        }
        if ($secret !== '' && (strlen($secret) < 10 || strlen($secret) > 255 || !preg_match('/^[A-Za-z0-9_-]+$/', $secret))) {
            throw new PublicSafeException('Secret Key格式不正确。');
        }
        $hosts = AdminValidation::normalizeHostnames((string) ($input['allowed_hostnames'] ?? ''));
        $enabled = isset($input['enabled']);
        if ($enabled && ($siteKey === '' || $secret === '' || $hosts === [])) {
            throw new PublicSafeException('启用Turnstile前必须配置Site Key、Secret Key和至少一个允许域名。');
        }
        return [
            'enabled' => $enabled,
            'login_enabled' => isset($input['login_enabled']),
            'register_enabled' => isset($input['register_enabled']),
            'site_key' => $siteKey,
            'secret_key' => $secret,
            'secret_was_supplied' => trim((string) ($input['secret_key'] ?? '')) !== '',
            'allowed_hostnames' => $hosts,
            'theme' => self::validChoice((string) ($input['theme'] ?? 'auto'), ['auto', 'light', 'dark'], 'auto'),
            'size' => self::validChoice((string) ($input['size'] ?? 'normal'), ['normal', 'compact'], 'normal'),
        ];
    }

    public static function save(int $adminId, array $input): array
    {
        $before = self::turnstileConfig();
        $config = self::validateInput($input);
        $pdo = db();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            self::setText('turnstile_enabled', $config['enabled'] ? '1' : '0', 'bool', $adminId);
            self::setText('turnstile_login_enabled', $config['login_enabled'] ? '1' : '0', 'bool', $adminId);
            self::setText('turnstile_register_enabled', $config['register_enabled'] ? '1' : '0', 'bool', $adminId);
            self::setText('turnstile_site_key', $config['site_key'], 'string', $adminId);
            self::setText('turnstile_allowed_hostnames', json_encode($config['allowed_hostnames'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]', 'json', $adminId);
            self::setText('turnstile_theme', $config['theme'], 'string', $adminId);
            self::setText('turnstile_size', $config['size'], 'string', $adminId);
            if ($config['secret_was_supplied']) {
                SystemSettingsRepository::set('turnstile_secret_key', null, encrypt_value($config['secret_key']), 'secret', true, $adminId);
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
        audit_log('turnstile_settings_updated', 'system_setting', null, true, [
            'enabled_before' => (bool) $before['enabled'],
            'enabled_after' => $config['enabled'],
            'login_enabled' => $config['login_enabled'],
            'register_enabled' => $config['register_enabled'],
            'allowed_hostnames' => $config['allowed_hostnames'],
            'secret_replaced' => $config['secret_was_supplied'],
        ]);
        if ((bool) $before['enabled'] !== $config['enabled']) {
            audit_log($config['enabled'] ? 'turnstile_enabled' : 'turnstile_disabled', 'system_setting');
        }
        if ($config['secret_was_supplied']) {
            audit_log('turnstile_secret_replaced', 'system_setting');
        }
        return $config;
    }

    public static function clearSecret(int $adminId): void
    {
        $config = self::turnstileConfig();
        if (!empty($config['enabled'])) {
            throw new PublicSafeException('请先关闭Turnstile总开关，再清除Secret Key。');
        }
        SystemSettingsRepository::clearSecret('turnstile_secret_key', $adminId);
        audit_log('turnstile_secret_cleared', 'system_setting');
    }

    public static function storeTestResult(int $adminId, bool $success, string $message): void
    {
        self::setText('turnstile_last_tested_at', date('Y-m-d H:i:s'), 'datetime', $adminId);
        self::setText('turnstile_last_test_status', $success ? 'success' : 'failed', 'string', $adminId);
        self::setText('turnstile_last_test_message', mb_substr($message, 0, 255, 'UTF-8'), 'string', $adminId);
    }

    private static function setText(string $key, string $value, string $type, int $adminId): void
    {
        SystemSettingsRepository::set($key, $value, null, $type, false, $adminId);
    }

    private static function envBool(string $key, bool $default): bool
    {
        $value = env_value($key, $default ? '1' : '0');
        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    private static function envHostnames(): array
    {
        try {
            return AdminValidation::normalizeHostnames(str_replace(',', "\n", env_value('TURNSTILE_ALLOWED_HOSTNAMES', '') ?? ''));
        } catch (Throwable) {
            return [];
        }
    }

    private static function validChoice(string $value, array $allowed, string $fallback): string
    {
        return in_array($value, $allowed, true) ? $value : $fallback;
    }


    public static function mailConfig(): array
    {
        $defaults = [
            "enabled" => self::envBool("MAIL_ENABLED", false),
            "host" => trim(env_value("MAIL_HOST", "") ?? ""),
            "port" => (int) (env_value("MAIL_PORT", "587") ?? "587"),
            "encryption" => trim(env_value("MAIL_ENCRYPTION", "tls") ?? "tls"),
            "username" => trim(env_value("MAIL_USERNAME", "") ?? ""),
            "password" => trim(env_value("MAIL_PASSWORD", "") ?? ""),
            "from_address" => trim(env_value("MAIL_FROM_ADDRESS", "") ?? ""),
            "from_name" => trim(env_value("MAIL_FROM_NAME", "成绩查询系统") ?? "成绩查询系统"),
            "source" => "env",
            "updated_at" => null,
            "updated_by" => null,
            "password_decryption_failed" => false,
        ];

        $rows = SystemSettingsRepository::byPrefix("mail_");
        if (!isset($rows["mail_enabled"])) {
            return $defaults;
        }

        $value = static fn (string $key, ?string $fallback = null): ?string =>
            isset($rows[$key]) ? (string) ($rows[$key]["value_text"] ?? "") : $fallback;

        $config = $defaults;
        $config["source"] = "database";
        $config["enabled"] = $value("mail_enabled", "0") === "1";
        $config["host"] = trim((string) $value("mail_host", ""));
        $config["port"] = (int) ($value("mail_port", "587") ?? "587");
        $config["encryption"] = self::validChoice((string) $value("mail_encryption", "tls"), ["tls", "ssl", "none"], "tls");
        $config["username"] = trim((string) $value("mail_username", ""));
        $config["from_address"] = trim((string) $value("mail_from_address", ""));
        $config["from_name"] = trim((string) $value("mail_from_name", "成绩查询系统"));
        $config["updated_at"] = $rows["mail_enabled"]["updated_at"] ?? null;
        $config["updated_by"] = $rows["mail_enabled"]["updated_by"] ?? null;

        $pwdRow = $rows["mail_password"] ?? null;
        $config["password"] = "";
        $config["password_decryption_failed"] = false;
        if (is_array($pwdRow) && !empty($pwdRow["value_encrypted"])) {
            try {
                $config["password"] = decrypt_value((string) $pwdRow["value_encrypted"]);
            } catch (Throwable $e) {
                $config["password_decryption_failed"] = true;
                PublicErrorHandler::logException($e, [
                    "component" => "system_settings",
                    "operation" => "decrypt",
                    "setting_key" => "mail_password",
                ]);
            }
        }

        $config["password_configured"] = $config["password"] !== "";
        $config["host_configured"] = $config["host"] !== "";
        $config["auth_configured"] = $config["username"] !== "" && $config["password"] !== "";
        $config["from_configured"] = $config["from_address"] !== "";
        $config["fully_configured"] = $config["enabled"]
            && $config["host"] !== ""
            && $config["username"] !== ""
            && $config["password"] !== ""
            && $config["from_address"] !== "";

        return $config;
    }

    public static function saveMailConfig(int $adminId, array $input): array
    {
        $enabled = isset($input["mail_enabled"]);
        $host = trim((string) ($input["mail_host"] ?? ""));
        $port = (int) ($input["mail_port"] ?? 587);
        $encryption = self::validChoice((string) ($input["mail_encryption"] ?? "tls"), ["tls", "ssl", "none"], "tls");
        $username = trim((string) ($input["mail_username"] ?? ""));
        $password = trim((string) ($input["mail_password"] ?? ""));
        $fromAddress = trim((string) ($input["mail_from_address"] ?? ""));
        $fromName = trim((string) ($input["mail_from_name"] ?? "成绩查询系统"));
        $passwordSupplied = $password !== "";

        if (!$passwordSupplied) {
            $current = self::mailConfig();
            $password = (string) $current["password"];
        }

        if ($enabled) {
            if ($host === "") throw new PublicSafeException("请填写SMTP服务器地址。");
            if ($port < 1 || $port > 65535) throw new PublicSafeException("SMTP端口无效。");
            if ($username === "") throw new PublicSafeException("请填写SMTP用户名。");
            if ($password === "") throw new PublicSafeException("请填写SMTP密码。");
            if ($fromAddress === "") throw new PublicSafeException("请填写发件人邮箱地址。");
        }

        $pdo = db();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            self::setText("mail_enabled", $enabled ? "1" : "0", "bool", $adminId);
            self::setText("mail_host", $host, "string", $adminId);
            self::setText("mail_port", (string) $port, "number", $adminId);
            self::setText("mail_encryption", $encryption, "string", $adminId);
            self::setText("mail_username", $username, "string", $adminId);
            self::setText("mail_from_address", $fromAddress, "string", $adminId);
            self::setText("mail_from_name", $fromName, "string", $adminId);
            if ($passwordSupplied) {
                SystemSettingsRepository::set("mail_password", null, encrypt_value($password), "secret", true, $adminId);
            }
            if ($ownsTransaction) $pdo->commit();
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        audit_log("mail_settings_updated", "system_setting", null, true, [
            "enabled" => $enabled,
            "host" => $host !== "" ? "configured" : "empty",
            "password_replaced" => $passwordSupplied,
        ]);

        return self::mailConfig();
    }

    public static function getMailConfigForService(): array
    {
        $config = self::mailConfig();
        return [
            "enabled" => $config["enabled"],
            "host" => $config["host"],
            "port" => $config["port"],
            "encryption" => $config["encryption"],
            "username" => $config["username"],
            "password" => $config["password"],
            "from_address" => $config["from_address"],
            "from_name" => $config["from_name"],
        ];
    }

}
