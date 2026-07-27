<?php

declare(strict_types=1);

require_once __DIR__ . '/../Exceptions/PublicSafeException.php';

final class PublicErrorHandler
{
    private const DEFAULT_MESSAGE = '系统暂时无法处理请求，请稍后重试。';

    /** @var list<string> */
    private const CONTEXT_KEYS = [
        'action',
        'component',
        'operation',
        'reason',
        'request_method',
        'response_type',
        'setting_key',
    ];

    public static function publicMessage(
        Throwable $exception,
        string $fallback = self::DEFAULT_MESSAGE
    ): string {
        if ($exception instanceof PublicSafeException) {
            return $exception->getMessage();
        }

        return $fallback;
    }

    /**
     * Log only an exception category, a random request ID and allow-listed
     * operational context. Exception messages, paths and stack traces are
     * intentionally excluded because they can contain SQL or secrets.
     */
    public static function logException(Throwable $exception, array $context = []): string
    {
        $requestId = bin2hex(random_bytes(8));
        $safeContext = [];

        foreach (self::CONTEXT_KEYS as $key) {
            if (!array_key_exists($key, $context) || !is_scalar($context[$key])) {
                continue;
            }
            $value = preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $context[$key]) ?? '';
            $safeContext[$key] = mb_substr($value, 0, 120, 'UTF-8');
        }

        error_log((string) json_encode([
            'event' => 'request_exception',
            'request_id' => $requestId,
            'exception_class' => get_class($exception),
            'context' => $safeContext,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $requestId;
    }
}
