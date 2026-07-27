<?php

declare(strict_types=1);

require_once __DIR__ . '/AdminValidation.php';

final class InteractiveSetup
{
    /** @return array{host:string,port:int,name:string,user:string,password:string,url:string,web_user:string,admin_username:string,admin_password:string} */
    public static function validate(array $input): array
    {
        $host = trim((string) ($input['host'] ?? ''));
        $port = filter_var(
            $input['port'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 65535]]
        );
        $name = trim((string) ($input['name'] ?? ''));
        $user = trim((string) ($input['user'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $url = trim((string) ($input['url'] ?? ''));
        $webUser = trim((string) ($input['web_user'] ?? ''));
        $adminUsername = trim((string) ($input['admin_username'] ?? ''));
        $adminPassword = (string) ($input['admin_password'] ?? '');

        if (
            $host === ''
            || strlen($host) > 255
            || preg_match('/[\x00-\x20\x7F]/', $host)
        ) {
            throw new InvalidArgumentException('数据库主机格式不正确。');
        }
        if ($port === false) {
            throw new InvalidArgumentException('数据库端口必须为1至65535之间的整数。');
        }
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
            throw new InvalidArgumentException('数据库名称只能包含英文字母、数字和下划线，最长64位。');
        }
        if (
            $user === ''
            || strlen($user) > 128
            || preg_match('/[\x00-\x1F\x7F]/', $user)
        ) {
            throw new InvalidArgumentException('数据库用户名格式不正确。');
        }
        self::assertSingleLine($password, '数据库密码');

        if ($url !== '') {
            if (
                strlen($url) > 2048
                || filter_var($url, FILTER_VALIDATE_URL) === false
                || !in_array((string) parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)
            ) {
                throw new InvalidArgumentException('网站地址必须是有效的HTTP或HTTPS地址。');
            }
            $url = rtrim($url, '/');
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            if (
                $webUser === ''
                || strtolower($webUser) === 'root'
                || !preg_match('/^[a-z_][a-z0-9_-]*[$]?$/i', $webUser)
                || self::resolveGroupId($webUser) === null
            ) {
                throw new InvalidArgumentException(
                    'PHP-FPM运行用户不存在、格式不正确或不安全，不能填写root。'
                );
            }
        } else {
            $webUser = '';
        }

        $usernameError = AdminValidation::validateUsername($adminUsername, true);
        if ($usernameError !== null) {
            throw new InvalidArgumentException($usernameError);
        }
        $passwordError = AdminValidation::validatePassword(
            $adminPassword,
            $adminUsername
        );
        if ($passwordError !== null) {
            throw new InvalidArgumentException($passwordError);
        }
        self::assertSingleLine($adminPassword, '超级管理员密码');

        return [
            'host' => $host,
            'port' => (int) $port,
            'name' => $name,
            'user' => $user,
            'password' => $password,
            'url' => $url,
            'web_user' => $webUser,
            'admin_username' => $adminUsername,
            'admin_password' => $adminPassword,
        ];
    }

    public static function renderEnvironment(array $config, string $appKey): string
    {
        if (!str_starts_with($appKey, 'base64:')) {
            throw new InvalidArgumentException('APP_KEY格式不正确。');
        }

        $values = [
            'APP_ENV' => 'production',
            'APP_DEBUG' => '0',
            'APP_URL' => (string) $config['url'],
            'APP_TIMEZONE' => 'Asia/Shanghai',
            'APP_KEY' => $appKey,
            'DB_HOST' => (string) $config['host'],
            'DB_PORT' => (string) $config['port'],
            'DB_NAME' => (string) $config['name'],
            'DB_USER' => (string) $config['user'],
            'DB_PASSWORD' => (string) $config['password'],
            'MAIL_ENABLED' => '0',
            'MAIL_HOST' => '',
            'MAIL_PORT' => '587',
            'MAIL_ENCRYPTION' => 'tls',
            'MAIL_USERNAME' => '',
            'MAIL_PASSWORD' => '',
            'MAIL_FROM_ADDRESS' => '',
            'MAIL_FROM_NAME' => '成绩查询系统',
            'TURNSTILE_ENABLED' => '0',
            'TURNSTILE_SITE_KEY' => '',
            'TURNSTILE_SECRET_KEY' => '',
            'TURNSTILE_ALLOWED_HOSTNAMES' => '',
            'TRUSTED_PROXIES' => '',
            'TRUST_CLOUDFLARE_IP' => '0',
            'QUERY_LOG_RETENTION_DAYS' => '180',
        ];

        $lines = [];
        foreach ($values as $key => $value) {
            $lines[] = $key . '=' . self::quoteEnvironmentValue($value);
        }
        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    public static function writeEnvironmentAtomically(
        string $target,
        string $contents,
        ?string $webUser = null
    ): void {
        if (is_file($target)) {
            throw new RuntimeException('.env已经存在，安装程序不会覆盖现有配置。');
        }
        $directory = dirname($target);
        $realDirectory = realpath($directory);
        if (!is_string($realDirectory) || !is_dir($realDirectory)) {
            throw new RuntimeException('无法确认配置文件目录。');
        }

        $temporary = $realDirectory . '/.env.setup.' . bin2hex(random_bytes(8));
        $handle = @fopen($temporary, 'xb');
        if (!is_resource($handle)) {
            throw new RuntimeException('无法创建临时配置文件。');
        }
        try {
            if (fwrite($handle, $contents) !== strlen($contents)) {
                throw new RuntimeException('配置文件写入不完整。');
            }
            fflush($handle);
        } finally {
            fclose($handle);
        }
        if (PHP_OS_FAMILY === 'Windows') {
            @chmod($temporary, 0600);
        } else {
            if (strtolower((string) $webUser) === 'root') {
                @unlink($temporary);
                throw new RuntimeException('不能将root设置为PHP-FPM运行用户。');
            }
            $groupId = self::resolveGroupId((string) $webUser);
            if ($groupId === null || !@chgrp($temporary, $groupId)) {
                @unlink($temporary);
                throw new RuntimeException(
                    '无法把.env授权给PHP-FPM运行用户，请使用root或网站运行用户执行安装。'
                );
            }
            if (!@chmod($temporary, 0640)) {
                @unlink($temporary);
                throw new RuntimeException('无法设置.env安全权限。');
            }
        }
        if (!@rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException('无法启用配置文件。');
        }
        @chmod($target, PHP_OS_FAMILY === 'Windows' ? 0600 : 0640);
    }

    public static function detectWebUser(): ?string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return null;
        }

        if (function_exists('shell_exec')) {
            $processes = @shell_exec('ps -eo user=,comm= 2>/dev/null');
            if (is_string($processes)) {
                foreach (preg_split('/\R/', $processes) ?: [] as $line) {
                    $parts = preg_split('/\s+/', trim($line), 2) ?: [];
                    $user = (string) ($parts[0] ?? '');
                    $command = strtolower((string) ($parts[1] ?? ''));
                    if (
                        $user !== ''
                        && $user !== 'root'
                        && str_contains($command, 'php-fpm')
                        && self::resolveGroupId($user) !== null
                    ) {
                        return $user;
                    }
                }
            }
        }

        foreach (['www', 'www-data', 'nginx', 'apache'] as $candidate) {
            if (self::resolveGroupId($candidate) !== null) {
                return $candidate;
            }
        }
        return null;
    }

