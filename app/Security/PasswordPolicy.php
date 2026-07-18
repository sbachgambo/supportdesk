<?php
declare(strict_types=1);

namespace App\Security;

use App\Core\Config;

/**
 * PasswordPolicy (§10.3) — hashing, verification, and NIST SP 800-63B policy.
 *
 * Policy is NIST-aligned, not folklore:
 *   - minimum 12 characters; NO composition rules (they reduce entropy);
 *   - no periodic rotation; allow all printable chars incl. spaces/Unicode;
 *   - paste is allowed (that's the frontend's job — nothing here forbids it);
 *   - breached-password denylist (local top-N list, or HIBP k-anonymity).
 *
 * Hashing: Argon2id when available (OWASP-min params), else bcrypt cost 12.
 * bcrypt's 72-byte truncation is real, so input is capped at 72 bytes and longer
 * input is REJECTED rather than silently truncated.
 *
 * Legacy migration (D4): a stored value beginning "v2$" verifies via the
 * prototype's salted-iterated path and is rewritten with password_hash() on the
 * next successful login (handled by Auth). See DEV_NOTES on the v2 parameters.
 */
final class PasswordPolicy
{
    public const MAX_BYTES = 72;               // bcrypt truncation boundary
    private const V2_ITERATIONS = 100000;      // see DEV_NOTES: confirm vs GAS before import
    private const V2_KEYLEN = 32;

    /** A fixed hash to verify against for non-existent users (timing parity, §10.3). */
    private static ?string $dummyHash = null;

    public static function minLength(): int
    {
        return Config::int('PASSWORD_MIN_LENGTH', 12);
    }

    /**
     * Validate a candidate password against policy. Returns an error string, or
     * null if acceptable. Used at set-password time (create user, reset, change).
     */
    public static function validate(string $password): ?string
    {
        if (strlen($password) > self::MAX_BYTES) {
            return 'Password must be at most ' . self::MAX_BYTES . ' bytes. '
                 . 'Long passphrases are fine — just a little shorter.';
        }
        // Count characters (not bytes) for the minimum, so Unicode isn't penalised.
        if (mb_strlen($password) < self::minLength()) {
            return 'Password must be at least ' . self::minLength() . ' characters.';
        }
        if (self::isBreached($password)) {
            return 'That password has appeared in known breaches. Please choose another.';
        }
        return null;
    }

    /**
     * The active hashing algo + options. Production uses OWASP-strength params;
     * the `testing` environment uses cheaper params so the suite isn't dominated by
     * KDF cost — the code path (algo selection, verify, rehash) is otherwise identical.
     *
     * @return array{0:string, 1:array<string,int>}
     */
    private static function hashParams(): array
    {
        $fast = Config::isLoaded() && Config::string('APP_ENV', 'production') === 'testing';
        if (defined('PASSWORD_ARGON2ID')) {
            return [PASSWORD_ARGON2ID, $fast
                ? ['memory_cost' => 8192, 'time_cost' => 1, 'threads' => 1]
                : ['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1]]; // 19 MiB, OWASP min
        }
        return [PASSWORD_BCRYPT, ['cost' => $fast ? 8 : 12]];
    }

    /** Hash for storage. Argon2id preferred; bcrypt cost 12 fallback (§10.3). */
    public static function hash(string $password): string
    {
        [$algo, $options] = self::hashParams();
        return password_hash($password, $algo, $options);
    }

    /**
     * Verify a password against a stored hash. Handles both modern password_hash()
     * values and legacy "v2$salt$hash" values. Constant-ish time within each branch.
     */
    public static function verify(string $password, string $stored): bool
    {
        if (str_starts_with($stored, 'v2$')) {
            return self::verifyLegacy($password, $stored);
        }
        return password_verify($password, $stored);
    }

    /** True if the stored hash should be re-hashed (legacy, or weaker params). */
    public static function needsRehash(string $stored): bool
    {
        if (str_starts_with($stored, 'v2$')) {
            return true; // always upgrade legacy on next login (D4)
        }
        [$algo, $options] = self::hashParams();
        return password_needs_rehash($stored, $algo, $options);
    }

    /**
     * Run a verify against a dummy hash to normalise timing (§10.3). The dummy is
     * generated once at the CURRENT params so its cost matches real hashes — whether
     * that's production argon2id or a bcrypt fallback — keeping unknown-email and
     * wrong-password response times comparable.
     */
    public static function verifyDummy(string $password): void
    {
        if (self::$dummyHash === null) {
            self::$dummyHash = self::hash('timing-normalization-dummy-value');
        }
        password_verify($password, self::$dummyHash);
    }

    // ── Breach check (§10.3) ─────────────────────────────────────────────────
    public static function isBreached(string $password): bool
    {
        $mode = Config::string('PASSWORD_BREACH_CHECK', 'local');
        return match ($mode) {
            'off'   => false,
            'hibp'  => self::isBreachedHibp($password),
            default => self::isBreachedLocal($password),
        };
    }

    /** @var array<string,true>|null */
    private static ?array $localList = null;

    private static function isBreachedLocal(string $password): bool
    {
        if (self::$localList === null) {
            self::$localList = [];
            $file = (defined('P3A_ROOT') ? P3A_ROOT : dirname(__DIR__, 2))
                  . '/app/Security/data/common_passwords.txt';
            if (is_file($file)) {
                foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    $line = trim($line);
                    if ($line !== '' && $line[0] !== '#') {
                        self::$localList[strtolower($line)] = true;
                    }
                }
            }
        }
        return isset(self::$localList[strtolower($password)]);
    }

    /**
     * HIBP k-anonymity range API (§10.3): SHA-1 the candidate, send only the first
     * 5 hex chars, compare suffixes locally. The password never leaves the server.
     * Fail-open on any network error. Wired for completeness; default mode is local.
     */
    private static function isBreachedHibp(string $password): bool
    {
        $sha1 = strtoupper(sha1($password));
        $prefix = substr($sha1, 0, 5);
        $suffix = substr($sha1, 5);

        $ctx = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
        $body = file_get_contents("https://api.pwnedpasswords.com/range/{$prefix}", false, $ctx);
        if ($body === false) {
            return false; // fail-open
        }
        foreach (explode("\n", $body) as $line) {
            $hashSuffix = strtoupper(trim(explode(':', $line)[0] ?? ''));
            if ($hashSuffix === $suffix) {
                return true;
            }
        }
        return false;
    }

    // ── Legacy v2 hashing (D4) ───────────────────────────────────────────────
    /**
     * Create a legacy "v2$salt$digest" value. Used by the GAS importer and by
     * tests. NB: the exact iteration/keylen must be reconciled with the actual GAS
     * prototype before a real import (see DEV_NOTES) — this is a documented
     * assumption, not the confirmed original algorithm.
     */
    public static function makeLegacy(string $password, ?string $saltHex = null): string
    {
        $saltHex ??= bin2hex(random_bytes(16));
        $salt = hex2bin($saltHex);
        $digest = hash_pbkdf2('sha256', $password, (string) $salt, self::V2_ITERATIONS, self::V2_KEYLEN * 2, false);
        return 'v2$' . $saltHex . '$' . $digest;
    }

    private static function verifyLegacy(string $password, string $stored): bool
    {
        $parts = explode('$', $stored);
        if (count($parts) !== 3) {
            return false;
        }
        [, $saltHex, $digest] = $parts;
        $expected = self::makeLegacy($password, $saltHex);
        return hash_equals($expected, $stored);
    }
}
