<?php
declare(strict_types=1);

/**
 * Phase 8 test (§17.1) — Reports.
 * Exit criterion: aggregate consistency — status/priority/channel distributions each
 * sum EXACTLY to the created count. Plus zero-filled volume, KPI/SLA maths, period
 * filtering, RFC 4180 CSV quoting, and the access-checked CSV export route.
 */

require __DIR__ . '/../lib.php';

$root = dirname(__DIR__, 2);
if (!defined('P3A_ROOT')) {
    define('P3A_ROOT', $root);
}
require $root . '/vendor/autoload.php';

use App\Core\Config;
use App\Core\Db;
use App\Core\Request;
use App\Core\Session;
use App\Models\User;
use App\Services\ReportService;

$envTesting = $root . '/.env.testing';
if (!is_file($envTesting)) {
    T::note('.env.testing not found — SKIPPING Phase 8');
    exit(T::summary());
}
Config::load($envTesting);

try {
    $pdo = Db::connect();
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $pdo->exec("DROP TABLE IF EXISTS `$t`");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $pdo->exec((string) file_get_contents("$root/database/schema.sql"));
    $pdo->exec((string) file_get_contents("$root/database/seed.sql"));
} catch (Throwable $e) {
    T::ok(false, 'schema/seed load: ' . $e->getMessage());
    exit(T::summary());
}

// ── seed a spread of tickets across days/statuses/priorities/channels ────────
$statuses = ['open', 'pending', 'resolved', 'closed'];
$priorities = ['urgent', 'high', 'normal', 'low'];
$channels = ['web_form', 'agent', 'email', 'status_page', 'widget'];
$insideCount = 0;
$mk = static function (int $daysAgo, string $status, string $priority, string $channel) use (&$insideCount): void {
    static $n = 0;
    $n++;
    $created = gmdate('Y-m-d H:i:s', time() - $daysAgo * 86400);
    $resolvedAt = in_array($status, ['resolved', 'closed'], true) ? gmdate('Y-m-d H:i:s', time() - $daysAgo * 86400 + 7200) : null;
    $slaRes = $status === 'resolved' ? 'met' : ($status === 'closed' ? 'breached' : 'pending');
    Db::insert('tickets', [
        'ticket_id' => sprintf('TKT-2026-%04d', $n),
        'subject' => "Subject {$n}", 'description' => 'body',
        'customer_email' => "c{$n}@example.com", 'priority' => $priority, 'status' => $status,
        'channel' => $channel, 'assigned_to' => 'agent1@p3a-support.com.ng',
        'created_at' => $created, 'updated_at' => $created, 'resolved_at' => $resolvedAt,
        'sla_response_deadline' => $created, 'sla_resolution_deadline' => $created,
        'sla_response_status' => 'met', 'sla_resolution_status' => $slaRes,
    ]);
    if ($daysAgo < 30) {
        $insideCount++;
    }
};
// inside 30-day window
$mk(1, 'open', 'urgent', 'web_form');
$mk(2, 'pending', 'high', 'email');
$mk(3, 'resolved', 'normal', 'agent');
$mk(5, 'closed', 'low', 'widget');
$mk(10, 'resolved', 'high', 'web_form');
$mk(29, 'open', 'normal', 'status_page');
// outside 30-day window (should not count for period=30)
$mk(45, 'open', 'urgent', 'web_form');
$mk(80, 'resolved', 'low', 'email');

// ── aggregate consistency (exit criterion) ───────────────────────────────────
T::suite('Phase 8: aggregate consistency');
$sum = ReportService::summary(30);
$created = $sum['kpis']['created'];
T::eq($insideCount, $created, "created count matches tickets in the 30-day window ({$insideCount})");

$statusSum = array_sum($sum['distributions']['status']);
$prioritySum = array_sum($sum['distributions']['priority']);
$channelSum = array_sum($sum['distributions']['channel']);
T::eq($created, $statusSum, 'status distribution sums to created');
T::eq($created, $prioritySum, 'priority distribution sums to created');
T::eq($created, $channelSum, 'channel distribution sums to created');

// period filtering: 90-day window includes the older tickets, 7-day fewer
T::ok(ReportService::kpis(90)['created'] > $created, '90-day window includes older tickets');
T::ok(ReportService::kpis(7)['created'] <= $created, '7-day window is a subset');
T::eq(30, ReportService::normalizePeriod(30), 'valid period kept');
T::eq(30, ReportService::normalizePeriod(999), 'invalid period → default 30');