    public static function prepareApplicationRoot(
        string $applicationRoot,
        ?string $webUser
    ): void {
        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }

        if (strtolower((string) $webUser) === 'root') {
            throw new RuntimeException('不能将root设置为PHP-FPM运行用户。');
        }
        $groupId = self::resolveGroupId((string) $webUser);
        $realRoot = realpath($applicationRoot);
        if (
            $groupId === null
            || !is_string($realRoot)
            || !is_dir($realRoot)
            || is_link($applicationRoot)
        ) {
            throw new RuntimeException('无法确认程序安装目录或PHP-FPM运行用户。');
        }
        if (!@chgrp($realRoot, $groupId)) {
            throw new RuntimeException('无法设置程序安装目录所属组。');
        }

        $permissions = @fileperms($realRoot);
        if (!is_int($permissions)) {
            throw new RuntimeException('无法读取程序安装目录权限。');
        }
        $safeMode = ($permissions & 0777) | 0050;
        if (!@chmod($realRoot, $safeMode)) {
            throw new RuntimeException('无法允许PHP-FPM访问程序安装目录。');
        }
    }

    public static function prepareWritableStorage(
        string $storageRoot,
        ?string $webUser
    ): void {
        if (!is_dir($storageRoot) && !@mkdir($storageRoot, 0770, true)) {
            throw new RuntimeException('无法创建storage目录。');
        }
        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }

        if (strtolower((string) $webUser) === 'root') {
            throw new RuntimeException('不能将root设置为PHP-FPM运行用户。');
        }
        $groupId = self::resolveGroupId((string) $webUser);
        $realRoot = realpath($storageRoot);
        if ($groupId === null || !is_string($realRoot)) {
            throw new RuntimeException('无法配置storage目录权限。');
        }

        $paths = [$realRoot];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $realRoot,
                FilesystemIterator::SKIP_DOTS
            ),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                throw new RuntimeException('storage目录中不允许存在符号链接。');
            }
            $paths[] = $item->getPathname();
        }

        foreach ($paths as $path) {
            if (!@chgrp($path, $groupId)) {
                throw new RuntimeException('无法设置storage目录所属组。');
            }
            $mode = is_dir($path) ? 0770 : 0660;
            if (!@chmod($path, $mode)) {
                throw new RuntimeException('无法设置storage目录权限。');
            }
        }
    }

    public static function quoteEnvironmentValue(string $value): string
    {
        self::assertSingleLine($value, '配置值');
        return '"' . strtr(
            $value,
            [
                '\\' => '\\\\',
                '"' => '\\"',
                "\t" => '\\t',
            ]
        ) . '"';
    }

    private static function assertSingleLine(string $value, string $label): void
    {
        if (str_contains($value, "\0") || str_contains($value, "\r") || str_contains($value, "\n")) {
            throw new InvalidArgumentException($label . '不能包含换行或空字节。');
        }
    }

    private static function resolveGroupId(string $user): ?int
    {
        if ($user === '') {
            return null;
        }
        if (function_exists('posix_getpwnam')) {
            $account = posix_getpwnam($user);
            if (is_array($account) && isset($account['gid'])) {
                return (int) $account['gid'];
            }
        }
        if (function_exists('shell_exec')) {
            $group = @shell_exec(
                'id -g ' . escapeshellarg($user) . ' 2>/dev/null'
            );
            $group = is_string($group) ? trim($group) : '';
            if ($group !== '' && ctype_digit($group)) {
                return (int) $group;
            }
        }
        return null;
    }
}
