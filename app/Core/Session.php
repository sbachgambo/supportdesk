<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Session (§10.4) — DB-backed sessions with HASHED tokens.
 *
 * The raw token is a 32-byte random value sent as a cookie; the DB stores only
 * sha256(token). A leaked database therefore grants no sessions. DB-backed (not
 * file-backed) because on shared hosting the default session save path may be
 * readable by other tenants.
 *
 * Cookie flags: HttpOnly, Secure (production), SameSite=Lax (D7), Path=/, host-only.
 * Concurrent cap: MAX_SESSIONS_PER_USER; the oldest is evicted on overflow.
 * Termination helpers implement the deactivation / reset / change rules (§10.4).
 */
final class Session
{
    public const COOKIE = 'p3a_session';

    /** @var array<string,mixed>|null the validated current session row (+ raw hash) */
    private static ?array $current = null;

    /**
     * Create a session, set the cookie, enforce the per-user cap, and return the
     * raw token. Called on successful login (and after a privilege change to rotate).
     */
    public static function start(
        int $userId,
        string $email,
        string $role,
        string $ip,
        string $userAgent,
        bool $mfaVerified
    ): string {
        $raw = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $raw);

        $lifetimeHours = Config::int('SESSION_LIFETIME_HOURS', 12);
        $now = time();
        $nowStr = gmdate('Y-m-d H:i:s', $now);
        $expiresStr = gmdate('Y-m-d H:i:s', $now + $lifetimeHours * 3600);

        Db::insert('sessions', [
            'token_hash'   => $tokenHash,
            'user_id'      => $userId,
            'email'        => $email,
            'role'         => $role,
            'ip_address'   => $ip,
            'user_agent'   => substr($userAgent, 0, 255),
            'mfa_verified' => $mfaVerified ? 1 : 0,
            'created_at'   => $nowStr,
            'last_seen_at' => $nowStr,
            'expires_at'   => $expiresStr,
        ]);

        self::enforceCap($userId);
        self::sendCookie($raw, $now + $lifetimeHours * 3600);

        self::$current = [
            'token_hash'   => $tokenHash,
            'user_id'      => $userId,
            'email'        => $email,
            'role'         => $role,
            'mfa_verified' => $mfaVerified ? 1 : 0,
        ];

        return $raw;
    }

    /** Validate the cookie against the DB; caches and returns the row, or null. */
    public static function validate(Request $request): ?array
    {
        $raw = $request->cookie(self::COOKIE);
        if ($raw === null || $raw === '') {
            return null;
        }
        $tokenHash = hash('sha256', $raw);

        $row = Db::queryOne(
            'SELECT token_hash, user_id, email, role, mfa_verified, expires_at, last_seen_at
             FROM sessions WHERE token_hash = :h',
            [':h' => $tokenHash]
        );
        if ($row === null) {
            self::clearCookie();
            return null;
        }
        if (strtotime((string) $row['expires_at']) <= time()) {
            Db::delete('sessions', 'token_hash = :h', [':h' => $tokenHash]);
            self::clearCookie();
            return null;
        }

        // Throttle last_seen writes to once per 60s to avoid a write per request.
        if (time() - strtotime((string) $row['last_seen_at']) > 60) {
            Db::update('sessions', ['last_seen_at' => gmdate('Y-m-d H:i:s')], 'token_hash = :h', [':h' => $tokenHash]);
        }

        self::$current = $row;
        return $row;
    }

    public static function current(): ?array
    {
        return self::$current;
    }

    public static function currentTokenHash(): ?string
    {
        return isset(self::$current['token_hash']) ? (string) self::$current['token_hash'] : null;
    }

    public static function userId(): ?int
    {
        return isset(self::$current['user_id']) ? (int) self::$current['user_id'] : null;
    }

    public static function role(): ?string
    {
        return isset(self::$current['role']) ? (string) self::$current['role'] : null;
    }

    public static function email(): ?string
    {
        return isset(self::$current['email']) ? (string) self::$current['email'] : null;
    }

    public static function isMfaVerified(): bool
    {
        return (int) (self::$current['mfa_verified'] ?? 0) === 1;
    }

    /** Mark the current session MFA-verified (Phase 12 TOTP flow). */
    public static function markMfaVerified(): void
    {
        $hash = self::currentTokenHash();
        if ($hash === null) {
            return;
        }
        Db::update('sessions', ['mfa_verified' => 1], 'token_hash = :h', [':h' => $hash]);
        self::$current['mfa_verified'] = 1;
    }

    /** Destroy the current session (logout). */
    public static function destroy(): void
    {
        $hash = self::currentTokenHash();
        if ($hash !== null) {
            Db::delete('sessions', 'token_hash = :h', [':h' => $hash]);
        }
        self::clearCookie();
        self::$current = null;
    }

    /** Kill ALL of a user's sessions (deactivation, admin/self-service reset). */
    public static function terminateAllForUser(int $userId): int
    {
        return Db::delete('sessions', 'user_id = :u', [':u' => $userId]);
    }

    /** Kill all of a user's sessions EXCEPT one (self password change keeps current). */
    public static function terminateOthersForUser(int $userId, string $keepTokenHash): int
    {
        return Db::delete(
            'sessions',
            'user_id = :u AND token_hash <> :keep',
            [':u' => $userId, ':keep' => $keepTokenHash]
        );
    }

    /** Purge expired sessions (cleanup.php nightly). */
    public static function purgeExpired(): int
    {
        return Db::delete('sessions', 'expires_at < :now', [':now' => gmdate('Y-m-d H:i:s')]);
    }

    // ── internals ────────────────────────────────────────────────────────────
    private static function enforceCap(int $userId): void
    {
        $max = Config::int('MAX_SESSIONS_PER_USER', 5);
        $rows = Db::queryAll(
            'SELECT token_hash FROM sessions WHERE user_id = :u ORDER BY created_at ASC, token_hash ASC',
            [':u' => $userId]
        );
        $excess = count($rows) - $max;
        for ($i = 0; $i < $excess; $i++) {
            Db::delete('sessions', 'token_hash = :h', [':h' => $rows[$i]['token_hash']]);
        }
    }

    private static function sendCookie(string $raw, int $expiresAt): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie(self::COOKIE, $raw, [
            'expires'  => $expiresAt,
            'path'     => '/',
            'httponly' => true,
            'secure'   => Config::isProduction(), // always https in prod (§10.13); http in local dev
            'samesite' => 'Lax',                   // D7
        ]);
    }

    private static function clearCookie(): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie(self::COOKIE, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'httponly' => true,
            'secure'   => Config::isProduction(),
            'samesite' => 'Lax',
        ]);
    }
}
