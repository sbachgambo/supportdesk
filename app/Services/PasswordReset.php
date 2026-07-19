<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Db;
use App\Core\Logger;
use App\Core\Session;
use App\Models\User;
use App\Security\Audit;
use App\Security\PasswordPolicy;
use App\Security\RateLimit;

/**
 * PasswordReset (§10.9) — hardened self-service reset.
 *
 *   - Token = 32 random bytes hex, STORED HASHED, 60-minute expiry, SINGLE USE.
 *   - The request endpoint is enumeration-safe: identical response for known and
 *     unknown emails, and NO row is created for unknown/inactive emails.
 *     Rate-limited 3/hr/email (PWRESET).
 *   - Referer-leak fix: GET /reset?token=X validates then consumes the token and
 *     hands the browser a short-lived signed reset cookie; the URL is rewritten
 *     tokenless (controller 302). The token never enters history or Referer.
 *   - Completing a reset kills ALL of the user's sessions and (Phase 10) emails
 *     the owner.
 */
final class PasswordReset
{
    private const BUCKET = 'PWRESET';
    private const TTL_SECONDS = 3600;          // 60 minutes
    private const RESET_COOKIE_TTL = 900;      // 15 minutes for the tokenless stage

    /**
     * Public request endpoint. Always returns the same generic outcome. Creates a
     * token (and, Phase 10, sends the email) only for an active user.
     */
    public static function request(string $email, string $ip): void
    {
        $email = strtolower(trim($email));
        $limit = Config::int('RATE_PWRESET_PER_HOUR', 3);

        if (RateLimit::exceeded(self::BUCKET, $email, $limit)) {
            return; // silently no-op; response is identical either way
        }
        RateLimit::recordHit(self::BUCKET, $email, 3600);

        $user = User::findByEmail($email);
        if ($user !== null && User::isActive($user)) {
            $raw = self::createToken($email, (string) $user['name']);
            Logger::security('pwreset_requested', "email={$email}");
            Audit::log($email, 'pwreset_requested', $email, '', $ip);
            self::sendResetEmail($email, (string) $user['name'], $raw);
            unset($raw);
        }
        // else: create no row, log nothing that distinguishes existence.
    }

    /** Email the (absolute, single-use, 60-min) reset link. Mailer handles suppression/pretend. */
    private static function sendResetEmail(string $email, string $name, string $rawToken): void
    {
        $link = \url('reset?token=' . $rawToken);
        $safeName = self::h($name !== '' ? $name : 'there');
        $safeLink = self::h($link);
        $body = "<p>Hi {$safeName},</p>"
            . '<p>We received a request to reset your password. Use the button below to choose a new one. '
            . 'This link expires in 60 minutes and can be used once.</p>'
            . "<p><a href=\"{$safeLink}\" style=\"display:inline-block;background:#4057F5;color:#fff;"
            . "text-decoration:none;padding:10px 18px;border-radius:8px;font-weight:700\">Reset your password</a></p>"
            . "<p style=\"color:#6b7280;font-size:13px\">If you didn't request this, you can safely ignore this "
            . 'email — your password will not change.</p>'
            . "<p style=\"color:#6b7280;font-size:12px;word-break:break-all\">{$safeLink}</p>";
        Mailer::sendTemplate($email, 'Reset your password', 'Password reset', $body);
    }

    private static function h(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /** Insert a reset row and return the RAW token (goes only into the email/link). */
    public static function createToken(string $email, string $name): string
    {
        $raw = bin2hex(random_bytes(32));
        Db::insert('password_resets', [
            'token_hash' => hash('sha256', $raw),
            'email'      => strtolower(trim($email)),
            'name'       => $name,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + self::TTL_SECONDS),
        ]);
        return $raw;
    }

    /** Validate a raw token without consuming it. Returns the row, or null. */
    public static function validateToken(string $rawToken): ?array
    {
        $row = Db::queryOne(
            'SELECT token_hash, email, name, expires_at, used_at
             FROM password_resets WHERE token_hash = :h',
            [':h' => hash('sha256', $rawToken)]
        );
        if ($row === null || $row['used_at'] !== null) {
            return null;
        }
        if (strtotime((string) $row['expires_at']) <= time()) {
            return null;
        }
        return $row;
    }

    /**
     * Consume a token (single use): validate, stamp used_at, return the email.
     * Called on GET /reset?token=X before the tokenless redirect.
     */
    public static function consumeToken(string $rawToken): ?string
    {
        $row = self::validateToken($rawToken);
        if ($row === null) {
            return null;
        }
        Db::update(
            'password_resets',
            ['used_at' => gmdate('Y-m-d H:i:s')],
            'token_hash = :h',
            [':h' => hash('sha256', $rawToken)]
        );
        return (string) $row['email'];
    }

    /**
     * Complete the reset: set the new password, kill ALL the user's sessions,
     * audit. Returns an error string or null on success.
     */
    public static function complete(string $email, string $newPassword, string $ip): ?string
    {
        $policyError = PasswordPolicy::validate($newPassword);
        if ($policyError !== null) {
            return $policyError;
        }
        $user = User::findByEmail($email);
        if ($user === null || !User::isActive($user)) {
            return 'This reset link is no longer valid.';
        }

        User::updatePasswordHash((int) $user['id'], PasswordPolicy::hash($newPassword));
        Session::terminateAllForUser((int) $user['id']);
        Logger::security('pwreset_completed', "email={$email}");
        Audit::log($email, 'pwreset_completed', $email, '', $ip);

        // Notify the owner that their password changed (security signal).
        $safeName = self::h((string) $user['name'] !== '' ? (string) $user['name'] : 'there');
        Mailer::sendTemplate($email, 'Your password was changed', 'Password changed',
            "<p>Hi {$safeName},</p>"
            . '<p>Your password was just changed and all other sessions were signed out.</p>'
            . "<p style=\"color:#6b7280;font-size:13px\">If this wasn't you, contact your administrator immediately.</p>");
        return null;
    }

    // ── tokenless reset-stage cookie (signed, server-keyed) ──────────────────
    public static function makeResetCookie(string $email): string
    {
        $expiry = time() + self::RESET_COOKIE_TTL;
        $mac = hash_hmac('sha256', $email . '|' . $expiry, Config::appKey());
        return base64_encode($email . '|' . $expiry . '|' . $mac);
    }

    /** Return the bound email if the reset cookie is valid, else null. */
    public static function readResetCookie(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            return null;
        }
        $parts = explode('|', $decoded);
        if (count($parts) !== 3) {
            return null;
        }
        [$email, $expiry, $mac] = $parts;
        if (!ctype_digit($expiry) || (int) $expiry < time()) {
            return null;
        }
        $expected = hash_hmac('sha256', $email . '|' . $expiry, Config::appKey());
        return hash_equals($expected, $mac) ? $email : null;
    }

    /** Purge used/expired reset rows (cleanup.php nightly). */
    public static function purgeExpired(): int
    {
        return Db::delete(
            'password_resets',
            'expires_at < :now OR used_at IS NOT NULL',
            [':now' => gmdate('Y-m-d H:i:s')]
        );
    }
}
