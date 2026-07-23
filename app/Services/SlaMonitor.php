<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Models\Ticket;
use App\Security\Audit;

/**
 * SlaMonitor (§14) — grades overdue PENDING milestones as breached and notifies the
 * assignee once per new breach.
 *
 * IDEMPOTENT by construction: it only touches tickets whose milestone is still
 * 'pending'. Once graded to 'breached', a second run in the same window finds nothing
 * and is a no-op (the prototype asserted this by comparing audit-row counts across two
 * consecutive runs — that assertion is ported in the test).
 */
final class SlaMonitor
{
    /**
     * @return array{response_breached:int, resolution_breached:int}
     */
    public static function run(): array
    {
        $now = gmdate('Y-m-d H:i:s');

        // Response: pending, never responded, past the deadline.
        $respRows = Db::queryAll(
            "SELECT ticket_id, assigned_to FROM tickets
             WHERE sla_response_status = 'pending'
               AND first_response_at IS NULL
               AND sla_response_deadline < :now",
            [':now' => $now]
        );
        foreach ($respRows as $r) {
            Db::update('tickets', ['sla_response_status' => 'breached'], 'ticket_id = :t', [':t' => $r['ticket_id']]);
            self::onBreach((string) $r['ticket_id'], $r['assigned_to'], 'response');
        }

        // Resolution: pending, still open, past the deadline.
        $resRows = Db::queryAll(
            "SELECT ticket_id, assigned_to FROM tickets
             WHERE sla_resolution_status = 'pending'
               AND status NOT IN ('resolved','closed')
               AND sla_resolution_deadline < :now",
            [':now' => $now]
        );
        foreach ($resRows as $r) {
            Db::update('tickets', ['sla_resolution_status' => 'breached'], 'ticket_id = :t', [':t' => $r['ticket_id']]);
            self::onBreach((string) $r['ticket_id'], $r['assigned_to'], 'resolution');
        }

        return ['response_breached' => count($respRows), 'resolution_breached' => count($resRows)];
    }

    private static function onBreach(string $ticketId, ?string $assignee, string $kind): void
    {
        Audit::log('system', 'sla_breach', $ticketId, $kind);
        if ($assignee !== null && $assignee !== '') {
            NotificationService::create($assignee, 'sla_breach', "SLA {$kind} breach on {$ticketId}", $ticketId);
            Mailer::sendTemplate(
                $assignee,
                "SLA breach: {$ticketId}",
                'SLA breach',
                "<p>The {$kind} SLA for ticket <strong>{$ticketId}</strong> has been breached.</p>"
            );
        }
        // Optional Slack alert (no-op unless a webhook is configured; never throws).
        $ticket = Ticket::find($ticketId);
        if ($ticket !== null) {
            SlackNotifier::slaBreach($ticket, $kind);
        }
    }
}
