<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Ids;
use App\Models\Category;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Security\Audit;

/**
 * TicketService (§3) — the ticket lifecycle. All business rules live here; models
 * stay thin. Every mutation audit-logs and touches updated_at.
 *
 * SLA (§3): deadlines are set at create; on a priority change they are recalculated
 * from the ORIGINAL creation time and both milestones re-graded. First response is
 * stamped on the first customer-visible agent reply (never on an internal note).
 * Reopen clears resolved_at and resets the resolution SLA to pending.
 */
final class TicketService
{
    public const PRIORITIES = ['urgent', 'high', 'normal', 'low'];
    public const STATUSES = ['open', 'pending', 'resolved', 'closed'];
    public const CHANNELS = ['web_form', 'agent', 'email', 'status_page', 'widget'];

    /**
     * Create a ticket. $actor is the acting agent (['name','email','role']) or null
     * for public/email channels. Returns ['ok'=>true,'ticket'=>row] or ['ok'=>false,'error'=>..].
     */
    public static function create(array $input, string $channel, ?array $actor = null): array
    {
        $subject = trim((string) ($input['subject'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $customerEmail = strtolower(trim((string) ($input['customer_email'] ?? '')));
        $customerName = trim((string) ($input['customer_name'] ?? ''));
        $company = trim((string) ($input['company'] ?? ''));
        $priority = (string) ($input['priority'] ?? 'normal');
        $categoryId = trim((string) ($input['category_id'] ?? ''));
        $tags = trim((string) ($input['tags'] ?? ''));

        // ── validation ──
        if ($subject === '' || mb_strlen($subject) > 200) {
            return self::err('Subject is required and must be at most 200 characters.');
        }
        if ($description === '' || mb_strlen($description) > 5000) {
            return self::err('Description is required and must be at most 5000 characters.');
        }
        if (!filter_var($customerEmail, FILTER_VALIDATE_EMAIL) || strlen($customerEmail) > 254) {
            return self::err('A valid customer email is required.');
        }
        if (mb_strlen($customerName) > 120) {
            return self::err('Customer name is too long.');
        }
        if (mb_strlen($company) > 120) {
            return self::err('Company name is too long.');
        }
        if (!in_array($priority, self::PRIORITIES, true)) {
            return self::err('Invalid priority.');
        }
        if (!in_array($channel, self::CHANNELS, true)) {
            return self::err('Invalid channel.');
        }
        if ($categoryId !== '' && !Category::existsActive($categoryId)) {
            return self::err('Selected category does not exist.');
        }
        if (mb_strlen($tags) > 255) {
            return self::err('Tags are too long.');
        }

        // Routing rules (§3): applied on non-agent channels; may override priority,
        // category, tags, and assignment BEFORE SLA is computed (priority drives SLA).
        $ruleAssignee = null;
        if ($channel !== 'agent') {
            $overrides = RoutingRules::apply([
                'subject' => $subject, 'description' => $description, 'priority' => $priority,
                'category_id' => $categoryId, 'customer_email' => $customerEmail,
                'channel' => $channel, 'tags' => $tags,
            ]);
            $priority = $overrides['priority'] ?? $priority;
            $categoryId = $overrides['category_id'] ?? $categoryId;
            $tags = $overrides['tags'] ?? $tags;
            $ruleAssignee = $overrides['assigned_to'] ?? null;
        }

        $now = gmdate('Y-m-d H:i:s');
        $deadlines = SlaCalculator::deadlines($priority, $now);

        // Assignment precedence: routing rule → explicit request → auto-assign least-busy.
        $requested = strtolower(trim((string) ($input['assigned_to'] ?? '')));
        if ($ruleAssignee !== null) {
            $assignee = $ruleAssignee;
        } elseif ($requested !== '' && User::findActiveAgent($requested) !== null) {
            $assignee = $requested;
        } else {
            $assignee = User::leastBusyAgentEmail();
        }

        // Sequence + insert in one transaction so concurrent creates can't collide.
        $ticketId = Db::transaction(static function () use (
            $subject, $description, $customerName, $customerEmail, $company, $priority,
            $categoryId, $tags, $channel, $assignee, $now, $deadlines, $input
        ): string {
            $tid = Ids::nextTicketId();
            Ticket::create([
                'ticket_id'               => $tid,
                'subject'                 => $subject,
                'description'             => $description,
                'customer_name'           => $customerName,
                'customer_email'          => $customerEmail,
                'customer_user_id'        => $input['customer_user_id'] ?? null,
                'company'                 => $company,
                'priority'                => $priority,
                'status'                  => 'open',
                'category_id'             => $categoryId === '' ? null : $categoryId,
                'tags'                    => $tags,
                'channel'                 => $channel,
                'assigned_to'             => $assignee,
                'created_at'              => $now,
                'updated_at'              => $now,
                'sla_response_deadline'   => $deadlines['response'],
                'sla_resolution_deadline' => $deadlines['resolution'],
                'sla_response_status'     => 'pending',
                'sla_resolution_status'   => 'pending',
            ]);
            return $tid;
        });

        // The reported issue becomes the first (customer) message in the thread.
        Message::add($ticketId, 'customer', $description, $customerName, $customerEmail, false);

        $actorEmail = (string) ($actor['email'] ?? $customerEmail);
        Audit::log($actorEmail, 'ticket_created', $ticketId, "channel={$channel} priority={$priority}");

        if ($assignee !== null) {
            NotificationService::create($assignee, 'assigned', "You were assigned {$ticketId}", $ticketId);
        }

        return ['ok' => true, 'ticket' => Ticket::find($ticketId)];
    }

    /** Customer-visible agent reply. Stamps first response if not yet set. */
    public static function reply(string $ticketId, string $text, array $actor): array
    {
        $ticket = Ticket::find($ticketId);
        if ($ticket === null) {
            return self::err('Ticket not found.');
        }
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 5000) {
            return self::err('Reply text is required and must be at most 5000 characters.');
        }

        Message::add($ticketId, 'agent', $text, (string) $actor['name'], (string) $actor['email'], false);

        $fields = [];
        if (($ticket['first_response_at'] ?? null) === null) {
            $fields['first_response_at'] = gmdate('Y-m-d H:i:s');
            $ticket['first_response_at'] = $fields['first_response_at'];
            $fields['sla_response_status'] = SlaCalculator::grade($ticket)['response'];
        }
        // Prototype parity (§3): an agent reply on an OPEN ticket hands the ball back
        // to the customer → pending. Other statuses (pending/resolved/closed) are left
        // as-is; a customer reply is what reopens a resolved ticket (customerReply).
        if ((string) $ticket['status'] === 'open') {
            $fields['status'] = 'pending';
        }
        Ticket::update($ticketId, $fields);

        Audit::log((string) $actor['email'], 'ticket_reply', $ticketId);
        return ['ok' => true, 'ticket' => Ticket::find($ticketId)];
    }

    /**
     * Customer reply from the portal (from_type 'customer'). Reopens a resolved/closed
     * ticket (clears resolved_at, resolution SLA → pending) and notifies the assignee.
     */
    public static function customerReply(string $ticketId, string $text, array $customer): array
    {
        $ticket = Ticket::find($ticketId);
        if ($ticket === null) {
            return self::err('Ticket not found.');
        }
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 5000) {
            return self::err('Reply text is required and must be at most 5000 characters.');
        }

        Message::add($ticketId, 'customer', $text, (string) ($customer['name'] ?? ''), (string) ($customer['email'] ?? ''), false);

        $fields = [];
        if (in_array((string) $ticket['status'], ['resolved', 'closed'], true)) {
            $fields['status'] = 'open';
            $fields['resolved_at'] = null;
            $fields['sla_resolution_status'] = 'pending';
        }
        Ticket::update($ticketId, $fields);

        Audit::log((string) ($customer['email'] ?? '-'), 'customer_reply', $ticketId);
        if (($ticket['assigned_to'] ?? null) !== null) {
            NotificationService::create((string) $ticket['assigned_to'], 'customer_reply', "New customer reply on {$ticketId}", $ticketId);
        }
        return ['ok' => true, 'ticket' => Ticket::find($ticketId)];
    }

    /** Internal note — never customer-visible, never counts as first response. */
    public static function addInternalNote(string $ticketId, string $text, array $actor): array
    {
        $ticket = Ticket::find($ticketId);
        if ($ticket === null) {
            return self::err('Ticket not found.');
        }
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 5000) {
            return self::err('Note text is required and must be at most 5000 characters.');
        }
        Message::add($ticketId, 'note', $text, (string) $actor['name'], (string) $actor['email'], true);
        Ticket::update($ticketId, []); // touch updated_at
        Audit::log((string) $actor['email'], 'ticket_note', $ticketId);
        return ['ok' => true, 'ticket' => Ticket::find($ticketId)];
    }

