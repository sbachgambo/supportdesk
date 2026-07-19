<?php
declare(strict_types=1);

/** Cron: nightly housekeeping (§14). */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\CleanupService;

$c = CleanupService::run();
fwrite(STDOUT, sprintf(
    "[%s] cleanup: rate_limits=%d sessions=%d reset_tokens=%d notifications=%d logs=%d\n",
    gmdate('c'),
    $c['rate_limits'],
    $c['sessions'],
    $c['reset_tokens'],
    $c['notifications'],
    $c['logs']
));
