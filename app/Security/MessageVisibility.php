<?php
declare(strict_types=1);

namespace App\Security;

use App\Core\Db;

/**
 * MessageVisibility (D2, §10.5) — the SINGLE gate for returning a ticket's messages.
 *
 * EVERY path that returns messages MUST call this — the authenticated customer view,
 * the anonymous status lookup, and the staff detail view. There is to be no ad-hoc
 * `if ($msg->is_internal) continue;` anywhere else. Duplicate filtering logic is
 * exactly how internal notes leak; this is tested once and trusted everywhere.
 *
 * Staff (agent/admin) see everything. Customers and anonymous lookups NEVER receive
 * `is_internal = 1`, `from_type = 'note'`, or `from_type = 'system'`. The same rule
 * governs attachment visibility (attachments.is_internal), used from Phase 6.
 */
final class MessageVisibility
{
    private const STAFF_ROLES = ['agent', 'admin'];

    /** True if this viewer role may see internal notes / system messages. */
    public static function seesInternal(string $viewerRole): bool
    {
        return in_array($viewerRole, self::STAFF_ROLES, true);
    }

    /**
     * Messages visible to $viewerRole on $ticketId, oldest first.
     * $viewerRole: 'admin'|'agent' (all) or anything else incl. 'customer'/'anonymous' (filtered).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function for(string $viewerRole, string $ticketId): array
    {
        if (self::seesInternal($viewerRole)) {
            return Db::queryAll(
                'SELECT message_id, ticket_id, from_type, from_name, from_email, text, is_internal, created_at
                 FROM messages WHERE ticket_id = :t ORDER BY created_at ASC, id ASC',
                [':t' => $ticketId]
            );
        }

        // Restricted view: only customer/agent messages that are not internal.
        return Db::queryAll(
            "SELECT message_id, ticket_id, from_type, from_name, text, created_at
             FROM messages
             WHERE ticket_id = :t
               AND is_internal = 0
               AND from_type IN ('customer','agent')
             ORDER BY created_at ASC, id ASC",
            [':t' => $ticketId]
        );
    }

    /**
     * Attachments visible to $viewerRole on $ticketId (Phase 6 uses this). Internal
     * attachments are hidden from non-staff exactly like internal messages.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function attachmentsFor(string $viewerRole, string $ticketId): array
    {
        if (self::seesInternal($viewerRole)) {
            return Db::queryAll(
                'SELECT id, ticket_id, message_id, original_name, mime_type, size_bytes, is_internal, uploaded_at
                 FROM attachments WHERE ticket_id = :t ORDER BY uploaded_at ASC',
                [':t' => $ticketId]
            );
        }
        return Db::queryAll(
            'SELECT id, ticket_id, message_id, original_name, mime_type, size_bytes, uploaded_at
             FROM attachments WHERE ticket_id = :t AND is_internal = 0 ORDER BY uploaded_at ASC',
            [':t' => $ticketId]
        );
    }
}