    /** Change status, with resolve/reopen semantics (§3). */
    public static function changeStatus(string $ticketId, string $newStatus, array $actor): array
    {
        $ticket = Ticket::find($ticketId);
        if ($ticket === null) {
            return self::err('Ticket not found.');
        }
        if (!in_array($newStatus, self::STATUSES, true)) {
            return self::err('Invalid status.');
        }
        $old = (string) $ticket['status'];
        $fields = ['status' => $newStatus];

        $isResolving = in_array($newStatus, ['resolved', 'closed'], true) && !in_array($old, ['resolved', 'closed'], true);
        $isReopening = in_array($old, ['resolved', 'closed'], true) && in_array($newStatus, ['open', 'pending'], true);

        if ($isResolving) {
            $fields['resolved_at'] = gmdate('Y-m-d H:i:s');
            $ticket['resolved_at'] = $fields['resolved_at'];
            $ticket['status'] = $newStatus;
            $fields['sla_resolution_status'] = SlaCalculator::grade($ticket)['resolution'];
        } elseif ($isReopening) {
            // Reopen clears resolved_at and resets the resolution SLA to pending (§3).
            $fields['resolved_at'] = null;
            $fields['sla_resolution_status'] = 'pending';
        }

        Ticket::update($ticketId, $fields);
        Audit::log((string) $actor['email'], 'ticket_status', $ticketId, "{$old}->{$newStatus}");
        return ['ok' => true, 'ticket' => Ticket::find($ticketId)];
    }

