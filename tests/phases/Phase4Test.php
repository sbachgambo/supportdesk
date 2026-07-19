<?php
declare(strict_types=1);

/**
 * Phase 4 test (§17.1) — Gateway & shell.
 * Exercises all five Dispatch gates in order: allowlist, rate-limit, CSRF,
 * authentication (+ MFA gate), authorization — plus the handler results.
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
use App\Models\User;
use App\Security\PasswordPolicy;

$envTesting = $root . '/.env.testing';
if (!is_file($envTesting)) {
    T::note('.env.testing not found — SKIPPING Phase 4');
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

$IP = '203.0.113.20';
$server = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api', 'REMOTE_ADDR' => $IP, 'CONTENT_TYPE' => 'application/json'];

$call = static function (string $action, array $payload, string $csrf) use ($server): array {
    $req = new Request(post: ['action' => $action, 'payload' => $payload, 'csrf' => $csrf], server: $server);
    $resp = Dispatch::handle($req);
    return [$resp->status(), json_decode($resp->body(), true)];
};

$admin = User::findByEmail('admin@p3a-support.com.ng');
$customer = User::findByEmail('customer@example.com');

// ── Gate 1: allowlist ────────────────────────────────────────────────────────
T::suite('Phase 4: Gate 1 — allowlist');
[$st, $body] = $call('nonexistentAction', [], 'x');
T::eq(400, $st, 'unknown action → 400');
T::ok(($body['ok'] ?? true) === false, 'unknown action → ok:false');

// ── Gate 3: CSRF (public) ────────────────────────────────────────────────────
T::suite('Phase 4: Gate 3 — CSRF');
Session::destroy();
[$st, $body] = $call('getPortalData', [], 'not-a-valid-token');
T::eq(419, $st, 'public action with bad CSRF → 419');

$goodPublic = Csrf::publicToken('getPortalData');
[$st, $body] = $call('getPortalData', [], $goodPublic);
T::eq(200, $st, 'public action with valid public CSRF → 200');
T::ok(($body['data']['branding']['company_name'] ?? '') !== '', 'getPortalData returns branding');
T::eq(5, count($body['data']['categories'] ?? []), 'getPortalData returns the 5 seeded categories');

// public token bound to purpose: token for one action must not work for another
[$st, $body] = $call('requestPasswordReset', ['email' => 'x@example.com'], $goodPublic);
T::eq(419, $st, 'public token is purpose-bound (getPortalData token rejected for requestPasswordReset)');
[$st, $body] = $call('requestPasswordReset', ['email' => 'customer@example.com'], Csrf::publicToken('requestPasswordReset'));
T::eq(200, $st, 'requestPasswordReset with its own token → 200');
T::ok(str_contains($body['data']['message'] ?? '', 'reset link'), 'requestPasswordReset returns the generic message');

// ── Gate 4: authentication ───────────────────────────────────────────────────
T::suite('Phase 4: Gate 4 — authentication (+ MFA)');
Session::destroy();
// With no session the session-bound CSRF token cannot validate, so gate 3 (CSRF)
// fires before gate 4 (auth) — exactly the fixed §9 order. A no-session request to
// an authed action is therefore rejected at 419; the 401 branch is defensive.
[$st, $body] = $call('getMe', [], 'anything');
T::eq(419, $st, 'authed action with no session → 419 (CSRF gate precedes auth, per §9 order)');
T::ok(($body['ok'] ?? true) === false, 'no-session authed request is rejected');

// customer session → getMe works
Session::start((int) $customer['id'], (string) $customer['email'], 'customer', $IP, 'ua', true);
[$st, $body] = $call('getMe', [], Csrf::token());
T::eq(200, $st, 'getMe with a valid customer session → 200');
T::eq('customer@example.com', $body['data']['email'] ?? '', 'getMe returns own profile');

// admin with mfa_verified = 0 → blocked at the MFA gate
Session::destroy();
Session::start((int) $admin['id'], (string) $admin['email'], 'admin', $IP, 'ua', false);
[$st, $body] = $call('getSystemConfig', [], Csrf::token());
T::eq(403, $st, 'admin with mfa_verified=0 → 403 (MFA gate)');
T::ok(($body['mfa_required'] ?? false) === true, 'response flags mfa_required');

// ── Gate 5: authorization ────────────────────────────────────────────────────
T::suite('Phase 4: Gate 5 — authorization');
// customer calling an admin-only action → denied (even with a valid session + CSRF)
Session::destroy();
Session::start((int) $customer['id'], (string) $customer['email'], 'customer', $IP, 'ua', true);
[$st, $body] = $call('getSystemConfig', [], Csrf::token());
T::eq(403, $st, 'customer calling admin action → 403 authz denied');
$denied = (int) Db::scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'authz_denied'");
T::ok($denied >= 1, 'authz denial is audit-logged');

// admin (mfa verified) → allowed
Session::destroy();
Session::start((int) $admin['id'], (string) $admin['email'], 'admin', $IP, 'ua', true);
[$st, $body] = $call('getSystemConfig', [], Csrf::token());
T::eq(200, $st, 'admin (mfa verified) → 200');
T::ok(isset($body['data']['config']['company_name']), 'getSystemConfig returns the config allowlist');

// ── Gate order: CSRF is checked before authorization ─────────────────────────
T::suite('Phase 4: gate ordering');
Session::start((int) $customer['id'], (string) $customer['email'], 'customer', $IP, 'ua', true);
[$st, $body] = $call('getSystemConfig', [], 'bad-csrf-token');
T::eq(419, $st, 'bad CSRF on an admin action → 419 (CSRF gate precedes authz)');

// ── Self-service password change (§10.3, §10.4) ──────────────────────────────
T::suite('Phase 4: self-service password change');
Session::destroy();
$knownPw = 'Current-Pass-123456';
User::updatePasswordHash((int) $customer['id'], PasswordPolicy::hash($knownPw), false);
Session::start((int) $customer['id'], (string) $customer['email'], 'customer', $IP, 'ua', true);

[$st, $body] = $call('changeMyPassword', ['current_password' => 'nope-nope-nope-01', 'new_password' => 'Brand-New-Pass-99'], Csrf::token());
T::eq(422, $st, 'wrong current password → 422');

[$st, $body] = $call('changeMyPassword', ['current_password' => $knownPw, 'new_password' => 'short'], Csrf::token());
T::eq(422, $st, 'new password below policy → 422');

[$st, $body] = $call('changeMyPassword', ['current_password' => $knownPw, 'new_password' => $knownPw], Csrf::token());
T::eq(422, $st, 'reusing the current password → 422');

$newPw = 'Fresh-Long-Pass-2026';
[$st, $body] = $call('changeMyPassword', ['current_password' => $knownPw, 'new_password' => $newPw], Csrf::token());
T::eq(200, $st, 'valid password change → 200');
$after = User::findById((int) $customer['id']);
T::ok(PasswordPolicy::verify($newPw, (string) $after['password_hash']), 'new password now verifies');
T::ok(!PasswordPolicy::verify($knownPw, (string) $after['password_hash']), 'old password no longer verifies');
T::eq(0, (int) $after['must_change_pw'], 'must_change_pw cleared after change');

exit(T::summary());
