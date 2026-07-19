<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Ids;

/**
 * NotificationService — writes in-app notifications (bell + unread badge). The
 * notification UI and email fan-out arrive in Phase 11; Phase 5 writes the rows so
 * assignment / reply events are recorded from the start. Notifications are
 * ownership-scoped by agent_email.
 */
final class NotificationService
{
    public static function create(string $agentEmail, string $type, string $message, ?string $ticketId = null): void
    {
        if ($agentEmail === '') {
            return;
        }
        Db::insert('notifications', [
            'notif_id'    => Ids::code('NTF'),
            'agent_email' => $agentEmail,
            'type'        => $type,
            'message'     => mb_substr($message, 0, 300),
            'ticket_id'   => $ticketId,
            'is_read'     => 0,
            'created_at'  => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
