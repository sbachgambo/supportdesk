<?php
declare(strict_types=1);

/**
 * Phase 9 test (§17.1) — Public surfaces.
 * Exit criteria: internal-note leak test; byte-identical errors for wrong-email vs
 * unknown-id on status lookup; widget header profile. Plus honeypot, priority
 * ceiling, input caps, rate limits, and category validation on submit.
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
use App\Core\SecurityHeaders;
use App\Core\Session;
use App\Controllers\PublicActions;
use App\Services\TicketService;

$envTesting = $root . '/.env.testing';
if (!is_file($envTesting)) {
    T::note('.env.testing not found — SKIPPING Phase 9');
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

Session::destroy(); // public surfaces are unauthenticated
$IP = '203.0.113.70';
$server = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api', 'REMOTE_ADDR' => $IP, 'CONTENT_TYPE' => 'application/json'];
$call = static function (string $action, array $payload, ?string $csrf = null) use ($server): array {
    $token = $csrf ?? Csrf::publicToken($action);
    $req = new Request(post: ['action' => $action, 'payload' => $payload, 'csrf' => $token], server: $server);
    $resp = Dispatch::handle($req);
    return [$resp->status(), $resp->body(), json_decode($resp->body(), true)];
};

// ── submit: CSRF, success, honeypot, ceiling, caps, category ─────────────────
T::suite('Phase 9: public submit (§3)');
[$st] = $call('submitTicket', ['customer_email' => 'a@example.com', 'subject' => 's', 'description' => 'd'], 'bad-token');
T::eq(419, $st, 'submit with bad public CSRF → 419');

[$st, , $body] = $call('submitTicket', ['customer_email' => 'jane@example.com', 'subject' => 'Help', 'description' => 'It broke', 'priority' => 'high']);
T::eq(200, $st, 'valid submit → 200');
$tid = $body['data']['ticket_id'] ?? '';
T::ok(str_starts_with($tid, 'TKT-'), 'submit returns a ticket id');
T::eq('web_form', Db::queryOne('SELECT channel FROM tickets WHERE ticket_id = :t', [':t' => $tid])['channel'], 'channel is web_form');

// priority ceiling: public may not select urgent → clamped to normal
[$st, , $body] = $call('submitTicket', ['customer_email' => 'jane@example.com', 'subject' => 'Urgent!', 'description' => 'now', 'priority' => 'urgent']);
$tid2 = $body['data']['ticket_id'];
T::eq('normal', Db::queryOne('SELECT priority FROM tickets WHERE ticket_id = :t', [':t' => $tid2])['priority'], 'public cannot set urgent (ceiling → normal)');

// honeypot: filled → pretend success, but NO ticket created
$before = (int) Db::scalar('SELECT COUNT(*) FROM tickets');
[$st, , $body] = $call('submitTicket', ['customer_email' => 'bot@example.com', 'subject' => 'spam', 'description' => 'x', 'website' => 'http://spam']);
T::eq(200, $st, 'honeypot submit returns 200 (pretends success)');
T::eq($before, (int) Db::scalar('SELECT COUNT(*) FROM tickets'), 'honeypot submission created NO ticket');

// invalid email → 422
[$st] = $call('submitTicket', ['customer_email' => 'notanemail', 'subject' => 's', 'description' => 'd']);
T::eq(422, $st, 'invalid email rejected');

// bad category → 422
[$st] = $call('submitTicket', ['customer_email' => 'jane@example.com', 'subject' => 's', 'description' => 'd', 'category_id' => 'CAT-NOPE']);
T::eq(422, $st, 'non-existent category rejected');

// input caps: overlong subject is truncated to 200 (not rejected)
[$st, , $body] = $call('submitTicket', ['customer_email' => 'jane@example.com', 'subject' => str_repeat('x', 500), 'description' => 'd']);
T::eq(200, $st, 'overlong subject accepted (capped)');
T::eq(200, mb_strlen(Db::queryOne('SELECT subject FROM tickets WHERE ticket_id = :t', [':t' => $body['data']['ticket_id']])['subject']), 'subject capped at 200 chars');

// rate limit: exhaust SUBMIT for one email → next is refused
Db::query('DELETE FROM rate_limits');
for ($i = 0; $i < 5; $i++) {
    $call('submitTicket', ['customer_email' => 'flood@example.com', 'subject' => "s{$i}", 'description' => 'd']);
}
[$st] = $call('submitTicket', ['customer_email' => 'flood@example.com', 'subject' => 's6', 'description' => 'd']);
T::eq(422, $st, '6th submission in an hour from one email is rate-limited');

// ── status lookup: byte-identical errors + note isolation ────────────────────
T::suite('Phase 9: status lookup (byte-identical + note isolation)');
Db::query('DELETE FROM rate_limits');
// build a ticket with an internal note + system message
$r = TicketService::create(['subject' => 'Status me', 'description' => 'please', 'customer_email' => 'owner@example.com', 'priority' => 'normal'], 'web_form');
$lookTid = (string) $r['ticket']['ticket_id'];
$agent = ['name' => 'Agent One', 'email' => 'agent1@p3a-support.com.ng', 'role' => 'agent'];
TicketService::reply($lookTid, 'We are on it.', $agent);
TicketService::addInternalNote($lookTid, 'SECRET internal note — do not disclose', $agent);
\App\Models\Message::add($lookTid, 'system', 'SYSTEM breach marker', 'system', '', false);

// correct id + email → ok, but no internal/system content
[$st, $raw, $body] = $call('checkTicketStatus', ['ticket_id' => $lookTid, 'email' => 'owner@example.com']);
T::eq(200, $st, 'correct id + email → 200');
T::ok(!str_contains($raw, 'SECRET internal note'), 'internal note NEVER exposed in status lookup');
T::ok(!str_contains($raw, 'SYSTEM breach marker'), 'system message NEVER exposed in status lookup');
T::ok(str_contains($raw, 'We are on it.'), 'customer-visible agent reply IS shown');

// wrong email vs unknown id → BYTE-IDENTICAL error
Db::query('DELETE FROM rate_limits');
[, $wrongEmail] = $call('checkTicketStatus', ['ticket_id' => $lookTid, 'email' => 'attacker@example.com']);
Db::query('DELETE FROM rate_limits');
[, $unknownId] = $call('checkTicketStatus', ['ticket_id' => 'TKT-2026-0000', 'email' => 'owner@example.com']);
T::eq($wrongEmail, $unknownId, 'wrong-email and unknown-id responses are BYTE-IDENTICAL');
T::ok(str_contains($wrongEmail, PublicActions::STATUS_GENERIC_ERROR), 'both are the generic lookup error');

// missing field → same generic error
Db::query('DELETE FROM rate_limits');
[$st, , $body] = $call('checkTicketStatus', ['ticket_id' => $lookTid, 'email' => '']);
T::ok(($body['ok'] ?? true) === false, 'missing email → failure');

// ── widget header profile (§10.12) ───────────────────────────────────────────
T::suite('Phase 9: widget header profile (§10.12)');
$w = SecurityHeaders::forRoute('widget');
T::ok(!isset($w['X-Frame-Options']), 'widget route: no X-Frame-Options (embeddable)');
T::ok(str_contains($w['Content-Security-Policy'], 'frame-ancestors *'), 'widget route: frame-ancestors *');
$d = SecurityHeaders::forRoute('default');
T::ok(str_contains($d['Content-Security-Policy'], "frame-ancestors 'self'"), 'default route stays frame-ancestors self');

exit(T::summary());
