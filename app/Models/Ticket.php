<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Db;

/**
 * Ticket (Model) — thin repository over the tickets table. Business rules (SLA,
 * assignment, lifecycle) live in Services\TicketService, not here.
 */
final class Ticket
{
    /** Columns a caller is allowed to set on insert (mass-assignment allowlist, §10.5). */
    private const INSERTABLE = [
        'ticket_id', 'subject', 'description', 'customer_name', 'customer_email',
        'customer_user_id', 'company', 'priority', 'status', 'category_id', 'tags', 'channel',
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
     * Paged list for staff with optional status/assignee/customer filters.
     *
     * The WHERE clause is FULLY STATIC — an empty-string sentinel per filter means
     * "no filter" — so nothing is ever interpolated into SQL (§10.1). LIMIT/OFFSET
     * are bound as ints (PARAM_INT via Db).
     *
     * @return array{rows:array<int,array<string,mixed>>, total:int}
     */
    public static function paged(array $filters, int $page, int $perPage = 10): array
    {
        $status = (string) ($filters['status'] ?? '');
        $assignee = (string) ($filters['assigned_to'] ?? '');
        $cemail = (string) ($filters['customer_email'] ?? '');
        // DISTINCT names per occurrence: with EMULATE_PREPARES=false a named placeholder
        // may NOT be reused within one statement, so the sentinel needs two names each.
        $params = [
            ':status_a' => $status, ':status_b' => $status,
            ':assignee_a' => $assignee, ':assignee_b' => $assignee,
            ':cemail_a' => $cemail, ':cemail_b' => $cemail,
        ];

        // WHERE inlined literally in both queries (no interpolation, §10.1). An empty
        // sentinel disables that filter. Keep the two WHERE blocks identical.
        $total = (int) Db::scalar(
            "SELECT COUNT(*) FROM tickets
             WHERE (:status_a = '' OR status = :status_b)
               AND (:assignee_a = '' OR assigned_to = :assignee_b)
               AND (:cemail_a = '' OR customer_email = :cemail_b)",
            $params
        );

        $params[':limit'] = $perPage;
        $params[':offset'] = (max(1, $page) - 1) * $perPage;
        $rows = Db::queryAll(
            "SELECT ticket_id, subject, customer_name, customer_email, priority, status,
                    assigned_to, category_id, created_at, updated_at,
                    sla_response_status, sla_resolution_status, first_response_at, resolved_at
             FROM tickets
             WHERE (:status_a = '' OR status = :status_b)
               AND (:assignee_a = '' OR assigned_to = :assignee_b)
               AND (:cemail_a = '' OR customer_email = :cemail_b)
             ORDER BY updated_at DESC
             LIMIT :limit OFFSET :offset",
            $params
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /** Count of a status for KPIs. */
    public static function countByStatus(string $status): int
    {
        return (int) Db::scalar('SELECT COUNT(*) FROM tickets WHERE status = :s', [':s' => $status]);
    }

    /**
     * All tickets for a sidebar view (prototype semantics), newest first, capped.
     * view: 'all' | 'mine' | 'breaches' | 'resolved'. Each query uses distinct params.
     */
    public static function allForView(string $view, string $agentEmail): array
    {
        // Columns inlined literally per query (no interpolation into SQL, §10.1).
        return match ($view) {
            'mine' => Db::queryAll(
                "SELECT ticket_id, subject, customer_name, customer_email, priority, status,
                        assigned_to, category_id, created_at, updated_at,
                        sla_response_status, sla_resolution_status, first_response_at, resolved_at
                 FROM tickets WHERE assigned_to = :email ORDER BY updated_at DESC LIMIT 1000",
                [':email' => $agentEmail]
            ),
            'breaches' => Db::queryAll(
                "SELECT ticket_id, subject, customer_name, customer_email, priority, status,
                        assigned_to, category_id, created_at, updated_at,
                        sla_response_status, sla_resolution_status, first_response_at, resolved_at
                 FROM tickets
                 WHERE (sla_response_status = 'breached' OR sla_resolution_status = 'breached')
                   AND status IN ('open','pending') ORDER BY updated_at DESC LIMIT 1000"
            ),
            'resolved' => Db::queryAll(
                "SELECT ticket_id, subject, customer_name, customer_email, priority, status,
                        assigned_to, category_id, created_at, updated_at,
                        sla_response_status, sla_resolution_status, first_response_at, resolved_at
                 FROM tickets WHERE status IN ('resolved','closed') ORDER BY updated_at DESC LIMIT 1000"
            ),
            default => Db::queryAll(
                "SELECT ticket_id, subject, customer_name, customer_email, priority, status,
                        assigned_to, category_id, created_at, updated_at,
                        sla_response_status, sla_resolution_status, first_response_at, resolved_at
                 FROM tickets ORDER BY updated_at DESC LIMIT 1000"
            ),
        };
    }

    public static function countResolvedLast24h(): int
    {
        return (int) Db::scalar(
            'SELECT COUNT(*) FROM tickets WHERE resolved_at IS NOT NULL AND resolved_at >= :s',
            [':s' => gmdate('Y-m-d H:i:s', time() - 86400)]
        );
    }

    /** Average first-response time in hours across responded tickets (dashboard stat). */
    public static function avgFirstResponseHours(): ?float
    {
        $v = Db::scalar(
            'SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, first_response_at)) / 60
             FROM tickets WHERE first_response_at IS NOT NULL'
        );
        return $v === null ? null : round((float) $v, 1);
    }

    public static function countActiveBreaches(): int
    {
        return (int) Db::scalar(
            "SELECT COUNT(*) FROM tickets
             WHERE (sla_response_status = 'breached' OR sla_resolution_status = 'breached')
               AND status NOT IN ('resolved','closed')"
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
