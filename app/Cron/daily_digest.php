<?php
declare(strict_types=1);

/** Cron: daily digest to active admins at 08:00 (§14). */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\DigestService;

$sent = DigestService::run();
fwrite(STDOUT, sprintf("[%s] daily_digest: sent=%d\n", gmdate('c'), $sent));