// ── zero-filled volume ───────────────────────────────────────────────────────
T::suite('Phase 8: volume-by-day (zero-filled)');
$vol = $sum['volume'];
T::eq(30, count($vol), 'volume has one entry per day in the window');
T::ok($vol[0]['count'] === 0 || is_int($vol[0]['count']), 'each day has an integer count');
$volSum = array_sum(array_map(static fn($p) => $p['count'], $vol));
T::eq($created, $volSum, 'volume totals equal the created count');
$hasZeroDay = array_filter($vol, static fn($p) => $p['count'] === 0) !== [];
T::ok($hasZeroDay, 'empty days are present and zero (not skipped)');

// ── KPI / SLA maths ──────────────────────────────────────────────────────────
T::suite('Phase 8: KPIs');
$k = $sum['kpis'];
T::ok($k['resolved'] >= 1, 'resolved count computed');
T::ok($k['avg_resolution_hours'] !== null && $k['avg_resolution_hours'] > 0, 'avg resolution hours computed');
// inside window: 2 resolved (met) + closed (breached) graded; met/(met+breached)
T::ok($k['sla_compliance_pct'] !== null && $k['sla_compliance_pct'] >= 0 && $k['sla_compliance_pct'] <= 100, 'SLA compliance is a valid percentage');

// ── agent performance ────────────────────────────────────────────────────────
T::suite('Phase 8: agent performance');
$agents = ReportService::agentPerformance(30);
T::ok(count($agents) >= 1, 'agent performance rows returned');
$a1 = null;
foreach ($agents as $a) {
    if ($a['email'] === 'agent1@p3a-support.com.ng') { $a1 = $a; }
}
T::ok($a1 !== null && (int) $a1['assigned'] === $insideCount, 'assigned count matches (all tickets assigned to agent1)');

// ── CSV export (RFC 4180) ────────────────────────────────────────────────────
T::suite('Phase 8: CSV export (RFC 4180)');
// a ticket with commas, quotes, and a newline in the subject
Db::insert('tickets', [
    'ticket_id' => 'TKT-2026-9999',
    'subject' => "Weird, \"quoted\"\nsubject", 'description' => 'b',
    'customer_email' => 'weird@example.com', 'priority' => 'normal', 'status' => 'open',
    'channel' => 'web_form', 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
    'sla_response_deadline' => gmdate('Y-m-d H:i:s'), 'sla_resolution_deadline' => gmdate('Y-m-d H:i:s'),
]);
$csv = ReportService::ticketsCsv(30);
T::ok(str_starts_with($csv, 'ticket_id,subject,'), 'CSV has a header row');
T::ok(str_contains($csv, "\r\n"), 'CSV uses CRLF line endings (RFC 4180)');
T::ok(str_contains($csv, '"Weird, ""quoted""' . "\n" . 'subject"'), 'fields with comma/quote/newline are quoted and quotes doubled');
$lines = explode("\r\n", trim($csv));
T::ok(count($lines) >= 2, 'CSV contains data rows');

// ── access control on getReports + CSV route ─────────────────────────────────
T::suite('Phase 8: access control');
$customer = User::findByEmail('customer@example.com');
Session::start((int) $customer['id'], (string) $customer['email'], 'customer', '203.0.113.60', 'ua', true);
$req = new Request(post: ['action' => 'getReports', 'payload' => ['period' => 30], 'csrf' => \App\Core\Csrf::token()],
    server: ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '203.0.113.60', 'CONTENT_TYPE' => 'application/json']);
$resp = \App\Core\Dispatch::handle($req);
T::eq(403, $resp->status(), 'customer calling getReports → 403 (agent-only)');

// CSV route: customer → 403, agent → 200 text/csv
$export = new App\Controllers\ExportController();
$csvReq = new Request(query: ['period' => '30'], server: ['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '203.0.113.60']);
T::eq(403, $export->ticketsCsv($csvReq)->status(), 'customer hitting CSV export → 403');
$agent = User::findByEmail('agent1@p3a-support.com.ng');
Session::start((int) $agent['id'], (string) $agent['email'], 'agent', '203.0.113.61', 'ua', true);
$resp = $export->ticketsCsv($csvReq);
T::eq(200, $resp->status(), 'agent CSV export → 200');
T::ok(str_contains($resp->headers()['Content-Type'] ?? '', 'text/csv'), 'CSV served as text/csv');
T::ok(str_contains($resp->headers()['Content-Disposition'] ?? '', 'attachment'), 'CSV served as an attachment');

exit(T::summary());
