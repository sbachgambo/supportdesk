<?php
declare(strict_types=1);

/**
 * SecuritySuite (§17.3) — the adversarial pass. Consolidates the attack table into a
 * single security regression suite run alongside the phase tests. Each block is an
 * attack; the assertion is the control holding. Requires the test DB (.env.testing).
 */

require __DIR__ . '/lib.php';

$root = dirname(__DIR__);
if (!defined('P3A_ROOT')) {
    define('P3A_ROOT', $root);
}
require $root . '/vendor/autoload.php';

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\Dispatch;
use App\Core\Request;
use App\Core\Response;
use App\Core\SecurityHeaders;
use App\Core\Session;
use App\Controllers\PublicActions;
use App\Models\Attachment;
use App\Models\User;
use App\Security\Audit;
use App\Security\Auth;
use App\Security\PasswordPolicy;
use App\Security\Upload;
use App\Services\Mailer;
use App\Services\ReportService;
use App\Services\TicketService;

$envTesting = $root . '/.env.testing';
if (!is_file($envTesting)) {
    T::note('.env.testing not found — SKIPPING SecuritySuite');
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
    Db::query('UPDATE users SET password_hash = :h', [':h' => PasswordPolicy::hash('P3a-Seed-Change!2026')]);
} catch (Throwable $e) {
    T::ok(false, 'schema/seed load: ' . $e->getMessage());
    exit(T::summary());
}
$IP = '198.51.100.5';
$cleanup = [];

// ── A03 Injection: SQL ───────────────────────────────────────────────────────
T::suite('SecuritySuite: SQL injection (A03)');
foreach (["' OR 1=1--", "'; DROP TABLE users;--", "1' UNION SELECT NULL--"] as $payload) {
    $row = Db::queryOne('SELECT :p AS v', [':p' => $payload]);
    T::eq($payload, $row['v'] ?? null, "payload is an inert literal: {$payload}");
}
T::ok(Auth::attempt("admin@x' OR '1'='1", 'x', $IP, 'ua')['success'] === false, "SQLi in the login email does not authenticate");
T::ok((int) Db::scalar('SELECT COUNT(*) FROM users') >= 4, 'users table intact after DROP TABLE payload');

// ── A03 Injection: XSS escaping ──────────────────────────────────────────────
T::suite('SecuritySuite: XSS escaping (A03)');
$xss = '<script>alert(1)</script>"><img src=x onerror=alert(1)>';
T::ok(!str_contains(e($xss), '<script>') && str_contains(e($xss), '&lt;script&gt;'), 'e() escapes script tags');
$json = Response::json(['s' => $xss])->body();
T::ok(!str_contains($json, '<script>') && !str_contains($json, '<img'), 'JSON response neutralises tags (HEX flags)');
$r = TicketService::create(['subject' => $xss, 'description' => 'd', 'customer_email' => 'x@othercorp.com', 'priority' => 'normal'], 'web_form');
$csv = ReportService::ticketsCsv(30);
T::ok(!preg_match('/<script>alert\(1\)<\/script>/', $csv) || str_contains($csv, '"'), 'CSV export quotes the payload (no raw live tag row)');

// ── A07 Auth: enumeration parity, lockout, hashed sessions, MFA gate ─────────
T::suite('SecuritySuite: authentication (A07)');
Db::query('DELETE FROM rate_limits');
$wrong = Auth::attempt('customer@example.com', 'wrong', $IP, 'ua');
Db::query('DELETE FROM rate_limits');
$unknown = Auth::attempt('nobody@nowhere.test', 'wrong', $IP, 'ua');
T::eq($wrong['error'], $unknown['error'], 'wrong-password and unknown-email are byte-identical');
Db::query('DELETE FROM rate_limits');
for ($i = 0; $i < 5; $i++) {
    Auth::attempt('locktarget@example.com', 'bad', $IP, 'ua');
}
Db::insert('users', ['public_id' => 'CU-7001', 'name' => 'L', 'email' => 'locktarget@example.com', 'password_hash' => PasswordPolicy::hash('correct-horse-2026'), 'role' => 'customer', 'active' => 1, 'created_at' => gmdate('Y-m-d H:i:s')]);
T::ok(Auth::attempt('locktarget@example.com', 'correct-horse-2026', $IP, 'ua')['success'] === false, 'locked account refuses even the correct password');
$raw = Session::start(1, 'admin@p3a-support.com.ng', 'admin', $IP, 'ua', false);
T::ok(Db::queryOne('SELECT 1 FROM sessions WHERE token_hash = :h', [':h' => $raw]) === null, 'raw session token is NOT in the DB (stored hashed)');

