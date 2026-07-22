<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * ReportService (§3) — KPIs, distributions, volume, agent performance, CSV.
 *
 * All figures are computed over "tickets CREATED in the period" so the three
 * distributions (status / priority / channel) each sum EXACTLY to the created
 * count. Volume-by-day is zero-filled. CSV export uses RFC 4180 quoting.
 *
 * Multi-tenancy: every method takes an org scope — admins see ALL organizations
 * ($allOrgs = true, the default so existing callers are unchanged); an org admin or
 * agent passes their own org so figures never cross the tenant boundary. The filter
 * ((:all_orgs = 1 OR organization_id <=> :org)) is bound, never interpolated (§10.1).
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
        return gmdate('Y-m-d 00:00:00', time() - ($days - 1) * 86400);
    }

    /** The 4 KPI cards. */
    public static function kpis(int $days, bool $allOrgs = true, ?string $orgId = null): array
    {
        $start = self::startDate($days);
        $scope = [':all_orgs' => $allOrgs ? 1 : 0, ':org' => $orgId];
        $created = (int) Db::scalar(
            "SELECT COUNT(*) FROM tickets WHERE created_at >= :s AND (:all_orgs = 1 OR organization_id <=> :org)",
            [':s' => $start] + $scope
        );
        $resolved = (int) Db::scalar(
            "SELECT COUNT(*) FROM tickets WHERE resolved_at IS NOT NULL AND resolved_at >= :s AND (:all_orgs = 1 OR organization_id <=> :org)",
            [':s' => $start] + $scope
        );
        $avgHours = Db::scalar(
            "SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, resolved_at)) / 60
             FROM tickets WHERE resolved_at IS NOT NULL AND resolved_at >= :s AND (:all_orgs = 1 OR organization_id <=> :org)",
            [':s' => $start] + $scope
        );
        $graded = (int) Db::scalar(
            "SELECT COUNT(*) FROM tickets WHERE created_at >= :s AND sla_resolution_status IN ('met','breached') AND (:all_orgs = 1 OR organization_id <=> :org)",
            [':s' => $start] + $scope
        );
        $met = (int) Db::scalar(
            "SELECT COUNT(*) FROM tickets WHERE created_at >= :s AND sla_resolution_status = 'met' AND (:all_orgs = 1 OR organization_id <=> :org)",
            [':s' => $start] + $scope
        );
        $compliance = $graded > 0 ? round($met / $graded * 100, 1) : null;
        $csat = Db::queryOne(
            "SELECT AVG(csat_rating) AS avg_rating, COUNT(csat_rating) AS rated
             FROM tickets WHERE created_at >= :s AND csat_rating IS NOT NULL AND (:all_orgs = 1 OR organization_id <=> :org)",
            [':s' => $start] + $scope
        );

        return [
            'created'             => $created,
            'resolved'            => $resolved,
            'avg_resolution_hours' => $avgHours === null ? null : round((float) $avgHours, 1),
            'sla_compliance_pct'  => $compliance,
            'csat_avg'            => ($csat['avg_rating'] ?? null) === null ? null : round((float) $csat['avg_rating'], 1),
            'csat_count'          => (int) ($csat['rated'] ?? 0),
        ];
    }

    /** Status / priority / channel distributions over tickets created in the period. */
    public static function distributions(int $days, bool $allOrgs = true, ?string $orgId = null): array
    {
        $start = self::startDate($days);
        return [
            'status'   => self::distribution('status', $start, $allOrgs, $orgId),
            'priority' => self::distribution('priority', $start, $allOrgs, $orgId),
            'channel'  => self::distribution('channel', $start, $allOrgs, $orgId),
        ];
    }

    /** $column is a hardcoded literal (never request-derived) — one of three callers. */
    private static function distribution(string $column, string $start, bool $allOrgs, ?string $orgId): array
    {
        $sql = match ($column) {
            'status'   => "SELECT status AS k, COUNT(*) AS c FROM tickets WHERE created_at >= :s AND (:all_orgs = 1 OR organization_id <=> :org) GROUP BY status",
            'priority' => "SELECT priority AS k, COUNT(*) AS c FROM tickets WHERE created_at >= :s AND (:all_orgs = 1 OR organization_id <=> :org) GROUP BY priority",
            'channel'  => "SELECT channel AS k, COUNT(*) AS c FROM tickets WHERE created_at >= :s AND (:all_orgs = 1 OR organization_id <=> :org) GROUP BY channel",
            default    => throw new \InvalidArgumentException('bad column'),
        };
        $out = [];
        foreach (Db::queryAll($sql, [':s' => $start, ':all_orgs' => $allOrgs ? 1 : 0, ':org' => $orgId]) as $row) {
            $out[(string) $row['k']] = (int) $row['c'];
        }
        return $out;
    }

    /** Volume-by-day, zero-filled for every day in the window (§3). */
    public static function volumeByDay(int $days, bool $allOrgs = true, ?string $orgId = null): array
    {
        $start = self::startDate($days);
        $counts = [];
        foreach (Db::queryAll(
            "SELECT DATE(created_at) AS d, COUNT(*) AS c FROM tickets
             WHERE created_at >= :s AND (:all_orgs = 1 OR organization_id <=> :org) GROUP BY DATE(created_at)",
            [':s' => $start, ':all_orgs' => $allOrgs ? 1 : 0, ':org' => $orgId]
        ) as $row) {
            $counts[(string) $row['d']] = (int) $row['c'];
        }

        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $day = gmdate('Y-m-d', strtotime($start) + $i * 86400);
            $series[] = ['date' => $day, 'count' => $counts[$day] ?? 0];
        }
        return $series;
    }

    /** Per-agent performance over the period (scoped to the caller's organization). */
    public static function agentPerformance(int $days, bool $allOrgs = true, ?string $orgId = null): array
    {
        $start = self::startDate($days);
        return Db::queryAll(
            "SELECT u.name, u.email,
                    SUM(CASE WHEN t.created_at >= :s1 THEN 1 ELSE 0 END) AS assigned,
                    SUM(CASE WHEN t.resolved_at IS NOT NULL AND t.resolved_at >= :s2 THEN 1 ELSE 0 END) AS resolved,
                    ROUND(AVG(CASE WHEN t.resolved_at IS NOT NULL AND t.resolved_at >= :s3
                              THEN TIMESTAMPDIFF(MINUTE, t.created_at, t.resolved_at) END) / 60, 1) AS avg_hours,
                    SUM(CASE WHEN t.created_at >= :s4 AND t.sla_resolution_status = 'met' THEN 1 ELSE 0 END) AS sla_met,
                    SUM(CASE WHEN t.created_at >= :s5 AND t.sla_resolution_status IN ('met','breached') THEN 1 ELSE 0 END) AS sla_graded,
                    ROUND(AVG(CASE WHEN t.created_at >= :s6 THEN t.csat_rating END), 1) AS csat_avg
             FROM users u
             LEFT JOIN tickets t ON t.assigned_to = u.email
             WHERE u.role IN ('agent','org_admin','admin') AND u.active = 1
               AND (:all_orgs = 1 OR u.organization_id <=> :org)
             GROUP BY u.email, u.name
             ORDER BY resolved DESC, u.name ASC",
            [':s1' => $start, ':s2' => $start, ':s3' => $start, ':s4' => $start, ':s5' => $start, ':s6' => $start,
             ':all_orgs' => $allOrgs ? 1 : 0, ':org' => $orgId]
        );
    }

    /** Everything the reports dashboard needs in one call. */
    public static function summary(int $period, bool $allOrgs = true, ?string $orgId = null): array
    {
        $days = self::normalizePeriod($period);
        return [
            'period'        => $days,
            'kpis'          => self::kpis($days, $allOrgs, $orgId),
            'distributions' => self::distributions($days, $allOrgs, $orgId),
            'volume'        => self::volumeByDay($days, $allOrgs, $orgId),
        ];
    }

    /**
     * Group tickets created in the period by ONE dimension, with a resolved count.
     * $dimension is matched against a HARDCODED allowlist — the SQL for each case is a
     * literal, nothing from the request is ever interpolated into the query (§10.1).
     *
     * @return array<int,array{label:string,total:int,resolved:int}>
     */
    public static function grouped(string $dimension, int $period, bool $allOrgs = true, ?string $orgId = null): array
    {
        $days = self::normalizePeriod($period);
        $scope = [':s' => self::startDate($days), ':all_orgs' => $allOrgs ? 1 : 0, ':org' => $orgId];
        // Each arm is a FULLY LITERAL query (no interpolation, §10.1); only bound
        // params (:s/:all_orgs/:org) vary. The dimension picks the arm; it is never
        // spliced into SQL.
        $sql = match ($dimension) {
            'product'  => "SELECT COALESCE(p.name, '— none —') AS label, COUNT(*) AS total, SUM(t.resolved_at IS NOT NULL) AS resolved
                           FROM tickets t LEFT JOIN products p ON p.product_id = t.product_id
                           WHERE t.created_at >= :s AND (:all_orgs = 1 OR t.organization_id <=> :org)
                           GROUP BY t.product_id, label ORDER BY total DESC, label ASC",
            'category' => "SELECT COALESCE(c.name, '— none —') AS label, COUNT(*) AS total, SUM(t.resolved_at IS NOT NULL) AS resolved
                           FROM tickets t LEFT JOIN categories c ON c.category_id = t.category_id
                           WHERE t.created_at >= :s AND (:all_orgs = 1 OR t.organization_id <=> :org)
                           GROUP BY t.category_id, label ORDER BY total DESC, label ASC",
            'agent'    => "SELECT COALESCE(NULLIF(t.assigned_to, ''), '— unassigned —') AS label, COUNT(*) AS total, SUM(t.resolved_at IS NOT NULL) AS resolved
                           FROM tickets t
                           WHERE t.created_at >= :s AND (:all_orgs = 1 OR t.organization_id <=> :org)
                           GROUP BY t.assigned_to ORDER BY total DESC, label ASC",
            'status'   => "SELECT t.status AS label, COUNT(*) AS total, SUM(t.resolved_at IS NOT NULL) AS resolved
                           FROM tickets t
                           WHERE t.created_at >= :s AND (:all_orgs = 1 OR t.organization_id <=> :org)
                           GROUP BY t.status ORDER BY total DESC, label ASC",
            'priority' => "SELECT t.priority AS label, COUNT(*) AS total, SUM(t.resolved_at IS NOT NULL) AS resolved
                           FROM tickets t
                           WHERE t.created_at >= :s AND (:all_orgs = 1 OR t.organization_id <=> :org)
                           GROUP BY t.priority ORDER BY total DESC, label ASC",
            default    => throw new \InvalidArgumentException('bad dimension'),
        };
        return array_map(
            static fn(array $r): array => [
                'label'    => (string) $r['label'],
                'total'    => (int) $r['total'],
                'resolved' => (int) $r['resolved'],
            ],
            Db::queryAll($sql, $scope)
        );
    }

    /** The dimensions grouped() accepts (allowlist shared with the controller). */
    public const GROUP_DIMENSIONS = ['product', 'category', 'agent', 'status', 'priority'];

    // ── CSV export (RFC 4180) ────────────────────────────────────────────────
    /**
     * Export tickets created in the period, optionally narrowed by a bound filter set
     * (status, priority, product_id, category_id, assigned_to, q). Every filter is a
     * sentinel-guarded bound parameter — an empty value means "no filter" (§10.1).
     */
    public static function ticketsCsv(int $period, bool $allOrgs = true, ?string $orgId = null, array $filters = []): string
    {
        $days = self::normalizePeriod($period);
        $status   = (string) ($filters['status'] ?? '');
        $priority = (string) ($filters['priority'] ?? '');
        $product  = (string) ($filters['product_id'] ?? '');
        $category = (string) ($filters['category_id'] ?? '');
        $assignee = (string) ($filters['assigned_to'] ?? '');
        $q        = (string) ($filters['q'] ?? '');
        $like     = '%' . $q . '%';

        $params = [
            ':s' => self::startDate($days), ':all_orgs' => $allOrgs ? 1 : 0, ':org' => $orgId,
            ':status_a' => $status, ':status_b' => $status,
            ':prio_a' => $priority, ':prio_b' => $priority,
            ':prod_a' => $product, ':prod_b' => $product,
            ':cat_a' => $category, ':cat_b' => $category,
            ':asg_a' => $assignee, ':asg_b' => $assignee,
            ':q_a' => $q, ':q_like1' => $like, ':q_like2' => $like,
        ];
        $rows = Db::queryAll(
            "SELECT t.ticket_id, t.subject, t.customer_email,
                    COALESCE(p.name, '') AS product, COALESCE(c.name, '') AS category,
                    t.priority, t.status, t.channel, t.assigned_to,
                    t.created_at, t.resolved_at, t.sla_response_status, t.sla_resolution_status, t.csat_rating
             FROM tickets t
             LEFT JOIN products p ON p.product_id = t.product_id
             LEFT JOIN categories c ON c.category_id = t.category_id
             WHERE t.created_at >= :s AND (:all_orgs = 1 OR t.organization_id <=> :org)
               AND (:status_a = '' OR t.status = :status_b)
               AND (:prio_a = '' OR t.priority = :prio_b)
               AND (:prod_a = '' OR t.product_id = :prod_b)
               AND (:cat_a = '' OR t.category_id = :cat_b)
               AND (:asg_a = '' OR t.assigned_to = :asg_b)
               AND (:q_a = '' OR t.subject LIKE :q_like1 OR t.customer_email LIKE :q_like2)
             ORDER BY t.created_at ASC",
            $params
        );

        $header = ['ticket_id', 'subject', 'customer_email', 'product', 'category', 'priority', 'status', 'channel',
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
