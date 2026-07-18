<?php
declare(strict_types=1);

/**
 * Tiny zero-dependency test helper shared by every suite (§17).
 * A framework would be more ceremony than value here — runnable scripts
 * that echo ✓/✗ and exit non-zero on failure are enough, and they run
 * anywhere PHP does (including a bare shared-hosting shell).
 */
final class T
{
    private static int $pass = 0;
    private static int $fail = 0;
    private static array $failures = [];
    private static string $suite = '';

    public static function suite(string $name): void
    {
        self::$suite = $name;
        echo "\n\033[1m── {$name} ──\033[0m\n";
    }

    public static function ok(bool $cond, string $label): bool
    {
        if ($cond) {
            self::$pass++;
            echo "  \033[32m✓\033[0m {$label}\n";
        } else {
            self::$fail++;
            self::$failures[] = self::$suite . ': ' . $label;
            echo "  \033[31m✗\033[0m {$label}\n";
        }
        return $cond;
    }

    public static function eq(mixed $expected, mixed $actual, string $label): bool
    {
        $cond = $expected === $actual;
        if (!$cond) {
            $label .= sprintf(
                ' (expected %s, got %s)',
                var_export($expected, true),
                var_export($actual, true)
            );
        }
        return self::ok($cond, $label);
    }

    public static function note(string $msg): void
    {
        echo "  \033[90m· {$msg}\033[0m\n";
    }

    /** Returns process exit code: 0 = all green, 1 = at least one failure. */
    public static function summary(): int
    {
        $total = self::$pass + self::$fail;
        echo "\n";
        if (self::$fail === 0) {
            echo "\033[32m\033[1mALL GREEN\033[0m — {$total} checks passed\n";
            return 0;
        }
        echo "\033[31m\033[1m" . self::$fail . " FAILED\033[0m of {$total}:\n";
        foreach (self::$failures as $f) {
            echo "  \033[31m✗\033[0m {$f}\n";
        }
        return 1;
    }

    public static function failCount(): int
    {
        return self::$fail;
    }

    public static function reset(): void
    {
        self::$pass = 0;
        self::$fail = 0;
        self::$failures = [];
    }
}

/** Recursively collect files matching an extension under a directory. */
function t_files(string $dir, string $ext): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $out = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === $ext) {
            $out[] = str_replace('\\', '/', $file->getPathname());
        }
    }
    sort($out);
    return $out;
}

/** Load a KEY=VALUE env file into an associative array (no interpolation). */
function t_load_env(string $path): array
{
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
        $v = trim($v);
        // strip trailing inline comment and surrounding quotes
        $v = preg_replace('/\s+#.*$/', '', $v) ?? $v;
        $v = trim($v, "\"'");
        $env[trim($k)] = $v;
    }
    return $env;
}