    /**
     * Change priority: recompute deadlines FROM ORIGINAL creation time with the new
     * tier, then re-grade both milestones (already-passed milestones flip immediately).
     */
    public static function changePriority(string $ticketId, string $newPriority, array $actor): array
    {
        $ticket = Ticket::find($ticketId);
        if ($ticket === null) {
            return self::err('Ticket not found.');
        }
        if (!in_array($newPriority, self::PRIORITIES, true)) {
            return self::err('Invalid priority.');
        }
        $old = (string) $ticket['priority'];
        $deadlines = SlaCalculator::deadlines($newPriority, (string) $ticket['created_at']);

        $ticket['priority'] = $newPriority;
        $ticket['sla_response_deadline'] = $deadlines['response'];
        $ticket['sla_resolution_deadline'] = $deadlines['resolution'];
        $graded = SlaCalculator::grade($ticket);

        Ticket::update($ticketId, [
            'priority'                => $newPriority,
            'sla_response_deadline'   => $deadlines['response'],
            'sla_resolution_deadline' => $deadlines['resolution'],
            'sla_response_status'     => $graded['response'],
            'sla_resolution_status'   => $graded['resolution'],
        ]);
        Audit::log((string) $actor['email'], 'ticket_priority', $ticketId, "{$old}->{$newPriority}");
        return ['ok' => true, 'ticket' => Ticket::find($ticketId)];
    }

    /** Assign (or reassign) to an active agent. */
    public static function assign(string $ticketId, string $agentEmail, array $actor): array
    {
        $ticket = Ticket::find($ticketId);
        if ($ticket === null) {
            return self::err('Ticket not found.');
        }
        $agentEmail = strtolower(trim($agentEmail));
        if ($agentEmail !== '' && User::findActiveAgent($agentEmail) === null) {
            return self::err('That agent is not an active agent.');
        }
        Ticket::update($ticketId, ['assigned_to' => $agentEmail === '' ? null : $agentEmail]);
        Audit::log((string) $actor['email'], 'ticket_assign', $ticketId, "to={$agentEmail}");
        if ($agentEmail !== '') {
            NotificationService::create($agentEmail, 'assigned', "You were assigned {$ticketId}", $ticketId);
        }
        return ['ok' => true, 'ticket' => Ticket::find($ticketId)];
    }

    /** Substitute {customerName}/{agentName}/{ticketId} in a canned-response body (§3). */
    public static function renderCanned(string $body, array $ticket, string $agentName): string
    {
        return strtr($body, [
            '{customerName}' => (string) ($ticket['customer_name'] ?? ''),
            '{agentName}'    => $agentName,
            '{ticketId}'     => (string) ($ticket['ticket_id'] ?? ''),
        ]);
    }

    private static function err(string $message): array
    {
        return ['ok' => false, 'error' => $message];
    }
}
