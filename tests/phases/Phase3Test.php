<?php
declare(strict_types=1);

/**
 * Phase 3 test (§17.1) — Auth.
 * Covers the §10.3 / §10.4 / §10.9 verify items: enumeration parity, timing
 * normalization, lockout, session cap, hashed session tokens, tokenless reset,
 * single-use reset tokens, legacy hash migration, CSRF (both mechanisms), and
 * the audit hash chain.
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
use App\Core\Request;
use App\Core\Session;
use App\Models\User;
use App\Security\Audit;
use App\Security\Auth;
use App\Security\PasswordPolicy;
use App\Security\RateLimit;
use App\Services\PasswordReset;

$envTesting = $root . '/.env.testing';
if (!is_file($envTesting)) {
    T::note('.env.testing not found — SKIPPING Phase 3');
    exit(T::summary());
}
Config::load($envTesting);

// Fresh schema + seed so state is deterministic.
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

$SEED_PW = 'P3a-Seed-Change!2026';
$IP = '203.0.113.10';
$UA = 'phpunit-fake-agent';

// Re-hash seeded users to the (fast) testing KDF params so the suite isn't dominated
// by the seed's production cost-12 bcrypt, and so real-verify vs dummy-verify stay
// cost-matched for the timing test. All seed users share the same default password.
Db::query('UPDATE users SET password_hash = :h', [':h' => PasswordPolicy::hash($SEED_PW)]);

/** Create an active user with a known password (or explicit hash). */
$makeUser = static function (string $email, string $publicId, string $password, ?string $explicitHash = null): int {
    $hash = $explicitHash ?? PasswordPolicy::hash($password);
    return (int) Db::insert('users', [
        'public_id' => $publicId, 'name' => $email, 'email' => $email,
        'password_hash' => $hash, 'role' => 'customer', 'active' => 1,
        'created_at' => gmdate('Y-m-d H:i:s'),
    ]);
};
$clearLimits = static function (): void {
    Db::query('DELETE FROM rate_limits');
};

// ── PasswordPolicy (§10.3) ───────────────────────────────────────────────────
T::suite('Phase 3: PasswordPolicy (§10.3)');
T::ok(PasswordPolicy::validate('short') !== null, 'rejects < 12 chars');
T::ok(PasswordPolicy::validate('password') !== null, 'rejects a breached password');
T::ok(PasswordPolicy::validate(str_repeat('a', 73)) !== null, 'rejects > 72 bytes (bcrypt cap)');
T::ok(PasswordPolicy::validate('a correct horse battery staple') === null, 'accepts a long passphrase with spaces');
$h = PasswordPolicy::hash('a correct horse battery staple');
T::ok(PasswordPolicy::verify('a correct horse battery staple', $h), 'hash/verify round trip');
T::ok(!PasswordPolicy::verify('wrong', $h), 'verify rejects wrong password');
T::ok(PasswordPolicy::needsRehash('v2$abc$def'), 'needsRehash true for legacy v2$');

// ── Enumeration parity + timing (§10.3) ──────────────────────────────────────
T::suite('Phase 3: enumeration parity + timing (§10.3)');
$clearLimits();
$wrong   = Auth::attempt('customer@example.com', 'definitely-the-wrong-pw', $IP, $UA);
$clearLimits();
$unknown = Auth::attempt('nobody-here@example.com', 'definitely-the-wrong-pw', $IP, $UA);
T::ok(!$wrong['success'] && !$unknown['success'], 'both fail');
T::eq($wrong['error'], $unknown['error'], 'wrong-password and unknown-email errors are BYTE-IDENTICAL');
T::eq(Auth::GENERIC_ERROR, (string) $wrong['error'], 'error is the single generic string');

// timing: both paths run one verify → comparable. min-of-3 to cut noise. Lenient bound.
$time = static function (callable $fn): float {
    $best = INF;
    for ($i = 0; $i < 3; $i++) {
        $t = microtime(true);
        $fn();
        $best = min($best, microtime(true) - $t);
    }
    return $best;
};
$clearLimits();
$tWrong = $time(fn() => Auth::attempt('customer@example.com', 'nope-nope-nope', $IP, $UA));
$clearLimits();
$tUnknown = $time(fn() => Auth::attempt('nobody-here2@example.com', 'nope-nope-nope', $IP, $UA));
$ratio = $tWrong > 0 ? $tUnknown / $tWrong : 1.0;
T::ok($ratio > 0.3 && $ratio < 3.3,
    sprintf('timing comparable (ratio %.2f; wrong=%.1fms unknown=%.1fms)', $ratio, $tWrong * 1000, $tUnknown * 1000));

