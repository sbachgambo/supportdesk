<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * DigestService (§14) — the 08:00 daily digest to active admins: new/resolved in the
 * last 24h, currently open, active SLA breaches, and per-agent workload.
 * (08:00, not midnight — a digest nobody reads at midnight is theatre.)
 */
final class DigestService
{
    /** Gather the digest figures (also used by the test to assert content). */
    public static function gather(): array
    {
        $since = gmdate('Y-m-d H:i:s', time() - 86400);
        return [
            'new_24h'      => (int) Db::scalar('SELECT COUNT(*) FROM tickets WHERE created_at >= :s', [':s' => $since]),
            'resolved_24h' => (int) Db::scalar('SELECT COUNT(*) FROM tickets WHERE resolved_at >= :s', [':s' => $since]),
            'open_now'     => (int) Db::scalar("SELECT COUNT(*) FROM tickets WHERE status = 'open'"),
            'pending_now'  => (int) Db::scalar("SELECT COUNT(*) FROM tickets WHERE status = 'pending'"),
            'breaches'     => (int) Db::scalar(
                "SELECT COUNT(*) FROM tickets WHERE sla_response_status = 'breached' OR sla_resolution_status = 'breached'"
            ),
            'workload'     => Db::queryAll(
                "SELECT assigned_to, COUNT(*) AS c FROM tickets
                 WHERE status IN ('open','pending') AND assigned_to IS NOT NULL
                 GROUP BY assigned_to ORDER BY c DESC"
            ),
        ];
    }

    /** Build + send the digest to every active admin. Returns the number sent. */
    public static function run(): int
    {
        $d = self::gather();
        $rows = '<ul>'
            . "<li>New tickets (24h): {$d['new_24h']}</li>"
            . "<li>Resolved (24h): {$d['resolved_24h']}</li>"
            . "<li>Open now: {$d['open_now']}</li>"
            . "<li>Pending now: {$d['pending_now']}</li>"
            . "<li>Active SLA breaches: {$d['breaches']}</li>"
            . '</ul>';
        $body = "<p>Here is today's support summary.</p>{$rows}";

        $sent = 0;
        foreach (Db::queryAll("SELECT email FROM users WHERE role = 'admin' AND active = 1") as $admin) {
            if (Mailer::sendTemplate((string) $admin['email'], 'Daily support digest', 'Daily digest', $body) === 'sent') {
                $sent++;
            }
        }
        return $sent;
    }
}
