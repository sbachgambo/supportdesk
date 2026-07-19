<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Security\RateLimit;

/**
 * CleanupService (§14) — nightly housekeeping: purge expired rate-limit rows,
 * expired sessions, used/expired reset tokens, notifications beyond the per-user cap
 * or older than 30 days, and log files older than 90 days.
 */
final class CleanupService
{
    private const NOTIF_CAP = 300;
    private const NOTIF_MAX_AGE_DAYS = 30;
    private const LOG_MAX_AGE_DAYS = 90;

    /** @return array<string,int> counts purged per category */
    public static function run(): array
    {
        $now = gmdate('Y-m-d H:i:s');

        $rateLimits = RateLimit::purgeExpired();
        $sessions = Db::delete('sessions', 'expires_at < :now', [':now' => $now]);
        $resets = PasswordReset::purgeExpired();

        $notifAge = gmdate('Y-m-d H:i:s', time() - self::NOTIF_MAX_AGE_DAYS * 86400);
        $notifsOld = Db::delete('notifications', 'created_at < :cut', [':cut' => $notifAge]);
        $notifsCapped = self::trimNotificationsToCap();

        $logs = self::purgeOldLogs();

        return [
            'rate_limits'    => $rateLimits,
            'sessions'       => $sessions,
            'reset_tokens'   => $resets,
            'notifications'  => $notifsOld + $notifsCapped,
            'logs'           => $logs,
        ];
    }

    /** Keep only the newest NOTIF_CAP notifications per agent. */
    private static function trimNotificationsToCap(): int
    {
        $removed = 0;
        $agents = Db::queryAll('SELECT DISTINCT agent_email FROM notifications');
        foreach ($agents as $a) {
            $email = (string) $a['agent_email'];
            $count = (int) Db::scalar('SELECT COUNT(*) FROM notifications WHERE agent_email = :e', [':e' => $email]);
            if ($count <= self::NOTIF_CAP) {
                continue;
            }
            // find the created_at cutoff (the NOTIF_CAP-th newest) and delete older.
            $cutoffRow = Db::queryOne(
                'SELECT created_at FROM notifications WHERE agent_email = :e
                 ORDER BY created_at DESC, id DESC LIMIT 1 OFFSET :off',
                [':e' => $email, ':off' => self::NOTIF_CAP - 1]
            );
            if ($cutoffRow !== null) {
                $removed += Db::delete(
                    'notifications',
                    'agent_email = :e AND created_at < :cut',
                    [':e' => $email, ':cut' => $cutoffRow['created_at']]
                );
            }
        }
        return $removed;
    }

    private static function purgeOldLogs(): int
    {
        $dir = (defined('P3A_ROOT') ? P3A_ROOT : dirname(__DIR__, 2)) . '/storage/logs';
        if (!is_dir($dir)) {
            return 0;
        }
        $cutoff = time() - self::LOG_MAX_AGE_DAYS * 86400;
        $removed = 0;
        foreach (glob($dir . '/*.log*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < $cutoff) {
                if (unlink($file)) {
                    $removed++;
                }
            }
        }
        return $removed;
    }
}
