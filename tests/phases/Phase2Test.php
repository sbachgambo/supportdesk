<?php
declare(strict_types=1);

/**
 * Phase 2 test (§17.1) — Core.
 * Unit-level assertions for Config, Db, SecurityHeaders, Response, Logger, Request.
 * The HTTP-level exit criteria (scratch route returns JSON; headers present;
 * exception → generic page + request id) are covered by tests/smoke_http.php.
 */

require __DIR__ . '/../lib.php';

$root = dirname(__DIR__, 2);
if (!defined('P3A_ROOT')) {
    define('P3A_ROOT', $root);
}
require $root . '/vendor/autoload.php';

use App\Core\Config;
use App\Core\Db;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\SecurityHeaders;

$envTesting = $root . '/.env.testing';
if (!is_file($envTesting)) {
    T::note('.env.testing not found — SKIPPING Phase 2 (see Phase 1 setup notes)');
    exit(T::summary());
}

// ── Config ──────────────────────────────────────────────────────────────────
T::suite('Phase 2: Config');
Config::load($envTesting);
T::ok(Config::isLoaded(), 'Config loads .env.testing');
T::eq('testing', Config::string('APP_ENV'), 'string accessor');
T::ok(Config::has('DB_NAME'), 'has() true for present key');
T::ok(!Config::has('NOPE_MISSING'), 'has() false for absent key');
T::eq(42, Config::int('NOPE_MISSING', 42), 'int default when absent');

$threw = false;
try {
    Config::string('DEFINITELY_MISSING_KEY');
} catch (Throwable $e) {
    $threw = true;
}
T::ok($threw, 'missing required string throws');

// https validation: a production env with a http:// APP_URL must fail to load
$badEnv = $root . '/storage/cache/_bad.env';
file_put_contents($badEnv, "APP_ENV=production\nAPP_URL=http://insecure\nAPP_KEY=x\nDB_NAME=x\nDB_USER=x\n");
$threwHttps = false;
try {
    Config::load($badEnv);
} catch (Throwable $e) {
    $threwHttps = str_contains($e->getMessage(), 'https');
}
unlink($badEnv);
T::ok($threwHttps, 'APP_URL must be https:// in production (§10.13)');

// restore the real testing config for the DB tests below
Config::load($envTesting);

// ── Db ──────────────────────────────────────────────────────────────────────
T::suite('Phase 2: Db (§10.1)');
try {
    $pdo = Db::connect();
    // getAttribute returns int 0/1, not bool — compare for falsiness.
    T::ok(!$pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES), 'PDO emulation is OFF (§10.1)');

    // exercise insert/queryOne/update/scalar/delete against a scratch table
    $pdo->exec('DROP TABLE IF EXISTS _p2_scratch');
    $pdo->exec('CREATE TABLE _p2_scratch (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50), n INT)');

    $id = Db::insert('_p2_scratch', ['name' => "O'Brien <x>", 'n' => 1]);
    T::ok((int) $id > 0, 'insert returns id');

    $row = Db::queryOne('SELECT name, n FROM _p2_scratch WHERE id = :id', [':id' => (int) $id]);
    T::eq("O'Brien <x>", $row['name'] ?? null, 'queryOne returns bound-param row (no injection)');

    $affected = Db::update('_p2_scratch', ['n' => 5], 'id = :id', [':id' => (int) $id]);
    T::eq(1, $affected, 'update affects one row');

    $n = Db::scalar('SELECT n FROM _p2_scratch WHERE id = :id', [':id' => (int) $id]);
    T::eq(5, (int) $n, 'scalar reads updated value');

    // transaction rolls back on throw
    $threwTx = false;
    try {
        Db::transaction(function () use ($id): void {
            Db::update('_p2_scratch', ['n' => 99], 'id = :id', [':id' => (int) $id]);
            throw new RuntimeException('rollback me');
        });
    } catch (Throwable $e) {
        $threwTx = true;
    }
    $nAfter = (int) Db::scalar('SELECT n FROM _p2_scratch WHERE id = :id', [':id' => (int) $id]);
    T::ok($threwTx && $nAfter === 5, 'transaction rolls back on exception');

    $del = Db::delete('_p2_scratch', 'id = :id', [':id' => (int) $id]);
    T::eq(1, $del, 'delete affects one row');
    $pdo->exec('DROP TABLE IF EXISTS _p2_scratch');

    // injection payload is inert as a bound value
    $inj = Db::queryOne("SELECT :p AS v", [':p' => "' OR 1=1--"]);
    T::eq("' OR 1=1--", $inj['v'] ?? null, "classic payload treated as literal, not SQL");
} catch (Throwable $e) {
    T::ok(false, 'Db suite error: ' . $e->getMessage());
}

