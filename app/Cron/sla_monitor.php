<?php
declare(strict_types=1);

/**
 * Cron: SLA breach monitor (§14). Every 30 min. Idempotent — a second run in the
 * same window is a no-op. Unreachable over HTTP even if placed in the webroot.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\SlaMonitor;

$counts = SlaMonitor::run();
fwrite(STDOUT, sprintf(
    "[%s] sla_monitor: response_breached=%d resolution_breached=%d\n",
    gmdate('c'),
    $counts['response_breached'],
    $counts['resolution_breached']
));
