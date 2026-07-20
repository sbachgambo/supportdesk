<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Db;

/**
 * Ticket (Model) — thin repository over the tickets table. Business rules (SLA,
 * assignment, lifecycle) live in Services\TicketService, not here.
 *
 * Multi-tenancy (isolation): staff-facing reads take an org scope — admins see ALL
 * organizations, an agent sees only their own. The scope is expressed with bound
 * sentinels ((:all_orgs = 1 OR organization_id <=> :org)) — nothing is interpolated
 * into SQL (§10.1); columns and WHERE clauses are written out literally in every
 * query. `<=>` is NULL-safe equality (an org-less agent sees the general/NULL queue).
 */
final class Ticket
{
    /** Columns a caller is allowed to set on insert (mass-assignment allowlist, §10.5). */
    private const INSERTABLE = [
        'ticket_id', 'subject', 'description', 'customer_name', 'customer_email',
        'customer_user_id', 'organization_id', 'priority', 'status', 'category_id', 'tags', 'channel',
        'assigned_to', 'created_at', 'updated_at', 'sla_response_deadline',
        'sla_resolution_deadline', 'sla_response_status', 'sla_resolution_status',
    ];

    public static function create(array $data): string
    {
        $row = array_intersect_key($data, array_flip(self::INSERTABLE));
        Db::insert('tickets', $row);
        return (string) $data['ticket_id'];
    }

    public static function find(string $ticketId): ?array
    {
        return Db::queryOne('SELECT * FROM tickets WHERE ticket_id = :t', [':t' => $ticketId]);
    }

    /** @param array<string,mixed> $fields */
    public static function update(string $ticketId, array $fields): void
    {
        $fields['updated_at'] = gmdate('Y-m-d H:i:s');
        Db::update('tickets', $fields, 'ticket_id = :t', [':t' => $ticketId]);
    }