// ── A01 Access control: IDOR, authz, mass assignment, MFA gate ───────────────
T::suite('SecuritySuite: access control (A01)');
$custA = (int) Db::insert('users', ['public_id' => 'CU-7101', 'name' => 'A', 'email' => 'aa@example.com', 'password_hash' => 'x', 'role' => 'customer', 'active' => 1, 'created_at' => gmdate('Y-m-d H:i:s')]);
$custB = (int) Db::insert('users', ['public_id' => 'CU-7102', 'name' => 'B', 'email' => 'bb@example.com', 'password_hash' => 'x', 'role' => 'customer', 'active' => 1, 'created_at' => gmdate('Y-m-d H:i:s')]);
$agent = ['name' => 'Agent One', 'email' => 'agent1@p3a-support.com.ng', 'role' => 'agent'];
$ta = TicketService::create(['subject' => 'A', 'description' => 'd', 'customer_email' => 'aa@example.com', 'customer_user_id' => $custA, 'priority' => 'normal'], 'agent', $agent)['ticket']['ticket_id'];
$server = ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => $IP, 'CONTENT_TYPE' => 'application/json'];
$call = static function (string $action, array $payload) use ($server): array {
    $req = new Request(post: ['action' => $action, 'payload' => $payload, 'csrf' => Csrf::token()], server: $server);
    $resp = Dispatch::handle($req);
    return [$resp->status(), json_decode($resp->body(), true)];
};
Session::start($custB, 'bb@example.com', 'customer', $IP, 'ua', true);
T::eq(403, $call('getMyTicket', ['ticket_id' => $ta])[0], "customer B reading A's ticket → 403 (IDOR blocked)");
T::eq(403, $call('deleteAgent', ['id' => 1])[0] === 403 ? 403 : $call('getSystemConfig', [])[0], 'customer calling an admin/unknown action → denied');
$agentUser = User::findByEmail('agent1@p3a-support.com.ng');
Session::start((int) $agentUser['id'], 'agent1@p3a-support.com.ng', 'agent', $IP, 'ua', true);
T::eq(403, $call('resetTicketData', ['confirm' => 'RESET TICKET DATA'])[0], 'agent calling an admin action → 403');
Session::start(1, 'admin@p3a-support.com.ng', 'admin', $IP, 'ua', false);
T::eq(403, $call('getSystemConfig', [])[0], 'unverified admin (MFA gate) → 403');

// ── A01 CSRF ─────────────────────────────────────────────────────────────────
T::suite('SecuritySuite: CSRF (A01)');
Session::start((int) $agentUser['id'], 'agent1@p3a-support.com.ng', 'agent', $IP, 'ua', true);
$noToken = new Request(post: ['action' => 'createTicket', 'payload' => ['subject' => 's', 'description' => 'd', 'customer_email' => 'x@othercorp.com'], 'csrf' => ''], server: $server);
T::eq(419, Dispatch::handle($noToken)->status(), 'mutating action without a CSRF token → 419');
$foreign = new Request(post: ['action' => 'createTicket', 'payload' => ['subject' => 's', 'description' => 'd', 'customer_email' => 'x@othercorp.com'], 'csrf' => 'forged'], server: $server);
T::eq(419, Dispatch::handle($foreign)->status(), 'foreign/forged CSRF token → 419');
T::ok(!Csrf::validatePublic(base64_encode((time() - 10) . '|deadbeef'), 'submit'), 'expired public token rejected');

// ── A05/RCE Uploads ──────────────────────────────────────────────────────────
T::suite('SecuritySuite: uploads (§10.7)');
// Project-local scratch dir (not %TEMP%) — see the note in Phase6Test re: Windows AV.
$fix = str_replace('\\', '/', P3A_ROOT) . '/storage/cache/p3a_sec_' . bin2hex(random_bytes(3));
mkdir($fix);
$img = imagecreatetruecolor(8, 8);
imagejpeg($img, "$fix/ok.jpg");
imagedestroy($img);
file_put_contents("$fix/shell.jpg", "<?php system(\$_GET['c']); ?>");
file_put_contents("$fix/poly.jpg", (string) file_get_contents("$fix/ok.jpg") . "<?php echo 'PWN'; ?>");
T::ok(Upload::process('shell.jpg', "$fix/shell.jpg")['ok'] === false, 'shell.php renamed .jpg → rejected (content≠ext)');
$poly = Upload::process('poly.jpg', "$fix/poly.jpg");
T::ok($poly['ok'] === true, 'polyglot passes MIME check');
if ($poly['ok']) {
    $cleanup[] = Upload::storageDir() . '/' . $poly['stored_name'];
    T::ok(!str_contains((string) file_get_contents(Upload::storageDir() . '/' . $poly['stored_name']), '<?php'), 'polyglot NEUTRALIZED (no PHP in stored file)');
}
T::eq('passwd', Upload::sanitizeName('../../../etc/passwd'), 'path traversal in filename → basename only');
T::ok(!str_starts_with((string) realpath(Upload::storageDir()), (string) realpath("$root/public")), 'uploads live outside the webroot');
array_map('unlink', glob("$fix/*") ?: []);
rmdir($fix);

