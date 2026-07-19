<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Session;
use App\Core\ValidationException;
use App\Models\Ticket;
use App\Models\User;
use App\Security\MessageVisibility;
use App\Services\TicketService;

/**
 * CustomerActions — the authenticated customer portal (D2). Reads are ownership-scoped:
 * getMyTickets lists only the caller's own tickets; getMyTicket / replyToMyTicket /
 * submitCsat are 'owner' actions (the gateway enforces ownership before they run).
 * Messages always come through the MessageVisibility gate, so internal notes never leak.
 */
final class CustomerActions
{
    private function me(): array
    {
        $user = User::findById((int) Session::userId());
        return [
            'id'    => (int) Session::userId(),
            'name'  => (string) ($user['name'] ?? ''),
            'email' => (string) Session::email(),
        ];
    }

    public function getMyTickets(array $payload, Request $request): array
    {
        $me = $this->me();
        $page = max(1, (int) ($payload['page'] ?? 1));
        $result = Ticket::pagedForCustomer($me['id'], $me['email'], $page, 10);
        return ['rows' => $result['rows'], 'total' => $result['total'], 'page' => $page, 'per_page' => 10];
    }

    public function getMyTicket(array $payload, Request $request): array
    {
        $ticketId = (string) ($payload['ticket_id'] ?? '');
        $ticket = Ticket::find($ticketId);
        if ($ticket === null) {
            throw new ValidationException('Ticket not found.');
        }
        // Viewer role governs visibility — a customer gets the filtered set (D2).
        $role = (string) Session::role();
        return [
            'ticket'      => $ticket,
            'messages'    => MessageVisibility::for($role, $ticketId),
            'attachments' => MessageVisibility::attachmentsFor($role, $ticketId),
        ];
    }

    public function replyToMyTicket(array $payload, Request $request): array
    {
        $result = TicketService::customerReply(
            (string) ($payload['ticket_id'] ?? ''),
            (string) ($payload['text'] ?? ''),
            $this->me()
        );
        if (($result['ok'] ?? false) !== true) {
            throw new ValidationException((string) ($result['error'] ?? 'Invalid request.'));
        }
        return ['ok' => true];
    }

    public function submitCsat(array $payload, Request $request): array
    {
        $ticketId = (string) ($payload['ticket_id'] ?? '');
        $ticket = Ticket::find($ticketId);
        if ($ticket === null) {
            throw new ValidationException('Ticket not found.');
        }
        if (!in_array((string) $ticket['status'], ['resolved', 'closed'], true)) {
            throw new ValidationException('You can only rate a ticket once it is resolved.');
        }
        $rating = (int) ($payload['rating'] ?? 0);
        if ($rating < 1 || $rating > 5) {
            throw new ValidationException('Please provide a rating from 1 to 5.');
        }
        $comment = (string) ($payload['comment'] ?? '');
        Ticket::setCsat($ticketId, $rating, $comment);
        return ['ok' => true];
    }
}
