<?php
declare(strict_types=1);

namespace App\Security;

use App\Core\Config;
use App\Core\Logger;
use App\Core\Session;
use App\Models\User;

/**
 * Auth (§10.3) — authentication with the enumeration/timing/lockout guarantees.
 *
 * Every failure path returns the SAME generic string (enumeration parity): wrong
 * password, unknown email, inactive account, and locked account are byte-identical.
 * A verify() always runs (against a dummy hash for unknown emails) so response time
 * does not distinguish "no such user" from "wrong password" (timing normalization).
 *
 * Lockout: 5 failures / 15 min / email (config), applied identically to unknown
 * emails, plus a per-IP layer at RATE_IP_MULTIPLIER × the allowance. During lockout
 * even a correct password is refused. Lock events audit-log and (Phase 10) alert admins.
 *
 * Legacy "v2$" hashes verify then are rewritten with password_hash() on this login (D4).
 */
final class Auth
{
    /** The single generic failure message — identical across all failure modes. */
    public const GENERIC_ERROR = 'Invalid email or password.';

    private const BUCKET = 'LOGINFAIL';

    /**
     * @return array{success:bool, error:?string, user_id:?int, role:?string, mfa_required:bool}
     */
    public static function attempt(string $email, string $password, string $ip, string $userAgent): array
    {
        $email = strtolower(trim($email));
        $ipKey = 'ip:' . $ip;

        $maxAttempts = Config::int('LOGIN_MAX_ATTEMPTS', 5);
        $lockWindow  = Config::int('LOGIN_LOCK_MINS', 15) * 60;
        $ipAllowance = $maxAttempts * Config::int('RATE_IP_MULTIPLIER', 3);

        $locked = RateLimit::exceeded(self::BUCKET, $email, $maxAttempts)
               || RateLimit::exceeded(self::BUCKET, $ipKey, $ipAllowance);

        $user = User::findByEmail($email);

        // Timing normalization: always spend a verify, even when the user is absent.
        if ($user !== null) {
            $valid = PasswordPolicy::verify($password, (string) $user['password_hash']);
        } else {
            PasswordPolicy::verifyDummy($password);
            $valid = false;
        }

        if ($locked) {
            Logger::security('login_locked', "email={$email}");
            Audit::log($email, 'login_locked', $email, 'attempt during lockout', $ip);
            return self::fail();
        }

        if ($user === null || !$valid || !User::isActive($user)) {
            self::recordFailure($email, $ipKey, $lockWindow, $maxAttempts, $ipAllowance, $ip);
            Logger::security('login_fail', "email={$email}");
            Audit::log($email, 'login_fail', $email, '', $ip);
            return self::fail();
        }

        // ── Success ──
        RateLimit::clear(self::BUCKET, $email);
        RateLimit::clear(self::BUCKET, $ipKey);

        // Legacy migration / parameter upgrade — invisible to the user (D4, §10.3).
        if (PasswordPolicy::needsRehash((string) $user['password_hash'])) {
            User::updatePasswordHash((int) $user['id'], PasswordPolicy::hash($password), false);
        }
        User::touchLastLogin((int) $user['id']);

        // MFA gate (D8): admins ALWAYS start unverified — enrolled ones clear the /mfa
        // challenge, not-yet-enrolled ones are forced through enrolment before the
        // session can act. Agents need it only once they self-enrol. Customers never.
        $role = (string) $user['role'];
        $mfaRequired = $role === 'admin' || ($role === 'agent' && (int) $user['totp_enabled'] === 1);

        Session::start(
            (int) $user['id'],
            (string) $user['email'],
            $role,
            $ip,
            $userAgent,
            !$mfaRequired
        );

        Logger::setUser((string) $user['public_id']);
        Audit::log((string) $user['email'], 'login_success', '', '', $ip);

        return [
            'success'      => true,
            'error'        => null,
            'user_id'      => (int) $user['id'],
            'role'         => $role,
            'mfa_required' => $mfaRequired,
        ];
    }

    public static function logout(): void
    {
        $email = Session::email() ?? '-';
        Audit::log($email, 'logout');
        Session::destroy();
    }

    private static function recordFailure(
        string $email,
        string $ipKey,
        int $window,
        int $maxAttempts,
        int $ipAllowance,
        string $ip
    ): void {
        $emailHits = RateLimit::recordHit(self::BUCKET, $email, $window);
        $ipHits = RateLimit::recordHit(self::BUCKET, $ipKey, $window);

        // If this failure is the one that trips the threshold, record the lock event
        // (Phase 10 wires the admin alert here).
        if ($emailHits === $maxAttempts || $ipHits === $ipAllowance) {
            Logger::security('account_locked', "email={$email} threshold reached");
            Audit::log($email, 'account_locked', $email, "hits={$emailHits}", $ip);
        }
    }

    private static function fail(): array
    {
        return [
            'success'      => false,
            'error'        => self::GENERIC_ERROR,
            'user_id'      => null,
            'role'         => null,
            'mfa_required' => false,
        ];
    }
}