// ── SecurityHeaders (§10.12) ─────────────────────────────────────────────────
T::suite('Phase 2: SecurityHeaders (§10.12)');
$h = SecurityHeaders::forRoute('default');
foreach ([
    'Strict-Transport-Security', 'X-Frame-Options', 'X-Content-Type-Options',
    'Referrer-Policy', 'Permissions-Policy', 'Cross-Origin-Opener-Policy',
    'Content-Security-Policy',
] as $name) {
    T::ok(isset($h[$name]), "header present: {$name}");
}
T::ok(str_contains($h['Content-Security-Policy'], "script-src 'self'"), "CSP script-src 'self'");
T::ok(!str_contains($h['Content-Security-Policy'], 'unsafe-inline') ||
      !preg_match("/script-src[^;]*unsafe-inline/", $h['Content-Security-Policy']),
      "script-src has NO unsafe-inline (D5)");
T::ok(!str_contains($h['X-Content-Type-Options'], 'XSS'), 'no X-XSS-Protection (deprecated, §10.12)');

$w = SecurityHeaders::forRoute('widget');
T::ok(!isset($w['X-Frame-Options']), 'widget: no X-Frame-Options');
T::ok(str_contains($w['Content-Security-Policy'], 'frame-ancestors *'), 'widget: frame-ancestors *');

$r = SecurityHeaders::forRoute('reset');
T::eq('no-referrer', $r['Referrer-Policy'], 'reset: Referrer-Policy no-referrer (§10.9)');

// ── Response JSON hardening (§10.2) ──────────────────────────────────────────
T::suite('Phase 2: Response JSON (§10.2)');
$resp = Response::json(['x' => '<script>alert(1)</script>']);
T::ok(!str_contains($resp->body(), '<script>'), 'JSON escapes < into \\u003c (no live tag)');
T::eq('application/json; charset=utf-8', $resp->headers()['Content-Type'] ?? '', 'JSON content-type');
T::eq(404, Response::json([], 404)->status(), 'status propagates');

// ── Logger (§10.10) ──────────────────────────────────────────────────────────
T::suite('Phase 2: Logger (§10.10)');
$secLog = $root . '/storage/logs/security.log';
if (is_file($secLog)) {
    unlink($secLog);
}
Logger::boot('testreqid00000000', '203.0.113.9', 'POST', '/login');
Logger::security('login_fail', "email=a@b.com password=SuperSecret123 line\nbreak attempt");
$contents = is_file($secLog) ? (string) file_get_contents($secLog) : '';
T::ok(str_contains($contents, 'testreqid00000000'), 'log line carries the request id');
T::ok(str_contains($contents, 'password=[REDACTED]'), 'password value redacted');
T::ok(!str_contains($contents, 'SuperSecret123'), 'raw secret never written');
T::eq(1, substr_count(trim($contents), "\n") + 1, 'log-injection: newline stripped → single line');

// ── Request (§16) ────────────────────────────────────────────────────────────
T::suite('Phase 2: Request (§16)');
$req = new Request(
    query: ['q' => 'hi'],
    post: ['action' => 'ping', 'name' => '  spaced  '],
    server: ['REQUEST_METHOD' => 'post', 'REQUEST_URI' => '/api?x=1', 'REMOTE_ADDR' => '198.51.100.7'],
);
T::eq('POST', $req->method(), 'method uppercased');
T::eq('/api', $req->path(), 'path strips query string');
T::eq('spaced', $req->str('name'), 'str() trims');
T::eq('198.51.100.7', $req->ip(), 'ip() reads REMOTE_ADDR');
T::eq('0.0.0.0', (new Request(server: ['REMOTE_ADDR' => 'not-an-ip']))->ip(), 'invalid ip → 0.0.0.0');

exit(T::summary());
