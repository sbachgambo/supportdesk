<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Session;
use App\Core\ValidationException;
use App\Models\Notification;

/**
 * NotificationActions (§3) — the bell + unread badge. OWNERSHIP-SCOPED: every action
 * operates only on the current agent's notifications (agent_email = session email),
 * so one agent can never read or mark another's.
 */
final class NotificationActions
{
    public function getNotifications(array $payload, Request $request): array
    {
        $email = (string) Session::email();
        return [
            'notifications' => Notification::forAgent($email),
            'unread'        => Notification::unreadCount($email),
        ];
    }

    public function getUnreadCount(array $payload, Request $request): array
    {
        return ['unread' => Notification::unreadCount((string) Session::email())];
    }

    public function markNotificationRead(array $payload, Request $request): array
    {
        $notifId = (string) ($payload['notif_id'] ?? '');
        // Scoped by the session email — marking another agent's notification affects 0 rows.
        $changed = Notification::markRead($notifId, (string) Session::email());
        if (!$changed) {
            throw new ValidationException('Notification not found.');
        }
        return ['ok' => true];
    }

    public function markAllNotificationsRead(array $payload, Request $request): array
    {
        return ['ok' => true, 'marked' => Notification::markAllRead((string) Session::email())];
    }
}
