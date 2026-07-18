<?php
declare(strict_types=1);

namespace App\Security;

use App\Core\Db;
use Throwable;

/**
 * RateLimit (§10.6) — DB-backed fixed-window limiter over the rate_limits table.
 *
 * FAIL-OPEN by deliberate design (carried from the prototype): if the limiter's
 * own query throws, the request proceeds. A limiter outage must not become a
 * self-inflicted denial of service. (For login this is moot anyway — without a DB
 * you cannot verify a password, so no login succeeds regardless.)
 *
 * Buckets (§7): SUBMIT, SUBMITGLOBAL, STATUS, PWRESET, LOGINFAIL, LOGINLOCK, TOTPFAIL.
 * Per-email is the primary key; per-IP is layered on where emails can be rotated
 * freely (login, submit) via a separate key.
 */
final class RateLimit
{
    /** Current hit count in the active window; 0 if none or window expired. Fail-open → 0. */
    public static function hits(string $bucket, string $key): int
    {
        try {
            $row = Db::queryOne(
                'SELECT hits, expires_at FROM rate_limits WHERE bucket = :b AND bucket_key = :k',
                [':b' => $bucket, ':k' => $key]
            );
            if ($row === null) {
                return 0;
            }
            if (strtotime((string) $row['expires_at']) <= time()) {
                return 0; // window has expired; treat as empty
            }
            return (int) $row['hits'];
        } catch (Throwable $e) {
            return 0; // fail-open
        }
    }

    /** True if the count has reached the limit. */
    public static function exceeded(string $bucket, string $key, int $limit): bool
    {
        return self::hits($bucket, $key) >= $limit;
    }

    /**
     * Record one hit within a window of $windowSeconds and return the new count.
     * Starts a fresh window if none is active. Fail-open → returns 1.
     */
    public static function recordHit(string $bucket, string $key, int $windowSeconds): int
    {
        try {
            $now = time();
            $row = Db::queryOne(
                'SELECT hits, expires_at FROM rate_limits WHERE bucket = :b AND bucket_key = :k',
                [':b' => $bucket, ':k' => $key]
            );

            $nowStr = gmdate('Y-m-d H:i:s', $now);
            $expStr = gmdate('Y-m-d H:i:s', $now + $windowSeconds);

            if ($row === null) {
                Db::query(
                    'INSERT INTO rate_limits (bucket, bucket_key, hits, window_start, expires_at)
                     VALUES (:b, :k, 1, :ws, :ex)
                     ON DUPLICATE KEY UPDATE hits = hits + 1',
                    [':b' => $bucket, ':k' => $key, ':ws' => $nowStr, ':ex' => $expStr]
                );
                return 1;
            }

            if (strtotime((string) $row['expires_at']) <= $now) {
                // window expired → reset to a new window
                Db::update(
                    'rate_limits',
                    ['hits' => 1, 'window_start' => $nowStr, 'expires_at' => $expStr],
                    'bucket = :b AND bucket_key = :k',
                    [':b' => $bucket, ':k' => $key]
                );
                return 1;
            }

            Db::query(
                'UPDATE rate_limits SET hits = hits + 1 WHERE bucket = :b AND bucket_key = :k',
                [':b' => $bucket, ':k' => $key]
            );
            return (int) $row['hits'] + 1;
        } catch (Throwable $e) {
            return 1; // fail-open
        }
    }

    /** Clear a bucket/key (e.g. reset login failures after a successful login). */
    public static function clear(string $bucket, string $key): void
    {
        try {
            Db::delete('rate_limits', 'bucket = :b AND bucket_key = :k', [':b' => $bucket, ':k' => $key]);
        } catch (Throwable $e) {
            // fail-open: nothing to do
        }
    }

    /** Remaining seconds until the active window expires (0 if none). */
    public static function retryAfter(string $bucket, string $key): int
    {
        try {
            $row = Db::queryOne(
                'SELECT expires_at FROM rate_limits WHERE bucket = :b AND bucket_key = :k',
                [':b' => $bucket, ':k' => $key]
            );
            if ($row === null) {
                return 0;
            }
            return max(0, strtotime((string) $row['expires_at']) - time());
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** Purge expired rows (called by cleanup.php nightly, §14). */
    public static function purgeExpired(): int
    {
        try {
            return Db::delete('rate_limits', 'expires_at < :now', [':now' => gmdate('Y-m-d H:i:s')]);
        } catch (Throwable $e) {
            return 0;
        }
    }
}
