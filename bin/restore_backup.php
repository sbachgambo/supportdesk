<?php
declare(strict_types=1);

/**
 * bin/restore_backup.php (§14) — decrypt + restore a backup into a SCRATCH database.
 * "A backup you have never restored is a hope, not a backup."
 *
 *   php bin/restore_backup.php <backup-file> --into=<db> [--force]
 *
 * Refuses to restore into the live DB_NAME unless --force is given (drills restore
 * into a scratch DB). The target DB must already exist.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Services\BackupService;

$argv = $_SERVER['argv'];
$file = $argv[1] ?? '';
$into = '';
$force = false;
foreach (array_slice($argv, 2) as $arg) {
    if (str_starts_with($arg, '--into=')) {
        $into = substr($arg, 7);
    } elseif ($arg === '--force') {
        $force = true;
    }
}

if ($file === '' || $into === '') {
    fwrite(STDERR, "Usage: php bin/restore_backup.php <backup-file> --into=<db> [--force]\n");
    exit(1);
}
if (!is_file($file)) {
    fwrite(STDERR, "Backup file not found: {$file}\n");
    exit(1);
}
if ($into === Config::string('DB_NAME') && !$force) {
    fwrite(STDERR, "Refusing to restore into the LIVE database ({$into}). Use --into=<scratch_db> for a drill, or --force to override.\n");
    exit(1);
}

$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
    Config::string('DB_HOST', 'localhost'),
    Config::int('DB_PORT', 3306),
    $into,
    Config::string('DB_CHARSET', 'utf8mb4')
);
try {
    $pdo = new PDO($dsn, Config::string('DB_USER'), Config::string('DB_PASS', ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    BackupService::restoreInto($file, $pdo);
} catch (Throwable $e) {
    fwrite(STDERR, "Restore failed: " . $e->getMessage() . "\n");
    exit(1);
}

$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
fwrite(STDOUT, sprintf("Restored %s into '%s' — %d tables.\n", basename($file), $into, count($tables)));