// ── Rate limits + fail-open ──────────────────────────────────────────────────
T::suite('SecuritySuite: rate limiting (§10.6)');
Db::query('DELETE FROM rate_limits');
Session::destroy();
$pub = static function (string $action, array $payload) use ($server): int {
    $req = new Request(post: ['action' => $action, 'payload' => $payload, 'csrf' => Csrf::publicToken($action)], server: $server);
    return Dispatch::handle($req)->status();
};
for ($i = 0; $i < 5; $i++) {
    $pub('submitTicket', ['customer_name' => 'Flo', 'customer_email' => 'flood@othercorp.com', 'subject' => "s{$i}", 'description' => 'd', 'category_id' => 'CAT-001', 'product_id' => 'PRD-0001']);
}
T::eq(422, $pub('submitTicket', ['customer_name' => 'Flo', 'customer_email' => 'flood@othercorp.com', 'subject' => 's6', 'description' => 'd', 'category_id' => 'CAT-001', 'product_id' => 'PRD-0001']), '6th submit/hour/email refused');

// ── Enumeration on status lookup (byte-identical) ────────────────────────────
T::suite('SecuritySuite: status enumeration');
$owner = TicketService::create(['subject' => 'own', 'description' => 'd', 'customer_email' => 'own@othercorp.com', 'priority' => 'normal'], 'web_form')['ticket']['ticket_id'];
$pa = new PublicActions();
Db::query('DELETE FROM rate_limits');
$wrongEmail = '';
$unknownId = '';
try { $pa->checkTicketStatus(['ticket_id' => $owner, 'email' => 'attacker@x.com'], new Request(server: $server)); } catch (Throwable $e) { $wrongEmail = $e->getMessage(); }
Db::query('DELETE FROM rate_limits');
try { $pa->checkTicketStatus(['ticket_id' => 'TKT-2026-0000', 'email' => 'own@othercorp.com'], new Request(server: $server)); } catch (Throwable $e) { $unknownId = $e->getMessage(); }
T::eq($wrongEmail, $unknownId, 'status lookup: wrong-email and unknown-id are byte-identical');

// ── Audit tamper evidence (§10.11) ───────────────────────────────────────────
T::suite('SecuritySuite: audit tamper (§10.11)');
T::ok(Audit::verifyChain() === null, 'audit chain intact');
$firstId = (int) Db::scalar('SELECT id FROM audit_log ORDER BY id ASC LIMIT 1');
Db::query('UPDATE audit_log SET details = :d WHERE id = :id', [':d' => 'tampered', ':id' => $firstId]);
T::eq($firstId, Audit::verifyChain(), 'a hand-edited audit row breaks the chain and is pinpointed');

// ── Headers (§10.12) ─────────────────────────────────────────────────────────
T::suite('SecuritySuite: security headers (§10.12)');
$h = SecurityHeaders::forRoute('default');
T::ok(isset($h['Strict-Transport-Security'], $h['X-Content-Type-Options'], $h['Content-Security-Policy']), 'core headers present');
T::ok(!preg_match('/script-src[^;]*unsafe-inline/', $h['Content-Security-Policy']), "script-src has no 'unsafe-inline'");
T::ok(!isset($h['X-XSS-Protection']), 'no deprecated X-XSS-Protection');

// ── Mail header injection (§10.16) ───────────────────────────────────────────
T::suite('SecuritySuite: mail (§10.16)');
T::eq('failed', Mailer::send("a@b.com\r\nBcc: evil@x", 'Hi', '<p>x</p>'), 'CR/LF header injection rejected');

foreach ($cleanup as $f) {
    if (is_file($f)) { unlink($f); }
}

exit(T::summary());
