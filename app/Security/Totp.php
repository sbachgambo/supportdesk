<?php
declare(strict_types=1);

namespace App\Security;

use App\Core\Config;

/**
 * Totp (D8, RFC 6238) — time-based one-time passwords, no external library.
 *
 *   - Secrets are 20 random bytes, base32-encoded for authenticator apps, and stored
 *     ENCRYPTED AT REST with a key derived from APP_KEY (encryptSecret/decryptSecret).
 *   - Verification allows ±1 time-step (30s window) and rejects a REPLAYED step:
 *     the caller persists the consumed step (users.totp_last_step) and passes it back,
 *     so a code cannot be used twice within its window.
 *   - Comparison is constant-time (hash_equals).
 *
 * Backup codes are generated here but hashed + stored by the enrollment service.
 */
final class Totp
{
    private const PERIOD = 30;
    private const DIGITS = 6;
    private const ALGO = 'sha1';

    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /** The otpauth:// URI for QR codes / manual entry. */
    public static function provisioningUri(string $secret, string $account, string $issuer): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&period=%d&digits=%d',
            rawurlencode($issuer),
            rawurlencode($account),
            $secret,
            rawurlencode($issuer),
            self::PERIOD,
            self::DIGITS
        );
    }

    /** The current 6-digit code for a secret (used by tests + display). */
    public static function codeAt(string $secret, ?int $timestamp = null): string
    {
        $time = $timestamp ?? time();
        return self::hotp($secret, intdiv($time, self::PERIOD));
    }

    /**
     * Verify a submitted code within ±1 step. Returns the consumed step (int) on success,
     * or null on failure. Rejects any step ≤ $lastStep (replay). The caller stores the
     * returned step as the new $lastStep.
     */
    public static function verify(string $secret, string $code, ?int $lastStep, ?int $timestamp = null): ?int
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== self::DIGITS) {
            return null;
        }
        $current = intdiv($timestamp ?? time(), self::PERIOD);
        for ($offset = -1; $offset <= 1; $offset++) {
            $step = $current + $offset;
            if ($lastStep !== null && $step <= $lastStep) {
                continue; // already consumed → replay, skip
            }
            if (hash_equals(self::hotp($secret, $step), $code)) {
                return $step;
            }
        }
        return null;
    }

    /** 10 human-friendly backup codes (returned plaintext once; stored hashed). */
    public static function generateBackupCodes(int $count = 10): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(4))); // 8 hex chars
        }
        return $codes;
    }

    // ── secret encryption at rest (§8, D8) ───────────────────────────────────
    public static function encryptSecret(string $secret): string
    {
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($secret, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv);
        return $iv . $cipher;
    }

    public static function decryptSecret(string $blob): string
    {
        $iv = substr($blob, 0, 16);
        $cipher = substr($blob, 16);
        $plain = openssl_decrypt($cipher, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv);
        return $plain === false ? '' : $plain;
    }

    private static function key(): string
    {
        return hash('sha256', Config::appKey() . '|totp', true);
    }

    // ── HOTP (RFC 4226) ──────────────────────────────────────────────────────
    private static function hotp(string $base32Secret, int $counter): string
    {
        $key = self::base32Decode($base32Secret);
        $binCounter = pack('N*', 0) . pack('N*', $counter); // 64-bit big-endian
        $hash = hash_hmac(self::ALGO, $binCounter, $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $truncated = (
            ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF)
        ) % (10 ** self::DIGITS);
        return str_pad((string) $truncated, self::DIGITS, '0', STR_PAD_LEFT);
    }

    // ── base32 (RFC 4648) ────────────────────────────────────────────────────
    private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private static function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::B32[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }
        return $out;
    }

    private static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(rtrim($b32, '='));
        $bits = '';
        foreach (str_split($b32) as $c) {
            $pos = strpos(self::B32, $c);
            if ($pos === false) {
                continue;
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }
}
