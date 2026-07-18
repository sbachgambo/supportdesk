<?php
declare(strict_types=1);

/**
 * bin/install.php — create schema + seed (§15 Phase 1 exit, §19.8).
 *
 *   php bin/install.php             → refuses if tables already exist
 *   php bin/install.php --force     → drops all P3A tables first, then re-installs
 *   php bin/install.php --testing   → use .env.testing (scratch DB) instead of .env
 *   php bin/install.php --no-seed   → schema only
 */

require __DIR__ . '/_lib.php';

$argv = $_SERVER['argv'];
$force    = in_array('--force', $argv, true);
$testing  = in_array('--testing', $argv, true);
$noSeed   = in_array('--no-seed', $argv, true);

$envFile = bin_root() . ($testing ? '/.env.testing' : '/.env');
$env = bin_env($envFile);
if ($env === []) {
    echo cli_red("No env file at {$envFile}. Copy .env.example and fill it in.\n");
    exit(1);
}

try {
    $pdo = bin_pdo($env);
} catch (Throwable $e) {
    echo cli_red('DB connection failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$existing = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
if ($existing !== [] && !$force) {
    echo cli_yellow('Database is not empty (' . count($existing) . " tables).\n");
    echo "Refusing to overwrite. Re-run with --force to drop and reinstall.\n";
    exit(1);
}

if ($existing !== [] && $force) {
    echo "Dropping " . count($existing) . " existing table(s)…\n";
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($existing as $t) {
        $pdo->exec('DROP TABLE IF EXISTS `' . $t . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}

$run = function (string $file) use ($pdo): void {
    $sql = file_get_contents(bin_root() . '/database/' . $file);
    if ($sql === false) {
        throw new RuntimeException("cannot read database/{$file}");
    }
    $pdo->exec($sql);
};

try {
    echo "Installing schema…\n";
    $run('schema.sql');
    if (!$noSeed) {
        echo "Seeding…\n";
        $run('seed.sql');
    }
} catch (Throwable $e) {
    echo cli_red('Install failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$users  = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

echo str_repeat('─', 50) . "\n";
cli_pass(count($tables) . ' tables created');
if (!$noSeed) {
    cli_pass("{$users} users seeded");
    echo "\n" . cli_bold('Default login') . " (change immediately — §19.9):\n";
    echo "  admin@p3a-support.com.ng  /  P3a-Seed-Change!2026\n";
}
echo "\n" . cli_green(cli_bold('Install complete.')) . "\n";
exit(0);
