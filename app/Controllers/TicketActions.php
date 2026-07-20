<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Session;
use App\Core\ValidationException;
use App\Models\CannedResponse;
use App\Models\Company;
use App\Models\Ticket;
use App\Models\User;
use App\Security\MessageVisibility;
use App\Services\TicketService;

/**
 * TicketActions — staff (agent+) ticket operations reachable through the gateway (§9).
 * Every message-returning path uses the single MessageVisibility gate (D2). Service
 * validation errors are re-thrown as ValidationException → the gateway returns 422.
 */
final class TicketActions
{
    /** Build the acting-agent context from the current session. */
    private function actor(): array
    {
        $user = User::findById((int) Session::userId());
        return [
            'name'  => (string) ($user['name'] ?? Session::email()),
            'email' => (string) Session::email(),
            'role'  => (string) Session::role(),
        ];
    }

    private function unwrap(array $result): array
    {
        if (($result['ok'] ?? false) !== true) {
            throw new ValidationException((string) ($result['error'] ?? 'Invalid request.'));
        }
        return $result;
    }

    public function createTicket(array $payload, Request $request): array
    {
        $result = $this->unwrap(TicketService::create($payload, 'agent', $this->actor()));
        return ['ticket' => $result['ticket']];
    }

    /** Staff dashboard KPI counts + active agents (for the assign dropdown). */
    public function getDashboardData(array $payload, Request $request): array
    {
        return [
            'kpis' => [
                'open'         => Ticket::countByStatus('open'),
                'pending'      => Ticket::countByStatus('pending'),
                'resolved_24h' => Ticket::countResolvedLast24h(),
                'breaches'     => Ticket::countActiveBreaches(),
            ],
            'avg_response_hours' => Ticket::avgFirstResponseHours(),
            'agents' => User::activeAgents(),
            'companies' => Company::allActive(),
        ];
    }

    public function getTickets(array $payload, Request $request): array
    {
        $page = max(1, (int) ($payload['page'] ?? 1));
        $filters = [
            'status'      => is_string($payload['status'] ?? null) ? $payload['status'] : '',
            'assigned_to' => is_string($payload['assigned_to'] ?? null) ? $payload['assigned_to'] : '',
        ];
        $result = Ticket::paged($filters, $page, 10);
        return ['rows' => $result['rows'], 'total' => $result['total'], 'page' => $page, 'per_page' => 10];
    }

    /** Sidebar views (prototype): all | mine | breaches | resolved. Client paginates 10/page. */
    public function getTicketsForView(array $payload, Request $request): array
    {
        $view = (string) ($payload['view'] ?? 'all');
        if (!in_array($view, ['all', 'mine', 'breaches', 'resolved'], true)) {
            $view = 'all';
        }
        return ['view' => $view, 'tickets' => Ticket::allForView($view, (string) Session::email())];
    }

    public function getTicket(array $payload, Request $request): array
    {
        $ticketId = (string) ($payload['ticket_id'] ?? '');
        $ticket = Ticket::find($ticketId);
        if ($ticket === null) {
            throw new ValidationException('Ticket not found.');
        }
        $role = (string) Session::role();
        return [
            'ticket'      => $ticket,
            'messages'    => MessageVisibility::for($role, $ticketId),
            'attachments' => MessageVisibility::attachmentsFor($role, $ticketId),
        ];
    }

    public function sendReply(array $payload, Request $request): array
    {
        $r = $this->unwrap(TicketService::reply(
            (string) ($payload['ticket_id'] ?? ''),
            (string) ($payload['text'] ?? ''),
            $this->actor()
        ));
        return ['ticket' => $r['ticket']];
    }

    public function addInternalNote(array $payload, Request $request): array
    {
        $r = $this->unwrap(TicketService::addInternalNote(
            (string) ($payload['ticket_id'] ?? ''),
            (string) ($payload['text'] ?? ''),
            $this->actor()
        ));
        return ['ticket' => $r['ticket']];
    }

    public function changeStatus(array $payload, Request $request): array
    {
        $r = $this->unwrap(TicketService::changeStatus(
            (string) ($payload['ticket_id'] ?? ''),
            (string) ($payload['status'] ?? ''),
            $this->actor()
        ));
        return ['ticket' => $r['ticket']];
    }

    public function changePriority(array $payload, Request $request): array
    {
        $r = $this->unwrap(TicketService::changePriority(
            (string) ($payload['ticket_id'] ?? ''),
            (string) ($payload['priority'] ?? ''),
            $this->actor()
        ));
        return ['ticket' => $r['ticket']];
    }

    public function assignTicket(array $payload, Request $request): array
    {
        $r = $this->unwrap(TicketService::assign(
            (string) ($payload['ticket_id'] ?? ''),
            (string) ($payload['assigned_to'] ?? ''),
            $this->actor()
        ));
        return ['ticket' => $r['ticket']];
    }

    public function resolveTicket(array $payload, Request $request): array
    {
        $r = $this->unwrap(TicketService::changeStatus(
            (string) ($payload['ticket_id'] ?? ''),
            'resolved',
            $this->actor()
        ));
        return ['ticket' => $r['ticket']];
    }

    public function reopenTicket(array $payload, Request $request): array
    {
        $r = $this->unwrap(TicketService::changeStatus(
            (string) ($payload['ticket_id'] ?? ''),
            'open',
            $this->actor()
        ));
        return ['ticket' => $r['ticket']];
    }

    public function getCannedResponses(array $payload, Request $request): array
    {
        return ['responses' => CannedResponse::allActive()];
    }

    public function applyCannedResponse(array $payload, Request $request): array
    {
        $ticket = Ticket::find((string) ($payload['ticket_id'] ?? ''));
        $canned = CannedResponse::find((string) ($payload['response_id'] ?? ''));
        if ($ticket === null || $canned === null) {
            throw new ValidationException('Ticket or canned response not found.');
        }
        $body = TicketService::renderCanned((string) $canned['body'], $ticket, $this->actor()['name']);
        return ['body' => $body];
    }
}
