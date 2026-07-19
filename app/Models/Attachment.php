<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Db;

/**
 * Attachment (Model) — thin repository over attachments. original_name is DISPLAY
 * ONLY and is never used to build a path (§10.7). Visibility filtering of internal
 * attachments goes through Security\MessageVisibility, not raw reads here.
 */
final class Attachment
{
    public static function create(array $data): int
    {
        return (int) Db::insert('attachments', [
            'ticket_id'     => $data['ticket_id'],
            'message_id'    => $data['message_id'] ?? null,
            'original_name' => $data['original_name'],
            'stored_name'   => $data['stored_name'],
            'mime_type'     => $data['mime_type'],
            'size_bytes'    => $data['size_bytes'],
            'sha256'        => $data['sha256'],
            'is_internal'   => !empty($data['is_internal']) ? 1 : 0,
            'uploaded_by'   => $data['uploaded_by'],
            'uploaded_at'   => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public static function find(int $id): ?array
    {
        return Db::queryOne('SELECT * FROM attachments WHERE id = :id', [':id' => $id]);
    }

    public static function countForTicket(string $ticketId): int
    {
        return (int) Db::scalar('SELECT COUNT(*) FROM attachments WHERE ticket_id = :t', [':t' => $ticketId]);
    }
}
