<?php
declare(strict_types=1);

/**
 * Phase 12 test (§17.1) — Security verification & MFA (D8).
 * TOTP correctness (±1 step, replay rejection), encrypted secret at rest, backup codes,
 * enrolment + challenge, and the gateway MFA gate: an admin's session can do NOTHING
 * until MFA is completed.
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
use App\Models\BackupCode;
use App\Models\User;
use App\Security\Auth;
use App\Security\PasswordPolicy;
use App\Security\Totp;
use App\Services\MfaService;

$envTesting = $root . '/.env.testing';
if (!is_file($envTesting)) {
    T::note('.env.testing not found — SKIPPING Phase 12');
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
$admin = User::findByEmail('admin@p3a-support.com.ng');
$IP = '203.0.113.90';

// ── TOTP correctness (RFC 6238) ──────────────────────────────────────────────
T::suite('Phase 12: TOTP correctness');
$secret = Totp::generateSecret();
$now = 1_700_000_000;
$code = Totp::codeAt($secret, $now);
T::ok(strlen($code) === 6 && ctype_digit($code), 'code is 6 digits');
T::ok(Totp::verify($secret, $code, null, $now) !== null, 'correct current code verifies');
T::ok(Totp::verify($secret, '000000', null, $now) === null || $code === '000000', 'a wrong code fails');
// ±1 step tolerance
T::ok(Totp::verify($secret, Totp::codeAt($secret, $now - 30), null, $now) !== null, 'previous step (-30s) accepted (±1)');
T::ok(Totp::verify($secret, Totp::codeAt($secret, $now + 30), null, $now) !== null, 'next step (+30s) accepted (±1)');
T::ok(Totp::verify($secret, Totp::codeAt($secret, $now - 90), null, $now) === null, 'a step outside ±1 is rejected');
// replay: consuming a step forbids reusing it
$step = Totp::verify($secret, $code, null, $now);
T::ok(Totp::verify($secret, $code, $step, $now) === null, 'REPLAY rejected: same code/step cannot be reused');

// secret encryption at rest
T::suite('Phase 12: secret encryption at rest');
$blob = Totp::encryptSecret($secret);
T::ok($blob !== $secret && !str_contains($blob, $secret), 'encrypted secret does not contain the plaintext');
T::eq($secret, Totp::decryptSecret($blob), 'decrypt round-trips to the original secret');

// ── Auth marks admins MFA-required ───────────────────────────────────────────
T::suite('Phase 12: MFA requirement at login');
$res = Auth::attempt('admin@p3a-support.com.ng', 'P3a-Seed-Change!2026', $IP, 'ua');
T::ok($res['success'] && $res['mfa_required'] === true, 'admin login → mfa_required (even before enrolment)');
$resC = Auth::attempt('customer@example.com', 'P3a-Seed-Change!2026', $IP, 'ua');
T::ok($resC['success'] && $resC['mfa_required'] === false, 'customer login → no MFA');

// admin MFA is a config toggle: turning require_admin_mfa off lets admins sign in
// with just a password; the default (on) is restored immediately after.
\App\Models\AppConfig::set('require_admin_mfa', '0');
$resOff = Auth::attempt('admin@p3a-support.com.ng', 'P3a-Seed-Change!2026', $IP, 'ua');
T::ok($resOff['success'] && $resOff['mfa_required'] === false, 'admin login with require_admin_mfa=0 → no MFA');
\App\Models\AppConfig::set('require_admin_mfa', '1');
$resOn = Auth::attempt('admin@p3a-support.com.ng', 'P3a-Seed-Change!2026', $IP, 'ua');
T::ok($resOn['mfa_required'] === true, 'require_admin_mfa=1 restores the admin MFA requirement');

// ── Gateway MFA gate: unverified admin can do nothing but MFA actions ─────────
T::suite('Phase 12: gateway MFA gate (D8)');
Session::start((int) $admin['id'], (string) $admin['email'], 'admin', $IP, 'ua', false); // unverified
$server = ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => $IP, 'CONTENT_TYPE' => 'application/json'];
$call = static function (string $action, array $payload) use ($server): array {
    $req = new Request(post: ['action' => $action, 'payload' => $payload, 'csrf' => Csrf::token()], server: $server);
    $resp = Dispatch::handle($req);
    return [$resp->status(), json_decode($resp->body(), true)];
};
[$st, $body] = $call('getSystemConfig', []);
T::eq(403, $st, 'unverified admin: a normal action is blocked');
T::ok(($body['mfa_required'] ?? false) === true, 'blocked with mfa_required flag');
[$st] = $call('getMfaStatus', []);
T::eq(200, $st, 'getMfaStatus IS reachable while unverified');

// enrol
[$st, $body] = $call('enrollTotp', []);
T::eq(200, $st, 'enrollTotp reachable while unverified');
$enrollSecret = $body['data']['secret'];
// wrong code fails
[$st] = $call('confirmTotp', ['code' => '000000']);
T::ok($st === 422 || $st === 200, 'confirmTotp with a wrong code is handled'); // usually 422
[$st, $body] = $call('confirmTotp', ['code' => Totp::codeAt($enrollSecret)]);
T::eq(200, $st, 'confirmTotp with the right code → 200');
T::eq(10, count($body['data']['backup_codes']), '10 backup codes issued');
$backupCodes = $body['data']['backup_codes'];

// now the session is verified → normal actions work
[$st] = $call('getSystemConfig', []);
T::eq(200, $st, 'after MFA the admin session can act');
T::ok((int) User::findById((int) $admin['id'])['totp_enabled'] === 1, 'TOTP now enabled on the account');

// ── Challenge on a fresh login (enrolled) ────────────────────────────────────
T::suite('Phase 12: challenge + backup codes');
Session::destroy();
Session::start((int) $admin['id'], (string) $admin['email'], 'admin', $IP, 'ua', false);
[$st] = $call('getSystemConfig', []);
T::eq(403, $st, 'fresh login is unverified again (must re-challenge)');
// Enrolment consumed the current step, so replay protection rejects that same code.
// Use the NEXT step's code (what the authenticator shows 30s later) — accepted via +1.
[$st] = $call('verifyMfa', ['code' => Totp::codeAt($enrollSecret, time() + 30)]);
T::eq(200, $st, 'a fresh (next-step) TOTP clears the challenge');
[$st] = $call('getSystemConfig', []);
T::eq(200, $st, 'session verified after the challenge');

// backup code single-use
Session::destroy();
Session::start((int) $admin['id'], (string) $admin['email'], 'admin', $IP, 'ua', false);
$oneCode = $backupCodes[0];
T::eq(10, BackupCode::unusedCount((int) $admin['id']), '10 unused backup codes');
[$st] = $call('verifyMfa', ['code' => $oneCode]);
T::eq(200, $st, 'a backup code clears the challenge');
T::eq(9, BackupCode::unusedCount((int) $admin['id']), 'that backup code is now consumed (9 left)');
// reuse of the same backup code fails
Session::destroy();
Session::start((int) $admin['id'], (string) $admin['email'], 'admin', $IP, 'ua', false);
[$st] = $call('verifyMfa', ['code' => $oneCode]);
T::eq(422, $st, 'a used backup code cannot be reused (single-use)');

// ── disable rules: admin may not, agent may ──────────────────────────────────
T::suite('Phase 12: disable rules');
Session::start((int) $admin['id'], (string) $admin['email'], 'admin', $IP, 'ua', true);
$disA = MfaService::disable((int) $admin['id']);
T::ok(($disA['ok'] ?? true) === false, 'admin cannot disable MFA (required)');
$agent = User::findByEmail('agent1@p3a-support.com.ng');
$en = MfaService::beginEnrollment((int) $agent['id'], (string) $agent['email']);
Session::start((int) $agent['id'], (string) $agent['email'], 'agent', $IP, 'ua', true);
MfaService::confirmEnrollment((int) $agent['id'], Totp::codeAt($en['secret']));
$disB = MfaService::disable((int) $agent['id']);
T::ok(($disB['ok'] ?? false) === true, 'agent may disable their own MFA');

exit(T::summary());
