<?php
declare(strict_types=1);

/**
 * Runs every suite in order and exits non-zero if any fail (§17).
 *   php tests/run_all.php            → static suite + all phase tests + security suite
 *   php tests/run_all.php --static   → static suite only (fast, no DB)
 *   php tests/run_all.php --phase 1  → static suite + Phase 1 test only
 *
 * Each suite runs in its own PHP process so a fatal in one cannot mask another.
 */

$root = dirname(__DIR__);
$args = $_SERVER['argv'];
$staticOnly = in_array('--static', $args, true);
$phaseIdx = array_search('--phase', $args, true);
$onlyPhase = $phaseIdx !== false ? ($args[$phaseIdx + 1] ?? null) : null;

$suites = [$root . '/tests/StaticSuite.php'];

if (!$staticOnly) {
    if ($onlyPhase !== null) {
        $suites[] = "$root/tests/phases/Phase{$onlyPhase}Test.php";
    } else {
        foreach (glob("$root/tests/phases/Phase*Test.php") ?: [] as $p) {
            $suites[] = $p;
        }
        if (is_file("$root/tests/smoke_http.php")) {
            $suites[] = "$root/tests/smoke_http.php";
        }
        if (is_file("$root/tests/SecuritySuite.php")) {
            $suites[] = "$root/tests/SecuritySuite.php";
        }
    }
}

$failed = 0;
foreach ($suites as $suite) {
    if (!is_file($suite)) {
        continue;
    }
    echo "\n\033[1m\033[36m▶ " . basename($suite) . "\033[0m\n";
    passthru('php ' . escapeshellarg($suite), $code);
    if ($code !== 0) {
        $failed++;
    }
}

echo "\n" . str_repeat('═', 50) . "\n";
if ($failed === 0) {
    echo "\033[32m\033[1m✓ ALL SUITES GREEN\033[0m\n";
    exit(0);
}
echo "\033[31m\033[1m✗ {$failed} SUITE(S) FAILED\033[0m\n";
exit(1);
