<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Db;
use App\Core\Ids;

/**
 * Message (Model) — thin repository over the messages table.
 *
 * NOTE: reads that return messages to a viewer MUST go through
 * Security\MessageVisibility, never a raw query here (D2). This model is for writes
 * and for internal (staff) counts/lookups.
 */
final class Message
{
    /**
     * @param 'customer'|'agent'|'note'|'system' $fromType
     */
    public static function add(
        string $ticketId,
        string $fromType,
        string $text,
        string $fromName = '',
        string $fromEmail = '',
        bool $isInternal = false
    ): string {
        $messageId = Ids::code('MSG');
        Db::insert('messages', [
            'message_id' => $messageId,
            'ticket_id'  => $ticketId,
            'from_type'  => $fromType,
            'from_name'  => $fromName,
            'from_email' => $fromEmail,
            'text'       => $text,
            'is_internal' => $isInternal ? 1 : 0,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return $messageId;
    }

    public static function countForTicket(string $ticketId): int
    {
        return (int) Db::scalar('SELECT COUNT(*) FROM messages WHERE ticket_id = :t', [':t' => $ticketId]);
    }
}
