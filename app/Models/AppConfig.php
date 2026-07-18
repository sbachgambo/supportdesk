<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Db;

/**
 * AppConfig (Model) — thin repository over the config table (key/value allowlist).
 * Named AppConfig to avoid confusion with Core\Config (the .env loader).
 */
final class AppConfig
{
    /** @return array<string,string> */
    public static function all(): array
    {
        $out = [];
        foreach (Db::queryAll('SELECT `key`, value FROM config') as $row) {
            $out[(string) $row['key']] = (string) $row['value'];
        }
        return $out;
    }

    public static function get(string $key, string $default = ''): string
    {
        $row = Db::queryOne('SELECT value FROM config WHERE `key` = :k', [':k' => $key]);
        return $row === null ? $default : (string) $row['value'];
    }

    /** @param array<string> $keys */
    public static function some(array $keys): array
    {
        $all = self::all();
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $all[$k] ?? '';
        }
        return $out;
    }
}