// ── Lockout (§10.3) ──────────────────────────────────────────────────────────
T::suite('Phase 3: lockout (§10.3)');
$clearLimits();
$lockEmail = 'locktest@example.com';
$makeUser($lockEmail, 'CU-9001', $SEED_PW);
for ($i = 0; $i < 5; $i++) {
    $r = Auth::attempt($lockEmail, 'wrong-password', $IP, $UA);
    T::ok(!$r['success'], 'wrong attempt #' . ($i + 1) . ' fails');
}
$correctButLocked = Auth::attempt($lockEmail, $SEED_PW, $IP, $UA);
T::ok(!$correctButLocked['success'], '6th attempt with the CORRECT password is refused (locked)');
T::eq(Auth::GENERIC_ERROR, (string) $correctButLocked['error'], 'locked error is identical to other failures (no account confirmation)');

// ── Session: hashed tokens + cap (§10.4) ─────────────────────────────────────
T::suite('Phase 3: session hashed tokens + cap (§10.4)');
$clearLimits();
Db::query('DELETE FROM sessions');
$capUserId = $makeUser('capuser@example.com', 'CU-9002', $SEED_PW);

$raw = Session::start($capUserId, 'capuser@example.com', 'customer', $IP, $UA, true);
$rawInDb = Db::queryOne('SELECT token_hash FROM sessions WHERE token_hash = :h', [':h' => $raw]);
T::ok($rawInDb === null, 'raw cookie value is NOT stored (a DB read cannot be replayed as a cookie)');
$hashedInDb = Db::queryOne('SELECT token_hash FROM sessions WHERE token_hash = :h', [':h' => hash('sha256', $raw)]);
T::ok($hashedInDb !== null, 'session stored as sha256(token) (§10.4)');

// cap: MAX_SESSIONS_PER_USER (default 5). Start 5 more (6 total) → 5 remain.
for ($i = 0; $i < 5; $i++) {
    Session::start($capUserId, 'capuser@example.com', 'customer', $IP, $UA, true);
}
$count = (int) Db::scalar('SELECT COUNT(*) FROM sessions WHERE user_id = :u', [':u' => $capUserId]);
T::eq(5, $count, 'concurrent cap holds at 5; oldest evicted on the 6th');

// termination rules
Session::terminateAllForUser($capUserId);
T::eq(0, (int) Db::scalar('SELECT COUNT(*) FROM sessions WHERE user_id = :u', [':u' => $capUserId]), 'terminateAllForUser clears every session');

// ── Legacy hash migration (D4, §10.3) ────────────────────────────────────────
T::suite('Phase 3: legacy migration (D4)');
$clearLimits();
$migPw = 'legacy-passphrase-2026';
$migId = $makeUser('legacy@example.com', 'CU-9003', $migPw, PasswordPolicy::makeLegacy($migPw));
$before = (string) User::findById($migId)['password_hash'];
T::ok(str_starts_with($before, 'v2$'), 'seeded user has a v2$ legacy hash');
$mig = Auth::attempt('legacy@example.com', $migPw, $IP, $UA);
T::ok($mig['success'], 'login succeeds against the legacy hash');
$after = (string) User::findById($migId)['password_hash'];
T::ok(!str_starts_with($after, 'v2$'), 'hash was transparently rewritten with password_hash() on login');
$clearLimits();
T::ok(Auth::attempt('legacy@example.com', $migPw, $IP, $UA)['success'], 'login still works after the rewrite');

// ── Password reset (§10.9) ───────────────────────────────────────────────────
T::suite('Phase 3: password reset (§10.9)');
$clearLimits();
$resetEmail = 'customer@example.com';
$rawTok = PasswordReset::createToken($resetEmail, 'Demo Customer');
T::ok(PasswordReset::validateToken($rawTok) !== null, 'fresh token validates');
T::eq($resetEmail, PasswordReset::consumeToken($rawTok), 'consume returns the bound email');
T::ok(PasswordReset::consumeToken($rawTok) === null, 'token is single-use (second consume fails)');

