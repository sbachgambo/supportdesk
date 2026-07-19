<?php
declare(strict_types=1);

/**
 * bin/verify_audit.php (§10.11) — walk the hash-chained audit log and report the
 * first broken link (row-level tampering or deletion), or confirm the chain is intact.
 *
 *   php bin/verify_audit.php [--testing]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
if (!defined('P3A_ROOT')) {
    define('P3A_ROOT', $root);
}
if (in_array('--testing', $_SERVER['argv'], true)) {
    define('P3A_ENV_FILE', $root . '/.env.testing');
}

require $root . '/app/bootstrap.php';

use App\Security\Audit;

$broken = Audit::verifyChain();
if ($broken === null) {
    fwrite(STDOUT, "\033[32mAudit chain intact.\033[0m\n");
    exit(0);
}
fwrite(STDERR, "\033[31mAudit chain BROKEN at row id {$broken}.\033[0m\n");
exit(1);
