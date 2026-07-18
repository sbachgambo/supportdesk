<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Config (§8) — loads .env once at bootstrap and exposes typed accessors.
 *
 * Rules from the brief:
 *   - Missing REQUIRED keys throw at bootstrap — fail loudly, not at 2am in cron.
 *   - APP_URL not starting with https:// is a fatal bootstrap error in production.
 *   - No other class parses .env; everything reads through here.
 */
final class Config
{
    /** @var array<string,string> */
    private static array $values = [];
    private static bool $loaded = false;

    /** Keys without which the app cannot safely run (§8). */
    private const REQUIRED = ['APP_ENV', 'APP_URL', 'APP_KEY', 'DB_NAME', 'DB_USER'];

    public static function load(string $envPath): void
    {
        if (!is_file($envPath)) {
            throw new RuntimeException("Config: env file not found at {$envPath}");
        }

        self::$values = [];
        foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
            // strip trailing inline comment and surrounding quotes; no interpolation
            $v = preg_replace('/\s+#.*$/', '', trim($v)) ?? '';
            self::$values[trim($k)] = trim($v, "\"'");
        }
        self::$loaded = true;

        foreach (self::REQUIRED as $key) {
            if ((self::$values[$key] ?? '') === '') {
                throw new RuntimeException("Config: required key {$key} is missing or empty");
            }
        }

        if (self::isProduction() && !str_starts_with(self::$values['APP_URL'], 'https://')) {
            throw new RuntimeException('Config: APP_URL must start with https:// in production (§10.13)');
        }
    }

    public static function isLoaded(): bool
    {
        return self::$loaded;
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::$values) && self::$values[$key] !== '';
    }

    public static function string(string $key, ?string $default = null): string
    {
        if (self::has($key)) {
            return self::$values[$key];
        }
        if ($default !== null) {
            return $default;
        }
        throw new RuntimeException("Config: missing required string key {$key}");
    }

    public static function int(string $key, ?int $default = null): int
    {
        if (self::has($key)) {
            return (int) self::$values[$key];
        }
        if ($default !== null) {
            return $default;
        }
        throw new RuntimeException("Config: missing required int key {$key}");
    }

    public static function bool(string $key, ?bool $default = null): bool
    {
        if (self::has($key)) {
            return in_array(strtolower(self::$values[$key]), ['1', 'true', 'yes', 'on'], true);
        }
        if ($default !== null) {
            return $default;
        }
        throw new RuntimeException("Config: missing required bool key {$key}");
    }

    /** Comma-separated value → trimmed non-empty array. */
    public static function list(string $key, string $default = ''): array
    {
        $raw = self::string($key, $default);
        return array_values(array_filter(array_map('trim', explode(',', $raw)), fn($v) => $v !== ''));
    }

    public static function isProduction(): bool
    {
        return (self::$values['APP_ENV'] ?? 'production') === 'production';
    }

    /** Decoded APP_KEY bytes (base64) — used by crypto (§8). */
    public static function appKey(): string
    {
        $decoded = base64_decode(self::string('APP_KEY'), true);
        if ($decoded === false || strlen($decoded) < 32) {
            throw new RuntimeException('Config: APP_KEY is not valid base64 of >=32 bytes');
        }
        return $decoded;
    }
}
