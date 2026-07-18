<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Csrf (§10.8, D6) — two mechanisms, one gate.
 *
 * Authenticated forms → a SESSION-BOUND token, derived from the current session's
 * token hash via HMAC(APP_KEY). It rotates automatically whenever the session token
 * rotates (login, privilege change) and needs no separate storage.
 *
 * Public forms (submit, status, reset request, widget) → a STATELESS signed token:
 *   base64( expiry . '|' . HMAC_SHA256(purpose . '|' . expiry, APP_KEY) )
 * validated by recomputing the HMAC with hash_equals() and checking a 10-minute
 * expiry. No cookie or session is required, so it works inside a third-party iframe
 * where SameSite=Lax withholds the cookie.
 *
 * All comparisons use hash_equals() (constant time).
 */
final class Csrf
{
    private const PUBLIC_TTL = 600; // 10 minutes

    // ── Authenticated (session-bound) ────────────────────────────────────────
    public static function token(): string
    {
        $bind = Session::currentTokenHash();
        if ($bind === null) {
            return '';
        }
        return hash_hmac('sha256', $bind . '|csrf', Config::appKey());
    }

    public static function validate(?string $token): bool
    {
        $expected = self::token();
        if ($expected === '' || $token === null || $token === '') {
            return false;
        }
        return hash_equals($expected, $token);
    }

    // ── Public (stateless HMAC) ──────────────────────────────────────────────
    public static function publicToken(string $purpose): string
    {
        $expiry = time() + self::PUBLIC_TTL;
        $mac = hash_hmac('sha256', $purpose . '|' . $expiry, Config::appKey());
        return base64_encode($expiry . '|' . $mac);
    }

    public static function validatePublic(?string $token, string $purpose): bool
    {
        if ($token === null || $token === '') {
            return false;
        }
        $decoded = base64_decode($token, true);
        if ($decoded === false) {
            return false;
        }
        $parts = explode('|', $decoded, 2);
        if (count($parts) !== 2) {
            return false;
        }
        [$expiry, $mac] = $parts;
        if (!ctype_digit($expiry) || (int) $expiry < time()) {
            return false; // expired or malformed
        }
        $expected = hash_hmac('sha256', $purpose . '|' . $expiry, Config::appKey());
        return hash_equals($expected, $mac);
    }
}
