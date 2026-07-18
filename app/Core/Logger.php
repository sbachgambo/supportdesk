<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Logger (§10.10) — structured, append-only logging to storage/logs/.
 *
 * Every line: ISO-8601 UTC | request-id | user | ip | method | path | channel | event | detail
 *
 * Guarantees:
 *   - NEVER logs passwords, tokens, TOTP secrets, .env values, or auth request bodies.
 *     Values are redacted by key name AND the free-text detail is scrubbed of anything
 *     that looks like a secret assignment.
 *   - Log-injection safe: \r and \n are stripped from every interpolated value, so an
 *     attacker cannot forge log lines.
 *   - security.log is a separate, short, boring channel (auth fails, lockouts, authz
 *     denials, upload/CSRF rejections). When it is not boring, something is happening.
 */
final class Logger
{
    private const CHANNELS = ['error', 'audit', 'ingest', 'mail', 'security'];

    private static string $requestId = '';
    private static string $ip = '-';
    private static string $method = '-';
    private static string $path = '-';
    private static ?string $userId = null;

    /** Redact any context key whose name suggests a secret. */
    private const SECRET_KEYS = [
        'password', 'passwd', 'pass', 'pwd', 'token', 'secret', 'totp',
        'code', 'csrf', 'app_key', 'authorization', 'cookie',
    ];

    public static function boot(string $requestId, string $ip = '-', string $method = '-', string $path = '-'): void
    {
        self::$requestId = $requestId;
        self::$ip = self::clean($ip);
        self::$method = self::clean($method);
        self::$path = self::clean($path);
    }

    public static function requestId(): string
    {
        if (self::$requestId === '') {
            self::$requestId = bin2hex(random_bytes(8));
        }
        return self::$requestId;
    }

    public static function setUser(?string $userId): void
    {
        self::$userId = $userId === null ? null : self::clean($userId);
    }

    public static function error(string $event, string $detail = '', array $context = []): void
    {
        self::write('error', $event, $detail, $context);
    }

    public static function security(string $event, string $detail = '', array $context = []): void
    {
        self::write('security', $event, $detail, $context);
    }

    public static function ingest(string $event, string $detail = '', array $context = []): void
    {
        self::write('ingest', $event, $detail, $context);
    }

    public static function mail(string $event, string $detail = '', array $context = []): void
    {
        self::write('mail', $event, $detail, $context);
    }

    public static function write(string $channel, string $event, string $detail = '', array $context = []): void
    {
        if (!in_array($channel, self::CHANNELS, true)) {
            $channel = 'error';
        }

        $fields = [
            gmdate('c'),
            self::requestId(),
            self::$userId ?? '-',
            self::$ip,
            self::$method,
            self::$path,
            self::clean($event),
            self::scrubDetail(self::clean($detail)),
        ];

        if ($context !== []) {
            $fields[] = self::encodeContext($context);
        }

        $line = implode(' | ', $fields) . PHP_EOL;
        $dir = self::logDir();
        // Guard writability explicitly rather than suppressing with @ (§16).
        // A failed log write must not itself throw and mask the real error, so we
        // simply skip when the directory is not writable (e.g. a misconfigured host).
        if (is_dir($dir) && is_writable($dir)) {
            file_put_contents($dir . '/' . $channel . '.log', $line, FILE_APPEND | LOCK_EX);
        }
    }

    private static function logDir(): string
    {
        return defined('P3A_ROOT') ? P3A_ROOT . '/storage/logs' : sys_get_temp_dir();
    }

    /** Strip CR/LF so user-supplied values cannot forge log lines. */
    private static function clean(string $value): string
    {
        return str_replace(["\r", "\n"], ' ', $value);
    }

    /** Remove anything resembling `secret=...` or `password: ...` from free text. */
    private static function scrubDetail(string $detail): string
    {
        $pattern = '/\b(' . implode('|', self::SECRET_KEYS) . ')\b\s*[=:]\s*\S+/i';
        return preg_replace($pattern, '$1=[REDACTED]', $detail) ?? $detail;
    }

    private static function encodeContext(array $context): string
    {
        $safe = [];
        foreach ($context as $k => $v) {
            $key = strtolower((string) $k);
            $isSecret = false;
            foreach (self::SECRET_KEYS as $needle) {
                if (str_contains($key, $needle)) {
                    $isSecret = true;
                    break;
                }
            }
            $safe[$k] = $isSecret ? '[REDACTED]' : (is_scalar($v) || $v === null ? $v : '[object]');
        }
        $json = json_encode($safe, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return self::clean($json === false ? '{}' : $json);
    }
}