// expired token rejected
$expiredRaw = bin2hex(random_bytes(32));
Db::insert('password_resets', [
    'token_hash' => hash('sha256', $expiredRaw), 'email' => $resetEmail, 'name' => 'x',
    'expires_at' => gmdate('Y-m-d H:i:s', time() - 60),
]);
T::ok(PasswordReset::validateToken($expiredRaw) === null, 'expired token rejected');

// unknown email → no row created (enumeration-safe)
$before = (int) Db::scalar('SELECT COUNT(*) FROM password_resets');
PasswordReset::request('nobody-unknown@example.com', $IP);
$after = (int) Db::scalar('SELECT COUNT(*) FROM password_resets');
T::eq($before, $after, 'request for unknown email creates NO token row');

// an ACTIVE, non-suppressed user actually gets the reset-link email (Mailer pretend → 'sent')
$mailBefore = (int) Db::scalar("SELECT COUNT(*) FROM mail_log WHERE subject = 'Reset your password'");
PasswordReset::request('admin@p3a-support.com.ng', $IP);
$mailAfter = (int) Db::scalar("SELECT COUNT(*) FROM mail_log WHERE subject = 'Reset your password'");
T::eq($mailBefore + 1, $mailAfter, 'reset request dispatches the reset-link email (mail_log records it)');
$sentRow = Db::queryOne("SELECT status FROM mail_log WHERE subject = 'Reset your password' ORDER BY id DESC LIMIT 1");
T::eq('sent', (string) ($sentRow['status'] ?? ''), 'reset email sent (pretend=sent under APP_ENV=testing)');

// completing a reset kills all the user's sessions
$ru = (int) User::findByEmail($resetEmail)['id'];
Db::query('DELETE FROM sessions');
Session::start($ru, $resetEmail, 'customer', $IP, $UA, true);
$err = PasswordReset::complete($resetEmail, 'brand-new-passphrase-2026', $IP);
T::ok($err === null, 'complete() succeeds with a valid new password');
T::eq(0, (int) Db::scalar('SELECT COUNT(*) FROM sessions WHERE user_id = :u', [':u' => $ru]), 'reset terminates all sessions');

// tokenless redirect: GET /reset?token=X → 302 with NO token in the Location
$freshRaw = PasswordReset::createToken($resetEmail, 'Demo Customer');
$controller = new App\Controllers\AuthController();
$req = new Request(query: ['token' => $freshRaw], server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/reset?token=' . $freshRaw, 'REMOTE_ADDR' => $IP]);
$resp = $controller->showReset($req);
T::eq(302, $resp->status(), 'GET /reset?token=X returns a 302 redirect');
$loc = $resp->headers()['Location'] ?? '';
T::ok($loc !== '' && !str_contains($loc, 'token'), 'redirect Location contains NO token (§10.9 Referer-leak fix)');

// ── CSRF (§10.8) ─────────────────────────────────────────────────────────────
T::suite('Phase 3: CSRF (§10.8)');
$pt = Csrf::publicToken('login');
T::ok(Csrf::validatePublic($pt, 'login'), 'public token validates for its purpose');
T::ok(!Csrf::validatePublic($pt, 'forgot'), 'public token rejected for a different purpose');
T::ok(!Csrf::validatePublic($pt . 'x', 'login'), 'tampered public token rejected');
T::ok(!Csrf::validatePublic(base64_encode((time() - 10) . '|deadbeef'), 'login'), 'expired public token rejected');
Session::destroy();
T::ok(!Csrf::validate('anything'), 'session-bound validate fails with no session');

// ── Audit hash chain (§10.11) ────────────────────────────────────────────────
T::suite('Phase 3: audit hash chain (§10.11)');
T::ok(Audit::verifyChain() === null, 'chain is intact after all the auth events above');
// tamper a row → chain must point at it
$firstId = (int) Db::scalar('SELECT id FROM audit_log ORDER BY id ASC LIMIT 1');
Db::query('UPDATE audit_log SET details = :d WHERE id = :id', [':d' => 'tampered!', ':id' => $firstId]);
T::eq($firstId, Audit::verifyChain(), 'verifyChain pinpoints the tampered row');

exit(T::summary());
