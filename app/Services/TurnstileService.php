<?php

declare(strict_types=1);

require_once __DIR__ . '/SystemSettingsService.php';

final class TurnstileService
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /** @var null|callable(array):array */
    private $transport;

    public function __construct(private readonly array $config, ?callable $transport = null)
    {
        $this->transport = $transport;
    }

    public function verify(string $token, string $expectedAction, string $remoteIp): array
    {
        $token = trim($token);
        if ($token === '') {
            return ['success' => false, 'message' => '请完成人机验证后再继续。', 'reason' => 'missing_token'];
        }
        $secret = (string) ($this->config['secret_key'] ?? '');
        if ($secret === '' || !empty($this->config['secret_decryption_failed'])) {
            return ['success' => false, 'message' => '安全验证服务暂时不可用，请联系管理员。', 'reason' => 'configuration_unavailable'];
        }
        $payload = [
            'secret' => $secret,
            'response' => $token,
            'remoteip' => $remoteIp,
            'idempotency_key' => self::uuidV4(),
        ];
        try {
            $response = $this->transport !== null
                ? ($this->transport)($payload)
                : $this->requestCloudflare($payload);
        } catch (Throwable $exception) {
            PublicErrorHandler::logException($exception, [
                'component' => 'turnstile',
                'operation' => 'siteverify',
            ]);
            return ['success' => false, 'message' => '人机验证服务暂时不可用，请稍后重试。', 'reason' => 'service_unavailable'];
        }
        if (!is_array($response) || empty($response['success'])) {
            $errorCodes = is_array($response['error-codes'] ?? null)
                ? array_values(array_filter($response['error-codes'], 'is_string'))
                : [];
            $reason = in_array('timeout-or-duplicate', $errorCodes, true) ? 'token_expired' : 'verification_failed';
            return ['success' => false, 'message' => '人机验证未通过，请重新验证。', 'reason' => $reason];
        }
        if (!hash_equals($expectedAction, (string) ($response['action'] ?? ''))) {
            return ['success' => false, 'message' => '人机验证场景不匹配，请刷新页面重试。', 'reason' => 'action_mismatch'];
        }
        $hostname = strtolower(rtrim((string) ($response['hostname'] ?? ''), '.'));
        $allowed = array_map(static fn (mixed $host): string => strtolower((string) $host), $this->config['allowed_hostnames'] ?? []);
        if ($allowed !== [] && !in_array($hostname, $allowed, true)) {
            return ['success' => false, 'message' => '人机验证域名不匹配，请联系超级管理员。', 'reason' => 'hostname_mismatch'];
        }
        return ['success' => true, 'message' => '人机验证通过。', 'reason' => 'success', 'hostname' => $hostname];
    }

    private function requestCloudflare(array $payload): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('服务器未启用cURL扩展。');
        }
        $curl = curl_init(self::VERIFY_URL);
        if ($curl === false) {
            throw new RuntimeException('无法初始化验证请求。');
        }
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if (!is_string($body) || $body === '' || $status < 200 || $status >= 300) {
            throw new RuntimeException('Cloudflare验证请求失败：HTTP ' . $status . ($error !== '' ? ' ' . $error : ''));
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Cloudflare返回格式无效。');
        }
        return $decoded;
    }

    private static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
