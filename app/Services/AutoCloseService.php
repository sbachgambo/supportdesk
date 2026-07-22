<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Models\AppConfig;
use App\Models\Ticket;
use App\Security\Audit;

/**
 * AutoCloseService (§14) — nightly housekeeping: tickets left in 'resolved' for
 * auto_close_days (config; default 7; 0 disables) are moved to 'closed', audited,
 * and the customer receives a one-time "how did we do?" email if they have not
 * already rated the ticket. Idempotent: closed tickets never match again, and a
 * customer reply still reopens a closed ticket (customerReply semantics).
 */
final class AutoCloseService
{
    public static function run(): int
    {
        $days = (int) AppConfig::get('auto_close_days', '7');
        if ($days <= 0) {
            return 0;
        }
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $rows = Db::queryAll(
            "SELECT ticket_id, subject, customer_name, customer_email, csat_rating
             FROM tickets
             WHERE status = 'resolved' AND resolved_at IS NOT NULL AND resolved_at <= :cutoff
             ORDER BY resolved_at ASC
             LIMIT 500",
            [':cutoff' => $cutoff]
        );

        foreach ($rows as $t) {
            $tid = (string) $t['ticket_id'];
            Ticket::update($tid, ['status' => 'closed']);
            Audit::log('system', 'ticket_auto_close', $tid, "resolved for {$days}+ days");
            if ($t['csat_rating'] === null) {
                CustomerMail::csatRequest($t);
            }
        }
        return count($rows);
    }
}
