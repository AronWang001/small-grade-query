<?php

declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';

// Fail closed before reading environment configuration. If .env is unreadable,
// PHP must not expose its absolute path or a bootstrap stack trace.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once APP_ROOT . '/vendor/autoload.php';
require_once __DIR__ . '/Support/AdminValidation.php';
require_once __DIR__ . '/Exceptions/PublicSafeException.php';
require_once __DIR__ . '/Support/PublicErrorHandler.php';

function load_environment(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    if (!is_readable($path)) {
        throw new RuntimeException('系统配置文件不可读。');
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        throw new RuntimeException('无法读取系统配置文件。');
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$name, $value] = array_map('trim', explode('=', $line, 2));
        if ($name === '') {
            continue;
        }
        if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
            $value = substr($value, 1, -1);
            $value = preg_replace_callback(
                '/\\\\(["\\\\nt])/u',
                static fn (array $match): string => match ($match[1]) {
                    '"' => '"',
                    '\\' => '\\',
                    'n' => "\n",
                    't' => "\t",
                },
                $value
            ) ?? $value;
        } elseif (strlen($value) >= 2 && $value[0] === "'" && str_ends_with($value, "'")) {
            $value = substr($value, 1, -1);
        }
        $_ENV[$name] = $value;
    }
}

try {
    load_environment(APP_ROOT . '/.env');
} catch (Throwable) {
    error_log('[grade-query] environment_configuration_unavailable');
    if (PHP_SAPI !== 'cli') {
        if (!headers_sent()) {
            http_response_code(503);
            header('Content-Type: text/html; charset=UTF-8');
        }
        echo '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><title>系统暂不可用</title><body><p>系统配置暂不可用，请联系管理员。</p></body></html>';
        exit;
    }
    throw new RuntimeException('系统配置文件不可用，请检查.env权限。');
}

function env_value(string $name, ?string $default = null): ?string
{
    if (array_key_exists($name, $_ENV)) {
        return (string) $_ENV[$name];
    }
    $value = getenv($name);
    return $value === false ? $default : (string) $value;
}

function app_env(): string
{
    return env_value('APP_ENV', 'production') ?? 'production';
}

date_default_timezone_set(env_value('APP_TIMEZONE', 'Asia/Shanghai') ?? 'Asia/Shanghai');

$isProduction = app_env() === 'production';
$debugEnabled = !$isProduction
    && in_array(
        strtolower((string) (env_value('APP_DEBUG', '0') ?? '0')),
        ['1', 'true', 'yes', 'on'],
        true
    );
ini_set('display_errors', $debugEnabled ? '1' : '0');
ini_set('display_startup_errors', $debugEnabled ? '1' : '0');
ini_set('log_errors', '1');
if (!is_dir(APP_ROOT . '/storage/logs')) {
    @mkdir(APP_ROOT . '/storage/logs', 0770, true);
}
ini_set('error_log', APP_ROOT . '/storage/logs/php-error.log');
error_reporting(E_ALL);

if (PHP_SAPI !== 'cli') {
    set_exception_handler(static function (Throwable $exception): void {
        PublicErrorHandler::logException($exception, [
            'component' => 'global_exception_handler',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? '',
        ]);
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=UTF-8');
        }
        echo '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><title>系统错误</title><body><p>系统暂时无法处理请求，请稍后重试。</p></body></html>';
    });
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = env_value('DB_HOST');
    $port = env_value('DB_PORT', '3306');
    $name = env_value('DB_NAME');
    $user = env_value('DB_USER');
    $password = env_value('DB_PASSWORD');
    if ($host === null || $name === null || $user === null || $password === null || $name === '' || $user === '') {
        throw new RuntimeException('数据库配置不完整，请联系管理员。');
    }

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, (int) $port, $name);
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ]);
    $pdo->exec("SET time_zone = '+08:00'");
    return $pdo;
}

