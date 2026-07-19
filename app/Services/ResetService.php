<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Logger;
use App\Security\Audit;

/**
 * ResetService (§3, §4, §10.11, §18) — ticket-data reset.
 *
 *   - Requires the EXACT typed confirmation phrase, validated server-side.
 *   - BACKS UP FIRST and only deletes if the backup succeeded (§18).
 *   - Deletes ticket data (tickets → cascades messages/attachments, plus
 *     notifications) but PRESERVES the audit log, config, users, categories,
 *     canned responses, routing rules, and KB — "the audit log survives ticket-data
 *     reset by design" (§10.11).
 *   - Appends a reset event to the audit log and ALERTS ALL ADMINS (§4).
 */
final class ResetService
{
    public const CONFIRM_PHRASE = 'RESET TICKET DATA';

    /**
     * @return array{ok:bool, error?:string, backup?:string, deleted?:array<string,int>}
     */
    public static function reset(string $typedPhrase, string $actorEmail, string $ip): array
    {
        // Exact-match, server-side. Trailing/leading whitespace tolerated, case exact.
        if (trim($typedPhrase) !== self::CONFIRM_PHRASE) {
            return ['ok' => false, 'error' => 'The confirmation phrase did not match. Nothing was changed.'];
        }

        // Back up first — abort the whole operation if it fails (§18).
        try {
            $backupPath = BackupService::create();
        } catch (\Throwable $e) {
            Logger::error('reset_backup_failed', $e->getMessage());
            return ['ok' => false, 'error' => 'Backup failed; ticket data was NOT reset.'];
        }

        $counts = Db::transaction(static function (): array {
            $tickets = (int) Db::scalar('SELECT COUNT(*) FROM tickets');
            $messages = (int) Db::scalar('SELECT COUNT(*) FROM messages');
            $attachments = (int) Db::scalar('SELECT COUNT(*) FROM attachments');
            $notifications = (int) Db::scalar('SELECT COUNT(*) FROM notifications');
            // Deleting tickets cascades messages + attachments (FK ON DELETE CASCADE).
            Db::query('DELETE FROM tickets');
            Db::query('DELETE FROM notifications');
            return [
                'tickets' => $tickets, 'messages' => $messages,
                'attachments' => $attachments, 'notifications' => $notifications,
            ];
        });

        // Record the reset in the (preserved) audit log, and alert every admin (§4).
        Audit::log($actorEmail, 'ticket_data_reset', '', 'backup=' . basename($backupPath) . ' tickets=' . $counts['tickets'], $ip);
        Logger::security('ticket_data_reset', "actor={$actorEmail} tickets={$counts['tickets']}");
        foreach (Db::queryAll("SELECT email FROM users WHERE role = 'admin' AND active = 1") as $adminRow) {
            NotificationService::create((string) $adminRow['email'], 'system_alert', "Ticket data was reset by {$actorEmail}.");
        }

        return ['ok' => true, 'backup' => basename($backupPath), 'deleted' => $counts];
    }
}
