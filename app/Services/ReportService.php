<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * ReportService (§3) — KPIs, distributions, volume, agent performance, CSV.
 *
 * All figures are computed over "tickets CREATED in the period" so the three
 * distributions (status / priority / channel) each sum EXACTLY to the created
 * count — that aggregate consistency is the phase's exit criterion. Volume-by-day
 * is zero-filled for empty days. CSV export uses RFC 4180 quoting.
 */
final class ReportService
{
    private const PERIODS = [7, 30, 90];

    public static function normalizePeriod(int $period): int
    {
        return in_array($period, self::PERIODS, true) ? $period : 30;
    }

    private static function startDate(int $days): string
    {
        // Inclusive window: midnight UTC, (days-1) ago, through now.
        return gmdate('Y-m-d 00:00:00', time() - ($days - 1) * 86400);
    }

    /** The 4 KPI cards. */
    public static function kpis(int $days): array
    {
        $start = self::startDate($days);
        $created = (int) Db::scalar('SELECT COUNT(*) FROM tickets WHERE created_at >= :s', [':s' => $start]);
        $resolved = (int) Db::scalar(
            'SELECT COUNT(*) FROM tickets WHERE resolved_at IS NOT NULL AND resolved_at >= :s',
            [':s' => $start]
        );
        $avgHours = Db::scalar(
            'SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, resolved_at)) / 60
             FROM tickets WHERE resolved_at IS NOT NULL AND resolved_at >= :s',
            [':s' => $start]
        );
        // SLA compliance %: resolution milestones met / graded (met+breached), created in period.
        $graded = (int) Db::scalar(
            "SELECT COUNT(*) FROM tickets WHERE created_at >= :s AND sla_resolution_status IN ('met','breached')",
            [':s' => $start]
        );
        $met = (int) Db::scalar(
            "SELECT COUNT(*) FROM tickets WHERE created_at >= :s AND sla_resolution_status = 'met'",
            [':s' => $start]
        );
        $compliance = $graded > 0 ? round($met / $graded * 100, 1) : null;

        return [
            'created'             => $created,
            'resolved'            => $resolved,
            'avg_resolution_hours' => $avgHours === null ? null : round((float) $avgHours, 1),
            'sla_compliance_pct'  => $compliance,
        ];
    }

    /**
     * Status / priority / channel distributions over tickets created in the period.
     * Each returned map sums to the created count.
     */
    public static function distributions(int $days): array
    {
        $start = self::startDate($days);
        return [
            'status'   => self::distribution('status', $start),
            'priority' => self::distribution('priority', $start),
            'channel'  => self::distribution('channel', $start),
        ];
    }

    /** $column is a hardcoded literal (never request-derived) — one of three callers. */
    private static function distribution(string $column, string $start): array
    {
        $sql = match ($column) {
            'status'   => 'SELECT status AS k, COUNT(*) AS c FROM tickets WHERE created_at >= :s GROUP BY status',
            'priority' => 'SELECT priority AS k, COUNT(*) AS c FROM tickets WHERE created_at >= :s GROUP BY priority',
            'channel'  => 'SELECT channel AS k, COUNT(*) AS c FROM tickets WHERE created_at >= :s GROUP BY channel',
            default    => throw new \InvalidArgumentException('bad column'),
        };
        $out = [];
        foreach (Db::queryAll($sql, [':s' => $start]) as $row) {
            $out[(string) $row['k']] = (int) $row['c'];
        }
        return $out;
    }

    /** Volume-by-day, zero-filled for every day in the window (§3). */
    public static function volumeByDay(int $days): array
    {
        $start = self::startDate($days);
        $counts = [];
        foreach (Db::queryAll(
            'SELECT DATE(created_at) AS d, COUNT(*) AS c FROM tickets WHERE created_at >= :s GROUP BY DATE(created_at)',
            [':s' => $start]
        ) as $row) {
            $counts[(string) $row['d']] = (int) $row['c'];
        }

        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $day = gmdate('Y-m-d', strtotime($start) + $i * 86400);
            $series[] = ['date' => $day, 'count' => $counts[$day] ?? 0]; // zero-fill
        }
        return $series;
    }

    /** Per-agent performance over the period. */
    public static function agentPerformance(int $days): array
    {
        $start = self::startDate($days);
        return Db::queryAll(
            "SELECT u.name, u.email,
                    SUM(CASE WHEN t.created_at >= :s1 THEN 1 ELSE 0 END) AS assigned,
                    SUM(CASE WHEN t.resolved_at IS NOT NULL AND t.resolved_at >= :s2 THEN 1 ELSE 0 END) AS resolved,
                    ROUND(AVG(CASE WHEN t.resolved_at IS NOT NULL AND t.resolved_at >= :s3
                              THEN TIMESTAMPDIFF(MINUTE, t.created_at, t.resolved_at) END) / 60, 1) AS avg_hours,
                    SUM(CASE WHEN t.created_at >= :s4 AND t.sla_resolution_status = 'met' THEN 1 ELSE 0 END) AS sla_met,
                    SUM(CASE WHEN t.created_at >= :s5 AND t.sla_resolution_status IN ('met','breached') THEN 1 ELSE 0 END) AS sla_graded
             FROM users u
             LEFT JOIN tickets t ON t.assigned_to = u.email
             WHERE u.role IN ('agent','admin') AND u.active = 1
             GROUP BY u.email, u.name
             ORDER BY resolved DESC, u.name ASC",
            [':s1' => $start, ':s2' => $start, ':s3' => $start, ':s4' => $start, ':s5' => $start]
        );
    }

    /** Everything the reports dashboard needs in one call. */
    public static function summary(int $period): array
    {
        $days = self::normalizePeriod($period);
        return [
            'period'        => $days,
            'kpis'          => self::kpis($days),
            'distributions' => self::distributions($days),
            'volume'        => self::volumeByDay($days),
        ];
    }

    // ── CSV export (RFC 4180) ────────────────────────────────────────────────
    public static function ticketsCsv(int $period): string
    {
        $days = self::normalizePeriod($period);
        $start = self::startDate($days);
        $rows = Db::queryAll(
            'SELECT ticket_id, subject, customer_email, priority, status, channel, assigned_to,
                    created_at, resolved_at, sla_response_status, sla_resolution_status, csat_rating
             FROM tickets WHERE created_at >= :s ORDER BY created_at ASC',
            [':s' => $start]
        );

        $header = ['ticket_id', 'subject', 'customer_email', 'priority', 'status', 'channel',
                   'assigned_to', 'created_at', 'resolved_at', 'sla_response_status', 'sla_resolution_status', 'csat_rating'];
        $out = self::csvRow($header);
        foreach ($rows as $r) {
            $out .= self::csvRow(array_map(static fn($v) => $v === null ? '' : (string) $v, array_values($r)));
        }
        return $out;
    }

    /** RFC 4180: quote fields containing " , CR or LF; double internal quotes; CRLF line ends. */
    private static function csvRow(array $fields): string
    {
        $escaped = array_map(static function (string $field): string {
            if (preg_match('/["\r\n,]/', $field)) {
                return '"' . str_replace('"', '""', $field) . '"';
            }
            return $field;
        }, $fields);
        return implode(',', $escaped) . "\r\n";
    }
}
