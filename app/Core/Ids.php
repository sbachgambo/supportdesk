<?php
declare(strict_types=1);

namespace App\Core;

use App\Models\AppConfig;

/**
 * Ids — human-facing identifier generation.
 *
 *   ticketId()  → "TKT-2026-0001" (prefix from config, per-year sequence)
 *   code()      → short unique codes for messages, notifications, etc.
 *
 * Sequence assignment for tickets happens inside a transaction in TicketService so
 * two concurrent creates cannot collide on the same number.
 */
final class Ids
{
    /** Next ticket id for the current UTC year, e.g. TKT-2026-0001. */
    public static function nextTicketId(): string
    {
        $prefix = AppConfig::get('ticket_prefix', 'TKT');
        $year = gmdate('Y');
        $like = $prefix . '-' . $year . '-%';
        $max = Db::scalar(
            'SELECT MAX(CAST(SUBSTRING_INDEX(ticket_id, :dash, -1) AS UNSIGNED))
             FROM tickets WHERE ticket_id LIKE :like',
            [':dash' => '-', ':like' => $like]
        );
        $next = ((int) $max) + 1;
        return sprintf('%s-%s-%04d', $prefix, $year, $next);
    }

    /**
     * A short random code with a prefix, e.g. code('MSG') → "MSG-1a2b3c4d5e" (2^48
     * of entropy). Collisions are astronomically unlikely; the UNIQUE constraint on
     * the target column is the backstop (an insert would fail rather than duplicate),
     * so no dynamic-identifier existence query is needed — keeping §10.1 absolute
     * (no interpolated table/column names, ever).
     */
    public static function code(string $prefix): string
    {
        return $prefix . '-' . bin2hex(random_bytes(6));
    }
}