    /**
     * Paged list for staff with optional status/assignee/customer filters + org scope.
     * WHERE is FULLY STATIC (empty-string / sentinel = "no filter"), nothing interpolated.
     *
     * @return array{rows:array<int,array<string,mixed>>, total:int}
     */
    public static function paged(array $filters, int $page, int $perPage, bool $allOrgs, ?string $orgId): array
    {
        $status = (string) ($filters['status'] ?? '');
        $assignee = (string) ($filters['assigned_to'] ?? '');
        $cemail = (string) ($filters['customer_email'] ?? '');
        // DISTINCT names per occurrence: EMULATE_PREPARES=false forbids reusing a name.
        $params = [
            ':status_a' => $status, ':status_b' => $status,
            ':assignee_a' => $assignee, ':assignee_b' => $assignee,
            ':cemail_a' => $cemail, ':cemail_b' => $cemail,
            ':all_orgs' => $allOrgs ? 1 : 0, ':org' => $orgId,
        ];

        $total = (int) Db::scalar(
            "SELECT COUNT(*) FROM tickets
             WHERE (:status_a = '' OR status = :status_b)
               AND (:assignee_a = '' OR assigned_to = :assignee_b)
               AND (:cemail_a = '' OR customer_email = :cemail_b)
               AND (:all_orgs = 1 OR organization_id <=> :org)",
            $params
        );

        $params[':limit'] = $perPage;
        $params[':offset'] = (max(1, $page) - 1) * $perPage;
        $rows = Db::queryAll(
            "SELECT ticket_id, subject, customer_name, customer_email, organization_id, priority, status,
                    assigned_to, category_id, created_at, updated_at,
                    sla_response_status, sla_resolution_status, first_response_at, resolved_at
             FROM tickets
             WHERE (:status_a = '' OR status = :status_b)
               AND (:assignee_a = '' OR assigned_to = :assignee_b)
               AND (:cemail_a = '' OR customer_email = :cemail_b)
               AND (:all_orgs = 1 OR organization_id <=> :org)
             ORDER BY updated_at DESC
             LIMIT :limit OFFSET :offset",
            $params
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * All tickets for a sidebar view (prototype semantics), newest first, capped, scoped
     * to the caller's organization. view: 'all' | 'mine' | 'breaches' | 'resolved'.
     * Columns inlined literally per query (no interpolation into SQL, §10.1).
     */
    public static function allForView(string $view, string $agentEmail, bool $allOrgs, ?string $orgId): array
    {
        $orgParams = [':all_orgs' => $allOrgs ? 1 : 0, ':org' => $orgId];

        return match ($view) {
            'mine' => Db::queryAll(
                "SELECT ticket_id, subject, customer_name, customer_email, organization_id, priority, status,
                        assigned_to, category_id, created_at, updated_at,
                        sla_response_status, sla_resolution_status, first_response_at, resolved_at
                 FROM tickets
                 WHERE assigned_to = :email AND (:all_orgs = 1 OR organization_id <=> :org)
                 ORDER BY updated_at DESC LIMIT 1000",
                [':email' => $agentEmail] + $orgParams
            ),
            'breaches' => Db::queryAll(
                "SELECT ticket_id, subject, customer_name, customer_email, organization_id, priority, status,
                        assigned_to, category_id, created_at, updated_at,
                        sla_response_status, sla_resolution_status, first_response_at, resolved_at
                 FROM tickets
                 WHERE (sla_response_status = 'breached' OR sla_resolution_status = 'breached')
                   AND status IN ('open','pending') AND (:all_orgs = 1 OR organization_id <=> :org)
                 ORDER BY updated_at DESC LIMIT 1000",
                $orgParams
            ),
            'resolved' => Db::queryAll(
                "SELECT ticket_id, subject, customer_name, customer_email, organization_id, priority, status,
                        assigned_to, category_id, created_at, updated_at,
                        sla_response_status, sla_resolution_status, first_response_at, resolved_at
                 FROM tickets
                 WHERE status IN ('resolved','closed') AND (:all_orgs = 1 OR organization_id <=> :org)
                 ORDER BY updated_at DESC LIMIT 1000",
                $orgParams
            ),
            default => Db::queryAll(
                "SELECT ticket_id, subject, customer_name, customer_email, organization_id, priority, status,
                        assigned_to, category_id, created_at, updated_at,
                        sla_response_status, sla_resolution_status, first_response_at, resolved_at
                 FROM tickets
                 WHERE (:all_orgs = 1 OR organization_id <=> :org)
                 ORDER BY updated_at DESC LIMIT 1000",
                $orgParams
            ),
        };
    }

    /** True if the ticket is visible to a caller with this org scope (isolation, D2). */
    public static function inScope(string $ticketId, bool $allOrgs, ?string $orgId): bool
    {
        if ($allOrgs) {
            return self::find($ticketId) !== null;
        }
        return (int) Db::scalar(
            'SELECT COUNT(*) FROM tickets WHERE ticket_id = :t AND organization_id <=> :org',
            [':t' => $ticketId, ':org' => $orgId]
        ) > 0;
    }

    /** Count of a status for KPIs, org-scoped. */
    public static function countByStatus(string $status, bool $allOrgs, ?string $orgId): int
    {
        return (int) Db::scalar(
            "SELECT COUNT(*) FROM tickets WHERE status = :s AND (:all_orgs = 1 OR organization_id <=> :org)",
            [':s' => $status, ':all_orgs' => $allOrgs ? 1 : 0, ':org' => $orgId]
        );
    }

    public static function countResolvedLast24h(bool $allOrgs, ?string $orgId): int
    {
        return (int) Db::scalar(
            "SELECT COUNT(*) FROM tickets
             WHERE resolved_at IS NOT NULL AND resolved_at >= :s AND (:all_orgs = 1 OR organization_id <=> :org)",
            [':s' => gmdate('Y-m-d H:i:s', time() - 86400), ':all_orgs' => $allOrgs ? 1 : 0, ':org' => $orgId]
        );
    }

    /** Average first-response time in hours across responded tickets (dashboard stat). */
    public static function avgFirstResponseHours(bool $allOrgs, ?string $orgId): ?float
    {
        $v = Db::scalar(
            "SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, first_response_at)) / 60
             FROM tickets WHERE first_response_at IS NOT NULL AND (:all_orgs = 1 OR organization_id <=> :org)",
            [':all_orgs' => $allOrgs ? 1 : 0, ':org' => $orgId]
        );
        return $v === null ? null : round((float) $v, 1);
    }

    public static function countActiveBreaches(bool $allOrgs, ?string $orgId): int
    {
        return (int) Db::scalar(
            "SELECT COUNT(*) FROM tickets
             WHERE (sla_response_status = 'breached' OR sla_resolution_status = 'breached')
               AND status NOT IN ('resolved','closed') AND (:all_orgs = 1 OR organization_id <=> :org)",
            [':all_orgs' => $allOrgs ? 1 : 0, ':org' => $orgId]
        );
    }

    /**
     * A customer's own tickets: linked by user id OR matching account email (covers
     * tickets raised anonymously before the account existed, D2). Fully-bound query.
     *
     * @return array{rows:array<int,array<string,mixed>>, total:int}
     */
    public static function pagedForCustomer(int $userId, string $email, int $page, int $perPage = 10): array
    {
        $params = [':uid' => $userId, ':email' => $email];
        $total = (int) Db::scalar(
            'SELECT COUNT(*) FROM tickets WHERE customer_user_id = :uid OR customer_email = :email',
            $params
        );
        $params[':limit'] = $perPage;
        $params[':offset'] = (max(1, $page) - 1) * $perPage;
        $rows = Db::queryAll(
            'SELECT ticket_id, subject, priority, status, category_id, created_at, updated_at,
                    sla_resolution_status, csat_rating
             FROM tickets
             WHERE customer_user_id = :uid OR customer_email = :email
             ORDER BY updated_at DESC
             LIMIT :limit OFFSET :offset',
            $params
        );
        return ['rows' => $rows, 'total' => $total];
    }

    public static function setCsat(string $ticketId, int $rating, string $comment): void
    {
        Db::update('tickets', [
            'csat_rating'  => $rating,
            'csat_comment' => mb_substr($comment, 0, 500),
        ], 'ticket_id = :t', [':t' => $ticketId]);
    }
}
