<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Models\AppConfig;
use DateTime;
use DateTimeZone;
use Throwable;

/**
 * SlaCalculator (§3) — SLA deadlines and grading.
 *
 * Deadlines are minutes-from-creation per priority tier, read from config (8 keys:
 * response + resolution × urgent/high/normal/low). On a priority change the
 * deadlines are RECALCULATED FROM THE ORIGINAL CREATION TIME (not "now"), and both
 * milestones are RE-GRADED — an already-passed milestone flips to met/breached
 * immediately rather than silently resetting.
 *
 * Business hours (config toggle `sla_business_hours_only`, default OFF): when on,
 * SLA minutes are consumed only inside the working window (`business_hours_start`
 * … `business_hours_end` on `business_days`, evaluated in APP_TIMEZONE) — a ticket
 * arriving Friday 17:05 gets its clock started Monday morning, not billed for the
 * weekend. Any malformed configuration falls back to plain calendar time (fail-safe:
 * a deadline is always produced).
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
        $responseMin = (int) AppConfig::get("sla_response_{$priority}", '0');
        $resolutionMin = (int) AppConfig::get("sla_resolution_{$priority}", '0');

        if ((string) AppConfig::get('sla_business_hours_only', '0') === '1') {
            return [
                'response'   => self::addBusinessMinutes($createdAtUtc, $responseMin),
                'resolution' => self::addBusinessMinutes($createdAtUtc, $resolutionMin),
            ];
        }

        $base = strtotime($createdAtUtc);
        return [
            'response'   => gmdate('Y-m-d H:i:s', $base + $responseMin * 60),
            'resolution' => gmdate('Y-m-d H:i:s', $base + $resolutionMin * 60),
        ];
    }

    /**
     * Add $minutes of WORKING time to a UTC timestamp, honouring the configured
     * business window in APP_TIMEZONE. Returns a UTC 'Y-m-d H:i:s'. Falls back to
     * plain calendar addition on any malformed configuration (fail-safe).
     */
    public static function addBusinessMinutes(string $utcTimestamp, int $minutes): string
    {
        $calendar = static fn(): string => gmdate('Y-m-d H:i:s', strtotime($utcTimestamp) + $minutes * 60);
        try {
            $start = (string) AppConfig::get('business_hours_start', '08:00');
            $end = (string) AppConfig::get('business_hours_end', '17:00');
            $daysCsv = (string) AppConfig::get('business_days', '1,2,3,4,5');
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $end)) {
                return $calendar();
            }
            [$sh, $sm] = array_map('intval', explode(':', $start));
            [$eh, $em] = array_map('intval', explode(':', $end));
            if (($eh * 60 + $em) <= ($sh * 60 + $sm)) {
                return $calendar(); // inverted/empty window → calendar time
            }
            $days = array_values(array_unique(array_filter(
                array_map('intval', explode(',', $daysCsv)),
                static fn(int $d): bool => $d >= 1 && $d <= 7
            )));
            if ($days === []) {
                return $calendar(); // no working days configured → calendar time
            }

            $tz = new DateTimeZone(Config::string('APP_TIMEZONE', 'UTC'));
            $utc = new DateTimeZone('UTC');
            $dt = new DateTime($utcTimestamp, $utc);
            $dt->setTimezone($tz);

            $remaining = max(0, $minutes);
            $guard = 0;
            while ($remaining > 0 && ++$guard < 800) { // guard ≈ 2 years of skipped days
                $winStart = (clone $dt)->setTime($sh, $sm, 0);
                $winEnd = (clone $dt)->setTime($eh, $em, 0);
                if (!in_array((int) $dt->format('N'), $days, true) || $dt >= $winEnd) {
                    $dt->modify('+1 day')->setTime($sh, $sm, 0);
                    continue;
                }
                if ($dt < $winStart) {
                    $dt = $winStart;
                }
                $available = intdiv($winEnd->getTimestamp() - $dt->getTimestamp(), 60);
                if ($available < 1) { // under a minute left in the window → next working day
                    $dt->modify('+1 day')->setTime($sh, $sm, 0);
                    continue;
                }
                $use = min($available, $remaining);
                $dt->modify("+{$use} minutes");
                $remaining -= $use;
            }
            $dt->setTimezone($utc);
            return $dt->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return $calendar();
        }
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
