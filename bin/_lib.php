<?php
declare(strict_types=1);

/**
 * Minimal shared helpers for bin/ CLI scripts (Phase 1).
 * bin/ scripts run before app/ exists (install bootstraps the app), so they
 * deliberately do NOT depend on Core\* — they parse .env themselves.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function bin_root(): string
{
    return dirname(__DIR__);
}

/** Parse a KEY=VALUE env file into an assoc array. No interpolation. */
function bin_env(?string $path = null): array
{
    $path ??= bin_root() . '/.env';
    $env = [];
    if (!is_file($path)) {
        return $env;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
        $v = preg_replace('/\s+#.*$/', '', trim($v)) ?? '';
        $env[trim($k)] = trim($v, "\"'");
    }
    return $env;
}

function cli_green(string $s): string { return "\033[32m{$s}\033[0m"; }
function cli_red(string $s): string   { return "\033[31m{$s}\033[0m"; }
function cli_yellow(string $s): string{ return "\033[33m{$s}\033[0m"; }
function cli_bold(string $s): string  { return "\033[1m{$s}\033[0m"; }

function cli_pass(string $label): void { echo '  ' . cli_green('✓') . " {$label}\n"; }
function cli_fail(string $label): void { echo '  ' . cli_red('✗') . " {$label}\n"; }
function cli_warn(string $label): void { echo '  ' . cli_yellow('!') . " {$label}\n"; }

/** Connect to the DB described by an env array. Throws on failure. */
function bin_pdo(array $env): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $env['DB_HOST'] ?? '127.0.0.1',
        $env['DB_NAME'] ?? '',
        $env['DB_CHARSET'] ?? 'utf8mb4'
    );
    return new PDO($dsn, $env['DB_USER'] ?? '', $env['DB_PASS'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
