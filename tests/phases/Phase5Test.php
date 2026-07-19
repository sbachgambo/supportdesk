<?php
declare(strict_types=1);

/**
 * Phase 5 test (§17.1) — Tickets.
 * Exit criteria: first-response stamping, SLA re-grade on priority change, reopen
 * semantics, internal-note isolation. Plus create/auto-assign/canned and the
 * MessageVisibility gate (D2) across staff vs customer/anonymous views.
 */

require __DIR__ . '/../lib.php';

$root = dirname(__DIR__, 2);
if (!defined('P3A_ROOT')) {
    define('P3A_ROOT', $root);
}
require $root . '/vendor/autoload.php';

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\Dispatch;
use App\Core\Request;
use App\Core\Session;
use App\Models\CannedResponse;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Security\MessageVisibility;
use App\Services\SlaCalculator;
use App\Services\TicketService;

$envTesting = $root . '/.env.testing';
if (!is_file($envTesting)) {
    T::note('.env.testing not found — SKIPPING Phase 5');
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

$agent = ['name' => 'Agent One', 'email' => 'agent1@p3a-support.com.ng', 'role' => 'agent'];
$base = ['subject' => 'Cannot log in', 'description' => 'I get an error on login.', 'customer_email' => 'jane@example.com', 'customer_name' => 'Jane Doe'];

// ── create + auto-assign + SLA at create ─────────────────────────────────────
T::suite('Phase 5: create + auto-assign + SLA');
$r1 = TicketService::create($base + ['priority' => 'high'], 'agent', $agent);
T::ok($r1['ok'], 'ticket created');
$t1 = $r1['ticket'];
T::ok(preg_match('/^TKT-\d{4}-\d{4}$/', (string) $t1['ticket_id']) === 1, 'ticket_id format TKT-YYYY-NNNN');
T::eq('open', $t1['status'], 'new ticket is open');
T::ok(!empty($t1['sla_response_deadline']) && !empty($t1['sla_resolution_deadline']), 'SLA deadlines set at create');
T::ok(in_array($t1['assigned_to'], ['agent1@p3a-support.com.ng', 'agent2@p3a-support.com.ng'], true), 'auto-assigned to an active agent');
$firstAssignee = $t1['assigned_to'];
T::eq(1, Message::countForTicket((string) $t1['ticket_id']), 'initial customer message created');

// least-busy: second ticket should go to the OTHER agent
$r2 = TicketService::create($base + ['priority' => 'normal', 'subject' => 'Second'], 'agent', $agent);
T::ok($r2['ticket']['assigned_to'] !== $firstAssignee, 'second auto-assign goes to the less-busy agent');

// ── first-response stamping ──────────────────────────────────────────────────
T::suite('Phase 5: first-response stamping');
$tid = (string) $t1['ticket_id'];
T::ok(Ticket::find($tid)['first_response_at'] === null, 'first_response_at null before any reply');
TicketService::reply($tid, 'Thanks, we are looking into it.', $agent);
$afterReply = Ticket::find($tid);
T::ok($afterReply['first_response_at'] !== null, 'first_response_at stamped on first agent reply');
T::eq('pending', $afterReply['status'], 'agent reply on an OPEN ticket moves it to pending (prototype parity)');
$stamp = $afterReply['first_response_at'];
TicketService::reply($tid, 'Any update on your side?', $agent);
T::eq($stamp, Ticket::find($tid)['first_response_at'], 'second reply does NOT move first_response_at');

// an internal note does NOT count as a first response
$r3 = TicketService::create($base + ['subject' => 'note-test'], 'agent', $agent);
$tid3 = (string) $r3['ticket']['ticket_id'];
TicketService::addInternalNote($tid3, 'Customer is a VIP — handle carefully.', $agent);
T::ok(Ticket::find($tid3)['first_response_at'] === null, 'internal note does not stamp first response');

// ── internal-note isolation via MessageVisibility (D2) ───────────────────────
T::suite('Phase 5: internal-note isolation (D2)');
$staffMsgs = MessageVisibility::for('agent', $tid3);
$custMsgs  = MessageVisibility::for('customer', $tid3);
$anonMsgs  = MessageVisibility::for('anonymous', $tid3);
$hasNote = static fn(array $msgs): bool => array_filter($msgs, static fn($m) => ($m['from_type'] ?? '') === 'note') !== [];
T::ok($hasNote($staffMsgs), 'staff view includes the internal note');
T::ok(!$hasNote($custMsgs), 'customer view EXCLUDES the internal note');
T::ok(!$hasNote($anonMsgs), 'anonymous view EXCLUDES the internal note');
$noteText = 'Customer is a VIP — handle carefully.';
$custBlob = json_encode($custMsgs);
T::ok(!str_contains((string) $custBlob, $noteText), 'note text never appears in the customer payload');
T::ok(MessageVisibility::seesInternal('admin') && !MessageVisibility::seesInternal('customer'), 'seesInternal: staff yes, customer no');

// system messages also hidden from customers
Message::add($tid3, 'system', 'SLA breach recorded', 'system', '', false);
$custMsgs2 = MessageVisibility::for('customer', $tid3);
T::ok(array_filter($custMsgs2, static fn($m) => ($m['from_type'] ?? '') === 'system') === [], 'system messages hidden from customers');

// ── SLA re-grade on priority change (§3) ─────────────────────────────────────
T::suite('Phase 5: SLA recalc + re-grade on priority change (§3)');
$r4 = TicketService::create($base + ['priority' => 'low', 'subject' => 'old ticket'], 'agent', $agent);
$tid4 = (string) $r4['ticket']['ticket_id'];
// Backdate creation 1000 minutes; recompute low deadlines from that origin (response 1440 → still future).
$past = gmdate('Y-m-d H:i:s', time() - 1000 * 60);
$lowDeadlines = SlaCalculator::deadlines('low', $past);
Db::update('tickets', [
    'created_at' => $past,
    'sla_response_deadline' => $lowDeadlines['response'],
    'sla_resolution_deadline' => $lowDeadlines['resolution'],
], 'ticket_id = :t', [':t' => $tid4]);
T::eq('pending', Ticket::find($tid4)['sla_response_status'], 'low-priority response still pending (deadline in future)');

// Escalate to urgent → response deadline recomputed from ORIGINAL creation (created+30m = ~970m ago) → breached.
TicketService::changePriority($tid4, 'urgent', $agent);
$esc = Ticket::find($tid4);
T::eq('urgent', $esc['priority'], 'priority updated to urgent');
T::ok(strtotime((string) $esc['sla_response_deadline']) < time(), 'response deadline recalculated from original creation (now in the past)');
T::eq('breached', $esc['sla_response_status'], 'already-passed response milestone re-graded to breached');

// ── resolve + reopen semantics (§3) ──────────────────────────────────────────
T::suite('Phase 5: resolve + reopen (§3)');
TicketService::changeStatus($tid, 'resolved', $agent);
$resolved = Ticket::find($tid);
T::eq('resolved', $resolved['status'], 'status resolved');
T::ok($resolved['resolved_at'] !== null, 'resolved_at stamped');
T::ok(in_array($resolved['sla_resolution_status'], ['met', 'breached'], true), 'resolution SLA graded on resolve');

TicketService::changeStatus($tid, 'open', $agent);
$reopened = Ticket::find($tid);
T::eq('open', $reopened['status'], 'reopened to open');
T::ok($reopened['resolved_at'] === null, 'reopen clears resolved_at');
T::eq('pending', $reopened['sla_resolution_status'], 'reopen resets resolution SLA to pending');

// ── canned response substitution (§3) ────────────────────────────────────────
T::suite('Phase 5: canned response substitution');
$canned = CannedResponse::find('CAN-001');
$rendered = TicketService::renderCanned((string) $canned['body'], $t1, 'Agent One');
T::ok(!str_contains($rendered, '{customerName}') && !str_contains($rendered, '{ticketId}'), 'placeholders substituted');
T::ok(str_contains($rendered, (string) $t1['ticket_id']), 'ticketId substituted with the real id');

// ── gateway round-trip (agent) ───────────────────────────────────────────────
T::suite('Phase 5: gateway round-trip');
$customer = User::findByEmail('customer@example.com');
$agentUser = User::findByEmail('agent1@p3a-support.com.ng');
Session::start((int) $agentUser['id'], (string) $agentUser['email'], 'agent', '203.0.113.30', 'ua', true);
$server = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api', 'REMOTE_ADDR' => '203.0.113.30', 'CONTENT_TYPE' => 'application/json'];
$call = static function (string $action, array $payload) use ($server): array {
    $req = new Request(post: ['action' => $action, 'payload' => $payload, 'csrf' => Csrf::token()], server: $server);
    $resp = Dispatch::handle($req);
    return [$resp->status(), json_decode($resp->body(), true)];
};
[$st, $body] = $call('createTicket', ['subject' => 'Via gateway', 'description' => 'Body here', 'customer_email' => 'bob@example.com', 'priority' => 'normal']);
T::eq(200, $st, 'createTicket via gateway → 200');
$gwTid = $body['data']['ticket']['ticket_id'] ?? '';
T::ok($gwTid !== '', 'gateway returns the new ticket id');
[$st, $body] = $call('getTicket', ['ticket_id' => $gwTid]);
T::eq(200, $st, 'getTicket via gateway → 200');
T::ok(isset($body['data']['messages']), 'getTicket returns messages (via MessageVisibility)');

// getTickets paged queue — exercises Ticket::paged (repeated-named-param safe under
// EMULATE_PREPARES=false). Regression guard: this path was unhit before the workbench.
[$st, $body] = $call('getTickets', ['page' => 1, 'status' => '']);
T::eq(200, $st, 'getTickets (unfiltered) → 200');
T::ok(($body['data']['total'] ?? -1) >= 1 && isset($body['data']['rows']), 'getTickets returns a paged queue');
[$st, $body] = $call('getTickets', ['page' => 1, 'status' => 'open']);
T::eq(200, $st, 'getTickets (status filter) → 200');
T::ok(isset($body['data']['total']), 'filtered getTickets returns a total');
[$st, $body] = $call('getDashboardData', []);
T::eq(200, $st, 'getDashboardData → 200');
T::ok(isset($body['data']['kpis']['open']), 'dashboard KPIs returned');

// validation error → 422 with a safe message
[$st, $body] = $call('createTicket', ['subject' => '', 'description' => 'x', 'customer_email' => 'bad']);
T::eq(422, $st, 'invalid createTicket → 422 (ValidationException)');
T::ok(($body['ok'] ?? true) === false && !empty($body['error']), '422 carries a safe error message');

exit(T::summary());
