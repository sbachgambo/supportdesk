<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Db;

/**
 * Notification (Model) — thin repository over notifications. Every read/write is
 * scoped by agent_email so notifications are ownership-isolated (an agent can only
 * see and mutate their own).
 */
final class Notification
{
    public static function forAgent(string $email, int $limit = 20): array
    {
        $params = [':e' => $email, ':limit' => $limit];
        return Db::queryAll(
            'SELECT notif_id, type, message, ticket_id, is_read, created_at
             FROM notifications WHERE agent_email = :e
             ORDER BY created_at DESC, id DESC LIMIT :limit',
            $params
        );
    }

    public static function unreadCount(string $email): int
    {
        return (int) Db::scalar(
            'SELECT COUNT(*) FROM notifications WHERE agent_email = :e AND is_read = 0',
            [':e' => $email]
        );
    }

    /** Mark one read — ONLY if it belongs to $email. Returns true if a row changed. */
    public static function markRead(string $notifId, string $email): bool
    {
        return Db::update(
            'notifications',
            ['is_read' => 1],
            'notif_id = :n AND agent_email = :e',
            [':n' => $notifId, ':e' => $email]
        ) > 0;
    }

    public static function markAllRead(string $email): int
    {
        return Db::update('notifications', ['is_read' => 1], 'agent_email = :e AND is_read = 0', [':e' => $email]);
    }
}
