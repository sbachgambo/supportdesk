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
        'customer_user_id', 'priority', 'status', 'category_id', 'tags', 'channel',
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
        $params = [
            ':f_status'   => (string) ($filters['status'] ?? ''),
            ':f_assignee' => (string) ($filters['assigned_to'] ?? ''),
            ':f_cemail'   => (string) ($filters['customer_email'] ?? ''),
        ];

        // WHERE inlined literally in both queries (no interpolation, §10.1). An empty
        // sentinel disables that filter. Keep the two WHERE blocks identical.
        $total = (int) Db::scalar(
            "SELECT COUNT(*) FROM tickets
             WHERE (:f_status = '' OR status = :f_status)
               AND (:f_assignee = '' OR assigned_to = :f_assignee)
               AND (:f_cemail = '' OR customer_email = :f_cemail)",
            $params
        );

        $params[':limit'] = $perPage;
        $params[':offset'] = (max(1, $page) - 1) * $perPage;
        $rows = Db::queryAll(
            "SELECT ticket_id, subject, customer_name, customer_email, priority, status,
                    assigned_to, category_id, created_at, updated_at,
                    sla_response_status, sla_resolution_status, first_response_at, resolved_at
             FROM tickets
             WHERE (:f_status = '' OR status = :f_status)
               AND (:f_assignee = '' OR assigned_to = :f_assignee)
               AND (:f_cemail = '' OR customer_email = :f_cemail)
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
