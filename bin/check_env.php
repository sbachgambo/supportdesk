<?php
declare(strict_types=1);

/**
 * bin/check_env.php — diagnose hosting readiness, red/green (§6, §15 Phase 1 exit).
 * Run on the target host BEFORE install. Exits non-zero if any hard requirement fails.
 *
 *   php bin/check_env.php
 *   php bin/check_env.php --testing   → check against .env.testing instead of .env
 */

require __DIR__ . '/_lib.php';

$useTesting = in_array('--testing', $_SERVER['argv'], true);
$envFile = bin_root() . ($useTesting ? '/.env.testing' : '/.env');
$env = bin_env($envFile);
$fail = 0;

echo cli_bold("P3A environment check — " . basename($envFile)) . "\n";
echo str_repeat('─', 50) . "\n";

// ── PHP version (D1: 8.1 minimum) ──
echo cli_bold("Runtime") . "\n";
if (PHP_VERSION_ID >= 80100) {
    cli_pass('PHP ' . PHP_VERSION . ' (>= 8.1 required)');
} else {
    cli_fail('PHP ' . PHP_VERSION . ' — 8.1+ required (D1); do not proceed on 7.4');
    $fail++;
}

// ── Required extensions (§5) ──
echo cli_bold("Extensions (§5)") . "\n";
$required = ['pdo_mysql', 'mbstring', 'openssl', 'fileinfo', 'curl', 'json', 'gd', 'zlib'];
foreach ($required as $ext) {
    if (extension_loaded($ext)) {
        cli_pass("ext-{$ext}");
    } else {
        cli_fail("ext-{$ext} missing — required (§5)");
        $fail++;
    }
}
// imap: required in production for D3 ingestion, but absent on many dev boxes and
// removed from PHP core in 8.4. Warn (not fail) so local dev isn't blocked; must be
// present on the Go54 host. See DEV_NOTES.
if (extension_loaded('imap')) {
    cli_pass('ext-imap');
} else {
    cli_warn('ext-imap missing — REQUIRED in production for inbound email (D3); ok to skip on a dev box');
}

// ── Password hashing (§10.3) ──
echo cli_bold("Crypto") . "\n";
if (defined('PASSWORD_ARGON2ID')) {
    cli_pass('PASSWORD_ARGON2ID available (preferred hashing)');
} else {
    cli_warn('PASSWORD_ARGON2ID unavailable — will fall back to bcrypt cost 12 (acceptable, §10.3)');
}

// ── Writable storage dirs (§6) ──
echo cli_bold("Storage (writable, §6)") . "\n";
foreach (['storage/uploads', 'storage/backups', 'storage/logs', 'storage/cache'] as $dir) {
    $full = bin_root() . '/' . $dir;
    if (is_dir($full) && is_writable($full)) {
        cli_pass("{$dir} writable");
    } else {
        cli_fail("{$dir} missing or not writable");
        $fail++;
    }
}

// ── .env sanity (§8) ──
echo cli_bold(".env (§8)") . "\n";
if (!is_file($envFile)) {
    cli_warn(basename($envFile) . ' not found — copy .env.example and fill it in');
} else {
    // APP_KEY set
    if (!empty($env['APP_KEY'])) {
        cli_pass('APP_KEY is set');
    } else {
        cli_fail('APP_KEY empty — run bin/generate_key.php');
        $fail++;
    }
    // APP_URL https in production (§8, §10.13)
    $isProd = ($env['APP_ENV'] ?? 'production') === 'production';
    if ($isProd && !str_starts_with($env['APP_URL'] ?? '', 'https://')) {
        cli_fail('APP_URL must start with https:// in production (§10.13)');
        $fail++;
    } else {
        cli_pass('APP_URL scheme ok for APP_ENV=' . ($env['APP_ENV'] ?? 'production'));
    }
    // DB connectivity
    try {
        $pdo = bin_pdo($env);
        $emu = $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
        cli_pass('DB connection ok (' . ($env['DB_NAME'] ?? '?') . ')');
        cli_pass('PDO emulation off: ' . ($emu ? 'NO — check' : 'yes'));
    } catch (Throwable $e) {
        cli_fail('DB connection failed: ' . $e->getMessage());
        $fail++;
    }
}

echo str_repeat('─', 50) . "\n";
if ($fail === 0) {
    echo cli_green(cli_bold('ALL GREEN — ready to install')) . "\n";
    exit(0);
}
echo cli_red(cli_bold("{$fail} hard requirement(s) failed — fix before install")) . "\n";
exit(1);
