<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\AppConfig;

/**
 * SlaCalculator (§3) — SLA deadlines and grading.
 *
 * Deadlines are minutes-from-creation per priority tier, read from config (8 keys:
 * response + resolution × urgent/high/normal/low). On a priority change the
 * deadlines are RECALCULATED FROM THE ORIGINAL CREATION TIME (not "now"), and both
 * milestones are RE-GRADED — an already-passed milestone flips to met/breached
 * immediately rather than silently resetting.
 */
final class SlaCalculator
{
    /**
     * Response + resolution deadlines (UTC 'Y-m-d H:i:s') for a priority, measured
     * from $createdAtUtc.
     *
     * @return array{response:string, resolution:string}
     */
    public static function deadlines(string $priority, string $createdAtUtc): array
    {
        $base = strtotime($createdAtUtc);
        $responseMin = (int) AppConfig::get("sla_response_{$priority}", '0');
        $resolutionMin = (int) AppConfig::get("sla_resolution_{$priority}", '0');
        return [
            'response'   => gmdate('Y-m-d H:i:s', $base + $responseMin * 60),
            'resolution' => gmdate('Y-m-d H:i:s', $base + $resolutionMin * 60),
        ];
    }

    /**
     * Grade both SLA milestones for a ticket given its deadlines and timestamps.
     *   response:   met if first_response_at ≤ deadline; breached if responded late
     *               OR (unresponded AND now past deadline); else pending.
     *   resolution: met if resolved_at ≤ deadline; breached if resolved late OR
     *               (unresolved, still open, AND now past deadline); else pending.
     *
     * @return array{response:string, resolution:string} statuses (met|breached|pending)
     */
    public static function grade(array $ticket, ?string $nowUtc = null): array
    {
        $now = $nowUtc !== null ? strtotime($nowUtc) : time();

        $respDeadline = strtotime((string) $ticket['sla_response_deadline']);
        $firstResp = $ticket['first_response_at'] ?? null;
        if ($firstResp !== null && $firstResp !== '') {
            $response = strtotime((string) $firstResp) <= $respDeadline ? 'met' : 'breached';
        } else {
            $response = $now > $respDeadline ? 'breached' : 'pending';
        }

        $resDeadline = strtotime((string) $ticket['sla_resolution_deadline']);
        $resolvedAt = $ticket['resolved_at'] ?? null;
        $status = (string) ($ticket['status'] ?? 'open');
        if ($resolvedAt !== null && $resolvedAt !== '') {
            $resolution = strtotime((string) $resolvedAt) <= $resDeadline ? 'met' : 'breached';
        } elseif (in_array($status, ['resolved', 'closed'], true)) {
            // resolved/closed without a resolved_at timestamp — treat as met-by-default
            $resolution = 'met';
        } else {
            $resolution = $now > $resDeadline ? 'breached' : 'pending';
        }

        return ['response' => $response, 'resolution' => $resolution];
    }
}
