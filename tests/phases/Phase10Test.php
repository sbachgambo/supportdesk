<?php
declare(strict_types=1);

/**
 * Phase 10 test (§17.1) — Automation.
 * Exit criteria: sender-mismatch creates a NEW ticket (never threads); the SLA monitor's
 * second run is a no-op; backup encrypts and restore_backup restores into a scratch DB.
 * Plus mailer validation/suppression/header-injection, digest, and cleanup.
 */

require __DIR__ . '/../lib.php';

$root = dirname(__DIR__, 2);
if (!defined('P3A_ROOT')) {
    define('P3A_ROOT', $root);
}
require $root . '/vendor/autoload.php';

use App\Core\Config;
use App\Core\Db;
use App\Models\AppConfig;
use App\Models\Message;
use App\Models\Ticket;
use App\Services\AutoCloseService;
use App\Services\BackupService;
use App\Services\CleanupService;
use App\Services\DigestService;
use App\Services\InboundMail;
use App\Services\Mailer;
use App\Services\SlaCalculator;
use App\Services\SlaMonitor;
use App\Services\TicketService;

$envTesting = $root . '/.env.testing';
if (!is_file($envTesting)) {
    T::note('.env.testing not found — SKIPPING Phase 10');
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
$cleanupFiles = [];

// ── Mailer (§10.16) ──────────────────────────────────────────────────────────
T::suite('Phase 10: mailer (§10.16)');
Db::query('DELETE FROM mail_log');
T::eq('failed', Mailer::send("a@b.com\r\nBcc: evil@x.com", 'Hi', '<p>x</p>'), 'CR/LF in recipient → header injection rejected');
T::eq('failed', Mailer::send('a@b.com', "Subj\r\nX-Evil: 1", '<p>x</p>'), 'CR/LF in subject → rejected');
T::eq('failed', Mailer::send('not-an-email', 'Hi', '<p>x</p>'), 'invalid recipient → failed');
T::eq('suppressed', Mailer::send('demo@example.com', 'Hi', '<p>x</p>'), 'suppressed domain → not sent');
T::eq('sent', Mailer::send('real@othercorp.com', 'Hi', '<p>x</p>'), 'valid non-suppressed recipient → sent (pretend)');
T::ok((int) Db::scalar('SELECT COUNT(*) FROM mail_log') === 5, 'every send is recorded in mail_log');
T::ok((int) Db::scalar("SELECT COUNT(*) FROM mail_log WHERE status='failed'") === 3, 'failures logged');
T::ok(str_contains(Mailer::template('Heading', '<p>body</p>'), 'Heading'), 'branded template wraps the content');

// ── InboundMail — the sender-match rule (§10.17, D3) ─────────────────────────
T::suite('Phase 10: inbound sender-match rule (§10.17)');
$owner = 'owner@othercorp.com';
$r = TicketService::create(['subject' => 'Original issue', 'description' => 'help', 'customer_email' => $owner, 'priority' => 'normal'], 'email');
$tid = (string) $r['ticket']['ticket_id'];
$before = Message::countForTicket($tid);

// matching sender + ticket id in subject → threads
$res = InboundMail::processMessage($owner, 'Owner', "Re: [{$tid}] more info", 'Here is more detail');
T::eq(InboundMail::RESULT_THREADED, $res['result'], 'matching sender threads onto the ticket');
T::eq($before + 1, Message::countForTicket($tid), 'a reply message was added to the original ticket');

// MISMATCH sender + SAME ticket id → new ticket, never threads
$ticketsBefore = (int) Db::scalar('SELECT COUNT(*) FROM tickets');
$msgsBefore = Message::countForTicket($tid);
$res = InboundMail::processMessage('attacker@evil.com', 'Attacker', "Re: [{$tid}] give me access", 'let me in');
T::eq(InboundMail::RESULT_MISMATCH_CREATED, $res['result'], 'sender MISMATCH creates a NEW ticket (never threads)');
T::eq($ticketsBefore + 1, (int) Db::scalar('SELECT COUNT(*) FROM tickets'), 'a brand-new ticket was created');
T::eq($msgsBefore, Message::countForTicket($tid), 'the stranger did NOT post into the original ticket');
T::ok($res['ticket_id'] !== $tid, 'the new ticket has a different id');

// no ticket id → new ticket
$res = InboundMail::processMessage('someone@othercorp.com', 'Someone', 'A totally new problem', 'body');
T::eq(InboundMail::RESULT_CREATED, $res['result'], 'no ticket id in subject → new ticket');

// machine sender → skipped (loop guard)
$res = InboundMail::processMessage('mailer-daemon@othercorp.com', '', 'bounce', 'body');
T::eq(InboundMail::RESULT_SKIPPED, $res['result'], 'machine sender (mailer-daemon@) skipped');
T::ok(InboundMail::extractTicketId("Re: {$tid} thanks") === $tid, 'ticket id extracted from a subject');

// ── SLA monitor idempotency (§14) ────────────────────────────────────────────
T::suite('Phase 10: SLA monitor idempotency (§14)');
// a ticket already past both deadlines, still pending
$past = gmdate('Y-m-d H:i:s', time() - 3600);
Db::insert('tickets', [
    'ticket_id' => 'TKT-2026-8000', 'subject' => 'overdue', 'description' => 'x',
    'customer_email' => 'c@othercorp.com', 'priority' => 'high', 'status' => 'open',
    'channel' => 'email', 'assigned_to' => 'agent1@p3a-support.com.ng',
    'created_at' => $past, 'updated_at' => $past,
    'sla_response_deadline' => $past, 'sla_resolution_deadline' => $past,
    'sla_response_status' => 'pending', 'sla_resolution_status' => 'pending',
]);
$run1 = SlaMonitor::run();
T::ok($run1['response_breached'] >= 1 && $run1['resolution_breached'] >= 1, 'first run grades overdue milestones as breached');
$auditAfter1 = (int) Db::scalar("SELECT COUNT(*) FROM audit_log WHERE action='sla_breach'");
$notifAfter1 = (int) Db::scalar("SELECT COUNT(*) FROM notifications WHERE type='sla_breach'");
T::ok($auditAfter1 >= 2 && $notifAfter1 >= 1, 'breach audited + assignee notified');

$run2 = SlaMonitor::run();
T::eq(0, $run2['response_breached'] + $run2['resolution_breached'], 'SECOND run is a NO-OP (idempotent)');
T::eq($auditAfter1, (int) Db::scalar("SELECT COUNT(*) FROM audit_log WHERE action='sla_breach'"), 'no duplicate audit rows on re-run');
T::eq($notifAfter1, (int) Db::scalar("SELECT COUNT(*) FROM notifications WHERE type='sla_breach'"), 'no duplicate notifications on re-run');

// ── Backup: encrypt + restore into a scratch DB (§14) ────────────────────────
T::suite('Phase 10: backup encrypt + restore (§14)');
$backupPath = BackupService::createBackup();
$cleanupFiles[] = $backupPath;
T::ok(is_file($backupPath) && str_ends_with($backupPath, '.gz.enc'), 'encrypted backup file written (.gz.enc)');
$rawBytes = (string) file_get_contents($backupPath);
T::ok(!str_contains($rawBytes, 'CREATE TABLE') && !str_contains($rawBytes, 'INSERT INTO'), 'backup bytes are encrypted (no plaintext SQL visible)');
$decrypted = BackupService::decrypt($backupPath);
T::ok(str_contains($decrypted, 'CREATE TABLE') && str_contains($decrypted, 'users'), 'decrypt() recovers the SQL dump');

// restore into a scratch DB and verify tables
$scratch = 'p3a_restore_test';
$pdo->exec("DROP DATABASE IF EXISTS `{$scratch}`");
$pdo->exec("CREATE DATABASE `{$scratch}` CHARACTER SET utf8mb4");
$scratchPdo = new PDO(
    'mysql:host=' . Config::string('DB_HOST') . ';dbname=' . $scratch . ';charset=utf8mb4',
    Config::string('DB_USER'), Config::string('DB_PASS', ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
BackupService::restoreInto($backupPath, $scratchPdo);
$restoredTables = $scratchPdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
T::eq(18, count($restoredTables), 'restore into a scratch DB recreates all 18 tables');
T::ok((int) $scratchPdo->query('SELECT COUNT(*) FROM users')->fetchColumn() >= 4, 'restored data is present (users)');
$pdo->exec("DROP DATABASE IF EXISTS `{$scratch}`");

// ── Cleanup (§14) ────────────────────────────────────────────────────────────
T::suite('Phase 10: cleanup (§14)');
$old = gmdate('Y-m-d H:i:s', time() - 86400);
Db::insert('rate_limits', ['bucket' => 'X', 'bucket_key' => 'k', 'hits' => 1, 'window_start' => $old, 'expires_at' => $old]);
Db::insert('password_resets', ['token_hash' => str_repeat('a', 64), 'email' => 'x@x.com', 'name' => 'x', 'expires_at' => $old]);
$c = CleanupService::run();
T::ok($c['rate_limits'] >= 1, 'expired rate-limit rows purged');
T::ok($c['reset_tokens'] >= 1, 'expired reset tokens purged');
T::ok((int) Db::scalar("SELECT COUNT(*) FROM rate_limits WHERE bucket_key='k'") === 0, 'the expired bucket is gone');

// ── Digest (§14) ─────────────────────────────────────────────────────────────
T::suite('Phase 10: daily digest (§14)');
$d = DigestService::gather();
T::ok(array_key_exists('new_24h', $d) && array_key_exists('breaches', $d), 'digest gathers the expected figures');
$sent = DigestService::run();
T::ok($sent >= 1, 'digest sent to at least one active admin (pretend transport)');

// ── Customer emails (#1): receipt on create, notification on reply ───────────
T::suite('Phase 10: customer emails');
Db::query('DELETE FROM mail_log');
$agentActor = ['name' => 'Agent', 'email' => 'agent1@p3a-support.com.ng', 'role' => 'agent'];
$ce = TicketService::create(['subject' => 'Email me', 'description' => 'd', 'customer_email' => 'cust@othercorp.com', 'customer_name' => 'Cus Tomer', 'priority' => 'normal'], 'web_form');
$ceId = (string) $ce['ticket']['ticket_id'];
$row = Db::queryOne("SELECT status FROM mail_log WHERE recipient = 'cust@othercorp.com' AND subject LIKE 'We received your request%' ORDER BY id DESC LIMIT 1");
T::ok($row !== null && $row['status'] === 'sent', 'submission receipt emailed to the customer (reference id)');
TicketService::reply($ceId, 'We are on it', $agentActor);
$row = Db::queryOne("SELECT status FROM mail_log WHERE recipient = 'cust@othercorp.com' AND subject LIKE 'New reply on your request%' ORDER BY id DESC LIMIT 1");
T::ok($row !== null && $row['status'] === 'sent', 'agent reply notifies the customer by email');
$mailsBefore = (int) Db::scalar("SELECT COUNT(*) FROM mail_log WHERE recipient = 'cust@othercorp.com'");
TicketService::addInternalNote($ceId, 'secret note', $agentActor);
T::eq($mailsBefore, (int) Db::scalar("SELECT COUNT(*) FROM mail_log WHERE recipient = 'cust@othercorp.com'"), 'internal note sends NO customer email');

// ── Auto-close (#2): resolved >N days → closed + satisfaction request ─────────
T::suite('Phase 10: auto-close + satisfaction request');
$ac = TicketService::create(['subject' => 'Close me', 'description' => 'd', 'customer_email' => 'close@othercorp.com', 'customer_name' => 'C', 'priority' => 'normal'], 'web_form');
$acId = (string) $ac['ticket']['ticket_id'];
TicketService::changeStatus($acId, 'resolved', $agentActor);
Db::query('UPDATE tickets SET resolved_at = :r WHERE ticket_id = :t', [':r' => gmdate('Y-m-d H:i:s', time() - 8 * 86400), ':t' => $acId]);
$closed = AutoCloseService::run(); // auto_close_days defaults to 7
T::ok($closed >= 1, 'auto-close closes tickets resolved more than 7 days ago');
T::eq('closed', (string) Db::scalar('SELECT status FROM tickets WHERE ticket_id = :t', [':t' => $acId]), 'the stale resolved ticket is now closed');
T::ok(Db::queryOne("SELECT 1 FROM mail_log WHERE recipient = 'close@othercorp.com' AND subject LIKE 'How did we do%'") !== null, 'unrated ticket gets a satisfaction-request email');
T::eq(0, AutoCloseService::run(), 'second auto-close run is a no-op (idempotent)');

// ── Business-hours SLA clock (#3) ────────────────────────────────────────────
T::suite('Phase 10: business-hours SLA clock');
AppConfig::set('business_hours_start', '09:00');
AppConfig::set('business_hours_end', '17:00');
AppConfig::set('business_days', '1,2,3,4,5');
$tz = new DateTimeZone(Config::string('APP_TIMEZONE', 'UTC'));
$utcTz = new DateTimeZone('UTC');
// Friday 16:30 local + 120 business minutes = 30 min Friday + 90 min Monday → Monday 10:30
$fri = (new DateTime('2026-07-24 16:30:00', $tz))->setTimezone($utcTz)->format('Y-m-d H:i:s');
$expMon = (new DateTime('2026-07-27 10:30:00', $tz))->setTimezone($utcTz)->format('Y-m-d H:i:s');
T::eq($expMon, SlaCalculator::addBusinessMinutes($fri, 120), 'Friday 16:30 + 120 business min → Monday 10:30 (weekend not billed)');
// Saturday arrival starts the clock Monday 09:00
$sat = (new DateTime('2026-07-25 12:00:00', $tz))->setTimezone($utcTz)->format('Y-m-d H:i:s');
$expMon2 = (new DateTime('2026-07-27 10:00:00', $tz))->setTimezone($utcTz)->format('Y-m-d H:i:s');
T::eq($expMon2, SlaCalculator::addBusinessMinutes($sat, 60), 'weekend arrival starts the clock Monday morning');
// the toggle changes what deadlines() produces (resolution crosses the weekend)
AppConfig::set('sla_business_hours_only', '1');
$bizDl = SlaCalculator::deadlines('urgent', $fri);
AppConfig::set('sla_business_hours_only', '0');
$calDl = SlaCalculator::deadlines('urgent', $fri);
T::ok($bizDl['resolution'] !== $calDl['resolution'], 'business-hours toggle changes computed deadlines');
T::eq(gmdate('Y-m-d H:i:s', strtotime($fri) + ((int) AppConfig::get('sla_resolution_urgent', '0')) * 60), $calDl['resolution'], 'toggle off → plain calendar deadlines (unchanged behaviour)');

foreach ($cleanupFiles as $f) {
    if (is_file($f)) { unlink($f); }
}

exit(T::summary());