function app_key(): string
{
    static $key = null;
    if (is_string($key)) {
        return $key;
    }
    $configured = env_value('APP_KEY', '') ?? '';
    if (str_starts_with($configured, 'base64:')) {
        $decoded = base64_decode(substr($configured, 7), true);
        $configured = $decoded === false ? '' : $decoded;
    }
    if (strlen($configured) < SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        throw new RuntimeException('APP_KEY 配置无效，请联系管理员。');
    }
$keyBytes = substr($configured, 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    // Reject weak/predictable keys (all-zero or all-same-byte)
    $firstByte = $keyBytes[0];
    $allSame = true;
    $allZero = true;
    for ($i = 0; $i < strlen($keyBytes); $i++) {
        if ($keyBytes[$i] !== $firstByte) { $allSame = false; }
        if ($keyBytes[$i] !== "\x00") { $allZero = false; }
    }
    if ($allZero || $allSame) {
        throw new RuntimeException("APP_KEY 为弱密钥，请使用 tools/generate_app_key.php 生成安全密钥。");
    }
    $key = $keyBytes;
    return $key;
}

function search_key(): string
{
    static $key = null;
    if (!is_string($key)) {
        $key = hash_hmac('sha256', 'grade-query-search-key-v1', app_key(), true);
    }
    return $key;
}

function encrypt_value(string $plainText): string
{
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = sodium_crypto_secretbox($plainText, $nonce, app_key());
    return base64_encode($nonce . $cipher);
}

function decrypt_value(?string $encoded): string
{
    if ($encoded === null || $encoded === '') {
        return '';
    }
    $binary = base64_decode($encoded, true);
    if ($binary === false || strlen($binary) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        throw new RuntimeException('敏感数据格式无效。');
    }
    $nonce = substr($binary, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = substr($binary, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain = sodium_crypto_secretbox_open($cipher, $nonce, app_key());
    if ($plain === false) {
        throw new RuntimeException('敏感数据解密失败。');
    }
    return $plain;
}

function normalize_value(string $value, string $fieldType = 'text'): string
{
    $value = trim($value);
    if ($fieldType === 'number') {
        if ($value === '' || !is_numeric($value)) {
            return $value;
        }
        $formatted = number_format((float) $value, 10, '.', '');
        return rtrim(rtrim($formatted, '0'), '.');
    }
    if ($fieldType === 'date') {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value ? $value : '';
    }
    $collapsed = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return mb_strtolower($collapsed, 'UTF-8');
}

function value_search_hash(string $value, string $fieldType = 'text'): string
{
    return hash_hmac('sha256', normalize_value($value, $fieldType), search_key());
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mask_sensitive(string $value): string
{
    $length = mb_strlen($value, 'UTF-8');
    if ($length <= 4) {
        return str_repeat('*', max(1, $length));
    }
    $prefixLength = $length >= 11 ? 6 : 2;
    $suffixLength = $length >= 8 ? 4 : 2;
    return mb_substr($value, 0, $prefixLength, 'UTF-8')
        . str_repeat('*', max(4, $length - $prefixLength - $suffixLength))
        . mb_substr($value, -$suffixLength, null, 'UTF-8');
}

/**
 * Check if an IP address matches a CIDR range.
 */
function cidr_match(string $ip, string $cidr): bool
{
    if (strpos($cidr, '/') === false) {
        return $ip === $cidr;
    }
    list($subnet, $bits) = explode('/', $cidr, 2);
    $bits = (int)$bits;
    if ($bits < 0 || $bits > 128) {
        return false;
    }
    $ipBin = inet_pton($ip);
    $subnetBin = inet_pton($subnet);
    if ($ipBin === false || $subnetBin === false) {
        return false;
    }
    $ipLen = strlen($ipBin);
    $subnetLen = strlen($subnetBin);
    if ($ipLen !== $subnetLen) {
        return false;
    }
    $fullBytes = intdiv($bits, 8);
    $remainBits = $bits % 8;
    for ($i = 0; $i < $fullBytes; $i++) {
        if ($ipBin[$i] !== $subnetBin[$i]) {
            return false;
        }
    }
    if ($remainBits > 0 && $fullBytes < $ipLen) {
        $mask = 0xFF << (8 - $remainBits);
        if ((ord($ipBin[$fullBytes]) & $mask) !== (ord($subnetBin[$fullBytes]) & $mask)) {
            return false;
        }
    }
    return true;
}
/**
 * Check if an IP belongs to Cloudflare's published ranges.
 */
function is_cloudflare_ip(string $ip): bool
{
    static $cfRanges = null;
    if ($cfRanges === null) {
        $cfRanges = [
            '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22',
            '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
            '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22',
            '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
            '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
            '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32',
            '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29',
            '2c0f:f248::/32',
        ];
    }
    foreach ($cfRanges as $cidr) {
        if (cidr_match($ip, $cidr)) {
            return true;
        }
    }
    return false;
}

function cloudflare_proxy_trust_enabled(): bool
{
    return in_array(
        strtolower(env_value('TRUST_CLOUDFLARE_IP', '0') ?? '0'),
        ['1', 'true', 'yes', 'on'],
        true
    );
}

/** @return list<string> */
function trusted_proxy_cidrs(): array
{
    return array_values(array_filter(
        array_map(
            'trim',
            explode(',', env_value('TRUSTED_PROXIES', '') ?? '')
        ),
        static fn(string $cidr): bool => $cidr !== ''
    ));
}

function is_configured_trusted_proxy(string $ip): bool
{
    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return false;
    }
    foreach (trusted_proxy_cidrs() as $cidr) {
        if (cidr_match($ip, $cidr)) {
            return true;
        }
    }
    return false;
}

function is_trusted_proxy_ip(string $ip): bool
{
    return is_configured_trusted_proxy($ip)
        || (cloudflare_proxy_trust_enabled() && is_cloudflare_ip($ip));
}

function safe_proxy_header(string $name, int $maxLength = 2048): ?string
{
    $value = (string) ($_SERVER[$name] ?? '');
    if (
        $value === ''
        || strlen($value) > $maxLength
        || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
    ) {
        return null;
    }
    return trim($value);
}

function is_https_request(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }

    $remoteAddr = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (filter_var($remoteAddr, FILTER_VALIDATE_IP) === false) {
        return false;
    }

    if (cloudflare_proxy_trust_enabled() && is_cloudflare_ip($remoteAddr)) {
        $visitorHeader = safe_proxy_header('HTTP_CF_VISITOR', 512);
        if ($visitorHeader === null) {
            return false;
        }
        $visitor = json_decode($visitorHeader, true);
        return is_array($visitor)
            && strtolower((string) ($visitor['scheme'] ?? '')) === 'https';
    }

    if (!is_configured_trusted_proxy($remoteAddr)) {
        return false;
    }

    $forwardedProto = safe_proxy_header('HTTP_X_FORWARDED_PROTO', 128);
    if ($forwardedProto === null) {
        return false;
    }
    $values = array_map('trim', explode(',', strtolower($forwardedProto)));
    return $values !== [] && $values[0] === 'https';
}

function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' https://challenges.cloudflare.com https://static.cloudflareinsights.com; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self' https://challenges.cloudflare.com https://cloudflareinsights.com; frame-src https://challenges.cloudflare.com; form-action 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'");
    header('Cache-Control: no-store, max-age=0');
}

function start_secure_session(): void
{
    if (PHP_SAPI === 'cli' || session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('grade_query_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => is_https_request(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function csrf_token(): string
{
    start_secure_session();
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(?string $token): bool
{
    start_secure_session();
    return is_string($token) && isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function require_csrf(): void
{
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        throw new PublicSafeException('页面已过期，请刷新后重试。');
    }
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function flash(string $type, string $message): void
{
    start_secure_session();
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function consume_flash(): ?array
{
    start_secure_session();
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : null;
}

function ip_in_cidr(string $ip, string $cidr): bool
{
    $cidr = trim($cidr);
    if ($cidr === '') {
        return false;
    }
    if (!str_contains($cidr, '/')) {
        return hash_equals($cidr, $ip);
    }
    [$network, $bitsText] = explode('/', $cidr, 2);
    $ipBinary = inet_pton($ip);
    $networkBinary = inet_pton($network);
    if ($ipBinary === false || $networkBinary === false || strlen($ipBinary) !== strlen($networkBinary)) {
        return false;
    }
    $bits = (int) $bitsText;
    $maxBits = strlen($ipBinary) * 8;
    if ($bits < 0 || $bits > $maxBits) {
        return false;
    }
    $bytes = intdiv($bits, 8);
    $remaining = $bits % 8;
    if ($bytes > 0 && substr($ipBinary, 0, $bytes) !== substr($networkBinary, 0, $bytes)) {
        return false;
    }
    if ($remaining === 0) {
        return true;
    }
    $mask = (0xFF << (8 - $remaining)) & 0xFF;
    return (ord($ipBinary[$bytes]) & $mask) === (ord($networkBinary[$bytes]) & $mask);
}

function client_ip(): string
{
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    if (filter_var($remote, FILTER_VALIDATE_IP) === false) {
        return '0.0.0.0';
    }

    if (cloudflare_proxy_trust_enabled() && is_cloudflare_ip($remote)) {
        $cloudflareClient = safe_proxy_header('HTTP_CF_CONNECTING_IP', 128);
        return $cloudflareClient !== null
            && filter_var($cloudflareClient, FILTER_VALIDATE_IP) !== false
            ? $cloudflareClient
            : $remote;
    }

    if (!is_configured_trusted_proxy($remote)) {
        return $remote;
    }

    $forwardedHeader = safe_proxy_header('HTTP_X_FORWARDED_FOR');
    if ($forwardedHeader === null) {
        return $remote;
    }

    $forwarded = array_map('trim', explode(',', $forwardedHeader));
    if ($forwarded === [] || count($forwarded) > 32) {
        return $remote;
    }
    foreach ($forwarded as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return $remote;
        }
    }

    $chain = array_merge($forwarded, [$remote]);
    for ($index = count($chain) - 1; $index >= 0; $index--) {
        $ip = $chain[$index];
        if (!is_trusted_proxy_ip($ip)) {
            return $ip;
        }
    }
    return $remote;
}

function parse_user_agent(string $userAgent): array
{
    $device = '其他';
    if (preg_match('/iPad|Tablet|PlayBook|Silk/i', $userAgent)) {
        $device = '平板';
    } elseif (preg_match('/Mobile|Android|iPhone|Windows Phone/i', $userAgent)) {
        $device = '手机';
    } elseif ($userAgent !== '') {
        $device = '电脑';
    }

    $os = '未知';
    foreach (['Windows' => '/Windows NT/i', 'Android' => '/Android/i', 'iOS' => '/iPhone|iPad|iPod/i', 'macOS' => '/Macintosh|Mac OS X/i', 'Linux' => '/Linux/i'] as $name => $pattern) {
        if (preg_match($pattern, $userAgent)) {
            $os = $name;
            break;
        }
    }

    $browser = '未知';
    foreach (['Edge' => '/Edg\//i', 'Chrome' => '/Chrome\//i', 'Firefox' => '/Firefox\//i', 'Safari' => '/Safari\//i', '微信' => '/MicroMessenger/i'] as $name => $pattern) {
        if (preg_match($pattern, $userAgent)) {
            $browser = $name;
            break;
        }
    }
    return ['device_type' => $device, 'os_name' => $os, 'browser_name' => $browser];
}

function audit_log(string $action, ?string $entityType = null, ?int $entityId = null, bool $success = true, array $details = []): void
{
    try {
        start_secure_session();
        $adminId = isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null;
        $redact = static function (mixed $value, string|int $key = '') use (&$redact): mixed {
            if (is_string($key) && preg_match('/(?:password|secret|token|session|phone_plain|email_plain)/i', $key)) {
                return '[REDACTED]';
            }
            if (is_array($value)) {
                foreach ($value as $childKey => $childValue) {
                    $value[$childKey] = $redact($childValue, $childKey);
                }
            }
            return $value;
        };
        $details = $redact($details);
        $safeDetails = $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = db()->prepare('INSERT INTO admin_audit_logs (admin_id, action, entity_type, entity_id, is_success, ip_address, details_json) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$adminId, $action, $entityType, $entityId, $success ? 1 : 0, encrypt_value(client_ip()), $safeDetails]);
    } catch (Throwable $exception) {
        PublicErrorHandler::logException($exception, [
            'component' => 'audit_log',
            'action' => $action,
        ]);
    }
}

function current_admin(): ?array
{
    start_secure_session();
    if (!isset($_SESSION['admin_id'], $_SESSION['admin_last_activity'], $_SESSION['admin_started_at'])) {
        return null;
    }
    $now = time();
    if ($now - (int) $_SESSION['admin_last_activity'] > 1800 || $now - (int) $_SESSION['admin_started_at'] > 28800) {
        destroy_session();
        return null;
    }
    $_SESSION['admin_last_activity'] = $now;
    $stmt = db()->prepare('SELECT id, username, username_normalized, role, status, phone_masked, email_masked, must_change_password, password_changed_at, last_login_at FROM admin_users WHERE id = ?');
    $stmt->execute([(int) $_SESSION['admin_id']]);
    $admin = $stmt->fetch();
    if (!is_array($admin)) {
        destroy_session();
        return null;
    }
    if (($admin['status'] ?? '') !== 'active') {
        $status = (string) ($admin['status'] ?? 'disabled');
        audit_log('admin_session_revoked', 'admin_user', (int) $admin['id'], false, ['status' => $status]);
        destroy_session();
        flash('error', $status === 'disabled' ? '您的管理员账号已停用，请联系超级管理员。' : '当前管理员账号不可使用。');
        return null;
    }
    $_SESSION['admin_role'] = (string) $admin['role'];
    return $admin;
}

function require_admin(bool $allowPasswordChangeOnly = false): array
{
    $admin = current_admin();
    if ($admin === null) {
        if (!isset($_SESSION['flash'])) {
            flash('error', '请先登录。');
        }
        redirect('login.php');
    }
    if (!$allowPasswordChangeOnly && (int) $admin['must_change_password'] === 1) {
        flash('warning', '首次登录必须先修改默认密码。');
        redirect('admin.php?tab=security');
    }
    return $admin;
}

function destroy_session(): void
{
    start_secure_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => (bool) $params['secure'],
            'httponly' => (bool) $params['httponly'],
            'samesite' => 'Strict',
        ]);
    }
    session_destroy();
}

function validate_new_password(string $password): ?string
{
    return AdminValidation::validatePassword($password);
}

function change_admin_password(int $adminId, string $oldPassword, string $newPassword): ?string
{
    $validation = validate_new_password($newPassword);
    if ($validation !== null) {
        return $validation;
    }
    $stmt = db()->prepare('SELECT password_hash, username, phone_encrypted FROM admin_users WHERE id = ?');
    $stmt->execute([$adminId]);
    $admin = $stmt->fetch();
    $hash = is_array($admin) ? (string) $admin['password_hash'] : '';
    if ($hash === '' || !password_verify($oldPassword, $hash)) {
        return '原密码不正确。';
    }
    if (password_verify($newPassword, $hash)) {
        return '新密码不能与当前密码相同。';
    }
    $phone = '';
    try {
        $phone = decrypt_value($admin['phone_encrypted'] ?? null);
    } catch (Throwable) {
        $phone = '';
    }
    $validation = AdminValidation::validatePassword($newPassword, (string) $admin['username'], $phone);
    if ($validation !== null) {
        return $validation;
    }
    db()->prepare('UPDATE admin_users SET password_hash = ?, must_change_password = 0, failed_attempts = 0, locked_until = NULL, password_changed_at = NOW() WHERE id = ?')->execute([password_hash($newPassword, PASSWORD_DEFAULT), $adminId]);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    audit_log('admin_password_changed', 'admin_user', $adminId);
    return null;
}

function request_user_agent(): string
{
    return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000, 'UTF-8');
}

function query_rate_limited(string $ipHash): bool
{
    $queries = [
        ['SELECT COUNT(*) FROM query_logs WHERE ip_hash = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)', 5],
        ['SELECT COUNT(*) FROM query_logs WHERE ip_hash = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)', 30],
        ['SELECT COUNT(*) FROM query_logs WHERE ip_hash = ? AND is_success = 0 AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)', 10],
    ];
    foreach ($queries as [$sql, $maximum]) {
        $stmt = db()->prepare($sql);
        $stmt->execute([$ipHash]);
        if ((int) $stmt->fetchColumn() >= $maximum) {
            return true;
        }
    }
    return false;
}

function write_query_log(?int $projectId, ?int $recordId, bool $success): void
{
    $ip = client_ip();
    $ipHash = hash_hmac('sha256', $ip, search_key());
    $ua = request_user_agent();
    $device = parse_user_agent($ua);
    $stmt = db()->prepare('INSERT INTO query_logs (project_id, record_id, is_success, ip_address, ip_hash, user_agent, device_type, os_name, browser_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$projectId, $recordId, $success ? 1 : 0, encrypt_value($ip), $ipHash, $ua, $device['device_type'], $device['os_name'], $device['browser_name']]);
}
