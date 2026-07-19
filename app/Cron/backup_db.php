<?php
declare(strict_types=1);

/** Cron: encrypted DB backup with retention pruning (§14). */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Logger;
use App\Services\BackupService;

$start = microtime(true);
try {
    $path = BackupService::createBackup();
    $bytes = is_file($path) ? filesize($path) : 0;
    fwrite(STDOUT, sprintf(
        "[%s] backup_db: file=%s bytes=%d duration=%.2fs\n",
        gmdate('c'),
        basename($path),
        (int) $bytes,
        microtime(true) - $start
    ));
} catch (Throwable $e) {
    Logger::error('backup_failed', $e->getMessage());
    fwrite(STDERR, "[" . gmdate('c') . "] backup_db FAILED: " . $e->getMessage() . "\n");
    exit(1);
}
