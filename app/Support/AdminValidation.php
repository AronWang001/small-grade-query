<?php

declare(strict_types=1);

final class AdminValidation
{
    private const RESERVED_USERNAMES = [
        'admin', 'administrator', 'root', 'system', 'superadmin',
        'super_admin', 'support', 'fastertech', 'fasterbyte',
    ];

    public static function normalizeUsername(string $username): string
    {
        $username = trim($username);
        if (class_exists('Normalizer')) {
            $normalized = Normalizer::normalize($username, Normalizer::FORM_KC);
            if (is_string($normalized)) {
                $username = $normalized;
            }
        }
        return mb_strtolower($username, 'UTF-8');
    }

    public static function validateUsername(string $username, bool $allowReserved = false): ?string
    {
        if ($username !== trim($username)) {
            return '用户名不能包含前后空格。';
        }
        if (preg_match('/[\x00-\x1F\x7F]/u', $username) || strip_tags($username) !== $username) {
            return '用户名包含不允许的字符。';
        }
        $length = mb_strlen($username, 'UTF-8');
        if ($length < 4 || $length > 30 || !preg_match('/^[\p{Han}A-Za-z0-9_]+$/u', $username)) {
            return '用户名需为4至30个中文、英文字母、数字或下划线。';
        }
        if (!$allowReserved && in_array(self::normalizeUsername($username), self::RESERVED_USERNAMES, true)) {
            return '该用户名为系统保留名称，请更换。';
        }
        return null;
    }

    public static function normalizePhone(string $phone): ?string
    {
        $phone = preg_replace('/[\s-]+/u', '', trim($phone)) ?? '';
        if (str_starts_with($phone, '+86')) {
            $phone = substr($phone, 3);
        } elseif (str_starts_with($phone, '0086')) {
            $phone = substr($phone, 4);
        }
        return preg_match('/^1[3-9]\d{9}$/', $phone) ? $phone : null;
    }

    public static function normalizeEmail(string $email): ?string
    {
        $email = trim($email);
        if ($email === '' || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }
        $separator = strrpos($email, '@');
        if ($separator === false) {
            return null;
        }
        return substr($email, 0, $separator) . '@' . strtolower(substr($email, $separator + 1));
    }

    public static function validatePassword(string $password, string $username = '', string $phone = ''): ?string
    {
        $length = strlen($password);
        if ($length < 8 || $length > 128) {
            return '密码长度必须为8至128位。';
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            return '密码必须同时包含字母和数字。';
        }
        if (hash_equals('123456', $password)) {
            return '密码不能使用123456。';
        }
        if ($username !== '' && hash_equals(self::normalizeUsername($username), self::normalizeUsername($password))) {
            return '密码不能与用户名完全相同。';
        }
        $normalizedPhone = self::normalizePhone($phone);
        if ($normalizedPhone !== null && hash_equals($normalizedPhone, $password)) {
            return '密码不能与手机号完全相同。';
        }
        return null;
    }

    public static function phoneHash(string $phone): string
    {
        return hash_hmac('sha256', $phone, search_key());
    }

    public static function emailHash(string $email): string
    {
        return hash_hmac('sha256', $email, search_key());
    }

    public static function maskPhone(string $phone): string
    {
        return preg_match('/^(\d{3})\d{4}(\d{4})$/', $phone, $matches)
            ? $matches[1] . '****' . $matches[2]
            : '未设置';
    }

    public static function maskEmail(string $email): string
    {
        $separator = strrpos($email, '@');
        if ($separator === false) {
            return '未设置';
        }
        $local = substr($email, 0, $separator);
        $domain = substr($email, $separator + 1);
        $visible = mb_substr($local, 0, min(3, mb_strlen($local, 'UTF-8')), 'UTF-8');
        return $visible . '***@' . $domain;
    }

    public static function maskIp(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            return $parts[0] . '.' . $parts[1] . '.*.*';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parts = explode(':', $ip);
            return implode(':', array_slice($parts, 0, 3)) . ':****';
        }
        return '未知';
    }

    public static function normalizeHostnames(string|array $input): array
    {
        $lines = is_array($input) ? $input : (preg_split('/\r?\n/', $input) ?: []);
        $hosts = [];
        foreach ($lines as $line) {
            $line = strtolower(trim((string) $line));
            if ($line === '') {
                continue;
            }
            if (!str_contains($line, '://')) {
                $line = 'https://' . $line;
            }
            $host = parse_url($line, PHP_URL_HOST);
            $host = is_string($host) ? rtrim(strtolower($host), '.') : '';
            if ($host === '' || str_contains($host, '*') || (filter_var($host, FILTER_VALIDATE_IP) === false && !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host))) {
                throw new InvalidArgumentException('允许域名中包含无效项目：' . mb_substr((string) $line, 0, 80, 'UTF-8'));
            }
            $hosts[$host] = true;
        }
        return array_keys($hosts);
    }
}
