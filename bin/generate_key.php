<?php
declare(strict_types=1);

/**
 * bin/generate_key.php — generate APP_KEY (32 random bytes, base64-encoded, §8).
 *
 *   php bin/generate_key.php          → if .env exists with an empty APP_KEY, writes it in place;
 *                                        otherwise prints the key to paste into .env.
 *   php bin/generate_key.php --force  → overwrite an existing APP_KEY (rotates it).
 *
 * WARNING: rotating APP_KEY invalidates stateless CSRF tokens (harmless, 10-min life)
 * and breaks TOTP secret decryption unless re-encrypted (§8). Do not rotate casually.
 */

require __DIR__ . '/_lib.php';

$force = in_array('--force', $_SERVER['argv'], true);
$key = base64_encode(random_bytes(32));
$envPath = bin_root() . '/.env';

if (!is_file($envPath)) {
    echo "No .env found. Add this line to your .env:\n\n";
    echo cli_bold("APP_KEY={$key}") . "\n";
    exit(0);
}

$contents = file_get_contents($envPath) ?: '';
$hasEmpty = (bool) preg_match('/^APP_KEY=\s*$/m', $contents);
$hasValue = (bool) preg_match('/^APP_KEY=\S+/m', $contents);

if ($hasValue && !$force) {
    echo cli_yellow("APP_KEY already set.") . " Use --force to rotate it (read the warning in this file first).\n";
    exit(1);
}

if ($hasEmpty || $hasValue) {
    $new = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $key, $contents);
} else {
    $new = rtrim($contents, "\n") . "\nAPP_KEY={$key}\n";
}
file_put_contents($envPath, $new);
@chmod($envPath, 0600);
echo cli_green('✓') . " APP_KEY written to .env" . ($force ? ' (rotated)' : '') . ".\n";
exit(0);
