<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Session;
use App\Models\AppConfig;
use App\Models\BackupCode;
use App\Models\User;
use App\Security\Audit;
use App\Security\RateLimit;
use App\Security\Totp;

/**
 * MfaService (D8) — TOTP enrolment and verification.
 *
 * Roles: admin → REQUIRED; agent → optional (self-enrolled); customer → unavailable.
 * Verification (TOTP or a single-use backup code) is rate-limited on the TOTPFAIL
 * bucket exactly like passwords, allows ±1 time-step, and rejects a replayed step.
 */
final class MfaService
{
    public static function requiresMfa(string $role): bool
    {
        if ($role === 'admin') {
            return AppConfig::get('require_admin_mfa', '1') === '1';
        }
        return $role === 'agent';
    }

    /** Start enrolment: generate + store an (encrypted) pending secret; return the URI. */
    public static function beginEnrollment(int $userId, string $email): array
    {
        $secret = Totp::generateSecret();
        User::setTotpSecret($userId, Totp::encryptSecret($secret));
        $issuer = AppConfig::get('company_name', 'P3A Support');
        return [
            'secret' => $secret,
            'uri'    => Totp::provisioningUri($secret, $email, $issuer),
        ];
    }

    /**
     * Confirm enrolment: verify a code against the pending secret; on success enable
     * TOTP, issue backup codes (returned ONCE), and mark this session MFA-verified.
     */
    public static function confirmEnrollment(int $userId, string $code): array
    {
        $user = User::findById($userId);
        if ($user === null || empty($user['totp_secret'])) {
            return ['ok' => false, 'error' => 'Start enrolment first.'];
        }
        $secret = Totp::decryptSecret((string) $user['totp_secret']);
        $step = Totp::verify($secret, $code, $user['totp_last_step'] === null ? null : (int) $user['totp_last_step']);
        if ($step === null) {
            return ['ok' => false, 'error' => 'That code is not valid. Try again.'];
        }
        User::enableTotp($userId);
        User::setTotpLastStep($userId, $step);
        $codes = Totp::generateBackupCodes();
        BackupCode::store($userId, $codes);
        Session::markMfaVerified();
        Audit::log((string) $user['email'], 'mfa_enrolled', (string) $user['public_id']);
        return ['ok' => true, 'backup_codes' => $codes];
    }

    /** The /mfa challenge: TOTP code OR a backup code. Rate-limited (TOTPFAIL). */
    public static function verifyChallenge(int $userId, string $code): array
    {
        $user = User::findById($userId);
        if ($user === null || (int) $user['totp_enabled'] !== 1) {
            return ['ok' => false, 'error' => 'MFA is not enrolled.'];
        }

        $bucketKey = 'user:' . $userId;
        if (RateLimit::exceeded('TOTPFAIL', $bucketKey, Config::int('LOGIN_MAX_ATTEMPTS', 5))) {
            return ['ok' => false, 'error' => 'Too many attempts. Please wait and try again.'];
        }

        $secret = Totp::decryptSecret((string) $user['totp_secret']);
        $lastStep = $user['totp_last_step'] === null ? null : (int) $user['totp_last_step'];
        $step = Totp::verify($secret, $code, $lastStep);
        if ($step !== null) {
            User::setTotpLastStep($userId, $step);
            Session::markMfaVerified();
            Audit::log((string) $user['email'], 'mfa_success');
            return ['ok' => true];
        }

        // Fall back to a single-use backup code.
        if (BackupCode::consume($userId, strtoupper(trim($code)))) {
            Session::markMfaVerified();
            Audit::log((string) $user['email'], 'mfa_backup_code_used');
            return ['ok' => true, 'backup_used' => true];
        }

        RateLimit::recordHit('TOTPFAIL', $bucketKey, Config::int('LOGIN_LOCK_MINS', 15) * 60);
        Audit::log((string) $user['email'], 'mfa_fail');
        return ['ok' => false, 'error' => 'That code is not valid.'];
    }

    /** Disable TOTP — admins may NOT (it is required); agents may. */
    public static function disable(int $userId): array
    {
        $user = User::findById($userId);
        if ($user === null) {
            return ['ok' => false, 'error' => 'User not found.'];
        }
        if ((string) $user['role'] === 'admin') {
            return ['ok' => false, 'error' => 'MFA is required for admins and cannot be disabled.'];
        }
        User::disableTotp($userId);
        BackupCode::store($userId, []);
        Audit::log((string) $user['email'], 'mfa_disabled');
        return ['ok' => true];
    }

    public static function status(int $userId): array
    {
        $user = User::findById($userId);
        $role = (string) ($user['role'] ?? '');
        return [
            'enabled'       => (int) ($user['totp_enabled'] ?? 0) === 1,
            'required'      => self::requiresMfa($role),
            'verified'      => Session::isMfaVerified(),
            'backup_codes'  => $user !== null ? BackupCode::unusedCount($userId) : 0,
        ];
    }
}
